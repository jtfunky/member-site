// Demo Beat — 120 BPM, 60 seconds, ~500+ notes
// Lanes: 0=Kick, 1=Snare, 2=HiHat, 3=Tom1, 4=Tom2, 5=Tom3, 6=Crash, 7=Ride

const BPM = 120;
const BEAT = 60000 / BPM;   // 500ms
const HALF = BEAT / 2;       // 250ms (8th note)
const QRTR = BEAT / 4;       // 125ms (16th note)

function note(time, lane) { return { time: Math.round(time), lane }; }

function beat4(startMs, kick, snare, hihat, extras = []) {
  // kick/snare/hihat are arrays of beat offsets (0=beat1, 0.5=and, 1=beat2, etc.)
  const notes = [];
  for (const b of kick)   notes.push(note(startMs + b * BEAT, 0));
  for (const b of snare)  notes.push(note(startMs + b * BEAT, 1));
  for (const b of hihat)  notes.push(note(startMs + b * BEAT, 2));
  for (const [b, lane] of extras) notes.push(note(startMs + b * BEAT, lane));
  return notes;
}

const HH_8TH = [0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5]; // 8th notes across 4 beats

const notes = [];

// === SECTION 1: 0–8s, measures 1–4 ===
// Simple 4/4: kick on 1&3, snare on 2&4, hi-hat on 8ths
for (let m = 0; m < 4; m++) {
  const s = m * 4 * BEAT;
  notes.push(...beat4(s, [0, 2], [1, 3], HH_8TH));
}

// === SECTION 2: 8–16s, measures 5–8 ===
// Add crash on bar 1, tom fills on bar 3-4
for (let m = 0; m < 4; m++) {
  const s = 8000 + m * 4 * BEAT;
  const extras = [];
  if (m === 0) extras.push([0, 6]); // crash bar 5
  if (m === 2) {
    // tom fill: T1 T2 T3
    extras.push([2, 3], [2.5, 4], [3, 5], [3.5, 5]);
  }
  if (m === 3) {
    extras.push([0, 3], [0.5, 4], [1, 5], [1.5, 3]);
  }
  notes.push(...beat4(s, [0, 2], [1, 3], HH_8TH, extras));
}

// === SECTION 3: 16–24s, measures 9–12 ===
// Crash on beat 1 of every 2 measures, busier kick pattern
for (let m = 0; m < 4; m++) {
  const s = 16000 + m * 4 * BEAT;
  const extras = [];
  if (m % 2 === 0) extras.push([0, 6]); // crash every 2 bars
  extras.push([1.5, 4], [3.5, 5]); // extra tom accents
  notes.push(...beat4(s, [0, 0.75, 2, 3.5], [1, 3], HH_8TH, extras));
}

// === SECTION 4: 24–32s, measures 13–16 ===
// Ride replaces hi-hat
const RIDE_PATTERN = [0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5];
for (let m = 0; m < 4; m++) {
  const s = 24000 + m * 4 * BEAT;
  const extras = RIDE_PATTERN.map(b => [b, 7]); // ride
  if (m === 0) extras.push([0, 6]); // crash on section start
  extras.push([1.5, 3], [3.5, 4]); // tom accents
  notes.push(...beat4(s, [0, 1.5, 2, 3], [1, 3], [], extras));
}

// === SECTION 5: 32–48s, measures 17–24 ===
// Full mix: hi-hat 16ths, active kick, all pads
const HH_16TH = [0, 0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2, 2.25, 2.5, 2.75, 3, 3.25, 3.5, 3.75];
for (let m = 0; m < 8; m++) {
  const s = 32000 + m * 4 * BEAT;
  const extras = [];
  if (m === 0 || m === 4) extras.push([0, 6]); // crash section markers
  // rotate tom accents
  const tomPairs = [[1.75, 3], [3.75, 4], [1.5, 5], [3.5, 3], [2.75, 4], [0.75, 5], [3.75, 3], [1.75, 5]];
  extras.push(tomPairs[m]);
  if (m % 2 === 1) extras.push([2.5, 4]); // extra tom on off-bars
  notes.push(...beat4(s, [0, 0.5, 2, 2.75], [1, 3], HH_16TH, extras));
}

// === SECTION 6: 48–60s, climax with rapid tom runs ===
for (let m = 0; m < 6; m++) {
  const s = 48000 + m * 4 * BEAT;
  const extras = [];
  if (m === 0) extras.push([0, 6]); // opening crash
  // 16th-note tom runs on beat 4
  if (m < 4) {
    extras.push([3, 3], [3.25, 4], [3.5, 5], [3.75, 3]);
  } else {
    // Final 2 bars: continuous tom rolls
    for (let i = 0; i < 16; i++) extras.push([i * 0.25, 3 + (i % 3)]);
    extras.push([0, 6]); // final crash
  }
  notes.push(...beat4(s, [0, 0.75, 2, 3], [1, 3], HH_16TH, extras));
}

// Sort by time and deduplicate near-collisions (same lane within 10ms → keep first)
notes.sort((a, b) => a.time - b.time || a.lane - b.lane);

const seen = new Set();
const deduped = notes.filter(n => {
  const key = `${n.lane}_${Math.round(n.time / 10)}`;
  if (seen.has(key)) return false;
  seen.add(key);
  return true;
});

export const song = {
  title: 'Demo Beat',
  artist: 'MS Drums',
  bpm: BPM,
  duration: 62000,
  notes: deduped,
};
