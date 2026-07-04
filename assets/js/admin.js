/* Admin song management */

// CSRF token injected by PHP into the page
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const form       = document.getElementById('song-form');
const saveBtn    = document.getElementById('dk-save-btn');
const statusEl   = document.getElementById('dk-status');
const editorTitle = document.getElementById('editor-title');

function setStatus(msg, type = 'info') {
  statusEl.textContent = msg;
  statusEl.className = 'status-msg status-' + type;
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const id       = document.getElementById('dk-edit-id').value;
  const title    = document.getElementById('dk-title').value.trim();
  const artist   = document.getElementById('dk-artist').value.trim();
  const bpm      = document.getElementById('dk-bpm').value;
  const duration = document.getElementById('dk-duration').value;
  const notesRaw = document.getElementById('dk-notes').value.trim();
  const audioFile = document.getElementById('dk-audio-file').files[0];

  if (!title) { setStatus('Title is required.', 'error'); return; }

  let notesArr;
  try {
    notesArr = JSON.parse(notesRaw || '[]');
    if (!Array.isArray(notesArr)) throw new Error();
  } catch {
    setStatus('Notes must be a valid JSON array.', 'error');
    return;
  }

  setStatus('Saving…', 'info');
  saveBtn.disabled = true;

  const fd = new FormData();
  if (id) fd.append('id', id);
  fd.append('title',    title);
  fd.append('artist',   artist);
  fd.append('bpm',      bpm);
  fd.append('duration', duration);
  fd.append('notes',    JSON.stringify(notesArr));
  if (audioFile) fd.append('audio', audioFile);

  fd.append('csrf_token', CSRF_TOKEN);

  try {
    const res  = await fetch('/api/song-save.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (!res.ok) {
      setStatus('Error: ' + (data.error || res.status), 'error');
      saveBtn.disabled = false;
      return;
    }

    document.getElementById('dk-edit-id').value = data.id;
    editorTitle.textContent = 'Edit Song';
    setStatus('Song saved (ID ' + data.id + ')!', 'success');

    // Reload page to refresh table
    setTimeout(() => window.location.href = '/admin/songs.php?edit=' + data.id, 800);
  } catch (err) {
    setStatus('Network error: ' + err.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
});

// ── Auto-generate notes when audio file is selected ──────────────────────────
const audioFileInput = document.getElementById('dk-audio-file');
const notesTextarea  = document.getElementById('dk-notes');
const analyzeStatus  = document.createElement('p');
analyzeStatus.className = 'field-hint';
notesTextarea.parentNode.insertBefore(analyzeStatus, notesTextarea.nextSibling);

audioFileInput.addEventListener('change', async () => {
  const file = audioFileInput.files[0];
  if (!file) return;

  // Auto-fill title from filename if empty
  if (!document.getElementById('dk-title').value) {
    document.getElementById('dk-title').value = file.name.replace(/\.[^.]+$/, '');
  }

  analyzeStatus.textContent = '🎵 Detecting BPM…';

  try {
    const arrayBuf = await file.arrayBuffer();
    const audioCtx = new AudioContext();
    const audioBuf = await audioCtx.decodeAudioData(arrayBuf);
    await audioCtx.close();

    // Duration from the decoded buffer — always exact & finite. (Reading
    // <audio>.duration at loadedmetadata returns Infinity for VBR MP3s / blob
    // URLs, which would store a bogus length and desync the game timeline.)
    document.getElementById('dk-duration').value = Math.round(audioBuf.duration * 1000);

    const bpm = await detectBpm(audioBuf);
    document.getElementById('dk-bpm').value = bpm;

    analyzeStatus.textContent = `🎵 BPM detected: ${bpm} — Analyzing drum hits…`;

    // STFT spectral-flux analysis (one FFT pass → per-band onset envelopes)
    const sr   = audioBuf.sampleRate;
    const mono = toMono(audioBuf);
    const flux = await computeBandFlux(mono, sr, ANALYSIS_BANDS, pct => {
      analyzeStatus.textContent = `🎵 BPM ${bpm} — Analyzing spectrum… ${Math.round(pct * 100)}%`;
    });

    const hopSec   = (HOP_MS / 1000);
    const detected = [];
    ANALYSIS_BANDS.forEach((band, bi) => {
      const onsets = pickPeaks(flux[bi], hopSec, AUTO_SENSITIVITY * band.lambda, band.minGap);
      onsets.forEach(time => detected.push({ time, lane: band.lane }));
    });

    detected.sort((a, b) => a.time - b.time);
    notesTextarea.value = JSON.stringify(detected);
    const c = ANALYSIS_BANDS.map(b => detected.filter(n => n.lane === b.lane).length);
    analyzeStatus.textContent = `✅ Auto-detected ${detected.length} notes (${c[0]} kick, ${c[1]} snare, ${c[2]} hi-hat). You can edit the JSON or use the Chart Editor for more control.`;
  } catch (err) {
    analyzeStatus.textContent = '⚠️ Auto-analysis failed: ' + err.message + '. Add notes manually or use the Chart Editor.';
  }
});

async function detectBpm(audioBuf) {
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
  const src = offCtx.createBufferSource();
  src.buffer = mono;
  const lpf = offCtx.createBiquadFilter();
  lpf.type = 'lowpass'; lpf.frequency.value = 150;
  src.connect(lpf); lpf.connect(offCtx.destination); src.start();
  const filtered = (await offCtx.startRendering()).getChannelData(0);

  // RMS energy envelope (first 30s max to keep it fast)
  const maxFrames = Math.min(Math.floor(filtered.length / hopSize), 3000);
  const energy = new Float32Array(maxFrames);
  for (let i = 0; i < maxFrames; i++) {
    let sum = 0;
    const offset = i * hopSize;
    for (let j = 0; j < hopSize; j++) sum += filtered[offset + j] ** 2;
    energy[i] = Math.sqrt(sum / hopSize);
  }

  // Autocorrelation over BPM range 60–200
  // 10ms per frame → framesPerBeat = 6000 / bpm
  const minP = Math.floor(6000 / 200); // fastest = 200 BPM → 30 frames
  const maxP = Math.ceil(6000 / 60);   // slowest =  60 BPM → 100 frames

  let bestBpm = 120, bestScore = -1;
  for (let period = minP; period <= maxP; period++) {
    let corr = 0;
    for (let i = 0; i + period < maxFrames; i++) corr += energy[i] * energy[i + period];
    corr /= (maxFrames - period);
    if (corr > bestScore) { bestScore = corr; bestBpm = Math.round(6000 / period); }
  }

  // Snap to common BPM values and check half/double
  const candidates = [bestBpm, bestBpm * 2, Math.round(bestBpm / 2)];
  const common = [60,70,72,74,75,76,78,80,84,85,88,90,92,95,96,100,102,104,108,110,112,115,120,124,126,128,130,132,138,140,144,150,160,170,180,200];
  const snapped = candidates.map(b => common.reduce((a, c) => Math.abs(c - b) < Math.abs(a - b) ? c : a));
  // Pick the candidate closest to typical song range (70–160)
  const inRange = snapped.filter(b => b >= 70 && b <= 160);
  return inRange.length ? inRange[0] : snapped[0];
}

// ── STFT spectral-flux onset detection ───────────────────────────────────────
// Per-lane frequency bands. Ranges are isolated so cymbals don't trigger snares
// and kick doesn't trigger snare. lambda scales the detection threshold (higher
// = stricter); hats are stricter because high-freq content is noisy.
const ANALYSIS_BANDS = [
  { lane:0, name:'kick',   lo:30,   hi:120,   minGap:100, lambda:1.00 },
  { lane:1, name:'snare',  lo:150,  hi:600,   minGap:90,  lambda:1.10 },
  { lane:2, name:'hi-hat', lo:7000, hi:15000, minGap:55,  lambda:1.30 },
];
const FFT_SIZE        = 2048;
const HOP_MS          = 10;
const AUTO_SENSITIVITY = 1.6;   // fixed default (admin has no slider)

// Mix all channels down to a single mono Float32Array.
function toMono(audioBuf) {
  const len = audioBuf.length;
  const out = new Float32Array(len);
  const chs = audioBuf.numberOfChannels;
  for (let ch = 0; ch < chs; ch++) {
    const src = audioBuf.getChannelData(ch);
    for (let i = 0; i < len; i++) out[i] += src[i] / chs;
  }
  return out;
}

// Iterative radix-2 Cooley–Tukey FFT, in place. re/im length must be a power of 2.
function fft(re, im) {
  const n = re.length;
  for (let i = 1, j = 0; i < n; i++) {
    let bit = n >> 1;
    for (; j & bit; bit >>= 1) j ^= bit;
    j ^= bit;
    if (i < j) {
      let t = re[i]; re[i] = re[j]; re[j] = t;
      t = im[i]; im[i] = im[j]; im[j] = t;
    }
  }
  for (let len = 2; len <= n; len <<= 1) {
    const ang = -2 * Math.PI / len;
    const wr = Math.cos(ang), wi = Math.sin(ang);
    const half = len >> 1;
    for (let i = 0; i < n; i += len) {
      let cr = 1, ci = 0;
      for (let k = 0; k < half; k++) {
        const a = i + k, b = a + half;
        const vr = re[b] * cr - im[b] * ci;
        const vi = re[b] * ci + im[b] * cr;
        re[b] = re[a] - vr; im[b] = im[a] - vi;
        re[a] += vr;        im[a] += vi;
        const ncr = cr * wr - ci * wi;
        ci = cr * wi + ci * wr; cr = ncr;
      }
    }
  }
}

// STFT with a Hann window; returns one positive-spectral-flux envelope per band.
async function computeBandFlux(mono, sr, bands, onProgress) {
  const N    = FFT_SIZE;
  const hop  = Math.max(1, Math.floor(sr * HOP_MS / 1000));
  const bins = N >> 1;
  const binHz = sr / N;

  const win = new Float32Array(N);
  for (let i = 0; i < N; i++) win[i] = 0.5 - 0.5 * Math.cos(2 * Math.PI * i / (N - 1));

  const ranges = bands.map(b => ({
    lo: Math.max(1, Math.floor(b.lo / binHz)),
    hi: Math.min(bins - 1, Math.ceil(b.hi / binHz)),
  }));

  const numFrames = Math.max(0, Math.floor((mono.length - N) / hop));
  const flux      = bands.map(() => new Float32Array(numFrames));

  const re      = new Float32Array(N);
  const im      = new Float32Array(N);
  const prevMag = new Float32Array(bins);
  const curMag  = new Float32Array(bins);

  for (let f = 0; f < numFrames; f++) {
    const start = f * hop;
    for (let i = 0; i < N; i++) { re[i] = mono[start + i] * win[i]; im[i] = 0; }
    fft(re, im);

    for (let k = 0; k < bins; k++) curMag[k] = Math.hypot(re[k], im[k]);

    // Positive spectral flux per band. Skip frame 0 (no previous spectrum).
    if (f > 0) {
      for (let bi = 0; bi < bands.length; bi++) {
        const { lo, hi } = ranges[bi];
        let sum = 0;
        for (let k = lo; k <= hi; k++) {
          const d = curMag[k] - prevMag[k];
          if (d > 0) sum += d;
        }
        flux[bi][f] = sum;
      }
    }
    prevMag.set(curMag);

    if ((f & 2047) === 0) {
      onProgress(numFrames ? f / numFrames : 1);
      await new Promise(r => setTimeout(r));   // yield so UI updates
    }
  }
  onProgress(1);
  return flux;
}

// Adaptive peak picking on a flux envelope. Returns onset times in ms.
function pickPeaks(flux, hopSec, lambda, minGapMs) {
  const n = flux.length;
  if (!n) return [];

  // Normalize by the 95th percentile so lambda/floor are scale-independent
  const sorted = Float32Array.from(flux).sort();
  const ref    = sorted[Math.floor(n * 0.95)] || sorted[n - 1] || 1;
  const norm   = new Float32Array(n);
  for (let i = 0; i < n; i++) norm[i] = flux[i] / ref;

  const W       = 3;      // local-max half-window
  const M       = 12;     // mean half-window (~120ms)
  const FLOOR   = 0.06;   // absolute noise floor
  const minGapF = Math.ceil((minGapMs / 1000) / hopSec);

  const onsets = [];
  let last = -1e9;

  for (let i = W; i < n - W; i++) {
    const v = norm[i];
    if (v < FLOOR) continue;

    let isMax = true;
    for (let k = 1; k <= W; k++) {
      if (v < norm[i - k] || v < norm[i + k]) { isMax = false; break; }
    }
    if (!isMax) continue;

    let sum = 0, cnt = 0;
    for (let k = i - M; k <= i + M; k++) {
      if (k >= 0 && k < n) { sum += norm[k]; cnt++; }
    }
    const thresh = (sum / cnt) * lambda + FLOOR;

    if (v > thresh && i - last >= minGapF) {
      onsets.push(Math.round(i * hopSec * 1000));
      last = i;
    }
  }
  return onsets;
}

// Delete buttons
document.querySelectorAll('.delete-song-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const id    = btn.dataset.id;
    const title = btn.dataset.title;
    if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;

    const form = document.getElementById('delete-form');
    document.getElementById('delete-song-id').value = id;
    form.submit();
  });
});
