// Placement Test — a basic 4/4 rock beat with a metronome, for the standardized
// placement exercise. Kick on 1 & 3, snare on 2 & 4, hi-hat on 8th notes.
// Steady and easy to follow so timing/accuracy are comparable across students.
// Lanes (canonical engine map): 0=Kick 1=Snare 2=HiHat.
const KICK = 0, SNARE = 1, HIHAT = 2;

const BPM  = 80;
const BEAT = 60000 / BPM;   // 750ms per quarter note
const BARS = 16;

function note(time, lane) { return { time: Math.round(time), lane }; }

const HH_8TH = [0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5]; // 8th notes across the bar
const notes  = [];

for (let m = 0; m < BARS; m++) {
  const s = m * 4 * BEAT;
  notes.push(note(s + 0 * BEAT, KICK),  note(s + 2 * BEAT, KICK));   // kick 1 & 3
  notes.push(note(s + 1 * BEAT, SNARE), note(s + 3 * BEAT, SNARE));  // snare 2 & 4
  for (const b of HH_8TH) notes.push(note(s + b * BEAT, HIHAT));     // hi-hat 8ths
}

notes.sort((a, b) => a.time - b.time || a.lane - b.lane);

export const song = {
  title: 'Placement Test',
  artist: 'MS Drums',
  bpm: BPM,
  duration: Math.round(BARS * 4 * BEAT + 2000), // 16 bars + 2s tail
  metronome: true,                              // click on every beat during play
  notes,
};
