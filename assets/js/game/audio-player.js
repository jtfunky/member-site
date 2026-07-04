// Audio player — loads from URL (no base64 needed)
// Uses Web Audio API for precise sync with game time

let ctx         = null;
let source      = null;
let audioBuffer = null;
let startOffset = 0;
let startedAt   = 0;
let paused      = false;
let pausedAt    = 0;

function getCtx() {
  if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
  return ctx;
}

export async function loadAudioUrl(url) {
  if (!url) return false;
  unloadAudio();
  try {
    const ac  = getCtx();
    const res = await fetch(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const buf  = await res.arrayBuffer();
    audioBuffer = await ac.decodeAudioData(buf);
    return true;
  } catch (e) {
    console.warn('DrumKit: audio load failed:', e.message);
    audioBuffer = null;
    return false;
  }
}

export function playAudio(offsetMs = 0) {
  if (!audioBuffer) return;
  const ac = getCtx();
  if (ac.state === 'suspended') ac.resume();

  stopAudio();
  source        = ac.createBufferSource();
  source.buffer = audioBuffer;
  source.connect(ac.destination);

  startOffset = offsetMs / 1000;
  startedAt   = ac.currentTime;
  source.start(0, startOffset);
  paused = false;
}

export function pauseAudio() {
  if (!source || paused) return;
  const ac = getCtx();
  pausedAt = ac.currentTime - startedAt + startOffset;
  stopAudio();
  paused = true;
}

export function resumeAudio() {
  if (!paused) return;
  playAudio(pausedAt * 1000);
}

export function stopAudio() {
  if (!source) return;
  try { source.stop(); } catch {}
  source = null;
}

export function unloadAudio() {
  stopAudio();
  audioBuffer = null;
  paused      = false;
  pausedAt    = 0;
}

export function hasAudio() {
  return audioBuffer !== null;
}

// True length (ms) of the decoded track, or 0 if no audio is loaded.
// Always exact and finite — used as a fallback when a song's stored duration
// is missing or wrong.
export function getAudioDurationMs() {
  return audioBuffer ? audioBuffer.duration * 1000 : 0;
}
