import { initMidi, selectInput, getInputList, onDeviceChange } from './midi.js';
import { mapMidiEvent, setChannelFilter, setNoteMap, getDefaultMap, getLaneNames, getLaneColors } from './input.js?v=20260720a';
import { loadSong, updateChart, getActiveNotes, findClosestNote, expireMissed, reset as resetChart } from './chart.js?v=20260702';
import { initRenderer, resize as resizeRenderer, drawFrame, addFlash, addJudgment, drawCountdown, setSongLanes, setHighlightLane, getLaneBounds } from './renderer.js?v=20260724';
import { createScoreState, judgeHit, judgeMiss, calcGrade, calcAccuracy, TIMING, setPrecisionMode } from './scoring.js?v=20260724c';
import { playCountdownBeep, resumeContext, playMetronomeClick, scheduleMetronomeClick, audioTimeFor } from './audio.js?v=20260810';
import { loadAudioUrl, unloadAudio, playAudio, pauseAudio, resumeAudio, stopAudio, hasAudio, getAudioDurationMs } from './audio-player.js?v=20260762';
import * as YouTube from './youtube.js?v=20260756';
import { startAcousticDetection, stopAcousticDetection, setAcousticSensitivity, isAcousticActive,
         startCalibration, cancelCalibration, isLaneCalibrated, clearLane, startPadDetection } from './acoustic.js?v=20260709';
import { startRecording, stopRecording, isRecording, isRecordingSupported } from './recorder.js?v=20260764';
import { song as demoSong } from './songs/demo.js?v=20260705';
import { song as rockSong } from './songs/rock-basics.js?v=20260705';
import { song as placementSong } from './songs/placement.js?v=20260715';
import { song as padSong } from './songs/pad-basics.js?v=20260717';
import { song as padPlacementSong } from './songs/placement-pad.js?v=20260711';
import { segments as tutorialSegments, basicSetNotes as tutorialBasicSetNotes } from './songs/tutorial.js?v=20260770';

demoSong.id       = 'demo';
demoSong.artist   = 'MS Drums';
demoSong.builtin  = true;
demoSong.category = 'kit';           // full-kit songs (e-drums / acoustic)

rockSong.id       = 'rock-basics';
rockSong.artist   = 'MS Drums';
rockSong.builtin  = true;
rockSong.category = 'kit';

placementSong.id            = 'placement';
placementSong.artist        = 'MS Drums';
placementSong.builtin       = true;
placementSong.category      = 'kit';
placementSong.placementTest = true;   // the standardized exercise used by the placement test

padPlacementSong.id            = 'placement-pad';
padPlacementSong.builtin       = true;
padPlacementSong.placementTest = true;   // pad-input variant (single column); flagged padPlacement

padSong.id       = 'pad-basics';
padSong.artist   = 'MS Drums';
padSong.builtin  = true;
padSong.category = 'pad';             // practice-pad exercises (own category)

// ── State ─────────────────────────────────────────────────
const STATES = { IDLE: 0, COUNTDOWN: 2, PLAYING: 3, PAUSED: 4, RESULTS: 5 };
let state            = STATES.IDLE;
let songStartTime    = 0;
let scrollTimeWindow = 2000;
let scoreState       = null;
let myBests          = {};   // { song_id: {score, grade, accuracy} } — member's per-song best
let countdownTimer   = null;
let rafId            = null;
let currentSong      = demoSong;
let songDuration     = 0;   // effective length (ms) for this playthrough
let pausedPosition   = 0;
let nextMetroBeat    = 0; // next metronome beat index due to be scheduled (incl. the lead-in)
let audioStarted     = false;     // audio starts when the lead-in reaches song pos 0

// YouTube casual mode: the embedded video is the master clock (the game follows
// it). No local/hosted buffer is used; sync is looser and ads/buffering freeze the
// clock (which harmlessly freezes the falling notes until playback resumes).
let youtubeMode  = false;
let ytLastTimeMs = 0;   // last content time (ms) sampled from the player
let ytLastPerf   = 0;   // performance.now() at that sample (for interpolation)

// Audio-sync calibration (ms), from the Settings slider, persisted per device.
// Added to songPos: + = notes arrive earlier vs the music, − = later. Only has a
// real effect on the YouTube clock (for hosted/local audio it self-cancels, since
// the audio is anchored to the same game clock).
// Hit-timing calibration (ms), per device. Compensates the player's input/output
// latency: it's subtracted from each hit's timestamp, so + = "my hits register
// late, pull them earlier". Only the JUDGMENT shifts — notes/audio stay aligned.
const HIT_OFFSET_KEY = 'drumkit_audio_offset';   // key kept so existing calibration carries over
// Clamp to the slider's range — a stale saved value beyond the (Normal-mode)
// hit window would silently make every hit unregisterable.
let hitOffsetMs = Math.max(-150, Math.min(150, parseInt(localStorage.getItem(HIT_OFFSET_KEY) || '0', 10) || 0));

// Precision Mode: opt-in tighter timing windows. Session-only by design (not
// persisted) so nobody gets quietly stuck on hard mode without realizing why —
// same reasoning as practice speed below. Always starts off.
let precisionMode = false;

// Practice speed (1 = normal, 0.75 / 0.5 = slowed). Session-only by design (not
// persisted) so nobody forgets it's on. Below 1: scores aren't saved and plan
// sessions can't be passed — slowing down is for practice, not progression.
let playRate = 1;
// Scrubber steps (index 0/1/2 → rate). Native range input snaps to these for free.
const PLAY_RATES = [0.5, 0.75, 1];

let planError     = '';   // plan-session load error (shown instead of a blank list)

// Every song gets a 2-bar lead-in: the clock starts this far "in the past" so notes
// scroll in from the top instead of appearing on the hit line, and the metronome
// pulses so the player can feel the tempo before the first block.
const LEAD_IN_BARS = 2;
let allSongs         = [];
let inputMode        = ''; // 'midi' | 'acoustic' — chosen from the menu before playing

// Placement-test config comes from a data element in game.php (the site CSP
// blocks inline scripts, so we can't set window globals there).
const _testCfg      = document.getElementById('drum-test-config');
const _gameCfg      = document.getElementById('game-config');
const _planCfg      = document.getElementById('plan-config');
const _tutorialCfg  = document.getElementById('tutorial-config');
const _kbTestCfg    = document.getElementById('keyboard-test-config');
const KEYBOARD_TEST_MODE = !!_kbTestCfg;   // one dedicated test account only — see game.php
const DRUM_TEST_MODE = !!_testCfg;
const TEST_PAD       = !!(_testCfg && _testCfg.dataset.pad === '1');   // pad placement test
// Practice-plan session mode: play one AI-generated exercise, gated by a pass score.
const PLAN_MODE      = !!_planCfg;
const PLAN_SESSION   = _planCfg ? (parseInt(_planCfg.dataset.session, 10) || 0) : 0;
// First-time tutorial: a short walkthrough of kick/snare/hi-hat/crash/tom shown
// before a student's first placement test. Full-kit only — a single practice
// pad can't distinguish between five different drums, so the pad path skips
// straight to its own (already single-lane) placement exercise as before.
const TUTORIAL_MODE  = !!_tutorialCfg;
let tutorialIndex   = 0;
let tutorialStarted = false;
// True when the current context needs the Practice-Pad input (pad placement test
// or a pad plan session), so updatePadInputOption() surfaces it there.
let padContext       = TEST_PAD;
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

// On touch devices, the very first tap anywhere on the page (menu included)
// goes fullscreen + landscape, so the whole game.php experience is immersive
// from the start rather than only once a song starts playing.
if (window.matchMedia('(hover: none)').matches) {
  document.addEventListener('click', enterFullscreen, { once: true });
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
  // The canvas measures its own box (70% of the gameplay body), which is zero
  // while #screen-game is display:none — re-measure now that it's visible.
  if (id === 'game') resizeRenderer();
}

// ── Song library ──────────────────────────────────────────
async function loadSongLibrary() {
  if (PLAN_MODE) { await loadPlanSession(); return; }
  if (TUTORIAL_MODE) return; // segments are driven directly by syncInputGate(), no library needed

  let custom = [];
  try {
    const res = await fetch('/api/songs.php');
    if (res.ok) custom = await res.json();
  } catch (e) { /* network error, just show demo */ }

  allSongs = [{ ...demoSong, builtin: true }, { ...rockSong, builtin: true }, { ...placementSong, builtin: true }, { ...padPlacementSong, builtin: true }, { ...padSong, builtin: true }, ...custom];
  await loadProgress();
  updatePadInputOption();
}

// The Practice Pad input only appears when a lesson asks for it — i.e. the admin
// has published Practice-Pad songs (pad category; the built-in demo doesn't count)
// — and never in the single-exercise placement / plan modes, which are full-kit.
function updatePadInputOption() {
  const padBtn  = document.querySelector('.input-opt[data-input="pad"]');
  const midiBtn = document.querySelector('.input-opt[data-input="midi"]');
  const acouBtn = document.querySelector('.input-opt[data-input="acoustic"]');
  if (!padBtn) return;

  // A pad placement test / pad plan session (padContext) is PAD-ONLY — hide the
  // kit inputs so the exercise is played (and recorded) on the pad. Otherwise pad
  // shows only when the admin has published pad-category songs.
  const padOnly = padContext;
  const hasPad  = padOnly ||
    (!DRUM_TEST_MODE && !PLAN_MODE && allSongs.some(s => s.category === 'pad' && !s.builtin));

  padBtn.style.display = hasPad ? '' : 'none';
  if (midiBtn) midiBtn.style.display = padOnly ? 'none' : '';
  if (acouBtn) acouBtn.style.display = padOnly ? 'none' : '';

  // Clear a now-unavailable selection.
  if ((!hasPad && inputMode === 'pad') || (padOnly && inputMode && inputMode !== 'pad')) {
    inputMode = '';
    syncInputGate();
  }
}

// Plan mode: fetch the one AI-generated session exercise and show it as the only
// "song". The server generates it on first call and enforces the hard lock.
async function loadPlanSession() {
  try {
    // Bound the wait so the screen can never hang: if generation is slow, the
    // server falls back to a built-in groove within ~15s; abort at 20s just in case.
    const ctrl = new AbortController();
    const to = setTimeout(() => ctrl.abort(), 20000);
    const res  = await fetch('/api/plan-session.php?session=' + PLAN_SESSION, { signal: ctrl.signal });
    clearTimeout(to);
    const data = await res.json();
    if (!res.ok) { planError = data.error || 'Could not load this session.'; allSongs = []; renderSongList(); return; }
    planError = '';
    padContext = !!data.pad;         // pad plan → offer the Practice-Pad input
    allSongs = [{
      id: 'plan-s' + PLAN_SESSION,
      title: data.title || ('Session ' + PLAN_SESSION),
      artist: 'Practice Plan',
      bpm: data.bpm || 90,
      duration: 0,
      notes: data.notes || [],
      category: data.pad ? 'pad' : 'kit',
      metronome: true,               // click track through the whole exercise
      planSession: PLAN_SESSION,
      passAccuracy: data.passAccuracy || 80,
      builtin: true,
    }];
    updatePadInputOption();
    renderSongList();
  } catch (e) {
    planError = (e && e.name === 'AbortError')
      ? 'The exercise took too long to generate. Please tap “Back to My Plan” and try again.'
      : 'Network error loading the session.';
    allSongs = [];
    renderSongList();
  }
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

// Active tag filters, as "group:name" keys. Faceted: AND across groups, OR within.
let tagFilters = new Set();

function applyTagFilter(songs) {
  if (!tagFilters.size) return songs;
  const byGroup = {};
  for (const key of tagFilters) {
    const i = key.indexOf(':'), g = key.slice(0, i), n = key.slice(i + 1);
    if (!byGroup[g]) byGroup[g] = [];
    byGroup[g].push(n);
  }
  return songs.filter(s => {
    const tags = s.tags || [];
    for (const g in byGroup) {
      // A song matches a chip by the tag itself OR by its parent tag — so
      // filtering by a parent category includes all its sub-category songs.
      if (!tags.some(t => t.group === g &&
          (byGroup[g].includes(t.name) || (t.parent && byGroup[g].includes(t.parent))))) return false;
    }
    return true;
  });
}

function renderTagFilter(sourceSongs) {
  const bar = document.getElementById('song-tag-filter');
  if (!bar) return;
  const groups = { genre: [], skill: [], folder: [] };
  const seen = new Set();
  for (const s of sourceSongs) for (const t of (s.tags || [])) {
    const key = t.group + ':' + t.name;
    if (!seen.has(key)) { seen.add(key); (groups[t.group] || groups.folder).push(t); }
    // Make sure a nested tag's PARENT also gets a chip, even if no song carries
    // the parent tag directly — clicking it matches all its children.
    if (t.parent) {
      const pkey = t.group + ':' + t.parent;
      if (!seen.has(pkey)) { seen.add(pkey); (groups[t.group] || groups.folder).push({ name: t.parent, group: t.group, parent: null }); }
    }
  }
  const order = [['genre', 'Genre'], ['skill', 'Skill'], ['folder', 'Folders']];
  // Children sort directly under their parent.
  const sortKey = t => (t.parent ? t.parent + '~1' + t.name : t.name + '~0');
  let html = '';
  for (const [gk, glabel] of order) {
    const arr = (groups[gk] || []).sort((a, b) => sortKey(a).localeCompare(sortKey(b)));
    if (!arr.length) continue;
    html += `<div class="stf-group"><span class="stf-label">${glabel}</span>`;
    for (const t of arr) {
      const key = gk + ':' + t.name;
      const label = t.parent ? `${escHtml(t.parent)} ▸ ${escHtml(t.name)}` : escHtml(t.name);
      html += `<button class="stf-chip${tagFilters.has(key) ? ' on' : ''}" data-key="${encodeURIComponent(key)}">${label}</button>`;
    }
    html += `</div>`;
  }
  if (tagFilters.size) html += `<button class="stf-clear" data-clear="1">✕ Clear</button>`;
  bar.innerHTML = html;
  bar.style.display = html ? '' : 'none';
  bar.querySelectorAll('.stf-chip').forEach(btn => btn.addEventListener('click', () => {
    const k = decodeURIComponent(btn.dataset.key);
    if (tagFilters.has(k)) tagFilters.delete(k); else tagFilters.add(k);
    renderSongList();
  }));
  bar.querySelector('.stf-clear')?.addEventListener('click', () => { tagFilters.clear(); renderSongList(); });
}

function renderSongList() {
  const list = document.getElementById('song-list');
  list.innerHTML = '';

  // Songs are scoped to the chosen input: the placement exercise in test mode,
  // pad-category songs for the practice pad, full-kit songs for e-drums/acoustic.
  // Plan session: show the one exercise, or a clear loading/error state (never a
  // blank screen — an input-select re-render must not wipe it away).
  if (PLAN_MODE) {
    const heading = document.querySelector('#song-library h2');
    if (heading) heading.textContent = 'Practice Session ' + PLAN_SESSION + ' — pass to unlock the next';
    if (!allSongs.length) {
      list.innerHTML =
        '<div class="loading">' + escHtml(planError || 'Generating your session exercise… (up to ~15s the first time)') + '</div>' +
        '<div style="text-align:center;margin-top:1rem"><a class="btn btn-ghost" href="/my-plan.php">← Back to My Plan</a></div>';
      return;
    }
  }

  let songs;
  if (PLAN_MODE) {
    songs = allSongs;   // the single session exercise
  } else if (DRUM_TEST_MODE) {
    songs = allSongs.filter(s => s.placementTest && (TEST_PAD ? s.padPlacement : !s.padPlacement));
  } else if (inputMode === 'pad') {
    songs = allSongs.filter(s => !s.placementTest && s.category === 'pad');
  } else {
    songs = allSongs.filter(s => !s.placementTest && s.category !== 'pad');
  }

  // Tag filter (admin-managed categories): show chips for the tags present in
  // this input's songs, then narrow the list by any that are active.
  renderTagFilter(songs);
  songs = applyTagFilter(songs);

  const heading = document.querySelector('#song-library h2');
  if (heading) {
    heading.textContent = PLAN_MODE ? ('Practice Session ' + PLAN_SESSION + ' — pass to unlock the next')
      : DRUM_TEST_MODE ? 'Placement Test — play this exercise'
      : (inputMode === 'pad' ? 'Practice Pad Songs' : 'Song Library');
  }

  songs.forEach(song => {
    const best = myBests[String(song.id)];
    const bestHtml = best
      ? `<span class="song-best"><i class="ti ti-trophy" aria-hidden="true"></i> Best: ${Number(best.score).toLocaleString()}${best.grade ? ' · ' + escHtml(best.grade) : ''}</span>`
      : '';
    const item = document.createElement('div');
    item.className = 'song-item';
    const audioState = (song.youtubeUrl && !song.audioUrl) ? '<i class="ti ti-brand-youtube" aria-hidden="true"></i> plays along to YouTube (casual)' : '';
    item.innerHTML = `
      <div class="song-item-info">
        <strong>${escHtml(song.title)}${song.exclusive ? ' <span class="song-excl"><i class="ti tif ti-star" aria-hidden="true"></i> Exclusive</span>' : ''}</strong>
        <span>${escHtml(song.artist || 'Unknown')}</span>
        <span class="song-meta">${song.bpm} BPM · ${song.notes?.length || 0} notes</span>
        <span class="song-audio-state">${audioState}</span>
        ${bestHtml}
      </div>
      <div class="song-item-actions">
        <button class="btn btn-primary btn-sm song-play-btn">Play</button>
      </div>
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

async function selectSong(song) {
  // Playing needs an input (e-drums, mic, or practice pad). Require one first.
  if (inputMode !== 'midi' && inputMode !== 'acoustic' && inputMode !== 'pad') {
    const msg = document.getElementById('input-required-msg');
    if (msg) msg.style.display = '';
    document.getElementById('menu-input-select')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  enterFullscreen(); // must fire synchronously in the click handler — Safari drops the gesture after any await or timer
  lockLandscape();
  resumeContext(); // AudioContext can start suspended — only ever explicitly resumed on a keypress elsewhere, so touch/MIDI-only students could reach here with it still asleep, delaying the first scheduled clicks
  currentSong = song;
  if (TUTORIAL_MODE) {
    // Keep the whole basic set on screen for all five segments — only the
    // highlighted lane changes as the tutorial advances, so students always
    // see where the current piece sits relative to the rest of the kit.
    setSongLanes(tutorialBasicSetNotes);
    setHighlightLane(tutorialSegments[tutorialIndex].lane);
    showTutorialBanner();
  } else {
    setSongLanes(song.notes); // only show the drums/cymbals this song's chart actually uses
    setHighlightLane(null);
  }

  // Show loading
  document.getElementById('countdown-title').textContent  = song.title;
  document.getElementById('countdown-artist').textContent = song.artist || '';

  // Casual YouTube mode: only when there's no hosted audio, so tight-sync hosted
  // audio always wins. Its own flow loads/plays the video first.
  if (!song.audioUrl && song.youtubeUrl) {
    unloadAudio();
    startYouTubeSong(song);
    return;
  }
  youtubeMode = false;

  // Hosted audio if the song has it; otherwise play silent (chart only).
  if (song.audioUrl) {
    try { await loadAudioUrl(song.audioUrl); } catch (e) { console.warn('Audio load failed:', e); unloadAudio(); }
  } else {
    unloadAudio();   // clear any previously-loaded track so it can't play over this song
  }

  startCountdown();
}

// ── Countdown ─────────────────────────────────────────────
function startCountdown() {
  // Show the play area (the highway) and draw the count-in OVER it — no separate
  // black screen. A continuous rAF loop keeps the highway painted every frame, so
  // the fullscreen resize can't leave a black gap between beats.
  showScreen('game');
  document.getElementById('hud-title').textContent = currentSong.title + (playRate < 1 ? ' — ' + Math.round(playRate * 100) + '% practice' : '');
  state = STATES.COUNTDOWN;

  // Count in at the effective (practice) tempo so beat 1 lands with the music.
  const beatMs = (60000 / (currentSong.bpm || 120)) / playRate;
  const startT = performance.now();
  let lastClickBeat = -1;

  cancelAnimationFrame(rafId);
  const loop = (now) => {
    if (state !== STATES.COUNTDOWN) return;       // quit/aborted
    const beat = Math.floor((now - startT) / beatMs); // 0..3 through the bar
    if (beat >= 4) { startGame(); return; }        // count-in done → notes fall

    drawCountdown(4 - beat);                        // count DOWN: 4, 3, 2, 1
    if (TUTORIAL_MODE) positionTutorialBanner(tutorialSegments[tutorialIndex].lane);
    if (beat > lastClickBeat) {                     // one metronome click per beat
      lastClickBeat = beat;
      playMetronomeClick(beat === 0);               // accent the first beat
    }
    rafId = requestAnimationFrame(loop);
  };
  rafId = requestAnimationFrame(loop);
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

  if (youtubeMode) { const ytMs = YouTube.getDurationMs(); if (ytMs > 0) return ytMs; }

  const notes = currentSong.notes || [];
  return notes.reduce((max, n) => Math.max(max, n.time), 0);
}

function startGame() {
  showScreen('game');
  document.getElementById('hud-title').textContent = currentSong.title + (playRate < 1 ? ' — ' + Math.round(playRate * 100) + '% practice' : '');

  loadSong(currentSong);
  scoreState    = createScoreState(currentSong.notes.length);
  songDuration  = resolveSongDuration();

  // Begin ~2 bars BEFORE the first note so blocks scroll in (not snap onto the
  // line). For songs with audio, start the track at that same point so the music
  // comes in with the blocks — no intro playing over an empty highway.
  const beatMs   = 60000 / (currentSong.bpm || 120);
  const leadMs   = beatMs * 4 * LEAD_IN_BARS;
  const firstNote = currentSong.notes.reduce((m, n) => Math.min(m, n.time), Infinity);
  const startPos  = (Number.isFinite(firstNote) ? firstNote : 0) - leadMs;
  // Song position begins at startPos. The clock advances at playRate (song-ms per
  // real ms), so the anchor is scaled: songPos = (now - songStartTime) * playRate.
  songStartTime = performance.now() - startPos / playRate;
  state         = STATES.PLAYING;
  nextMetroBeat = Math.floor(startPos / beatMs); // first lead-in beat
  audioStarted  = false;
  revealTutorialBanner();

  // If the lead-in point is already inside the track, start the audio there now;
  // otherwise gameLoop starts it when the clock reaches position 0.
  if (hasAudio() && startPos >= 0) { playAudio(startPos, playRate); audioStarted = true; }

  cancelAnimationFrame(rafId);
  rafId = requestAnimationFrame(gameLoop);
}

// ── YouTube casual mode ───────────────────────────────────
function ytLoading(show) {
  const el = document.getElementById('yt-loading');
  if (el) el.hidden = !show;
}

async function startYouTubeSong(song) {
  youtubeMode = true;
  document.getElementById('yt-stage')?.classList.add('active');
  const id = YouTube.parseYouTubeId(song.youtubeUrl);
  if (!id) { youtubeMode = false; document.getElementById('yt-stage')?.classList.remove('active'); startCountdown(); return; }   // bad link → silent run

  showScreen('game');
  document.getElementById('hud-title').textContent = song.title;
  state = STATES.COUNTDOWN;            // hold the loop idle while the video loads
  cancelAnimationFrame(rafId);
  ytLoading(true);
  try {
    await YouTube.play(id);            // resolves once real content plays (past any ad)
  } catch (e) {
    console.warn('YouTube start failed:', e);
    ytLoading(false);
    youtubeMode = false;
    document.getElementById('yt-stage')?.classList.remove('active');
    startGame();                       // fall back to a silent chart run
    return;
  }
  ytLoading(false);
  startGameYouTube();
}

// Like startGame, but the video is the clock: no lead-in metronome, no buffer.
function startGameYouTube() {
  showScreen('game');
  document.getElementById('hud-title').textContent = currentSong.title + (playRate < 1 ? ' — ' + Math.round(playRate * 100) + '% practice' : '');
  loadSong(currentSong);
  scoreState   = createScoreState(currentSong.notes.length);
  songDuration = resolveSongDuration();
  state        = STATES.PLAYING;
  audioStarted = true;                 // the video is already playing
  YouTube.setRate(playRate);           // practice speed (video slows natively)
  ytLastTimeMs = YouTube.getTimeMs();
  ytLastPerf   = performance.now();
  cancelAnimationFrame(rafId);
  rafId = requestAnimationFrame(gameLoop);
}

// Song position (ms) from the YouTube clock, interpolated between the player's
// coarse samples with the perf clock. Freezes while the video isn't actively
// playing (ad/buffer/pause) — which harmlessly freezes the notes until it resumes.
function ytSongPos() {
  const now = performance.now();
  const t   = YouTube.getTimeMs();
  if (t > ytLastTimeMs + 20 || t < ytLastTimeMs - 250) { ytLastTimeMs = t; ytLastPerf = now; }
  if (!YouTube.isPlaying()) { ytLastPerf = now; return ytLastTimeMs; }
  // The video's content clock advances at playRate × real time.
  return ytLastTimeMs + (now - ytLastPerf) * playRate;
}

function gameLoop(now) {
  if (state !== STATES.PLAYING) return;

  const songPos = youtubeMode ? ytSongPos() : (now - songStartTime) * playRate;

  // A YouTube run ends when the video reports it has ended.
  if (youtubeMode && YouTube.isEnded()) { endGame(); return; }

  // Kick off the audio once the lead-in reaches song position 0.
  if (!audioStarted && songPos >= 0) {
    if (hasAudio()) playAudio(0, playRate);
    audioStarted = true;
  }

  updateChart(songPos, scrollTimeWindow);

  // Metronome: during the lead-in (song pos < 0) for every song, and through the
  // whole song for metronome-flagged exercises (e.g. the placement test) or any
  // song with no backing track — without music playing, the click is the only
  // thing keeping time. Skipped in YouTube mode — the video is already the
  // music (no silent lead-in).
  // Scheduled a short window ahead on the AudioContext's own clock — rather
  // than fired the instant this rAF frame notices the beat crossed — so the
  // click lands sample-accurate on the beat instead of drifting up to a frame
  // (~16ms+) late relative to the notes, which is audible as being "off".
  if (!youtubeMode) {
    const beatMs        = 60000 / (currentSong.bpm || 120);
    const METRO_LOOKAHEAD_MS = 150;
    const clickThroughSong = currentSong.metronome || !hasAudio();
    while (true) {
      const beatSongPos = nextMetroBeat * beatMs;
      if (beatSongPos >= songDuration) break; // song's over — nothing left to click
      const beatRealTime = songStartTime + beatSongPos / playRate;
      if (beatRealTime > now + METRO_LOOKAHEAD_MS) break; // not due yet
      // Click through the lead-in + the downbeat (beat 0) for every song; keep
      // clicking through the whole song for metronome-flagged exercises and
      // any song with no backing track.
      if (nextMetroBeat <= 0 || clickThroughSong) {
        scheduleMetronomeClick(audioTimeFor(beatRealTime), nextMetroBeat % 4 === 0);
      }
      nextMetroBeat++;
    }
  }

  const missedLanes = expireMissed(songPos, TIMING.MISS_EXPIRE);
  missedLanes.forEach(lane => {
    judgeMiss(scoreState, lane);
    addJudgment('MISS', lane);
  });

  // MIDI hits are now processed immediately in midiHandler() as they arrive,
  // not buffered here — this used to be where they were drained once per
  // frame, adding up to ~16ms of delay between the physical hit and its
  // sound/judgment.

  drawFrame(getActiveNotes(), songPos, scrollTimeWindow, scoreState);

  // Re-anchor every frame rather than once at selectSong() time — the canvas
  // is still 0×0 while #screen-game hasn't been made active yet, so a single
  // upfront position call reads garbage bounds and never gets corrected.
  if (TUTORIAL_MODE) positionTutorialBanner(tutorialSegments[tutorialIndex].lane);

  document.getElementById('hud-score').textContent = scoreState.score;
  document.getElementById('hud-combo').textContent = scoreState.combo;

  if (songPos >= songDuration + 2000) {
    endGame();
    return;
  }

  rafId = requestAnimationFrame(gameLoop);
}

function processHit(lane, now) {
  // Judge against the same clock the notes use, minus the player's input latency.
  const songPos = (youtubeMode ? ytSongPos() : (now - songStartTime) * playRate) - hitOffsetMs * playRate;
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
  }
}

// Single-pad input: one hit is judged against the nearest unhit note in ANY lane
// (timing practice — the student plays the rhythm on one pad).
function processPadHit(now) {
  const songPos = (youtubeMode ? ytSongPos() : (now - songStartTime) * playRate) - hitOffsetMs * playRate;
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
  }
}

function endGame() {
  state = STATES.RESULTS;
  cancelAnimationFrame(rafId);
  stopAudio();
  if (youtubeMode) { YouTube.stop(); youtubeMode = false; document.getElementById('yt-stage')?.classList.remove('active'); }

  showScreen('results');
  // Clear any pass/fail or practice banner left over from a previous run.
  const staleNote = document.getElementById('plan-result');
  if (staleNote) staleNote.innerHTML = '';

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

  // Tutorial segments are pure familiarization — never saved, never scored
  // against anything. Placement-test mode: save this play and fetch AI
  // coaching for it. Normal play: record the score so it shows up in the
  // member's progress. Practice speed (<100%): results are shown but
  // nothing is saved, and plan sessions can't be passed — full speed only.
  if (TUTORIAL_MODE) advanceTutorial();
  else if (playRate < 1) showPracticeNote();
  else if (DRUM_TEST_MODE) submitPlacementTest(acc, grade);
  else if (PLAN_MODE) submitPlanSession();
  else savePlay(acc, grade);
}

// ── Tutorial (first-time kick/snare/hi-hat/crash/tom walkthrough) ─────────
let tutorialBannerPositioned = false; // reset per segment — see positionTutorialBanner()

function showTutorialBanner() {
  const el = document.getElementById('tutorial-banner');
  if (!el) return;
  const seg = tutorialSegments[tutorialIndex];
  el.innerHTML = '<strong>' + escHtml(seg.name) + '</strong><br>' + escHtml(seg.instruction);
  el.hidden = true; // stays hidden through the countdown — revealed once play actually starts
  el.style.setProperty('--lane-color', getLaneColors()[seg.lane]);
  tutorialBannerPositioned = false;
  positionTutorialBanner(seg.lane);
}

function revealTutorialBanner() {
  if (!TUTORIAL_MODE) return;
  const el = document.getElementById('tutorial-banner');
  if (el) el.hidden = false;
}

// Anchors the instruction cloud beside the highlighted lane (right by
// default, left if there isn't room), just above the hit area — pointing at
// the drum being taught instead of covering it or floating top-center.
function positionTutorialBanner(lane) {
  if (tutorialBannerPositioned) return; // already placed for this segment — skip the layout reads
  const el     = document.getElementById('tutorial-banner');
  const canvas = document.getElementById('game-canvas');
  const screen = document.getElementById('screen-game');
  if (!el || !canvas || !screen) return;

  const bounds     = getLaneBounds(lane);
  const canvasRect = canvas.getBoundingClientRect();
  const screenRect = screen.getBoundingClientRect();
  if (canvasRect.width === 0) return; // canvas not visible yet — try again next call

  const offX = canvasRect.left - screenRect.left;
  const offY = canvasRect.top  - screenRect.top;

  const GAP_H = 14;
  const width  = el.offsetWidth  || 300;
  const height = el.offsetHeight || 90;
  const right = offX + bounds.right + GAP_H;
  const left  = offX + bounds.left  - GAP_H - width;
  const fitsRight = right + width <= offX + canvasRect.width;
  const x = fitsRight ? right : Math.max(offX, left);

  // Clear the hit-zone target's own radius (varies per lane — kick is much
  // bigger than a cymbal) plus a gap, so the cloud sits above it, never over it.
  const GAP_V    = 18;
  const targetTop = offY + bounds.y - bounds.r;
  const y = targetTop - GAP_V - height;

  el.style.left      = x + 'px';
  el.style.top       = y + 'px';
  el.style.transform = 'none';
  tutorialBannerPositioned = true;
}

function advanceTutorial() {
  const screen = document.getElementById('screen-results') || document.body;
  let el = document.getElementById('plan-result');
  if (!el) { el = document.createElement('div'); el.id = 'plan-result'; el.className = 'plan-result'; screen.insertBefore(el, screen.firstChild); }

  const seg    = tutorialSegments[tutorialIndex];
  const isLast = tutorialIndex >= tutorialSegments.length - 1;
  el.innerHTML =
    '<div class="pr-msg pass"><i class="ti tif ti-circle-check" aria-hidden="true"></i> ' + escHtml(seg.name) + ' — nice work!</div>' +
    '<div class="pr-actions">' +
      (isLast
        ? '<button id="tutorial-finish" class="btn btn-primary">Start Placement Test →</button>'
        : '<button id="tutorial-next" class="btn btn-primary">Next: ' + escHtml(tutorialSegments[tutorialIndex + 1].name) + ' →</button>') +
    '</div>';

  document.getElementById('tutorial-next')?.addEventListener('click', () => {
    tutorialIndex++;
    selectSong(tutorialSegments[tutorialIndex].song);
  });
  document.getElementById('tutorial-finish')?.addEventListener('click', finishTutorial);
}

async function finishTutorial() {
  try {
    await fetch('/api/tutorial-complete.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN } });
  } catch (e) { /* non-fatal — worst case they see the tutorial again next time */ }
  location.href = '/game.php?test=1';
}

// Banner shown on the results screen after a slowed practice run.
function showPracticeNote() {
  const screen = document.getElementById('screen-results') || document.body;
  let el = document.getElementById('plan-result');
  if (!el) { el = document.createElement('div'); el.id = 'plan-result'; el.className = 'plan-result'; screen.insertBefore(el, screen.firstChild); }
  el.innerHTML = '<div class="pr-msg fail"><i class="ti ti-adjustments" aria-hidden="true"></i> Practice run at '
    + Math.round(playRate * 100) + '% speed — score not saved'
    + (PLAN_MODE ? '. Passing a session needs full speed (100%).' : '.') + '</div>';
}

// Plan session: verify + record server-side, then show pass/fail vs the threshold.
async function submitPlanSession() {
  const c = scoreState.counts;
  const payload = { session_no: PLAN_SESSION, perfect: c.PERFECT, good: c.GOOD, miss: c.MISS, total: scoreState.totalNotes };
  let data = null;
  try {
    const res = await fetch('/api/plan-complete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify(payload),
    });
    data = await res.json();
  } catch (e) { /* network — handled below */ }
  renderPlanResult(data);
}

function renderPlanResult(data) {
  const screen = document.getElementById('screen-results') || document.body;
  let el = document.getElementById('plan-result');
  if (!el) { el = document.createElement('div'); el.id = 'plan-result'; el.className = 'plan-result'; screen.insertBefore(el, screen.firstChild); }
  if (!data || data.error) {
    el.innerHTML = '<div class="pr-msg fail">' + escHtml((data && data.error) || 'Could not save your result — check your connection.') + '</div>';
    return;
  }
  const passed = !!data.passed;
  el.innerHTML =
    '<div class="pr-msg ' + (passed ? 'pass' : 'fail') + '">' +
      (passed ? '<i class="ti tif ti-circle-check" aria-hidden="true"></i> Passed! ' : '<i class="ti ti-refresh" aria-hidden="true"></i> Not yet — ') + data.accuracy + '% (need ' + data.passAccuracy + '%)' +
    '</div>' +
    '<div class="pr-actions">' +
      '<a class="btn btn-ghost" href="/my-plan.php">Back to My Plan</a>' +
      (passed && data.nextUnlocked ? '<a class="btn btn-primary" href="/game.php?plan=' + (PLAN_SESSION + 1) + '">Next session →</a>' : '') +
    '</div>';
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
  pausedPosition = youtubeMode ? ytSongPos() : (performance.now() - songStartTime) * playRate;
  state = STATES.PAUSED;
  cancelAnimationFrame(rafId);
  if (youtubeMode) YouTube.pause();
  else             pauseAudio();
  showScreen('pause');
}

function resumeGame() {
  if (state !== STATES.PAUSED) return;
  showScreen('game');
  state = STATES.PLAYING;
  if (youtubeMode) {
    YouTube.resume();                          // the video re-drives the clock
    ytLastTimeMs = YouTube.getTimeMs();
    ytLastPerf   = performance.now();
  } else {
    songStartTime = performance.now() - pausedPosition / playRate;
    if (audioStarted) resumeAudio();           // if paused during the lead-in, gameLoop starts it at pos 0
  }
  rafId = requestAnimationFrame(gameLoop);
}

// ── Keyboard: pause/resume only ───────────────────────────
// Playing is e-drums (MIDI) or acoustic mic only — the keyboard no longer
// triggers hits, but Escape still pauses/resumes. Number keys 1..0 trigger
// lanes 0..9 as well, but only for the one dedicated keyboard-test account
// (KEYBOARD_TEST_MODE) — everyone else keeps the MIDI/acoustic-only rule.
const KEYBOARD_TEST_LANES = { '1': 0, '2': 1, '3': 2, '4': 3, '5': 4, '6': 5, '7': 6, '8': 7, '9': 8, '0': 9 };

document.addEventListener('keydown', e => {
  if (e.repeat) return;
  resumeContext();

  if (e.key === 'Escape') {
    if (state === STATES.PLAYING) pauseGame();
    else if (state === STATES.PAUSED) resumeGame();
    return;
  }

  if (KEYBOARD_TEST_MODE && state === STATES.PLAYING && KEYBOARD_TEST_LANES.hasOwnProperty(e.key)) {
    processHit(KEYBOARD_TEST_LANES[e.key], performance.now());
  }
});

// ── MIDI ──────────────────────────────────────────────────
let midiDeviceName = '';   // connected device (for the status line); '' = none

// Custom pad map (note → lane), persisted per device and merged over the GM
// defaults. Lets kits that send non-standard note numbers be bound manually via
// the "Map pads" panel (click Assign, strike the pad).
const MIDI_MAP_KEY = 'drumkit_game_midi_map';
let customNoteMap = {};
try { customNoteMap = JSON.parse(localStorage.getItem(MIDI_MAP_KEY) || '{}') || {}; } catch (e) {}
setNoteMap(customNoteMap);
let padLearnLane = null;   // lane currently waiting for a pad strike (learn mode)

// The single message handler for the connected port. During play, hits flow into
// the game; on the menu, a pad hit either binds the pad being learned or flashes
// the status line so the student can confirm the kit is talking to the game.
function midiHandler(msg) {
  // MIDI hits aren't a "user gesture" the browser recognizes, so they can't
  // unlock a suspended AudioContext themselves — but if it's already unlocked
  // (the normal case, from selectSong()'s resumeContext() call), this is a
  // harmless no-op. Cheap safety net for whatever's causing e-drum sessions
  // to end up silent.
  resumeContext();
  if (state === STATES.PLAYING && inputMode === 'midi') {
    const hit = mapMidiEvent(msg);
    if (hit) processHit(hit.lane, performance.now()); // processed the instant the hit arrives, not on the next frame tick
    return;
  }
  if (padLearnLane !== null) { bindPadNote(padLearnLane, msg.note); return; }
  if (inputMode === 'midi') flashMidiStatus(msg.note);
}

function bindPadNote(lane, note) {
  customNoteMap[note] = lane;
  try { localStorage.setItem(MIDI_MAP_KEY, JSON.stringify(customNoteMap)); } catch (e) {}
  setNoteMap(customNoteMap);
  padLearnLane = null;
  buildPadMapList();
}

// Notes currently bound to a lane: the GM defaults still in effect + custom ones.
function notesForLane(lane) {
  const def = getDefaultMap();
  const notes = [];
  for (const n in def)          { if (def[n] === lane && customNoteMap[n] === undefined) notes.push(+n); }
  for (const n in customNoteMap){ if (customNoteMap[n] === lane) notes.push(+n); }
  return notes.sort((a, b) => a - b);
}

function buildPadMapList() {
  const list = document.getElementById('padmap-list');
  if (!list) return;
  const names  = getLaneNames();
  const colors = getLaneColors();
  list.innerHTML = '';
  names.forEach((name, lane) => {
    const customNotes = Object.keys(customNoteMap).filter(n => customNoteMap[n] === lane).map(Number);
    const learning = padLearnLane === lane;
    const row = document.createElement('div');
    row.className = 'cal-row' + (learning ? ' cal-listening' : (customNotes.length ? ' cal-done' : ''));
    row.innerHTML = `
      <span class="cal-name" style="color:${colors[lane]}">${escHtml(name)}</span>
      <span class="cal-state${customNotes.length ? ' ok' : ''}">${
        learning ? 'Hit that pad now…'
                 : 'notes ' + notesForLane(lane).join(', ') + (customNotes.length ? ' (custom)' : '')
      }</span>
      <button class="cal-btn padmap-assign">${learning ? 'Cancel' : 'Assign'}</button>
      ${customNotes.length ? '<button class="cal-btn cal-btn-clear padmap-clear">Clear</button>' : ''}
    `;
    row.querySelector('.padmap-assign').addEventListener('click', () => {
      padLearnLane = learning ? null : lane;
      buildPadMapList();
    });
    row.querySelector('.padmap-clear')?.addEventListener('click', () => {
      for (const n of customNotes) delete customNoteMap[n];
      try { localStorage.setItem(MIDI_MAP_KEY, JSON.stringify(customNoteMap)); } catch (e) {}
      setNoteMap(customNoteMap);
      padLearnLane = null;
      buildPadMapList();
    });
    list.appendChild(row);
  });
}

let midiFlashTimer = null;
function flashMidiStatus(note) {
  if (!acousticStatus || acousticStatus.style.display === 'none') return;
  acousticStatus.innerHTML = '<i class="ti ti-usb" aria-hidden="true"></i> '
    + escHtml(midiDeviceName) + ' — pad received (note ' + note + '). You\'re good to play!';
  acousticStatus.className = 'acoustic-status acoustic-active';
  clearTimeout(midiFlashTimer);
  midiFlashTimer = setTimeout(showMidiStatus, 1500);
}

// Status line under the input cards while E-Drum Kit is selected.
function showMidiStatus() {
  if (!acousticStatus || inputMode !== 'midi') return;
  acousticStatus.style.display = '';
  if (midiDeviceName) {
    acousticStatus.innerHTML = '<i class="ti ti-usb" aria-hidden="true"></i> MIDI connected: '
      + escHtml(midiDeviceName) + ' — hit a pad to test.';
    acousticStatus.className = 'acoustic-status acoustic-active';
  } else {
    acousticStatus.innerHTML = '<i class="ti ti-usb" aria-hidden="true"></i> No MIDI device found — plug in your kit (USB) and it will connect automatically.';
    acousticStatus.className = 'acoustic-status acoustic-pending';
  }
}

async function setupMidi() {
  try {
    await initMidi();
    populateMidiDevices();
    // Re-list AND reconnect when devices come/go (e.g. kit plugged in after load).
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
  // Options carry the real port id — selectInput() looks devices up by id string.
  sel.innerHTML = list.length
    ? list.map(d => `<option value="${escHtml(d.id)}">${escHtml(d.name)}</option>`).join('')
    : '<option value="">No MIDI devices found</option>';
  // Auto-connect the first device so choosing "E-Drum Kit" just works.
  if (list.length) {
    sel.value = list[0].id;
    selectInput(list[0].id, midiHandler);
    midiDeviceName = list[0].name;
  } else {
    selectInput(null, midiHandler);   // detach if the device went away
    midiDeviceName = '';
  }
  if (inputMode === 'midi') showMidiStatus();
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

  let armed = false, hint = '<i class="ti ti-lock" aria-hidden="true"></i> Choose your input above to unlock the song library.';
  if (inputMode === 'midi' || inputMode === 'pad') {
    armed = true;
  } else if (inputMode === 'acoustic') {
    armed = requiredCalibrated();
    hint  = '<i class="ti ti-adjustments" aria-hidden="true"></i> Calibrate your Kick, Snare and Hi-Hat below to unlock the song library.';
  }

  if (cal) cal.style.display = (inputMode === 'acoustic') ? '' : 'none';
  if (lib) lib.style.display = armed ? '' : 'none';
  if (msg) { msg.style.display = armed ? 'none' : ''; if (!armed) msg.innerHTML = hint; }

  // Tutorial mode has no browsable library — the moment an input is armed,
  // jump straight into the first segment (kick) instead of showing a list.
  if (armed && TUTORIAL_MODE && !tutorialStarted) {
    tutorialStarted = true;
    selectSong(tutorialSegments[tutorialIndex].song);
    return;
  }

  renderSongList();   // re-filter the library for the chosen input's category
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

  // The pad-mapping panel only applies to the e-drum input.
  const padWrap = document.getElementById('padmap-wrap');
  if (padWrap) padWrap.style.display = (mode === 'midi') ? '' : 'none';
  if (mode !== 'midi') padLearnLane = null;

  if (mode === 'midi') {
    await setupMidi();
    showMidiStatus();   // "MIDI connected: <kit> — hit a pad to test" / plug-in hint
    if (acousticSensGroup) acousticSensGroup.style.display = 'none';
  } else if (mode === 'acoustic') {
    if (acousticSensGroup) acousticSensGroup.style.display = '';
    if (acousticStatus) {
      acousticStatus.style.display = '';
      acousticStatus.innerHTML = '<i class="ti ti-microphone" aria-hidden="true"></i> Requesting microphone access…';
      acousticStatus.className = 'acoustic-status acoustic-pending';
    }
    try {
      await startAcousticDetection((lane) => {
        if (state === STATES.PLAYING) processHit(lane, performance.now());
      });
      if (acousticStatus) {
        acousticStatus.innerHTML = '<i class="ti ti-microphone" aria-hidden="true"></i> Microphone active — calibrate your kit below.';
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
      acousticStatus.innerHTML = '<i class="ti ti-microphone" aria-hidden="true"></i> Requesting microphone access…';
      acousticStatus.className = 'acoustic-status acoustic-pending';
    }
    try {
      await startPadDetection(() => {
        if (state === STATES.PLAYING) processPadHit(performance.now());
      });
      if (acousticStatus) {
        acousticStatus.innerHTML = '<i class="ti ti-microphone" aria-hidden="true"></i> Microphone active — hit your pad to play. No calibration needed.';
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

// Gameplay recording (canvas + song/hit audio) saved straight to the
// student's device — no server involved. Not offered in YouTube mode since
// the video's audio lives in a separate iframe and can't be tapped.
const btnRecord  = document.getElementById('btn-record');
const recordTip  = document.getElementById('record-tip');
let recordTipTimer = null;
// Most browsers can only record .webm (real .mp4 needs a heavy client-side
// transcoder we deliberately skipped — see recorder.js). Nudge students
// toward a free converter app if they need .mp4 for social platforms that
// don't accept .webm uploads (Instagram, TikTok, etc).
function noteRecordingExt(ext) {
  if (ext !== 'webm' || !recordTip) return;
  showRecordTip('Saved as .webm — use any free video converter to get .mp4 for Instagram/TikTok/etc.');
}
function showRecordTip(text) {
  if (!recordTip) return;
  recordTip.textContent = text;
  recordTip.hidden = false;
  clearTimeout(recordTipTimer);
  recordTipTimer = setTimeout(() => { recordTip.hidden = true; }, 7000);
}
if (btnRecord && !isRecordingSupported()) btnRecord.style.display = 'none';
btnRecord?.addEventListener('click', async () => {
  if (!isRecording()) {
    if (youtubeMode) { showRecordTip("Recording isn't available for YouTube songs — their audio can't be captured."); return; }
    const ok = startRecording(canvas);
    if (!ok) return;
    btnRecord.classList.add('recording');
    btnRecord.innerHTML = '<i class="ti ti-player-stop" aria-hidden="true"></i> Stop & Save';
  } else {
    btnRecord.disabled = true;
    const base = (currentSong?.title || 'groove-quest-play').toLowerCase().replace(/[^a-z0-9]+/g, '-');
    const ext  = await stopRecording(base);
    noteRecordingExt(ext);
    btnRecord.disabled = false;
    btnRecord.classList.remove('recording');
    btnRecord.innerHTML = '<i class="ti ti-video" aria-hidden="true"></i> Record';
  }
});

// A student leaving mid-recording (quit/exit/back-to-menu) shouldn't silently
// lose the clip — auto-stop and save it, same as clicking "Stop & Save".
function finishRecordingIfActive() {
  if (!isRecording()) return;
  const base = (currentSong?.title || 'groove-quest-play').toLowerCase().replace(/[^a-z0-9]+/g, '-');
  stopRecording(base).then(noteRecordingExt);
  btnRecord?.classList.remove('recording');
  if (btnRecord) btnRecord.innerHTML = '<i class="ti ti-video" aria-hidden="true"></i> Record';
}

document.getElementById('btn-pause')?.addEventListener('click', pauseGame);
document.getElementById('btn-resume')?.addEventListener('click', resumeGame);

// Exiting a song/demo goes back to the menu but deliberately stays fullscreen
// + landscape on touch devices — the whole game.php experience is immersive,
// not just active gameplay. Fullscreen/orientation are only released when the
// student actually navigates away from the page. Tutorial mode has no menu of
// its own to go back to, so it sends the student back to the placement-test
// page instead of a confusing empty song list.
function goToMenu() {
  if (TUTORIAL_MODE) { location.href = '/placement-test.php'; return; }
  showScreen('menu');
}

document.getElementById('btn-quit')?.addEventListener('click', () => {
  finishRecordingIfActive();
  state = STATES.IDLE;
  cancelAnimationFrame(rafId);
  stopAudio();
  if (youtubeMode) { YouTube.stop(); youtubeMode = false; document.getElementById('yt-stage')?.classList.remove('active'); }
  goToMenu();
});
document.getElementById('btn-to-menu')?.addEventListener('click', () => {
  finishRecordingIfActive();
  state = STATES.IDLE;
  if (youtubeMode) { YouTube.stop(); youtubeMode = false; document.getElementById('yt-stage')?.classList.remove('active'); }
  goToMenu();
});
document.getElementById('btn-fullscreen-exit')?.addEventListener('click', () => {
  finishRecordingIfActive();
  state = STATES.IDLE;
  cancelAnimationFrame(rafId);
  stopAudio();
  if (youtubeMode) { YouTube.stop(); youtubeMode = false; document.getElementById('yt-stage')?.classList.remove('active'); }
  goToMenu();
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

// Hit-timing calibration slider. Applies live so you can nudge it mid-song and
// re-check whether your hits land on the beat.
const offsetSlider = document.getElementById('audio-offset');
const offsetLabel  = document.getElementById('audio-offset-label');
function showOffset() { if (offsetLabel) offsetLabel.textContent = (hitOffsetMs > 0 ? '+' : '') + hitOffsetMs + ' ms'; }
if (offsetSlider) { offsetSlider.value = hitOffsetMs; showOffset(); }
offsetSlider?.addEventListener('input', () => {
  hitOffsetMs = parseInt(offsetSlider.value, 10) || 0;
  showOffset();
  try { localStorage.setItem(HIT_OFFSET_KEY, String(hitOffsetMs)); } catch (e) {}
});

const precisionToggle = document.getElementById('precision-mode-toggle');
if (precisionToggle) precisionToggle.checked = precisionMode;
precisionToggle?.addEventListener('change', e => {
  precisionMode = e.target.checked;
  setPrecisionMode(precisionMode);
});

// Practice speed select (session-only, never persisted). Hidden for placement
// tests — those are standardized and always run at full speed.
const rateSel  = document.getElementById('play-rate');
const rateHint = document.getElementById('play-rate-hint');
if (DRUM_TEST_MODE) {
  const sr = document.getElementById('speed-row');
  if (sr) sr.style.display = 'none';
}
rateSel?.addEventListener('input', () => {
  playRate = PLAY_RATES[parseInt(rateSel.value, 10)] ?? 1;
  if (rateHint) {
    rateHint.style.display = playRate < 1 ? '' : 'none';
    rateHint.textContent = PLAN_MODE
      ? "Practice speed — you can't pass the session below 100%."
      : "Practice speed — scores aren't saved.";
  }
});

document.getElementById('midi-device-select')?.addEventListener('change', e => {
  // Port ids are strings (Map keys) — never parseInt them.
  selectInput(e.target.value, midiHandler);
  const picked = getInputList().find(d => d.id === e.target.value);
  midiDeviceName = picked ? picked.name : '';
  if (inputMode === 'midi') showMidiStatus();
});

// Pad-mapping panel (E-Drum input): toggle open/closed + reset to GM defaults.
document.getElementById('padmap-toggle')?.addEventListener('click', () => {
  const p = document.getElementById('pad-map');
  if (!p) return;
  const show = p.style.display === 'none';
  p.style.display = show ? '' : 'none';
  padLearnLane = null;
  if (show) buildPadMapList();
});
document.getElementById('padmap-reset')?.addEventListener('click', () => {
  customNoteMap = {};
  try { localStorage.removeItem(MIDI_MAP_KEY); } catch (e) {}
  setNoteMap({});
  padLearnLane = null;
  buildPadMapList();
});

document.getElementById('midi-channel')?.addEventListener('change', e => {
  setChannelFilter(parseInt(e.target.value, 10));
});

// ── Boot ──────────────────────────────────────────────────
showScreen('menu');
syncInputGate();     // song library starts locked until an input is chosen
loadSongLibrary();
