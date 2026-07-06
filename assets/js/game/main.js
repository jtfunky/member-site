import { initMidi, selectInput, getInputList, onDeviceChange } from './midi.js';
import { onMidiMessage, flushHits, setChannelFilter, getDefaultMap, getLaneNames } from './input.js';
import { loadSong, updateChart, getActiveNotes, findClosestNote, expireMissed, reset as resetChart } from './chart.js?v=20260702';
import { initRenderer, drawFrame, addFlash, addJudgment } from './renderer.js?v=20260711';
import { createScoreState, judgeHit, judgeMiss, calcGrade, calcAccuracy, TIMING } from './scoring.js?v=20260702';
import { playHitClick, playCountdownBeep, resumeContext } from './audio.js';
import { loadAudioUrl, playAudio, pauseAudio, resumeAudio, stopAudio, hasAudio, getAudioDurationMs } from './audio-player.js';
import { startAcousticDetection, stopAcousticDetection, setAcousticSensitivity, isAcousticActive,
         startCalibration, cancelCalibration, isLaneCalibrated, clearLane, startPadDetection } from './acoustic.js?v=20260709';
import { song as demoSong } from './songs/demo.js?v=20260705';
import { song as rockSong } from './songs/rock-basics.js?v=20260705';

demoSong.id           = 'demo';
demoSong.artist       = 'MS Drums';
demoSong.builtin      = true;
demoSong.placementTest = true;   // the standardized exercise used by the placement test

rockSong.id      = 'rock-basics';
rockSong.artist  = 'MS Drums';
rockSong.builtin = true;

// ── State ─────────────────────────────────────────────────
const STATES = { IDLE: 0, COUNTDOWN: 2, PLAYING: 3, PAUSED: 4, RESULTS: 5 };
let state            = STATES.IDLE;
let songStartTime    = 0;
let scrollTimeWindow = 2000;
let hitSoundEnabled  = true;
let scoreState       = null;
let myBests          = {};   // { song_id: {score, grade, accuracy} } — member's per-song best
let countdownTimer   = null;
let rafId            = null;
let currentSong      = demoSong;
let songDuration     = 0;   // effective length (ms) for this playthrough
let pausedPosition   = 0;
let allSongs         = [];
let inputMode        = ''; // 'midi' | 'acoustic' — chosen from the menu before playing

// Placement-test config comes from a data element in game.php (the site CSP
// blocks inline scripts, so we can't set window globals there).
const _testCfg      = document.getElementById('drum-test-config');
const _gameCfg      = document.getElementById('game-config');
const DRUM_TEST_MODE = !!_testCfg;
// CSRF is on #game-config (present on every game load); test config kept as marker.
const CSRF_TOKEN     = _gameCfg ? (_gameCfg.dataset.csrf || '') : (_testCfg ? (_testCfg.dataset.csrf || '') : '');

const canvas = document.getElementById('game-canvas');
initRenderer(canvas);

// ── Fullscreen helpers ────────────────────────────────────
function enterFullscreen() {
  const el = document.documentElement;
  let p;
  if (el.requestFullscreen)            p = el.requestFullscreen();
  else if (el.webkitRequestFullscreen) p = el.webkitRequestFullscreen();
  // Force landscape once fullscreen is actually active. The Screen Orientation
  // lock overrides the device's own rotation lock (auto-rotate off) on browsers
  // that support it (Android Chrome/Edge). iOS ignores it → the rotate overlay.
  Promise.resolve(p).then(lockLandscape).catch(() => {});
}

function exitFullscreenApi() {
  if (document.fullscreenElement || document.webkitFullscreenElement) {
    if (document.exitFullscreen) document.exitFullscreen().catch(() => {});
    else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
  }
}

// Auto-pause when user presses Escape to exit fullscreen
document.addEventListener('fullscreenchange', () => {
  if (!document.fullscreenElement && state === STATES.PLAYING) pauseGame();
});
document.addEventListener('webkitfullscreenchange', () => {
  if (!document.webkitFullscreenElement && state === STATES.PLAYING) pauseGame();
});

// ── Screen helpers ────────────────────────────────────────
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  const el = document.getElementById('screen-' + id);
  if (el) el.classList.add('active');
}

// ── Song library ──────────────────────────────────────────
async function loadSongLibrary() {
  let custom = [];
  try {
    const res = await fetch('/api/songs.php');
    if (res.ok) custom = await res.json();
  } catch (e) { /* network error, just show demo */ }

  allSongs = [{ ...demoSong, builtin: true }, { ...rockSong, builtin: true }, ...custom];
  await loadProgress();
}

// Fetch best-per-song + recent plays and re-render the library. Called on boot
// and after each play so scores stay current without a page reload. Skipped for
// the placement test; non-fatal if the table isn't migrated yet.
async function loadProgress() {
  if (!DRUM_TEST_MODE) {
    try {
      const res = await fetch('/api/my-plays.php');
      if (res.ok) {
        const data = await res.json();
        myBests = data.bests || {};
        renderRecentPlays(data.recent || []);
      }
    } catch (e) { /* non-fatal */ }
  }
  renderSongList();
}

function renderRecentPlays(recent) {
  const wrap = document.getElementById('recent-plays');
  if (!wrap) return;
  if (!recent.length) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }
  wrap.style.display = '';
  const rows = recent.map(r => `
    <div class="recent-row">
      <span class="recent-song">${escHtml(r.song_title || '—')}</span>
      <span class="recent-grade">${escHtml(r.grade || '')}</span>
      <span class="recent-score">${Number(r.score || 0).toLocaleString()}</span>
      <span class="recent-acc">${Number(r.accuracy || 0).toFixed(1)}%</span>
    </div>`).join('');
  wrap.innerHTML = `<h2>Recent Plays</h2><div class="recent-list">${rows}</div>`;
}

function renderSongList() {
  const list = document.getElementById('song-list');
  list.innerHTML = '';

  // Placement test uses a single standardized exercise so results are comparable.
  const songs = DRUM_TEST_MODE ? allSongs.filter(s => s.placementTest) : allSongs;
  if (DRUM_TEST_MODE) {
    const heading = document.querySelector('#song-library h2');
    if (heading) heading.textContent = 'Placement Test — play this exercise';
  }

  songs.forEach(song => {
    const best = myBests[String(song.id)];
    const bestHtml = best
      ? `<span class="song-best">🏆 Best: ${Number(best.score).toLocaleString()}${best.grade ? ' · ' + escHtml(best.grade) : ''}</span>`
      : '';
    const item = document.createElement('div');
    item.className = 'song-item';
    item.innerHTML = `
      <div class="song-item-info">
        <strong>${escHtml(song.title)}</strong>
        <span>${escHtml(song.artist || 'Unknown')}</span>
        <span class="song-meta">${song.bpm} BPM · ${song.notes?.length || 0} notes</span>
        ${bestHtml}
      </div>
      <button class="btn btn-primary btn-sm song-play-btn">Play</button>
    `;
    item.querySelector('.song-play-btn').addEventListener('click', () => selectSong(song));
    list.appendChild(item);
  });
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Song selection ────────────────────────────────────────
function lockLandscape() {
  try {
    screen.orientation?.lock('landscape').catch(() => {});
  } catch (e) {}
}

function unlockOrientation() {
  try { screen.orientation?.unlock(); } catch (e) {}
}

async function selectSong(song) {
  // Playing needs an input (e-drums or mic). Require one before starting.
  if (inputMode !== 'midi' && inputMode !== 'acoustic') {
    const msg = document.getElementById('input-required-msg');
    if (msg) msg.style.display = '';
    document.getElementById('menu-input-select')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  enterFullscreen(); // must fire synchronously in the click handler — Safari drops the gesture after any await or timer
  lockLandscape();
  currentSong = song;

  // Show loading
  document.getElementById('countdown-title').textContent  = song.title;
  document.getElementById('countdown-artist').textContent = song.artist || '';

  if (song.audioUrl) {
    try { await loadAudioUrl(song.audioUrl); } catch (e) { console.warn('Audio load failed:', e); }
  }

  startCountdown();
}

// ── Countdown ─────────────────────────────────────────────
function startCountdown() {
  showScreen('countdown');
  let n = 3;
  const numEl = document.getElementById('countdown-number');
  numEl.textContent = n;
  playCountdownBeep(false);

  countdownTimer = setInterval(() => {
    n--;
    if (n <= 0) {
      clearInterval(countdownTimer);
      startGame();
    } else {
      numEl.textContent = n;
      playCountdownBeep(n === 1);
    }
  }, 1000);
}

// ── Game ──────────────────────────────────────────────────
// Effective song length (ms). Prefer the stored duration, but self-correct when
// it's missing/zero/garbage by falling back to the decoded audio's real length,
// then to the last note's time so the game always ends cleanly.
function resolveSongDuration() {
  const stored = Number(currentSong.duration);
  if (Number.isFinite(stored) && stored > 0) return stored;

  const audioMs = getAudioDurationMs();
  if (audioMs > 0) return audioMs;

  const notes = currentSong.notes || [];
  return notes.reduce((max, n) => Math.max(max, n.time), 0);
}

function startGame() {
  showScreen('game');
  document.getElementById('hud-title').textContent = currentSong.title;

  loadSong(currentSong);
  scoreState    = createScoreState(currentSong.notes.length);
  songStartTime = performance.now();
  songDuration  = resolveSongDuration();
  state         = STATES.PLAYING;

  if (hasAudio()) playAudio(0);   // start from the beginning of the track (offset is ms-into-song, not a timestamp)

  cancelAnimationFrame(rafId);
  rafId = requestAnimationFrame(gameLoop);
}

function gameLoop(now) {
  if (state !== STATES.PLAYING) return;

  const songPos = now - songStartTime;
  updateChart(songPos, scrollTimeWindow);

  const missedLanes = expireMissed(songPos, TIMING.MISS_EXPIRE);
  missedLanes.forEach(lane => {
    judgeMiss(scoreState, lane);
    addJudgment('MISS', lane);
  });

  // MIDI hits (buffered from the device between frames)
  const hits = flushHits();
  hits.forEach(hit => processHit(hit.lane, now));

  drawFrame(getActiveNotes(), songPos, scrollTimeWindow, scoreState);

  document.getElementById('hud-score').textContent = scoreState.score;
  document.getElementById('hud-combo').textContent = scoreState.combo;

  if (songPos >= songDuration + 2000) {
    endGame();
    return;
  }

  rafId = requestAnimationFrame(gameLoop);
}

function processHit(lane, now) {
  const songPos = now - songStartTime;
  const note = findClosestNote(lane, songPos, TIMING.MISS_EXPIRE);
  if (note.index === -1) return;

  const activeNotes = getActiveNotes();
  const n      = activeNotes[note.index];
  const signed = songPos - n.time;   // − = early (rushing), + = late (dragging)
  const dt     = Math.abs(signed);
  const judgment = judgeHit(scoreState, dt, signed, lane);
  if (judgment) {
    n.hit = true;
    addFlash(lane);
    addJudgment(judgment, lane);
    if (hitSoundEnabled) playHitClick();
  }
}

// Single-pad input: one hit is judged against the nearest unhit note in ANY lane
// (timing practice — the student plays the rhythm on one pad).
function processPadHit(now) {
  const songPos = now - songStartTime;
  const activeNotes = getActiveNotes();
  let best = -1, bestDt = Infinity;
  for (let i = 0; i < activeNotes.length; i++) {
    const n = activeNotes[i];
    if (n.hit || n.missed) continue;
    const dt = Math.abs(songPos - n.time);
    if (dt < bestDt && dt <= TIMING.MISS_EXPIRE) { bestDt = dt; best = i; }
  }
  if (best === -1) return;

  const n      = activeNotes[best];
  const signed = songPos - n.time;
  const judgment = judgeHit(scoreState, Math.abs(signed), signed, n.lane);
  if (judgment) {
    n.hit = true;
    addFlash(n.lane);
    addJudgment(judgment, n.lane);
    if (hitSoundEnabled) playHitClick();
  }
}

function endGame() {
  state = STATES.RESULTS;
  cancelAnimationFrame(rafId);
  stopAudio();
  showScreen('results');

  const acc   = calcAccuracy(scoreState);
  const grade = calcGrade(scoreState);
  const c     = scoreState.counts;

  document.getElementById('results-grade').textContent  = grade;
  document.getElementById('results-song').textContent   = currentSong.title;
  document.getElementById('r-score').textContent        = scoreState.score;
  document.getElementById('r-accuracy').textContent     = acc.toFixed(1) + '%';
  document.getElementById('r-perfect').textContent      = c.PERFECT;
  document.getElementById('r-good').textContent         = c.GOOD;
  document.getElementById('r-miss').textContent         = c.MISS;
  document.getElementById('r-combo').textContent        = scoreState.maxCombo;

  // Placement-test mode: save this play and fetch AI coaching for it.
  // Normal play: record the score so it shows up in the member's progress.
  if (DRUM_TEST_MODE) submitPlacementTest(acc, grade);
  else savePlay(acc, grade);
}

// Record a completed (non-test) play. Fire-and-forget — never blocks the results.
async function savePlay(acc, grade) {
  const c = scoreState.counts;
  const payload = {
    song_id:      String(currentSong.id ?? ''),
    song_title:   currentSong.title,
    score:        scoreState.score,
    accuracy:     Math.round(acc * 10) / 10,
    grade,
    max_combo:    scoreState.maxCombo,
    perfect:      c.PERFECT,
    good:         c.GOOD,
    miss:         c.MISS,
    total_notes:  scoreState.totalNotes,
    input_method: inputMode,
  };
  try {
    await fetch('/api/save-play.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify(payload),
    });
    loadProgress();   // refresh best-per-song + recent list from the server
  } catch (e) { /* offline / network — non-fatal */ }
}

// ── Placement test: submit metrics + render AI coaching ───
const mean  = arr => (arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0);
const stdev = arr => {
  if (arr.length < 2) return 0;
  const m = mean(arr);
  return Math.sqrt(mean(arr.map(x => (x - m) * (x - m))));
};

async function submitPlacementTest(acc, grade) {
  const box = document.getElementById('results-ai');
  if (box) {
    box.style.display = '';
    box.innerHTML = '<div class="ai-loading">Analyzing your playing…</div>';
  }

  const laneNames = getLaneNames();
  const c = scoreState.counts;
  const pads = Object.keys(scoreState.lanes).map(k => {
    const b = scoreState.lanes[k];
    const attempts = b.perfect + b.good + b.miss;
    return {
      pad: laneNames[k] || ('Lane ' + k),
      perfect: b.perfect,
      good: b.good,
      miss: b.miss,
      accuracy: attempts ? Math.round(((b.perfect * 100 + b.good * 50) / (attempts * 100)) * 1000) / 10 : null,
      avg_offset_ms: b.offsetCount ? Math.round(b.offsetSum / b.offsetCount) : null,
    };
  });

  const payload = {
    song: currentSong.title,
    input_method: inputMode,
    score: scoreState.score,
    accuracy: Math.round(acc * 10) / 10,
    grade,
    perfect: c.PERFECT,
    good: c.GOOD,
    miss: c.MISS,
    max_combo: scoreState.maxCombo,
    total_notes: scoreState.totalNotes,
    avg_offset_ms: Math.round(mean(scoreState.offsets)),
    timing_consistency_ms: Math.round(stdev(scoreState.offsets)),
    pads,
  };

  try {
    const saveRes = await fetch('/api/save-test.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify(payload),
    });
    const saved = await saveRes.json();
    if (!saveRes.ok || !saved.id) throw new Error(saved.error || 'Could not save your test.');

    const fbRes = await fetch('/api/ai-feedback.php?id=' + encodeURIComponent(saved.id), {
      headers: { 'X-CSRF-Token': CSRF_TOKEN },
    });
    const fb = await fbRes.json();
    if (box) {
      if (fbRes.ok && fb.feedback_html) {
        box.innerHTML = '<h3>Coach Zach</h3><div class="ai-feedback-body">' + fb.feedback_html + '</div>';
      } else {
        box.innerHTML = '<div class="ai-error">' + escHtml(fb.error || 'Feedback is not available right now.') + '</div>';
      }
    }
  } catch (e) {
    if (box) box.innerHTML = '<div class="ai-error">' + escHtml(e.message) + '</div>';
  }
}

// ── Pause / Resume ────────────────────────────────────────
function pauseGame() {
  if (state !== STATES.PLAYING) return;
  pausedPosition = performance.now() - songStartTime;
  state = STATES.PAUSED;
  cancelAnimationFrame(rafId);
  pauseAudio();
  showScreen('pause');
}

function resumeGame() {
  if (state !== STATES.PAUSED) return;
  showScreen('game');
  songStartTime = performance.now() - pausedPosition;
  state = STATES.PLAYING;
  resumeAudio();   // resumes from the paused position it stored internally
  rafId = requestAnimationFrame(gameLoop);
}

// ── Keyboard: pause/resume only ───────────────────────────
// Playing is e-drums (MIDI) or acoustic mic only — the keyboard no longer
// triggers hits, but Escape still pauses/resumes.
document.addEventListener('keydown', e => {
  if (e.repeat) return;
  resumeContext();

  if (e.key === 'Escape') {
    if (state === STATES.PLAYING) pauseGame();
    else if (state === STATES.PAUSED) resumeGame();
  }
});

// ── MIDI ──────────────────────────────────────────────────
async function setupMidi() {
  try {
    await initMidi(msg => {
      if (state !== STATES.PLAYING || inputMode !== 'midi') return;
      onMidiMessage(msg);
    });
    populateMidiDevices();
    onDeviceChange(() => populateMidiDevices());
  } catch (e) {
    const sel = document.getElementById('midi-device-select');
    if (sel) sel.innerHTML = '<option>MIDI not available (use Acoustic mic instead)</option>';
  }
}

function populateMidiDevices() {
  const sel = document.getElementById('midi-device-select');
  if (!sel) return;
  const list = getInputList();
  sel.innerHTML = list.length
    ? list.map((d, i) => `<option value="${i}">${escHtml(d.name)}</option>`).join('')
    : '<option value="">No MIDI devices found</option>';
}

// ── UI wiring ─────────────────────────────────────────────
const acousticStatus = document.getElementById('acoustic-status');
const acousticSensGroup = document.getElementById('acoustic-sensitivity-group');

// Acoustic requires calibrating at least these before play (Kick, Snare, Hi-Hat).
const REQUIRED_LANES = [0, 1, 2];
// Drums offered in the calibration list (covers every lane the built-in songs use;
// 18" crash / lane 8 is omitted as no built-in song uses it). Students calibrate
// only the drums they actually have — uncalibrated lanes simply won't fire.
const CAL_LANES = [0, 1, 2, 3, 4, 5, 6, 7, 9];

function requiredCalibrated() {
  return REQUIRED_LANES.every(l => isLaneCalibrated(l));
}

// Students configure their input first. E-drums arm on selection; acoustic arms
// only once the required drums are calibrated. The song library stays locked (with
// a context-appropriate hint) until then.
function syncInputGate() {
  const lib = document.getElementById('song-library');
  const msg = document.getElementById('input-required-msg');
  const cal = document.getElementById('acoustic-calibration');

  let armed = false, hint = '🔒 Choose your input above to unlock the song library.';
  if (inputMode === 'midi' || inputMode === 'pad') {
    armed = true;
  } else if (inputMode === 'acoustic') {
    armed = requiredCalibrated();
    hint  = '🎚️ Calibrate your Kick, Snare and Hi-Hat below to unlock the song library.';
  }

  if (cal) cal.style.display = (inputMode === 'acoustic') ? '' : 'none';
  if (lib) lib.style.display = armed ? '' : 'none';
  if (msg) { msg.style.display = armed ? 'none' : ''; if (!armed) msg.textContent = hint; }
}

// ── Acoustic calibration UI ───────────────────────────────
let calibrating = false;

function buildCalibrationList() {
  const list = document.getElementById('cal-list');
  if (!list) return;
  const names = getLaneNames();
  list.innerHTML = '';

  CAL_LANES.forEach(lane => {
    const done     = isLaneCalibrated(lane);
    const required = REQUIRED_LANES.includes(lane);
    const row = document.createElement('div');
    row.className = 'cal-row' + (done ? ' cal-done' : '');
    row.dataset.lane = lane;
    row.innerHTML = `
      <span class="cal-name">${escHtml(names[lane] || ('Lane ' + lane))}${required ? '<span class="cal-req">required</span>' : ''}</span>
      <span class="cal-state ${done ? 'ok' : ''}">${done ? '✓ Calibrated' : 'Not set'}</span>
      <button class="cal-btn">${done ? 'Redo' : 'Calibrate'}</button>
      ${done ? '<button class="cal-btn cal-btn-clear">Clear</button>' : ''}
    `;
    row.querySelector('.cal-btn').addEventListener('click', () => beginCalibrate(lane, row));
    const clr = row.querySelector('.cal-btn-clear');
    if (clr) clr.addEventListener('click', () => { clearLane(lane); buildCalibrationList(); syncInputGate(); });
    list.appendChild(row);
  });
}

function beginCalibrate(lane, row) {
  if (calibrating) return;
  calibrating = true;
  document.querySelectorAll('.cal-row').forEach(r => r.classList.remove('cal-listening'));
  row.classList.add('cal-listening');
  const stateEl = row.querySelector('.cal-state');
  stateEl.classList.remove('ok');
  stateEl.textContent = 'Hit it now… 0/4';

  startCalibration(
    lane,
    (count, needed) => { stateEl.textContent = `Hit it now… ${count}/${needed}`; },
    () => { calibrating = false; buildCalibrationList(); syncInputGate(); }
  ).catch(err => {
    calibrating = false;
    row.classList.remove('cal-listening');
    stateEl.textContent = '❌ ' + err.message;
  });
}

async function switchInputMode(mode) {
  // Stop previous acoustic if active
  if ((inputMode === 'acoustic' || inputMode === 'pad') && mode !== inputMode) {
    stopAcousticDetection();
    if (acousticStatus) { acousticStatus.style.display = 'none'; }
    if (acousticSensGroup) acousticSensGroup.style.display = 'none';
  }

  inputMode = mode;

  if (mode === 'midi') {
    await setupMidi();
    if (acousticStatus) acousticStatus.style.display = 'none';
    if (acousticSensGroup) acousticSensGroup.style.display = 'none';
  } else if (mode === 'acoustic') {
    if (acousticSensGroup) acousticSensGroup.style.display = '';
    if (acousticStatus) {
      acousticStatus.style.display = '';
      acousticStatus.textContent = '🎙️ Requesting microphone access…';
      acousticStatus.className = 'acoustic-status acoustic-pending';
    }
    try {
      await startAcousticDetection((lane) => {
        if (state === STATES.PLAYING) processHit(lane, performance.now());
      });
      if (acousticStatus) {
        acousticStatus.textContent = '🎙️ Microphone active — calibrate your kit below.';
        acousticStatus.className = 'acoustic-status acoustic-active';
      }
      buildCalibrationList();
    } catch (err) {
      if (acousticStatus) {
        acousticStatus.textContent = '❌ ' + err.message;
        acousticStatus.className = 'acoustic-status acoustic-error';
      }
      inputMode = ''; // acoustic failed — no input armed until the user picks again
    }
  } else if (mode === 'pad') {
    if (acousticSensGroup) acousticSensGroup.style.display = '';
    if (acousticStatus) {
      acousticStatus.style.display = '';
      acousticStatus.textContent = '🎙️ Requesting microphone access…';
      acousticStatus.className = 'acoustic-status acoustic-pending';
    }
    try {
      await startPadDetection(() => {
        if (state === STATES.PLAYING) processPadHit(performance.now());
      });
      if (acousticStatus) {
        acousticStatus.textContent = '🎙️ Microphone active — hit your pad to play. No calibration needed.';
        acousticStatus.className = 'acoustic-status acoustic-active';
      }
    } catch (err) {
      if (acousticStatus) {
        acousticStatus.textContent = '❌ ' + err.message;
        acousticStatus.className = 'acoustic-status acoustic-error';
      }
      inputMode = ''; // mic failed — no input armed
    }
  } else {
    if (acousticStatus) acousticStatus.style.display = 'none';
    if (acousticSensGroup) acousticSensGroup.style.display = 'none';
  }

  // Lock/unlock the song library based on the final armed input.
  syncInputGate();
}

document.querySelectorAll('.input-opt').forEach(btn => {
  btn.addEventListener('click', () => {
    // On touch devices, go fullscreen on the first input tap so the browser
    // address bar is hidden for the whole menu → calibration → game flow.
    if (window.matchMedia('(hover: none)').matches) enterFullscreen();
    document.querySelectorAll('.input-opt').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    switchInputMode(btn.dataset.input);
  });
});

// Acoustic sensitivity slider
document.getElementById('acoustic-sensitivity')?.addEventListener('input', function() {
  document.getElementById('acoustic-sensitivity-label').textContent = this.value;
  setAcousticSensitivity(parseInt(this.value, 10));
});

document.getElementById('btn-pause')?.addEventListener('click', pauseGame);
document.getElementById('btn-resume')?.addEventListener('click', resumeGame);
document.getElementById('btn-quit')?.addEventListener('click', () => {
  exitFullscreenApi();
  unlockOrientation();
  state = STATES.IDLE;
  cancelAnimationFrame(rafId);
  stopAudio();
  showScreen('menu');
});
document.getElementById('btn-to-menu')?.addEventListener('click', () => {
  exitFullscreenApi();
  unlockOrientation();
  state = STATES.IDLE;
  showScreen('menu');
});
document.getElementById('btn-fullscreen-exit')?.addEventListener('click', () => {
  exitFullscreenApi();
  unlockOrientation();
  state = STATES.IDLE;
  cancelAnimationFrame(rafId);
  stopAudio();
  showScreen('menu');
});
document.getElementById('btn-retry')?.addEventListener('click', () => {
  if (currentSong) selectSong(currentSong);
});

const settingsBtn = document.getElementById('btn-settings');
const settingsScreen = document.getElementById('screen-settings');
settingsBtn?.addEventListener('click', () => {
  settingsScreen.classList.toggle('active');
});
document.getElementById('close-settings')?.addEventListener('click', () => {
  settingsScreen.classList.remove('active');
});

const speedSlider = document.getElementById('speed-slider');
const speedLabel  = document.getElementById('speed-label');
speedSlider?.addEventListener('input', () => {
  scrollTimeWindow = parseInt(speedSlider.value, 10);
  speedLabel.textContent = scrollTimeWindow + ' ms';
});

document.getElementById('hit-sound-toggle')?.addEventListener('change', e => {
  hitSoundEnabled = e.target.checked;
});

document.getElementById('midi-device-select')?.addEventListener('change', e => {
  selectInput(parseInt(e.target.value, 10));
});

document.getElementById('midi-channel')?.addEventListener('change', e => {
  setChannelFilter(parseInt(e.target.value, 10));
});

// ── Boot ──────────────────────────────────────────────────
showScreen('menu');
syncInputGate();     // song library starts locked until an input is chosen
loadSongLibrary();
