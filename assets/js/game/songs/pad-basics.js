// Pad Practice — a single-pad rhythm exercise (category: pad). All notes sit in one
// column; with a practice pad, any hit registers against the nearest note, so this
// trains timing. 4 bars of quarter notes, then 4 bars of 8th notes, at 80 BPM.
const BPM  = 80;
const BEAT = 60000 / BPM;   // 750ms per quarter note
const LANE = 1;             // snare column (lane is irrelevant for pad scoring)

function note(time) { return { time: Math.round(time), lane: LANE }; }

const notes = [];
// Bars 1-4: quarter notes (one hit per beat)
for (let m = 0; m < 4; m++) for (let b = 0; b < 4; b++) notes.push(note((m * 4 + b) * BEAT));
// Bars 5-8: 8th notes (two hits per beat)
const off = 4 * 4 * BEAT;
for (let m = 0; m < 4; m++) for (let b = 0; b < 8; b++) notes.push(note(off + m * 4 * BEAT + b * 0.5 * BEAT));

notes.sort((a, b) => a.time - b.time);

export const song = {
  title: 'Pad Practice',
  artist: 'MS Drums',
  bpm: BPM,
  duration: Math.round(8 * 4 * BEAT + 2000), // 8 bars + 2s tail
  category: 'pad',
  metronome: true,          // steady click helps a timing exercise
  notes,
};
