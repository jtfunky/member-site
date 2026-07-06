/**
 * Acoustic drum detection via microphone — with per-kit calibration.
 *
 * The student "teaches" each drum by hitting it a few times; we store a
 * loudness-normalized spectral fingerprint per lane (in localStorage). During
 * play, every onset is matched to the nearest learned fingerprint and that lane
 * fires. There is NO generic fallback — only calibrated drums are detected.
 */

let audioCtx    = null;
let analyser    = null;
let stream      = null;
let active      = false;              // is the analysis loop running
let mode        = 'idle';             // 'idle' | 'play' | 'calibrate'
let hitCallback = null;
let animId      = null;

// ── Fingerprint: log-spaced spectral bands, L2-normalized (shape, not loudness).
const N_BANDS = 32;
const F_MIN   = 40;
const F_MAX   = 16000;

// Calibrated profiles: { [lane]: Float32Array(N_BANDS) } (unit vectors).
let profiles = loadProfiles();

// ── Onset detection (global energy spike).
let avgEnergy  = 0;
let lastEnergy = 0;
let cooldown   = 0;
let sensitivity = 1.5;
const COOLDOWN_FRAMES = 10;   // ~160ms between hits
const SMOOTH          = 0.9;
const NOISE_FLOOR     = 0.003;

// ── Calibration capture state.
let calLane      = null;
let calCaptures  = [];
let calOnCapture = null;
let calOnDone    = null;
const CAL_NEEDED = 4;         // hits captured & averaged per drum

const STORAGE_KEY = 'zada_acoustic_cal_v1';
const MIN_SIM     = 0.6;      // min cosine similarity to accept a match

// ── Persistence ───────────────────────────────────────────────────────────────
function loadProfiles() {
  try {
    const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    const out = {};
    for (const k of Object.keys(raw)) out[k] = Float32Array.from(raw[k]);
    return out;
  } catch { return {}; }
}
function saveProfiles() {
  try {
    const raw = {};
    for (const k of Object.keys(profiles)) raw[k] = Array.from(profiles[k]);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(raw));
  } catch { /* storage full / disabled — session-only */ }
}

export function getCalibratedLanes()   { return Object.keys(profiles).map(Number); }
export function isLaneCalibrated(lane) { return !!profiles[lane]; }
export function clearLane(lane)        { delete profiles[lane]; saveProfiles(); }
export function clearAllCalibration()  { profiles = {}; saveProfiles(); }

// ── DSP helpers ───────────────────────────────────────────────────────────────
let bandBins = null;
function computeBandBins(sampleRate, binCount) {
  const nyquist = sampleRate / 2;
  const edges = [];
  for (let i = 0; i <= N_BANDS; i++) {
    const f = F_MIN * Math.pow(F_MAX / F_MIN, i / N_BANDS);
    edges.push(Math.min(binCount - 1, Math.round((f / nyquist) * binCount)));
  }
  const bins = [];
  for (let i = 0; i < N_BANDS; i++) bins.push([edges[i], Math.max(edges[i], edges[i + 1])]);
  return bins;
}

function featureVector(freqData, sampleRate) {
  if (!bandBins) bandBins = computeBandBins(sampleRate, freqData.length);
  const v = new Float32Array(N_BANDS);
  let norm = 0;
  for (let i = 0; i < N_BANDS; i++) {
    const [lo, hi] = bandBins[i];
    let e = 0;
    for (let b = lo; b <= hi; b++) { const x = freqData[b] / 255; e += x * x; }
    v[i] = e / Math.max(1, hi - lo + 1);
    norm += v[i] * v[i];
  }
  norm = Math.sqrt(norm) || 1;
  for (let i = 0; i < N_BANDS; i++) v[i] /= norm;
  return v;
}

function totalEnergy(freqData) {
  let e = 0;
  for (let i = 0; i < freqData.length; i++) { const x = freqData[i] / 255; e += x * x; }
  return e / freqData.length;
}

function cosine(a, b) {          // both unit-normalized → dot == cosine similarity
  let dot = 0;
  for (let i = 0; i < a.length; i++) dot += a[i] * b[i];
  return dot;
}

// ── Mic / graph setup ─────────────────────────────────────────────────────────
async function ensureMic() {
  if (stream && audioCtx) {
    if (audioCtx.state === 'suspended') { try { await audioCtx.resume(); } catch (e) {} }
    return;
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
  } catch (e) {
    throw new Error('Microphone access denied. Please allow microphone access and try again.');
  }
  audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  // Mobile browsers (esp. iOS Safari) start the context suspended; without this
  // the analyser reads silence and no hits are ever detected.
  if (audioCtx.state === 'suspended') { try { await audioCtx.resume(); } catch (e) {} }
  analyser = audioCtx.createAnalyser();
  analyser.fftSize = 2048;
  analyser.smoothingTimeConstant = 0;
  audioCtx.createMediaStreamSource(stream).connect(analyser);
  bandBins = null;
}

function resetOnset() { avgEnergy = 0; lastEnergy = 0; cooldown = 0; }
function ensureLoop() { if (!animId) loop(); }

// ── Public API ────────────────────────────────────────────────────────────────
export async function startAcousticDetection(onHit) {
  hitCallback = onHit;
  await ensureMic();
  mode = 'play';
  active = true;
  resetOnset();
  ensureLoop();
  return true;
}

// Single practice pad: onHit() fires (no lane) on every detected hit. The game
// maps it to the nearest note in any lane. No calibration needed.
export async function startPadDetection(onHit) {
  hitCallback = onHit;
  await ensureMic();
  mode = 'pad';
  active = true;
  resetOnset();
  ensureLoop();
  return true;
}

// Teach one lane: captures CAL_NEEDED hits, averages them into a fingerprint.
// onCapture(count, needed) fires per hit; onDone(lane) when saved.
export async function startCalibration(lane, onCapture, onDone) {
  await ensureMic();
  calLane      = lane;
  calCaptures  = [];
  calOnCapture = onCapture || null;
  calOnDone    = onDone || null;
  mode   = 'calibrate';
  active = true;
  resetOnset();
  ensureLoop();
}

export function cancelCalibration() {
  calLane = null; calCaptures = [];
  if (mode === 'calibrate') mode = 'play';
}

export function stopAcousticDetection() {
  active = false;
  mode = 'idle';
  cancelAnimationFrame(animId);
  animId = null;
  if (stream)   { stream.getTracks().forEach(t => t.stop()); stream = null; }
  if (audioCtx) { audioCtx.close(); audioCtx = null; }
  analyser = null;
  bandBins = null;
  resetOnset();
}

export function setAcousticSensitivity(value) {
  sensitivity = 2.5 - ((value - 1) / 9) * 1.4;   // slider 1..10 → 2.5..1.1
}
export function isAcousticActive() { return active; }

// ── Analysis loop ─────────────────────────────────────────────────────────────
function loop() {
  if (!active) { animId = null; return; }
  animId = requestAnimationFrame(loop);

  const freqData = new Uint8Array(analyser.frequencyBinCount);
  analyser.getByteFrequencyData(freqData);

  const energy = totalEnergy(freqData);
  avgEnergy = SMOOTH * avgEnergy + (1 - SMOOTH) * energy;

  let onset = false;
  if (cooldown > 0) {
    cooldown--;
  } else if (energy > avgEnergy * sensitivity && energy > lastEnergy && avgEnergy > NOISE_FLOOR) {
    onset = true;
    cooldown = COOLDOWN_FRAMES;
  }
  lastEnergy = energy;
  if (!onset) return;

  // Single-pad mode: any onset is a hit (the game maps it to the nearest note in
  // any lane). No drum classification / fingerprint needed.
  if (mode === 'pad') { if (hitCallback) hitCallback(); return; }

  const fp = featureVector(freqData, audioCtx.sampleRate);

  if (mode === 'calibrate') {
    calCaptures.push(fp);
    if (calOnCapture) calOnCapture(calCaptures.length, CAL_NEEDED);
    if (calCaptures.length >= CAL_NEEDED) {
      const avg = new Float32Array(N_BANDS);
      for (const c of calCaptures) for (let i = 0; i < N_BANDS; i++) avg[i] += c[i];
      let norm = 0;
      for (let i = 0; i < N_BANDS; i++) norm += avg[i] * avg[i];
      norm = Math.sqrt(norm) || 1;
      for (let i = 0; i < N_BANDS; i++) avg[i] /= norm;

      profiles[calLane] = avg;
      saveProfiles();
      const done = calLane;
      calLane = null; calCaptures = [];
      mode = 'play';
      if (calOnDone) calOnDone(done);
    }
    return;
  }

  // Play: classify the onset to the nearest calibrated drum.
  const lanes = Object.keys(profiles);
  if (!lanes.length) return;
  let bestLane = -1, bestSim = -1;
  for (const k of lanes) {
    const sim = cosine(fp, profiles[k]);
    if (sim > bestSim) { bestSim = sim; bestLane = +k; }
  }
  if (bestLane >= 0 && bestSim >= MIN_SIM && hitCallback) hitCallback(bestLane);
}
