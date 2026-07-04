/**
 * Acoustic drum detection via microphone.
 * Uses Web Audio API AnalyserNode to detect transients in frequency bands
 * and map them to game lanes.
 *
 * Detection works by tracking energy in each band; a hit is registered when
 * energy spikes above a rolling average (onset detection).
 */

let audioCtx    = null;
let analyser    = null;
let stream      = null;
let active      = false;
let hitCallback = null;
let animId      = null;

// Frequency bands → lane mapping
// Each band: [minHz, maxHz, lane, label]
// Note: acoustic detection is approximate — toms and cymbals overlap in frequency.
const BANDS = [
  [20,    150,  0, 'Kick'],       // lane 0 — Kick (sub-bass thump)
  [150,   450,  1, 'Snare'],      // lane 1 — Snare (crack/body)
  [6000, 10000, 2, 'Hi-Hat'],     // lane 2 — Hi-Hat (tight metallic transient)
  [450,   750,  3, 'Hi Tom 1'],   // lane 3 — Hi Tom 1
  [750,  1200,  4, 'Hi Tom 2'],   // lane 4 — Hi Tom 2
  [1200, 2200,  5, 'Mid Tom'],    // lane 5 — Mid Tom
  [2200, 4500,  6, 'Floor Tom'],  // lane 6 — Floor Tom
  [4500, 7000,  7, '16" Crash'],  // lane 7 — 16" Crash (bright attack)
  [10000,14000, 8, '18" Crash'],  // lane 8 — 18" Crash (slightly darker wash)
  [14000,20000, 9, '22" Ride'],   // lane 9 — 22" Ride (high shimmer)
];

// Per-band state for onset detection
const bandState = BANDS.map(() => ({
  avgEnergy:    0,
  lastEnergy:   0,
  cooldown:     0,   // frames until next hit allowed
}));

// Sensitivity: multiplier over rolling average to count as a hit (lower = more sensitive)
let sensitivity = 1.5;

// Cooldown frames between hits per band (at ~60fps, 12 = 200ms)
const COOLDOWN_FRAMES = 12;

// Smooth factor for rolling average (0=instant, 0.99=very slow)
const SMOOTH = 0.92;

export async function startAcousticDetection(onHit) {
  hitCallback = onHit;

  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
  } catch (e) {
    throw new Error('Microphone access denied. Please allow microphone access and try again.');
  }

  audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  analyser  = audioCtx.createAnalyser();
  analyser.fftSize       = 2048;
  analyser.smoothingTimeConstant = 0;   // we do our own smoothing

  const source = audioCtx.createMediaStreamSource(stream);
  source.connect(analyser);

  active = true;
  loop();
  return true;
}

export function stopAcousticDetection() {
  active = false;
  cancelAnimationFrame(animId);
  if (stream)   { stream.getTracks().forEach(t => t.stop()); stream = null; }
  if (audioCtx) { audioCtx.close(); audioCtx = null; }
  analyser = null;
  bandState.forEach(s => { s.avgEnergy = 0; s.lastEnergy = 0; s.cooldown = 0; });
}

export function setAcousticSensitivity(value) {
  // value 1–10 slider: map to 2.5 (least sensitive) → 1.1 (most sensitive)
  sensitivity = 2.5 - ((value - 1) / 9) * 1.4;
}

export function isAcousticActive() {
  return active;
}

function getBandEnergy(freqData, sampleRate, minHz, maxHz) {
  const nyquist  = sampleRate / 2;
  const binCount = freqData.length;
  const minBin   = Math.floor((minHz / nyquist) * binCount);
  const maxBin   = Math.min(Math.ceil((maxHz / nyquist) * binCount), binCount - 1);

  let energy = 0;
  for (let i = minBin; i <= maxBin; i++) {
    const lin = freqData[i] / 255;  // 0–1
    energy += lin * lin;
  }
  return energy / Math.max(1, maxBin - minBin + 1);
}

function loop() {
  if (!active) return;
  animId = requestAnimationFrame(loop);

  const freqData   = new Uint8Array(analyser.frequencyBinCount);
  analyser.getByteFrequencyData(freqData);
  const sampleRate = audioCtx.sampleRate;

  BANDS.forEach((band, i) => {
    const [minHz, maxHz, lane] = band;
    const state = bandState[i];

    const energy = getBandEnergy(freqData, sampleRate, minHz, maxHz);

    // Update rolling average
    state.avgEnergy = SMOOTH * state.avgEnergy + (1 - SMOOTH) * energy;

    if (state.cooldown > 0) {
      state.cooldown--;
    } else {
      const threshold = state.avgEnergy * sensitivity;
      // Onset: current energy spikes above threshold AND rose from last frame
      if (energy > threshold && energy > state.lastEnergy && state.avgEnergy > 0.005) {
        state.cooldown = COOLDOWN_FRAMES;
        if (hitCallback) hitCallback(lane);
      }
    }

    state.lastEnergy = energy;
  });
}
