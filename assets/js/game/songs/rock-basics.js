// Rock Basics — 90 BPM, ~43s, beginner practice groove.
// A gentler alternative to the Demo Beat: steady 8th-note hi-hats, kick on 1 & 3,
// snare on 2 & 4, a crash at each section, and one simple tom fill per phrase.
// Lanes follow the canonical 10-lane engine map (see input.js getLaneNames):
// 0=Kick 1=Snare 2=HiHat 3=HiTom1 4=HiTom2 5=MidTom 6=FloorTom
// 7=16"Crash 8=18"Crash 9=22"Ride
const KICK = 0, SNARE = 1, HIHAT = 2, TOM1 = 3, TOM2 = 4, FLOOR = 6, CRASH = 7;

const BPM  = 90;
const BEAT = 60000 / BPM;   // ~666.7ms per quarter note

function note(time, lane) { return { time: Math.round(time), lane }; }

// kick/snare/hihat are arrays of beat offsets (0=beat1, 0.5=&, 1=beat2, …).
function beat4(startMs, kick, snare, hihat, extras = []) {
  const notes = [];
  for (const b of kick)  notes.push(note(startMs + b * BEAT, KICK));
  for (const b of snare) notes.push(note(startMs + b * BEAT, SNARE));
  for (const b of hihat) notes.push(note(startMs + b * BEAT, HIHAT));
  for (const [b, lane] of extras) notes.push(note(startMs + b * BEAT, lane));
  return notes;
}

const HH_8TH = [0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5]; // 8th notes across 4 beats
const notes  = [];

// 16 measures of basic rock. Every 4th bar swaps the hi-hats for a tom fill on
// beats 3–4 so beginners practice moving around the kit.
for (let m = 0; m < 16; m++) {
  const s = m * 4 * BEAT;
  const extras = [];

  if (m % 4 === 0) extras.push([0, CRASH]); // crash to mark each 4-bar phrase

  if (m % 4 === 3) {
    // Simple descending tom fill on beats 3 & 4 (hi-hats stop for the fill).
    const fillHats = [0, 0.5, 1, 1.5]; // hats only on beats 1–2
    extras.push([2, TOM1], [2.5, TOM1], [3, TOM2], [3.5, FLOOR]);
    notes.push(...beat4(s, [0, 2], [1], fillHats, extras));
  } else {
    notes.push(...beat4(s, [0, 2], [1, 3], HH_8TH, extras));
  }
}

// Sort by time; drop same-lane collisions within 10ms (keep the first).
notes.sort((a, b) => a.time - b.time || a.lane - b.lane);
const seen = new Set();
const deduped = notes.filter(n => {
  const key = `${n.lane}_${Math.round(n.time / 10)}`;
  if (seen.has(key)) return false;
  seen.add(key);
  return true;
});

export const song = {
  title: 'Rock Basics',
  artist: 'MS Drums',
  bpm: BPM,
  duration: Math.round(16 * 4 * BEAT + 2000), // 16 bars + 2s tail
  notes: deduped,
};
