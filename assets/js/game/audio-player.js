// Audio player — loads from URL (no base64 needed)
// Uses Web Audio API for precise sync with game time

import { getSharedCtx, getRecordDestination, getMasterGain } from './audio-context.js?v=20260727';

let source      = null;
let audioBuffer = null;
let startOffset = 0;
let startedAt   = 0;
let paused      = false;
let pausedAt    = 0;

function getCtx() {
  return getSharedCtx();
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

// Load a track the user picked from their own computer (a File from an <input
// type=file>). Decoded and played entirely in the browser — nothing is uploaded
// or stored on the server. Same buffer path as loadAudioUrl, so playback stays
// sample-accurately synced to the chart.
export async function loadAudioFile(file) {
  if (!file) return false;
  unloadAudio();
  try {
    const ac  = getCtx();
    const buf = await file.arrayBuffer();
    audioBuffer = await ac.decodeAudioData(buf);
    return true;
  } catch (e) {
    console.warn('DrumKit: local audio decode failed:', e.message);
    audioBuffer = null;
    return false;
  }
}

let playRate = 1;   // practice speed (1 / 0.75 / 0.5); buffer position advances at this rate

export function playAudio(offsetMs = 0, rate = 1) {
  if (!audioBuffer) return;
  const ac = getCtx();
  if (ac.state === 'suspended') ac.resume();

  stopAudio();
  source        = ac.createBufferSource();
  source.buffer = audioBuffer;
  playRate      = rate > 0 ? rate : 1;
  source.playbackRate.value = playRate;   // slows audio (pitch drops with it)
  source.connect(getMasterGain());
  source.connect(getRecordDestination());

  startOffset = offsetMs / 1000;
  startedAt   = ac.currentTime;
  source.start(0, startOffset);
  paused = false;
}

export function pauseAudio() {
  if (!source || paused) return;
  const ac = getCtx();
  // Buffer position advances at playRate × real time.
  pausedAt = (ac.currentTime - startedAt) * playRate + startOffset;
  stopAudio();
  paused = true;
}

export function resumeAudio() {
  if (!paused) return;
  playAudio(pausedAt * 1000, playRate);
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
