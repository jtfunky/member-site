'use strict';

// ── Lane definitions (8 pads, matching members-site game lanes) ──────────────
const PADS = [
  { lane: 0, name: 'KICK',       key: 'Space',  keyCode: ' ',  color: '#ef4444' },
  { lane: 1, name: 'SNARE',      key: 'Z',      keyCode: 'z',  color: '#e5e5e5' },
  { lane: 2, name: 'HI-HAT',     key: 'X',      keyCode: 'x',  color: '#eab308' },
  { lane: 3, name: 'HI TOM 1',   key: 'C',      keyCode: 'c',  color: '#60a5fa' },
  { lane: 4, name: 'HI TOM 2',   key: 'V',      keyCode: 'v',  color: '#3b82f6' },
  { lane: 6, name: 'FLOOR TOM',  key: 'B',      keyCode: 'b',  color: '#16a34a' },
  { lane: 7, name: 'CRASH',      key: 'N',      keyCode: 'n',  color: '#06b6d4' },
  { lane: 9, name: 'RIDE',       key: 'M',      keyCode: 'm',  color: '#f97316' },
];

// Key → pad index lookup
const KEY_MAP = {};
PADS.forEach((p, i) => { KEY_MAP[p.keyCode] = i; });

// ── State ────────────────────────────────────────────────────────────────────
let notes         = [];        // { time, lane } sorted by time
let selection     = new Set(); // selected note objects (multi-select; survives sorting)
let clipboard     = [];        // copied hits as { time, lane }, relative to the earliest
let latencyMs     = 0;         // subtracted from hit timestamp
let quantizeMode  = 'none';    // 'none' | 'beat' | '8th' | '16th'
let isRecording   = false;
let rafId         = null;
let audioReady    = false;
let loadedFile    = null;      // the loaded audio File — uploaded to the library on save
let ytPlayer      = null;      // YT.Player when a YouTube video is loaded for charting
let ytReady       = false;     // that player is ready → transport routes to it

// ── DOM refs ─────────────────────────────────────────────────────────────────
const audioEl      = document.getElementById('audio');
const fileInput    = document.getElementById('file-input');
const titleIn      = document.getElementById('title');
const artistIn     = document.getElementById('artist');
const bpmIn        = document.getElementById('bpm');
const durationIn   = document.getElementById('duration');
const playBtn      = document.getElementById('btn-play');
const stopBtn      = document.getElementById('btn-stop');
const recBtn       = document.getElementById('btn-record');
const seekBar      = document.getElementById('seek-bar');
const timeDisplay  = document.getElementById('time-display');
const noteCountEl  = document.getElementById('note-count');
const canvas       = document.getElementById('timeline');
const ctx          = canvas.getContext('2d');
const latencySlider= document.getElementById('latency-slider');
const latencyVal   = document.getElementById('latency-val');
const quantizeSel  = document.getElementById('quantize-sel');
const toast        = document.getElementById('toast');
const restoreBar   = document.getElementById('restore-bar');

// ── Audio setup ───────────────────────────────────────────────────────────────
fileInput.addEventListener('change', e => {
  const file = e.target.files[0];
  if (!file) return;
  loadedFile = file;
  const url = URL.createObjectURL(file);
  audioEl.src = url;
  audioEl.load();
  audioEl.onloadedmetadata = () => {
    audioReady = true;
    if (!titleIn.value) titleIn.value = file.name.replace(/\.[^.]+$/, '');
    // Capture the TRUE full duration (handles the Infinity/VBR-MP3 case) so the
    // exported JSON's `duration` matches the audio exactly — the game uses it to
    // size the note-scroll timeline and to know when the song ends.
    resolveAudioDuration(audioEl).then(seconds => {
      durationIn.value = Math.round(seconds * 1000);
      seekBar.max = seconds;
      clampScroll();
      syncZoomUI();
      save();
      drawTimeline();
    });
    save();
    showToast('Audio loaded — detecting BPM…');
    detectBpm(file).then(bpm => {
      bpmIn.value = bpm;
      save();
      showToast(`BPM detected: ${bpm}`);
    }).catch(() => showToast('Audio loaded'));
  };
});

// Some encoders (notably VBR MP3 and blob: URLs) report `duration = Infinity`
// at loadedmetadata until the element has seeked to the end. Force that seek so
// we always resolve the real, full length of the file.
function resolveAudioDuration(el) {
  return new Promise(resolve => {
    if (isFinite(el.duration) && el.duration > 0) { resolve(el.duration); return; }
    const onChange = () => {
      if (isFinite(el.duration) && el.duration > 0) {
        el.removeEventListener('durationchange', onChange);
        el.currentTime = 0;          // restore playhead after the probe seek
        resolve(el.duration);
      }
    };
    el.addEventListener('durationchange', onChange);
    el.currentTime = 1e101;          // seek past the end → browser computes real duration
  });
}

audioEl.addEventListener('timeupdate', () => {
  seekBar.value = audioEl.currentTime;
  updateTimeDisplay();
});

audioEl.addEventListener('ended', () => {
  stopPlayback();
});

seekBar.addEventListener('input', () => {
  transportSeekSec(parseFloat(seekBar.value));
  scheduledUpToMs = curTimeSec() * 1000;   // resync chart playback after scrub
  drawTimeline();
});

// ── Transport ─────────────────────────────────────────────────────────────────
playBtn.addEventListener('click', togglePlay);
stopBtn.addEventListener('click', stopPlayback);
recBtn.addEventListener('click', toggleRecord);

function togglePlay() {
  if (!transportReady()) { showToast('Load an audio file or a YouTube link first'); return; }
  // "Hear chart" routing only applies to the uploaded <audio> element.
  if (playbackEnabled && !usingYouTube()) ensureSongRouting();
  else if (actx) actx.resume();
  if (transportPaused()) {
    transportPlay();
    playBtn.innerHTML = '<i class="ti ti-player-pause"></i> Pause';
    startLoop();
  } else {
    transportPause();
    playBtn.innerHTML = '<i class="ti ti-player-play"></i> Play';
    stopLoop();
  }
}

function stopPlayback() {
  transportPause();
  transportSeekSec(0);
  playBtn.innerHTML = '<i class="ti ti-player-play"></i> Play';
  isRecording = false;
  recBtn.classList.remove('armed');
  recBtn.textContent = '● Rec';
  stopLoop();
  drawTimeline();
}

function toggleRecord() {
  if (!transportReady()) { showToast('Load an audio file or a YouTube link first'); return; }
  isRecording = !isRecording;
  recBtn.classList.toggle('armed', isRecording);
  recBtn.textContent = isRecording ? '■ Stop Rec' : '● Rec';
  if (isRecording && transportPaused()) togglePlay();
}

function startLoop() {
  cancelAnimationFrame(rafId);
  scheduledUpToMs = curTimeSec() * 1000;
  function loop() {
    if (playbackEnabled && actx) scheduleChartNotes();
    if (usingYouTube()) seekBar.value = curTimeSec();   // no timeupdate event for YT
    if (zoom > 1 && !transportPaused()) followPlayhead();
    drawTimeline();
    updateTimeDisplay();
    rafId = requestAnimationFrame(loop);
  }
  rafId = requestAnimationFrame(loop);
}

// Look-ahead scheduler: each frame, place any notes due in the next ~150ms at
// their exact AudioContext time. Because the song now plays through the same
// context, drums line up with the music sample-accurately (no RAF jitter).
const SCHED_LOOKAHEAD = 0.15;   // seconds
function scheduleChartNotes() {
  const songPos = curTimeSec();            // seconds
  const ctxNow  = actx.currentTime;
  const fromMs  = scheduledUpToMs;
  const toMs    = (songPos + SCHED_LOOKAHEAD) * 1000;

  // Big jump (seek/lag) → resync without firing a burst.
  if (toMs <= fromMs || (toMs - fromMs) > 1000) { scheduledUpToMs = songPos * 1000; return; }

  for (const n of notes) {
    if (n.time > fromMs && n.time <= toMs) {
      const when = ctxNow + (n.time / 1000 - songPos);
      playDrumLane(n.lane, when > ctxNow ? when : ctxNow);
    }
  }
  scheduledUpToMs = toMs;
}

function stopLoop() {
  cancelAnimationFrame(rafId);
  rafId = null;
}

// ── Playback source (uploaded <audio> OR a YouTube video) ─────────────────────
// The editor charts against a loaded track. Normally that's the uploaded audio;
// if a YouTube video is loaded instead (for a YouTube-only song), the transport
// routes to the YT player so Play / Record / scrub work against the video.
function usingYouTube()   { return ytReady && !audioReady; }
function transportReady() { return audioReady || ytReady; }
function curTimeSec()     { return usingYouTube() ? (ytPlayer && ytPlayer.getCurrentTime ? (ytPlayer.getCurrentTime() || 0) : 0) : (audioEl.currentTime || 0); }
function curDurSec()      { return usingYouTube() ? (ytPlayer && ytPlayer.getDuration ? (ytPlayer.getDuration() || 0) : 0) : (audioEl.duration || 0); }
function transportPaused(){ return usingYouTube() ? (!ytPlayer || !ytPlayer.getPlayerState || ytPlayer.getPlayerState() !== 1) : audioEl.paused; }
function transportPlay()  { if (usingYouTube()) { if (ytPlayer && ytPlayer.playVideo) ytPlayer.playVideo(); } else audioEl.play(); }
function transportPause() { if (usingYouTube()) { if (ytPlayer && ytPlayer.pauseVideo) ytPlayer.pauseVideo(); } else audioEl.pause(); }
function transportSeekSec(s) {
  s = Math.max(0, s);
  if (usingYouTube()) { if (ytPlayer && ytPlayer.seekTo) ytPlayer.seekTo(s, true); }
  else audioEl.currentTime = s;
}

// YouTube IFrame API — loaded on demand, then a player mounts in #ce-yt-player.
let _ytApiCbs = [];
function ensureYouTubeApi(cb) {
  if (window.YT && window.YT.Player) { cb(); return; }
  _ytApiCbs.push(cb);
  if (window.__ceYtApiLoading) return;
  window.__ceYtApiLoading = true;
  window.onYouTubeIframeAPIReady = () => { _ytApiCbs.forEach(f => f()); _ytApiCbs = []; };
  const s = document.createElement('script');
  s.src = 'https://www.youtube.com/iframe_api';
  document.head.appendChild(s);
}
function parseYtId(url) {
  const m = String(url).match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
  if (m) return m[1];
  return /^[A-Za-z0-9_-]{11}$/.test(String(url).trim()) ? String(url).trim() : null;
}
function loadYouTubeIntoEditor() {
  const id = parseYtId((document.getElementById('song-youtube')?.value || '').trim());
  if (!id) { showToast('Paste a valid YouTube link first'); return; }
  const wrap = document.getElementById('ce-yt-wrap');
  if (wrap) wrap.style.display = '';
  ensureYouTubeApi(() => {
    if (ytPlayer && ytPlayer.loadVideoById) { ytPlayer.loadVideoById(id); ytReady = true; onYtLoaded(); return; }
    ytPlayer = new YT.Player('ce-yt-player', {
      videoId: id,
      width: '100%', height: '100%',
      playerVars: { controls: 0, rel: 0, modestbranding: 1, playsinline: 1 },
      events: {
        onReady: () => { ytReady = true; onYtLoaded(); },
        onStateChange: e => { if (window.YT && e.data === YT.PlayerState.ENDED) stopPlayback(); },
      },
    });
  });
}
function onYtLoaded() {
  showToast('YouTube loaded — Play / Rec now use the video');
  const durS = ytPlayer && ytPlayer.getDuration ? ytPlayer.getDuration() : 0;
  if (durS > 0) {
    seekBar.max = durS;
    if (!parseInt(durationIn.value, 10)) durationIn.value = Math.round(durS * 1000);
  }
  clampScroll(); syncZoomUI(); drawTimeline();
}

function updateTimeDisplay() {
  const cur = curTimeSec();
  const dur = curDurSec();
  timeDisplay.textContent = formatTime(cur) + ' / ' + formatTime(dur);
}

function formatTime(sec) {
  const m = Math.floor(sec / 60);
  const s = Math.floor(sec % 60);
  const ms = Math.floor((sec % 1) * 1000);
  return `${m}:${String(s).padStart(2,'0')}.${String(ms).padStart(3,'0')}`;
}

// ── Pad hit ───────────────────────────────────────────────────────────────────
function hitPad(padIndex) {
  const pad  = PADS[padIndex];
  const padEl = document.querySelectorAll('.pad')[padIndex];

  // Audible feedback — synthesized drum sound for this pad type
  playDrum(padIndex);

  // Flash effect
  padEl.classList.add('flash');
  padEl.style.boxShadow = `0 0 16px ${pad.color}`;
  setTimeout(() => {
    padEl.classList.remove('flash');
    padEl.style.boxShadow = '';
  }, 150);

  if (!isRecording || !transportReady()) return;

  let time = Math.round(curTimeSec() * 1000) - latencyMs;
  time = Math.max(0, time);

  // Quantize
  const bpm = parseFloat(bpmIn.value) || 120;
  const beatMs = 60000 / bpm;
  if (quantizeMode === 'beat')  time = quantize(time, beatMs);
  if (quantizeMode === '8th')   time = quantize(time, beatMs / 2);
  if (quantizeMode === '16th')  time = quantize(time, beatMs / 4);

  // Deduplicate: ignore if same lane within 30ms
  const dup = notes.find(n => n.lane === pad.lane && Math.abs(n.time - time) < 30);
  if (dup) return;

  notes.push({ time, lane: pad.lane });
  notes.sort((a, b) => a.time - b.time);
  updateNoteCount();
  quantizeBackup = null;   // editing invalidates the quantize-undo snapshot
  save();
}

function quantize(timeMs, gridMs) {
  return Math.round(timeMs / gridMs) * gridMs;
}

// ── Drum sounds (synthesized via Web Audio — no sample files needed) ──────────
const SOUND_KEY = 'drumkit_pad_sound';
let soundEnabled = localStorage.getItem(SOUND_KEY) !== '0';

// "Hear chart": play recorded notes as drum sounds during playback (preview only —
// never part of the exported JSON). Off by default.
const PLAYBACK_KEY = 'drumkit_chart_playback';
let playbackEnabled = localStorage.getItem(PLAYBACK_KEY) === '1';
let scheduledUpToMs = 0; // song position (ms) up to which chart notes are scheduled
let actx = null;         // shared AudioContext for drum synthesis
let drumBus = null;      // master gain
let sharedNoise = null;  // pre-built noise buffer (reused — no per-hit allocation)
let mediaSrc = null;     // MediaElementSource — routes the song through the context

function ensureAudio() {
  if (!actx) {
    const AC = window.AudioContext || window.webkitAudioContext;
    // 'interactive' requests the smallest output buffer → lowest latency.
    actx = new AC({ latencyHint: 'interactive' });
    drumBus = actx.createGain();
    drumBus.gain.value = 0.8;
    drumBus.connect(actx.destination);
    sharedNoise = buildNoise(1.0);
  }
  if (actx.state === 'suspended') actx.resume();
  return actx;
}

// Route the <audio> song through the same AudioContext as the drums, so both
// share one clock/output path and stay perfectly in sync. createMediaElementSource
// can only run ONCE per element; after this the song is audible ONLY while the
// context is running, so callers must keep it resumed before playing.
function ensureSongRouting() {
  ensureAudio();
  if (!mediaSrc) {
    try {
      mediaSrc = actx.createMediaElementSource(audioEl);
      mediaSrc.connect(actx.destination);
    } catch (e) { /* already routed */ }
  }
}

// Warm the audio engine on the very first user interaction so the first pad
// hit isn't delayed by context start-up.
window.addEventListener('pointerdown', () => ensureAudio(), { once: true });
window.addEventListener('keydown',     () => ensureAudio(), { once: true });

function buildNoise(dur) {
  const len = Math.floor(actx.sampleRate * dur);
  const buf = actx.createBuffer(1, len, actx.sampleRate);
  const d = buf.getChannelData(0);
  for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
  return buf;
}

// Short helper: noise burst through a filter with an exponential decay.
// Reuses the shared noise buffer (the gain envelope cuts it to length).
function noiseHit(t, dur, filterType, freq, gain) {
  const n = actx.createBufferSource(); n.buffer = sharedNoise;
  const f = actx.createBiquadFilter(); f.type = filterType; f.frequency.value = freq;
  const g = actx.createGain();
  g.gain.setValueAtTime(gain, t);
  g.gain.exponentialRampToValueAtTime(0.001, t + dur);
  n.connect(f); f.connect(g); g.connect(drumBus);
  n.start(t); n.stop(t + dur + 0.02);
}

// Pitched membrane (kick / toms): sine with a downward pitch sweep + decay.
function membrane(t, fStart, fEnd, dur, gain) {
  const o = actx.createOscillator(); o.type = 'sine';
  o.frequency.setValueAtTime(fStart, t);
  o.frequency.exponentialRampToValueAtTime(fEnd, t + dur * 0.4);
  const g = actx.createGain();
  g.gain.setValueAtTime(gain, t);
  g.gain.exponentialRampToValueAtTime(0.001, t + dur);
  o.connect(g); g.connect(drumBus);
  o.start(t); o.stop(t + dur);
}

function playKick(t)  { membrane(t, 150, 50, 0.4, 1.0); }
function playTom(t, f){ membrane(t, f, f * 0.5, 0.35, 0.9); }

function playSnare(t) {
  noiseHit(t, 0.2, 'highpass', 1800, 0.7);   // wires/snares
  membrane(t, 180, 120, 0.12, 0.5);          // drum body
}
function playHat(t)   { noiseHit(t, 0.05, 'highpass', 7000, 0.5); }   // closed hat
function playCrash(t) { noiseHit(t, 1.0,  'highpass', 5000, 0.5); }   // long wash

function playRide(t) {
  noiseHit(t, 0.5, 'highpass', 6000, 0.3);   // shimmer
  const o = actx.createOscillator(); o.type = 'square'; o.frequency.value = 5000;
  const g = actx.createGain();
  g.gain.setValueAtTime(0.12, t);
  g.gain.exponentialRampToValueAtTime(0.001, t + 0.3);
  o.connect(g); g.connect(drumBus);
  o.start(t); o.stop(t + 0.3);               // metallic "ping"
}

// Dispatch by lane so the sound always matches the drum type.
// Live pad hit — respects the "Pad sounds" mute.
function playDrum(padIndex) {
  if (!soundEnabled) return;
  playDrumLane(PADS[padIndex].lane);
}

// Raw synth dispatch by lane — used by live hits AND recorded-chart playback.
// `when` (AudioContext time) lets the scheduler place notes precisely; omit it
// for live hits (plays immediately).
function playDrumLane(lane, when) {
  ensureAudio();
  const t = (when !== undefined) ? when : actx.currentTime;
  switch (lane) {
    case 0: playKick(t);      break;  // Kick
    case 1: playSnare(t);     break;  // Snare
    case 2: playHat(t);       break;  // Hi-Hat
    case 3: playTom(t, 220);  break;  // Hi Tom 1
    case 4: playTom(t, 180);  break;  // Hi Tom 2
    case 6: playTom(t, 110);  break;  // Floor Tom
    case 7: playCrash(t);     break;  // Crash
    case 9: playRide(t);      break;  // Ride
  }
}

// ── Keyboard ─────────────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
  // Ignore when typing in an input
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;

  // Always prevent default to block browser media shortcuts (K, M, etc.)
  e.preventDefault();

  if (e.key === 'Escape') { cancelLearn(); clearSelection(); return; }
  if (e.key === ' ') { togglePlay(); return; }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) { selectAll(); return; }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'c' || e.key === 'C')) { copySelected(); return; }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'v' || e.key === 'V')) { pasteClipboard(); return; }
  if ((e.ctrlKey || e.metaKey) && e.key === 'z') { undoLast(); return; }
  if (e.key === 'Delete' || e.key === 'Backspace') { deleteSelected(); return; }
  if (e.key.startsWith('Arrow')) { nudgeSelected(e.key, e.shiftKey, e.repeat); return; }

  const padIdx = KEY_MAP[e.key.toLowerCase()];
  if (padIdx !== undefined && !e.repeat) hitPad(padIdx);
});

// ── Pad buttons ───────────────────────────────────────────────────────────────
document.querySelectorAll('.pad').forEach((el, i) => {
  el.addEventListener('mousedown', e => { e.preventDefault(); hitPad(i); });
});

// ── Timeline canvas ───────────────────────────────────────────────────────────
const LABEL_W   = 80;
const ROW_PAD   = 4;
const NOTE_R    = 5;

function rowHeight() { return Math.floor((canvas.height) / PADS.length); }

// ── Zoom & horizontal scroll (the "magnify" tool) ─────────────────────────────
const MAX_ZOOM = 64;
let zoom     = 1;     // 1 = whole song fits the plot area; >1 magnifies
let scrollMs = 0;     // song time (ms) shown at the left edge of the plot area

const panWrap = document.getElementById('pan-wrap');
const panBar  = document.getElementById('pan-bar');
const zoomVal = document.getElementById('zoom-val');

function viewDur()     { return curDur() / zoom; }                 // visible span (ms)
function maxScrollMs() { return Math.max(0, curDur() - viewDur()); }
function clampScroll() { scrollMs = Math.max(0, Math.min(maxScrollMs(), scrollMs)); }

// Map a song time (ms) → canvas x, honouring zoom + scroll.
function xFromTime(t, W) {
  return LABEL_W + ((t - scrollMs) / viewDur()) * (W - LABEL_W);
}

// Zoom to `z`, keeping the time under `centerX` (canvas px) fixed on screen.
function setZoom(z, centerX) {
  const plotW = canvas.width - LABEL_W;
  if (centerX === undefined) centerX = LABEL_W + plotW / 2;
  const frac       = Math.max(0, Math.min(1, (centerX - LABEL_W) / plotW));
  const anchorTime = scrollMs + frac * viewDur();
  zoom     = Math.max(1, Math.min(MAX_ZOOM, z));
  scrollMs = anchorTime - frac * viewDur();
  clampScroll();
  syncZoomUI();
  drawTimeline();
}

function syncZoomUI() {
  if (zoomVal) zoomVal.textContent = (Math.round(zoom * 10) / 10) + '×';
  if (panWrap) panWrap.style.display = zoom > 1 ? '' : 'none';
  if (panBar)  { panBar.max = maxScrollMs(); panBar.value = scrollMs; }
}

// Keep a given time inside the visible window (used by arrow-nudge & playback).
function ensureTimeVisible(t) {
  const margin = viewDur() * 0.12;
  if (t < scrollMs + margin)                  scrollMs = t - margin;
  else if (t > scrollMs + viewDur() - margin) scrollMs = t - viewDur() + margin;
  clampScroll();
  syncZoomUI();
}

// While playing zoomed-in, scroll the view to follow the playhead.
function followPlayhead() {
  const t = curTimeSec() * 1000;
  if (t < scrollMs || t > scrollMs + viewDur()) {
    scrollMs = t - viewDur() * 0.2;
    clampScroll();
    syncZoomUI();
  }
}

function drawTimeline() {
  const W = canvas.width = canvas.offsetWidth;
  const H = canvas.height = canvas.offsetHeight;
  const rh = H / PADS.length;
  const cur = curTimeSec() * 1000;

  ctx.clearRect(0, 0, W, H);
  ctx.fillStyle = '#0d0d0f';
  ctx.fillRect(0, 0, W, H);

  PADS.forEach((pad, i) => {
    const y = i * rh;
    // Row background alternating
    ctx.fillStyle = i % 2 === 0 ? 'rgba(255,255,255,0.02)' : 'transparent';
    ctx.fillRect(0, y, W, rh);

    // Label strip
    ctx.fillStyle = pad.color + '22';
    ctx.fillRect(0, y, LABEL_W, rh);
    ctx.fillStyle = pad.color;
    ctx.font = 'bold 10px monospace';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(pad.name, 6, y + rh / 2);

    // Row divider
    ctx.strokeStyle = 'rgba(255,255,255,0.06)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, y + rh); ctx.lineTo(W, y + rh);
    ctx.stroke();
  });

  // Beat grid lines — only the visible window; finer subdivisions when magnified
  const bpm = parseFloat(bpmIn.value) || 120;
  const beatMs = 60000 / bpm;
  const beatPx = (beatMs / viewDur()) * (W - LABEL_W);
  const subdiv = beatPx > 220 ? 4 : (beatPx > 90 ? 2 : 1);
  const stepMs = beatMs / subdiv;
  const firstStep = Math.max(0, Math.floor(scrollMs / stepMs));
  const lastStep  = Math.ceil((scrollMs + viewDur()) / stepMs);
  ctx.lineWidth = 1;
  for (let s = firstStep; s <= lastStep; s++) {
    const x = xFromTime(s * stepMs, W);
    if (x < LABEL_W || x > W) continue;
    ctx.strokeStyle = (s % subdiv === 0) ? 'rgba(255,255,255,0.08)' : 'rgba(255,255,255,0.035)';
    ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke();
  }

  // Notes
  notes.forEach((n, idx) => {
    const padIdx = PADS.findIndex(p => p.lane === n.lane);
    if (padIdx === -1) return;
    const pad = PADS[padIdx];
    const x = xFromTime(n.time, W);
    if (x < LABEL_W - NOTE_R || x > W + NOTE_R) return;   // cull notes outside the view
    const y = padIdx * rh + rh / 2;
    const sel = selection.has(n);

    ctx.beginPath();
    ctx.arc(x, y, sel ? NOTE_R + 2 : NOTE_R, 0, Math.PI * 2);
    ctx.fillStyle = sel ? '#ffffff' : pad.color;
    ctx.shadowColor = pad.color;
    ctx.shadowBlur  = sel ? 12 : 6;
    ctx.fill();
    ctx.shadowBlur = 0;
  });

  // Playhead
  const px = xFromTime(cur, W);
  if (px >= LABEL_W && px <= W) {
    ctx.strokeStyle = 'rgba(255,255,255,0.9)';
    ctx.lineWidth = 2;
    ctx.shadowColor = '#fff';
    ctx.shadowBlur = 8;
    ctx.beginPath(); ctx.moveTo(px, 0); ctx.lineTo(px, H); ctx.stroke();
    ctx.shadowBlur = 0;
  }

  // Label divider
  ctx.strokeStyle = 'rgba(255,255,255,0.15)';
  ctx.lineWidth = 1;
  ctx.beginPath(); ctx.moveTo(LABEL_W, 0); ctx.lineTo(LABEL_W, H); ctx.stroke();

  // Rubber-band selection rectangle
  if (drag && drag.kind === 'box' && drag.moved) {
    const bx0 = Math.min(drag.startX, drag.curX), bx1 = Math.max(drag.startX, drag.curX);
    const by0 = Math.min(drag.startY, drag.curY), by1 = Math.max(drag.startY, drag.curY);
    ctx.fillStyle   = 'rgba(139,92,246,0.15)';
    ctx.fillRect(bx0, by0, bx1 - bx0, by1 - by0);
    ctx.strokeStyle = 'rgba(139,92,246,0.85)';
    ctx.lineWidth   = 1;
    ctx.strokeRect(bx0, by0, bx1 - bx0, by1 - by0);
  }
}

// Click on canvas to seek or select note
// ── Timeline interaction: click to select/seek, drag to retime/move lane ──────
const DRAG_THRESHOLD = 3;   // px of movement before a click becomes a drag
let drag = null;            // { kind:'note'|'box', ... } — see beginNoteDrag / mousedown

function curDur() {
  return parseFloat(durationIn.value) || (curDurSec() * 1000) || 60000;
}
function canvasCoords(e) {
  const rect = canvas.getBoundingClientRect();
  return {
    x: (e.clientX - rect.left) * (canvas.width  / rect.width),
    y: (e.clientY - rect.top)  * (canvas.height / rect.height),
  };
}
function timeFromX(x) {
  const t = scrollMs + ((x - LABEL_W) / (canvas.width - LABEL_W)) * viewDur();
  return Math.max(0, Math.min(curDur(), t));
}
function laneFromY(y) {
  const rh = canvas.height / PADS.length;
  const padIdx = Math.max(0, Math.min(PADS.length - 1, Math.floor(y / rh)));
  return PADS[padIdx].lane;
}
function noteIndexAt(x, y) {
  const lane = laneFromY(y);
  const tol  = (8 / (canvas.width - LABEL_W)) * viewDur();   // 8px tolerance (zoom-aware)
  const t    = timeFromX(x);
  let best = -1, bestDiff = Infinity;
  for (let i = 0; i < notes.length; i++) {
    if (notes[i].lane !== lane) continue;
    const d = Math.abs(notes[i].time - t);
    if (d < tol && d < bestDiff) { bestDiff = d; best = i; }
  }
  return best;
}

// ── Selection helpers (multi-select) ──────────────────────────────────────────
function selectOnly(note) { selection.clear(); if (note) selection.add(note); }
function selectAll() {
  selection.clear();
  for (const n of notes) selection.add(n);
  updateNoteCount(); drawTimeline();
  showToast(notes.length ? `Selected all ${notes.length} notes` : 'No notes yet');
}
function clearSelection() { if (selection.size) { selection.clear(); updateNoteCount(); drawTimeline(); } }
function updateNoteCount() {
  const sel = selection.size;
  noteCountEl.textContent = notes.length + ' notes' + (sel ? ` · ${sel} selected` : '');
}
function padIndexFromY(y) {
  const rh = canvas.height / PADS.length;
  return Math.max(0, Math.min(PADS.length - 1, Math.floor(y / rh)));
}
function padIndexOfLane(lane) {
  const i = PADS.findIndex(p => p.lane === lane);
  return i === -1 ? 0 : i;
}

// Snapshot every selected note's start time/lane so a group drag preserves
// relative timing and lane spacing. Records group bounds for clamping.
function beginNoteDrag(anchor, x, y, additive, wasSelected) {
  const startTimes = new Map(), startPads = new Map();
  let minStartTime = Infinity, minPad = Infinity, maxPad = -Infinity;
  for (const n of selection) {
    startTimes.set(n, n.time);
    const p = padIndexOfLane(n.lane);
    startPads.set(n, p);
    minStartTime = Math.min(minStartTime, n.time);
    minPad = Math.min(minPad, p);
    maxPad = Math.max(maxPad, p);
  }
  return {
    kind: 'note', anchor, additive, wasSelected,
    startX: x, startY: y, moved: false,
    anchorStartTime: anchor.time,
    anchorStartPad: padIndexOfLane(anchor.lane),
    startTimes, startPads, minStartTime, minPad, maxPad,
  };
}

function selectNotesInBox(d, additive) {
  if (!additive) selection.clear();
  const x0 = Math.min(d.startX, d.curX), x1 = Math.max(d.startX, d.curX);
  const y0 = Math.min(d.startY, d.curY), y1 = Math.max(d.startY, d.curY);
  const rh = canvas.height / PADS.length;
  const W  = canvas.width;
  for (const n of notes) {
    const nx = xFromTime(n.time, W);
    if (nx < LABEL_W) continue;
    const ny = padIndexOfLane(n.lane) * rh + rh / 2;
    if (nx >= x0 && nx <= x1 && ny >= y0 && ny <= y1) selection.add(n);
  }
}

canvas.addEventListener('mousedown', e => {
  const { x, y } = canvasCoords(e);
  if (x < LABEL_W) return;
  e.preventDefault();
  const idx = noteIndexAt(x, y);
  const additive = e.shiftKey || e.ctrlKey || e.metaKey;

  if (idx !== -1) {
    const note = notes[idx];
    const wasSelected = selection.has(note);
    if (additive) { if (!wasSelected) selection.add(note); }
    else if (!wasSelected) selectOnly(note);   // grab an unselected note → select just it
    drag = beginNoteDrag(note, x, y, additive, wasSelected);
    updateNoteCount();
    drawTimeline();
  } else {
    // empty area → rubber-band box (or, if no movement, a click to seek/deselect)
    drag = { kind: 'box', startX: x, startY: y, curX: x, curY: y, moved: false, additive };
  }
});

window.addEventListener('mousemove', e => {
  if (!drag) return;
  const { x, y } = canvasCoords(e);

  if (!drag.moved &&
      Math.abs(x - drag.startX) < DRAG_THRESHOLD &&
      Math.abs(y - drag.startY) < DRAG_THRESHOLD) return;

  if (drag.kind === 'box') {                    // grow the selection rectangle
    drag.moved = true;
    drag.curX = x; drag.curY = y;
    drawTimeline();
    return;
  }

  if (!drag.moved) {
    drag.moved = true;
    quantizeBackup = notes.map(n => ({ ...n }));   // snapshot so Undo reverts the move
  }

  // Anchor's target time (snap to grid unless Alt) → shared time delta for the group
  let targetTime = timeFromX(x);
  const grid = currentGridMs();
  if (grid && !e.altKey) targetTime = Math.round(targetTime / grid) * grid;
  let dTime = Math.round(targetTime - drag.anchorStartTime);
  dTime = Math.max(dTime, -drag.minStartTime);              // keep earliest note ≥ 0

  // Vertical: shared lane-row delta, clamped so the whole group stays in range
  let dPad = padIndexFromY(y) - drag.anchorStartPad;
  dPad = Math.max(dPad, -drag.minPad);
  dPad = Math.min(dPad, (PADS.length - 1) - drag.maxPad);

  for (const n of selection) {
    n.time = Math.max(0, drag.startTimes.get(n) + dTime);
    n.lane = PADS[drag.startPads.get(n) + dPad].lane;
  }
  drawTimeline();
});

window.addEventListener('mouseup', e => {
  if (!drag) return;
  const d = drag; drag = null;

  if (d.kind === 'box') {
    if (d.moved) {                              // finalize rubber-band selection
      selectNotesInBox(d, d.additive);
    } else if (!d.additive) {                   // plain click on empty area → deselect + seek
      selection.clear();
      const { x } = canvasCoords(e);
      if (x >= LABEL_W && transportReady()) {
        transportSeekSec(timeFromX(x) / 1000);
        seekBar.value = curTimeSec();
        scheduledUpToMs = curTimeSec() * 1000;
      }
    }
    updateNoteCount();
    drawTimeline();
    return;
  }

  if (d.moved) {                                // finished moving the group
    notes.sort((a, b) => a.time - b.time);
    updateNoteCount();
    save();
    if (soundEnabled) playDrumLane(d.anchor.lane);   // audible drop confirmation
    drawTimeline();
  } else {                                      // a click on a note (no movement)
    if (d.additive) {
      if (d.wasSelected) selection.delete(d.anchor);   // shift/ctrl-click toggles off
      // else it was added on mousedown → stays selected
    } else {
      selectOnly(d.anchor);                     // plain click → collapse to this note
    }
    updateNoteCount();
    drawTimeline();
  }
});

// Hover cursor: "move" over a note, grabbing while dragging, crosshair elsewhere
canvas.addEventListener('mousemove', e => {
  if (drag) { canvas.style.cursor = drag.kind === 'note' ? 'grabbing' : 'crosshair'; return; }
  const { x, y } = canvasCoords(e);
  canvas.style.cursor = (x >= LABEL_W && noteIndexAt(x, y) !== -1) ? 'move' : 'crosshair';
});

// Magnify: Ctrl/Cmd + wheel zooms at the cursor; plain wheel pans when zoomed in.
canvas.addEventListener('wheel', e => {
  if (e.ctrlKey || e.metaKey) {
    e.preventDefault();
    const { x } = canvasCoords(e);
    setZoom(zoom * (e.deltaY < 0 ? 1.18 : 1 / 1.18), x);
  } else if (zoom > 1) {
    e.preventDefault();
    scrollMs += (e.deltaY + e.deltaX) * 0.5 * (viewDur() / (canvas.width - LABEL_W));
    clampScroll(); syncZoomUI(); drawTimeline();
  }
}, { passive: false });

document.getElementById('btn-zoom-in') .addEventListener('click', () => setZoom(zoom * 1.6));
document.getElementById('btn-zoom-out').addEventListener('click', () => setZoom(zoom / 1.6));
document.getElementById('btn-zoom-fit').addEventListener('click', () => {
  zoom = 1; scrollMs = 0; syncZoomUI(); drawTimeline();
});
panBar.addEventListener('input', () => { scrollMs = parseFloat(panBar.value) || 0; clampScroll(); drawTimeline(); });

// Resize observer
const ro = new ResizeObserver(() => drawTimeline());
ro.observe(canvas.parentElement);

// ── Editing ───────────────────────────────────────────────────────────────────
function undoLast() {
  // If a bulk edit (quantize or drag) just happened, the first Undo restores it.
  if (quantizeBackup) {
    notes = quantizeBackup;
    quantizeBackup = null;
    selection.clear();
    updateNoteCount();
    save(); drawTimeline();
    showToast('Reverted last change');
    return;
  }
  if (!notes.length) return;
  notes.pop();
  selection.clear();
  updateNoteCount();
  save(); drawTimeline();
  showToast('Undone');
}

function deleteSelected() {
  if (!selection.size) return;
  notes = notes.filter(n => !selection.has(n));
  selection.clear();
  quantizeBackup = null;
  updateNoteCount();
  save(); drawTimeline();
}

// Copy the selected hits, stored relative to the earliest so they paste as a block.
function copySelected() {
  if (!selection.size) return;
  const arr  = [...selection].map(n => ({ time: n.time, lane: n.lane }));
  const minT = arr.reduce((m, n) => Math.min(m, n.time), Infinity);
  clipboard  = arr.map(n => ({ time: n.time - minT, lane: n.lane }))
                  .sort((a, b) => a.time - b.time || a.lane - b.lane);
  showToast(`Copied ${clipboard.length} hit${clipboard.length === 1 ? '' : 's'}`);
}

// Paste the clipboard at the playhead (earliest copied hit lands on the playhead).
// The pasted hits become the new selection; Undo (Ctrl+Z) reverts the whole paste.
function pasteClipboard() {
  if (!clipboard.length) return;
  quantizeBackup = notes.map(n => ({ ...n }));    // snapshot so Undo reverts the paste
  const base   = Math.round(curTimeSec() * 1000);
  const pasted = [];
  for (const c of clipboard) {
    const t = Math.max(0, base + c.time);
    if (notes.some(n => n.lane === c.lane && Math.abs(n.time - t) < 30)) continue; // skip dupes
    const nn = { time: t, lane: c.lane };
    notes.push(nn);
    pasted.push(nn);
  }
  notes.sort((a, b) => a.time - b.time);
  selection.clear();
  for (const n of pasted) selection.add(n);
  updateNoteCount();
  save(); drawTimeline();
  ensureTimeVisible(base);
  showToast(`Pasted ${pasted.length} hit${pasted.length === 1 ? '' : 's'} at playhead`);
}

// Fine-move every selected note with the arrow keys. Left/Right retime (by the
// active grid, or 10ms when no grid; Shift = 1ms). Up/Down shift lane. The whole
// selection moves together, clamped so it stays on-grid and in-range.
function nudgeSelected(key, fine, silent) {
  if (!selection.size) return;
  if (key === 'ArrowLeft' || key === 'ArrowRight') {
    const dir  = key === 'ArrowRight' ? 1 : -1;
    const step = fine ? 1 : (currentGridMs() || 10);
    let minT = Infinity;
    for (const n of selection) minT = Math.min(minT, n.time);
    const d = Math.max(dir * step, -minT);                 // keep earliest note ≥ 0
    let earliest = Infinity;
    for (const n of selection) { n.time = Math.max(0, Math.round(n.time + d)); earliest = Math.min(earliest, n.time); }
    notes.sort((a, b) => a.time - b.time);
    ensureTimeVisible(earliest);
  } else if (key === 'ArrowUp' || key === 'ArrowDown') {
    let minP = Infinity, maxP = -Infinity;
    for (const n of selection) { const p = padIndexOfLane(n.lane); minP = Math.min(minP, p); maxP = Math.max(maxP, p); }
    let dPad = key === 'ArrowUp' ? -1 : 1;
    dPad = Math.max(dPad, -minP);
    dPad = Math.min(dPad, (PADS.length - 1) - maxP);
    if (dPad !== 0) for (const n of selection) n.lane = PADS[padIndexOfLane(n.lane) + dPad].lane;
    if (soundEnabled && !silent && selection.size === 1) for (const n of selection) playDrumLane(n.lane);
  } else {
    return;
  }
  quantizeBackup = null;
  save(); drawTimeline();
}

document.getElementById('btn-undo').addEventListener('click', undoLast);
document.getElementById('btn-select-all').addEventListener('click', selectAll);
document.getElementById('btn-copy-notes')?.addEventListener('click', copySelected);
document.getElementById('btn-paste-notes')?.addEventListener('click', pasteClipboard);
// Clear All resets the WHOLE editor — notes, song info, tags, audio and YouTube —
// so the next save creates a brand-new song (it will NOT update the loaded one).
// To wipe just the notes, use Ctrl+A then Delete instead.
document.getElementById('btn-clear').addEventListener('click', () => {
  if (!confirm('Clear EVERYTHING — notes, song info, tags, audio/YouTube — and start a new song?')) return;
  stopPlayback();

  notes = []; selection.clear(); clipboard = [];
  quantizeBackup = null;

  titleIn.value = ''; artistIn.value = '';
  bpmIn.value = 120; durationIn.value = 0;
  const catSel = document.getElementById('song-category'); if (catSel) catSel.value = 'kit';
  const ytIn = document.getElementById('song-youtube');    if (ytIn) ytIn.value = '';
  document.querySelectorAll('.song-tag-cb').forEach(cb => { cb.checked = false; });

  // Unload audio + YouTube so the transport fully resets.
  audioEl.removeAttribute('src'); audioEl.load();
  audioReady = false; loadedFile = null; fileInput.value = '';
  if (ytPlayer && ytPlayer.stopVideo) { try { ytPlayer.stopVideo(); } catch (e) {} }
  ytReady = false;
  const ytWrap = document.getElementById('ce-yt-wrap'); if (ytWrap) ytWrap.style.display = 'none';

  // Detach from the previously loaded song/request → next save INSERTS a new song.
  savedSongId = '';
  fromRequestId = '';
  setSaveStatus('');
  if (loadAudioBtn) loadAudioBtn.innerHTML = '<i class="ti ti-folder-open"></i> Load Audio File';
  if (audioCurrent) { audioCurrent.textContent = ''; audioCurrent.classList.remove('replace'); }

  seekBar.value = 0;
  zoom = 1; scrollMs = 0;
  try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
  updateNoteCount();
  updateTimeDisplay();
  syncZoomUI();
  drawTimeline();
  showToast('Cleared — you\'re starting a new song');
});

// ── Latency ───────────────────────────────────────────────────────────────────
latencySlider.addEventListener('input', () => {
  const prev = latencyMs;
  latencyMs  = parseInt(latencySlider.value, 10);
  latencyVal.textContent = (latencyMs >= 0 ? '+' : '') + latencyMs + 'ms';
  // Shift all existing notes by the delta
  const delta = latencyMs - prev;
  notes = notes.map(n => ({ ...n, time: Math.max(0, n.time - delta) }));
  quantizeBackup = null;
  save(); drawTimeline();
});

// ── Quantize ─────────────────────────────────────────────────────────────────
quantizeSel.addEventListener('change', () => {
  quantizeMode = quantizeSel.value;
});

// Grid size (ms) for the current Quantize selection, or null for "None".
function currentGridMs() {
  if (quantizeMode === 'none') return null;
  const beatMs = 60000 / (parseFloat(bpmIn.value) || 120);
  if (quantizeMode === 'beat') return beatMs;
  if (quantizeMode === '8th')  return beatMs / 2;
  if (quantizeMode === '16th') return beatMs / 4;
  return null;
}

// Snap ALL recorded notes to the selected grid (post-record cleanup).
let quantizeBackup = null;   // one-level undo snapshot
function quantizeAll() {
  const grid = currentGridMs();
  if (grid === null) { showToast('Pick a grid first (Beat / 8th / 16th)'); return; }
  if (!notes.length) { showToast('No notes to quantize'); return; }
  if (!confirm(`Snap all ${notes.length} notes to the ${quantizeMode} grid? (Undo available right after.)`)) return;

  quantizeBackup = notes.map(n => ({ ...n }));

  const seen = new Set();
  const snapped = [];
  for (const n of notes) {
    const t = Math.max(0, Math.round(n.time / grid) * grid);
    const key = n.lane + ':' + t;
    if (seen.has(key)) continue;        // merge notes that collide after snapping
    seen.add(key);
    snapped.push({ time: t, lane: n.lane });
  }
  snapped.sort((a, b) => a.time - b.time);

  const removed = notes.length - snapped.length;
  notes = snapped;
  selection.clear();
  updateNoteCount();
  save(); drawTimeline();
  showToast(`Quantized to ${quantizeMode}${removed ? ` · merged ${removed} duplicate${removed !== 1 ? 's' : ''}` : ''} · press Undo to revert`);
}

document.getElementById('btn-quantize-apply').addEventListener('click', quantizeAll);

// ── Metadata save on change ───────────────────────────────────────────────────
[titleIn, artistIn, bpmIn, durationIn].forEach(el => {
  el.addEventListener('input', () => { clampScroll(); syncZoomUI(); save(); drawTimeline(); });
});

// ── Export ────────────────────────────────────────────────────────────────────
function buildChart() {
  return {
    title:    titleIn.value  || 'Untitled',
    artist:   artistIn.value || 'Unknown',
    bpm:      parseFloat(bpmIn.value) || 120,
    duration: parseInt(durationIn.value, 10) || 0,
    notes:    notes.map(n => ({ time: n.time, lane: n.lane })),
  };
}

// The copy/download export buttons are optional (removed from the members-site
// admin editor, which saves directly). Guard so their absence doesn't throw.
document.getElementById('btn-copy')?.addEventListener('click', () => {
  navigator.clipboard.writeText(JSON.stringify(buildChart(), null, 2))
    .then(() => showToast('Copied to clipboard!'))
    .catch(() => showToast('Copy failed — try Download instead'));
});

document.getElementById('btn-copy-admin')?.addEventListener('click', () => {
  const arr = notes.map(n => ({ time: n.time, lane: n.lane }));
  navigator.clipboard.writeText(JSON.stringify(arr))
    .then(() => showToast('Notes array copied — paste into Admin → Songs → Notes (JSON)'))
    .catch(() => showToast('Copy failed'));
});

document.getElementById('btn-download')?.addEventListener('click', () => {
  const chart   = buildChart();
  const json    = JSON.stringify(chart, null, 2);
  const blob    = new Blob([json], { type: 'application/json' });
  const url     = URL.createObjectURL(blob);
  const a       = document.createElement('a');
  a.href        = url;
  a.download    = (chart.title || 'chart').replace(/[^a-z0-9_\- ]/gi, '_') + '.json';
  a.click();
  URL.revokeObjectURL(url);
  showToast('Downloaded!');
});

// ── Persistence ───────────────────────────────────────────────────────────────
const STORAGE_KEY = 'drumkit_chart_editor';

function save() {
  const data = {
    title:    titleIn.value,
    artist:   artistIn.value,
    bpm:      bpmIn.value,
    duration: durationIn.value,
    notes,
    latency:  latencyMs,
    savedAt:  Date.now(),
  };
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch {}
  updateNoteCount();
}

function tryRestore() {
  let data;
  try { data = JSON.parse(localStorage.getItem(STORAGE_KEY) || ''); } catch { return; }
  if (!data || !data.notes || !data.notes.length) return;
  restoreBar.style.display = 'flex';
  document.getElementById('restore-info').textContent =
    `Unsaved session: "${data.title || 'Untitled'}" — ${data.notes.length} notes`;

  document.getElementById('btn-restore').addEventListener('click', () => {
    titleIn.value    = data.title    || '';
    artistIn.value   = data.artist   || '';
    bpmIn.value      = data.bpm      || 120;
    durationIn.value = data.duration || 0;
    notes            = data.notes    || [];
    latencyMs        = data.latency  || 0;
    latencySlider.value = latencyMs;
    latencyVal.textContent = (latencyMs >= 0 ? '+' : '') + latencyMs + 'ms';
    selection.clear();
    updateNoteCount();
    restoreBar.style.display = 'none';
    drawTimeline();
    showToast('Session restored');
  }, { once: true });

  document.getElementById('btn-discard').addEventListener('click', () => {
    localStorage.removeItem(STORAGE_KEY);
    restoreBar.style.display = 'none';
  }, { once: true });
}

// ── Toast ─────────────────────────────────────────────────────────────────────
let toastTimer;
function showToast(msg) {
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2000);
}

// ── MIDI Input ───────────────────────────────────────────────────────────────
// Default General-MIDI note number → pad index in PADS array. Used as a starting
// point; the user can remap any note to any pad via the "Map Pads" panel.
const DEFAULT_MIDI_MAP = {
  35:0, 36:0,           // Kick
  38:1, 40:1,           // Snare
  42:2, 44:2, 46:2,    // Hi-Hat
  50:3,                 // Hi Tom 1
  48:4,                 // Hi Tom 2
  43:5, 41:5,           // Floor Tom
  49:6, 57:6, 52:6, 55:6, // Crash
  51:7, 53:7, 59:7,    // Ride
};
const MIDI_MAP_KEY = 'drumkit_midi_map';

// Active note→pad map (persisted separately from the chart). midiLearnTarget holds
// the pad index currently waiting for a note while the user assigns it.
let midiMap         = loadMidiMap();
let midiLearnTarget = null;

function loadMidiMap() {
  try {
    const saved = JSON.parse(localStorage.getItem(MIDI_MAP_KEY) || 'null');
    if (saved && typeof saved === 'object') return saved;
  } catch {}
  return { ...DEFAULT_MIDI_MAP };
}
function saveMidiMap() {
  try { localStorage.setItem(MIDI_MAP_KEY, JSON.stringify(midiMap)); } catch {}
}

const midiDevSel  = document.getElementById('midi-device-sel');
const midiStatus  = document.getElementById('midi-status');
const midiFlash   = document.getElementById('midi-hit-flash');
let midiAccess    = null;
let midiPort      = null;
let flashTimer    = null;

async function initMidi() {
  if (!navigator.requestMIDIAccess) {
    midiStatus.textContent = 'Web MIDI not supported (use Chrome/Edge)';
    midiStatus.className = 'midi-status error';
    return;
  }
  try {
    midiAccess = await navigator.requestMIDIAccess();
    populateMidiDevices();
    midiAccess.onstatechange = populateMidiDevices;
  } catch (e) {
    midiStatus.textContent = 'MIDI access denied';
    midiStatus.className = 'midi-status error';
  }
}

function populateMidiDevices() {
  const inputs = [...midiAccess.inputs.values()];
  const prev   = midiDevSel.value;
  midiDevSel.innerHTML = '<option value="">— Select device —</option>';
  inputs.forEach(input => {
    const opt = document.createElement('option');
    opt.value = input.id;
    opt.textContent = input.name;
    midiDevSel.appendChild(opt);
  });
  // Re-select previous if still present
  if (prev && [...midiDevSel.options].some(o => o.value === prev)) {
    midiDevSel.value = prev;
    connectMidi(prev);
  }
  if (!inputs.length) {
    midiStatus.textContent = 'No MIDI devices found';
    midiStatus.className = 'midi-status';
  }
}

function connectMidi(id) {
  // Disconnect old port
  if (midiPort) { midiPort.onmidimessage = null; midiPort = null; }
  if (!id || !midiAccess) return;

  midiPort = midiAccess.inputs.get(id);
  if (!midiPort) return;

  midiPort.onmidimessage = onMidiMessage;
  midiStatus.textContent = '● ' + midiPort.name;
  midiStatus.className = 'midi-status connected';
}

function onMidiMessage(e) {
  const [status, note, velocity] = e.data;
  const isNoteOn = (status & 0xf0) === 0x90 && velocity > 0;
  if (!isNoteOn) return;

  showLastNote(note);

  // Assignment mode: bind this note to the pad being learned, don't record a hit.
  if (midiLearnTarget !== null) {
    midiMap[note] = midiLearnTarget;
    midiLearnTarget = null;
    saveMidiMap();
    refreshMidiUI();
    showToast(`Assigned note ${note} → ${PADS[midiMap[note]].name}`);
    return;
  }

  const padIdx = midiMap[note];
  if (padIdx === undefined) return;
  hitPad(padIdx);
  flashMidi();
}

function flashMidi() {
  midiFlash.classList.add('on');
  clearTimeout(flashTimer);
  flashTimer = setTimeout(() => midiFlash.classList.remove('on'), 80);
}

let lastNoteTimer = null;
function showLastNote(note) {
  const el = document.getElementById('midi-last-note');
  if (!el) return;
  el.textContent = 'last note: ' + note;
  el.classList.add('on');
  clearTimeout(lastNoteTimer);
  lastNoteTimer = setTimeout(() => el.classList.remove('on'), 1500);
}

// ── Pad-mapping UI ────────────────────────────────────────────────────────────
function notesForPad(padIdx) {
  return Object.keys(midiMap)
    .filter(n => midiMap[n] === padIdx)
    .map(Number).sort((a, b) => a - b);
}

function buildMidiUI() {
  // Small note label appended under each big play-pad
  document.querySelectorAll('.pad').forEach((el, i) => {
    const span = document.createElement('span');
    span.className = 'pad-midi';
    span.dataset.pad = i;
    el.appendChild(span);
  });

  // Per-pad assignment rows in the panel
  const grid = document.getElementById('midi-map-grid');
  grid.innerHTML = PADS.map((p, i) => `
    <div class="midi-map-row" data-pad="${i}">
      <span class="mm-name" style="color:${p.color}">${p.name}</span>
      <span class="mm-note" id="mm-note-${i}">—</span>
      <button class="mm-assign" data-pad="${i}">Assign</button>
    </div>`).join('');

  grid.querySelectorAll('.mm-assign').forEach(btn =>
    btn.addEventListener('click', () => startLearn(parseInt(btn.dataset.pad, 10)))
  );

  refreshMidiUI();
}

function refreshMidiUI() {
  PADS.forEach((p, i) => {
    const notes = notesForPad(i);
    const cell  = document.getElementById('mm-note-' + i);
    if (cell) cell.textContent = notes.length ? notes.join(', ') : '—';
    const label = document.querySelector(`.pad-midi[data-pad="${i}"]`);
    if (label) label.innerHTML = notes.length ? '<i class="ti ti-usb"></i> ' + notes.join(',') : '';
  });
  // Clear any listening visuals
  document.querySelectorAll('.mm-assign').forEach(b => {
    b.classList.remove('listening');
    b.textContent = 'Assign';
  });
  document.querySelectorAll('.midi-map-row').forEach(r => r.classList.remove('listening'));
}

function startLearn(padIdx) {
  midiLearnTarget = padIdx;
  refreshMidiUI();
  const btn = document.querySelector(`.mm-assign[data-pad="${padIdx}"]`);
  if (btn) { btn.classList.add('listening'); btn.textContent = 'Hit pad…'; }
  const row = btn && btn.closest('.midi-map-row');
  if (row) row.classList.add('listening');
  showToast(`Strike the pad for ${PADS[padIdx].name} on your kit…`);
}

function cancelLearn() {
  if (midiLearnTarget === null) return;
  midiLearnTarget = null;
  refreshMidiUI();
  showToast('Assignment cancelled');
}

document.getElementById('btn-map-pads').addEventListener('click', () => {
  const panel = document.getElementById('midi-map-panel');
  panel.style.display = panel.style.display === 'none' ? '' : 'none';
});

const soundBtn = document.getElementById('btn-sound');
function refreshSoundBtn() {
  soundBtn.innerHTML = soundEnabled ? '<i class="ti ti-volume"></i> Pad sounds' : '<i class="ti ti-volume-off"></i> Pad sounds';
  soundBtn.classList.toggle('off', !soundEnabled);
}
soundBtn.addEventListener('click', () => {
  soundEnabled = !soundEnabled;
  localStorage.setItem(SOUND_KEY, soundEnabled ? '1' : '0');
  if (soundEnabled) ensureAudio();   // unlock AudioContext on this user gesture
  refreshSoundBtn();
});
refreshSoundBtn();

const playbackBtn = document.getElementById('btn-playback');
function refreshPlaybackBtn() {
  playbackBtn.classList.toggle('on', playbackEnabled);
}
playbackBtn.addEventListener('click', () => {
  playbackEnabled = !playbackEnabled;
  localStorage.setItem(PLAYBACK_KEY, playbackEnabled ? '1' : '0');
  if (playbackEnabled) ensureSongRouting();         // route song through context for sync
  scheduledUpToMs = curTimeSec() * 1000;     // avoid burst when toggled mid-play
  refreshPlaybackBtn();
  showToast(playbackEnabled ? 'Chart playback ON — hear your recorded notes' : 'Chart playback off');
});
refreshPlaybackBtn();

document.getElementById('btn-midi-reset').addEventListener('click', () => {
  midiMap = { ...DEFAULT_MIDI_MAP };
  midiLearnTarget = null;
  saveMidiMap();
  refreshMidiUI();
  showToast('MIDI map reset to default');
});

midiDevSel.addEventListener('change', () => connectMidi(midiDevSel.value));

buildMidiUI();
initMidi();

// ── BPM Detection ────────────────────────────────────────────────────────────
async function detectBpm(file) {
  const arrayBuf = await file.arrayBuffer();
  const audioCtx = new AudioContext();
  const audioBuf = await audioCtx.decodeAudioData(arrayBuf);
  await audioCtx.close();

  const sr      = audioBuf.sampleRate;
  const hopSize = Math.floor(sr * 0.01); // 10ms frames

  // Low-pass filter to isolate kick-range energy
  const offCtx = new OfflineAudioContext(1, audioBuf.length, sr);
  const mono   = offCtx.createBuffer(1, audioBuf.length, sr);
  const dat    = mono.getChannelData(0);
  for (let ch = 0; ch < audioBuf.numberOfChannels; ch++) {
    const src = audioBuf.getChannelData(ch);
    for (let i = 0; i < audioBuf.length; i++) dat[i] += src[i] / audioBuf.numberOfChannels;
  }
  const bufSrc = offCtx.createBufferSource();
  bufSrc.buffer = mono;
  const lpf = offCtx.createBiquadFilter();
  lpf.type = 'lowpass'; lpf.frequency.value = 150;
  bufSrc.connect(lpf); lpf.connect(offCtx.destination); bufSrc.start();
  const filtered = (await offCtx.startRendering()).getChannelData(0);

  // RMS energy envelope (first 30s max)
  const maxFrames = Math.min(Math.floor(filtered.length / hopSize), 3000);
  const energy = new Float32Array(maxFrames);
  for (let i = 0; i < maxFrames; i++) {
    let sum = 0;
    const offset = i * hopSize;
    for (let j = 0; j < hopSize; j++) sum += filtered[offset + j] ** 2;
    energy[i] = Math.sqrt(sum / hopSize);
  }

  // Autocorrelation over BPM range 60–200 (10ms frames → 6000/bpm frames/beat)
  const minP = Math.floor(6000 / 200);
  const maxP = Math.ceil(6000 / 60);
  let bestBpm = 120, bestScore = -1;
  for (let period = minP; period <= maxP; period++) {
    let corr = 0;
    for (let i = 0; i + period < maxFrames; i++) corr += energy[i] * energy[i + period];
    corr /= (maxFrames - period);
    if (corr > bestScore) { bestScore = corr; bestBpm = Math.round(6000 / period); }
  }

  // Snap to common BPM and prefer typical song range (70–160)
  const common = [60,70,72,74,75,76,78,80,84,85,88,90,92,95,96,100,102,104,108,110,112,115,120,124,126,128,130,132,138,140,144,150,160,170,180,200];
  const candidates = [bestBpm, bestBpm * 2, Math.round(bestBpm / 2)];
  const snapped = candidates.map(b => common.reduce((a, c) => Math.abs(c - b) < Math.abs(a - b) ? c : a));
  const inRange = snapped.filter(b => b >= 70 && b <= 160);
  return inRange.length ? inRange[0] : snapped[0];
}

// ── Init ──────────────────────────────────────────────────────────────────────
syncZoomUI();
drawTimeline();
tryRestore();
updateTimeDisplay();

// ── Save to Library (members-site admin integration) ──────────────────────────
// The site CSP forbids inline scripts/handlers, so the "Load Audio" button wires
// up here instead of an inline onclick, and CSRF comes from a data element.
document.getElementById('btn-load-audio')?.addEventListener('click', () => fileInput.click());
document.getElementById('btn-load-youtube')?.addEventListener('click', loadYouTubeIntoEditor);

const ceCfg     = document.getElementById('ce-config');
const CE_CSRF   = ceCfg ? (ceCfg.dataset.csrf || '') : '';
let savedSongId = ceCfg ? (ceCfg.dataset.songId || '') : ''; // set after first save → later saves update the same row

// Charting a paid song request: prefill from it and mark the save exclusive +
// linked so the buyer(s) get access (see song-editor.php ?request=ID).
let fromRequestId = ceCfg ? (ceCfg.dataset.requestId || '') : '';
if (fromRequestId) {
  if (!titleIn.value && ceCfg.dataset.reqTitle) titleIn.value = ceCfg.dataset.reqTitle;
  const ytIn = document.getElementById('song-youtube');
  if (ytIn && ceCfg.dataset.reqYoutube) ytIn.value = ceCfg.dataset.reqYoutube;
}

const saveBtn      = document.getElementById('btn-save-library');
const saveStatus   = document.getElementById('save-status');
const loadAudioBtn = document.getElementById('btn-load-audio');
const audioCurrent = document.getElementById('audio-current');

// Reflect a newly-picked file so the admin sees it will replace the stored track
// on save. (A second 'change' listener — the editor's own handler still runs.)
fileInput.addEventListener('change', () => {
  const f = fileInput.files[0];
  if (f && audioCurrent) {
    audioCurrent.textContent = `New: ${f.name} — replaces the saved track on save`;
    audioCurrent.classList.add('replace');
  }
});
function setSaveStatus(msg, cls = '') {
  if (saveStatus) { saveStatus.textContent = msg; saveStatus.className = 'ce-save-status' + (cls ? ' ' + cls : ''); }
}

saveBtn?.addEventListener('click', async () => {
  const chart = buildChart();
  if (!chart.notes.length)                    { setSaveStatus('Add some drum hits before saving.', 'err'); return; }
  if (!chart.title || chart.title === 'Untitled') { setSaveStatus('Enter a song title first.', 'err'); return; }
  // A new song needs *some* audio source: an uploaded track OR a YouTube link
  // (YouTube-backed/exclusive songs have no mp3). Updating an existing one keeps
  // whatever's stored if nothing new is provided.
  const hasYouTube = !!(document.getElementById('song-youtube')?.value || '').trim();
  if (!loadedFile && !savedSongId && !hasYouTube) {
    setSaveStatus('Add an audio file or a YouTube link so the song has music.', 'err');
    return;
  }

  setSaveStatus('Saving…');
  saveBtn.disabled = true;

  const fd = new FormData();
  if (savedSongId) fd.append('id', savedSongId);
  fd.append('title',    chart.title);
  fd.append('artist',   chart.artist === 'Unknown' ? '' : chart.artist);
  fd.append('category', document.getElementById('song-category')?.value || 'kit');
  fd.append('youtube_url', document.getElementById('song-youtube')?.value || '');
  document.querySelectorAll('.song-tag-cb:checked').forEach(cb => fd.append('tag_ids[]', cb.value));
  if (fromRequestId) { fd.append('request_id', fromRequestId); fd.append('visibility', 'exclusive'); }
  fd.append('bpm',      chart.bpm);
  fd.append('duration', chart.duration);
  fd.append('notes',    JSON.stringify(chart.notes));
  if (loadedFile) fd.append('audio', loadedFile);
  fd.append('csrf_token', CE_CSRF);

  try {
    const res  = await fetch('/api/song-save.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { setSaveStatus('Error: ' + (data.error || res.status), 'err'); return; }
    savedSongId = String(data.id);
    setSaveStatus(`Saved to library (song #${data.id}). Saving again updates it.`, 'ok');
    showToast('Saved to library!');
  } catch (err) {
    setSaveStatus('Network error: ' + err.message, 'err');
  } finally {
    saveBtn.disabled = false;
  }
});

// ── Edit an existing song ─────────────────────────────────────────────────────
// When song-editor.php was opened with ?song=ID it fills the ce-config data-*
// attributes; load that song so the admin can edit and re-save it (savedSongId is
// already set from data-song-id, so Save updates the existing row).
if (ceCfg && ceCfg.dataset.songId && ceCfg.dataset.title !== undefined) {
  const d = ceCfg.dataset;
  titleIn.value    = d.title    || '';
  artistIn.value   = d.artist   || '';
  bpmIn.value      = d.bpm      || 120;
  durationIn.value = d.duration || 0;
  const catSel = document.getElementById('song-category');
  if (catSel && (d.category === 'kit' || d.category === 'pad')) catSel.value = d.category;
  const ytIn = document.getElementById('song-youtube');
  if (ytIn && d.youtube) ytIn.value = d.youtube;

  try { notes = JSON.parse(d.notes || '[]'); } catch { notes = []; }
  notes = notes.filter(n => n && Number.isFinite(+n.time) && Number.isFinite(+n.lane))
               .map(n => ({ time: Math.round(+n.time), lane: Math.trunc(+n.lane) }))
               .sort((a, b) => a.time - b.time);

  // The editor has pads for 8 of the 10 engine lanes (no mid-tom / 18" crash).
  // Notes on those lanes stay in the chart and are re-saved, but can't be shown
  // or edited here — warn so the admin isn't surprised.
  const shownLanes = new Set(PADS.map(p => p.lane));
  const hidden = notes.filter(n => !shownLanes.has(n.lane)).length;

  selection.clear();
  restoreBar.style.display = 'none';   // editing a specific song overrides the autosave prompt

  if (d.audioUrl) {
    if (loadAudioBtn) loadAudioBtn.innerHTML = '<i class="ti ti-repeat"></i> Replace Audio';
    if (audioCurrent) {
      audioCurrent.textContent = `Current track: ${d.audioName || '(stored)'} — kept unless you load a replacement`;
      audioCurrent.classList.remove('replace');
    }
    audioEl.src = d.audioUrl;
    audioEl.load();
    audioEl.onloadedmetadata = () => {
      audioReady = true;
      seekBar.max = (Number.isFinite(audioEl.duration) && audioEl.duration > 0)
        ? audioEl.duration
        : (parseFloat(durationIn.value) || 0) / 1000;
      clampScroll();
      syncZoomUI();
      drawTimeline();
    };
  }

  updateNoteCount();
  syncZoomUI();
  drawTimeline();
  showToast(`Editing "${d.title || 'song'}"${hidden ? ` — ${hidden} note(s) on mid-tom/18" crash are preserved but not editable here` : ''}`);
}
