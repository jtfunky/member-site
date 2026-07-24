// Practice-Pad Placement Test — a standardized SINGLE-PAD timing exercise. All
// notes sit in one column; with a practice pad any hit registers against the
// nearest note, so this measures pure rhythm/timing. Steady 8th notes with a
// couple of 16th figures, so accuracy/consistency are comparable across students.
const PAD = 1;              // one column (lane is irrelevant for pad scoring)

const BPM  = 80;
const BEAT = 60000 / BPM;   // 750ms per quarter
const BARS = 16;

function note(time) { return { time: Math.round(time), lane: PAD }; }

// Base pattern: steady 8th notes (0,0.5,…,3.5). Every 4th bar adds two 16th
// figures on beats 2 and 4 for a light challenge, kept identical each time.
const EIGHTHS   = [0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5];
const SIXTEENTH = [1.25, 3.25];   // the extra 'e/a' hits on the busier bars

const notes = [];
for (let m = 0; m < BARS; m++) {
  const s = m * 4 * BEAT;
  for (const b of EIGHTHS) notes.push(note(s + b * BEAT));
  if (m % 4 === 3) for (const b of SIXTEENTH) notes.push(note(s + b * BEAT));
}
notes.sort((a, b) => a.time - b.time);

export const song = {
  title: 'Practice-Pad Placement',
  artist: 'MS Drums',
  bpm: BPM,
  duration: Math.round(BARS * 4 * BEAT + 2000),
  metronome: true,
  category: 'pad',
  padPlacement: true,
  notes,
};
