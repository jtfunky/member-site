// Tutorial — a short, single-drum walkthrough shown before a student's first
// placement test. Five segments (kick, snare, hi-hat, crash, tom), each just
// a handful of evenly-spaced hits on ONE lane at a slow, comfortable tempo.
// Pure familiarization: not scored, not saved anywhere, no pass/fail.
// Lanes (canonical engine map): 0=Kick 1=Snare 2=HiHat 6=FloorTom 7=16"Crash.
const KICK = 0, SNARE = 1, HIHAT = 2, FLOOR_TOM = 6, CRASH_16 = 7;

const BPM  = 70;
const BEAT = 60000 / BPM;
const HITS = 12; // notes per segment — enough reps to actually build a feel for it

function note(time, lane) { return { time: Math.round(time), lane }; }

function segmentSong(lane, title) {
  const notes = [];
  for (let i = 0; i < HITS; i++) notes.push(note(i * BEAT, lane));
  return {
    title,
    artist: 'Tutorial',
    bpm: BPM,
    duration: Math.round((HITS - 1) * BEAT + 2500),
    metronome: true, // steady click through the whole segment
    notes,
  };
}

export const segments = [
  {
    name: 'Kick',
    lane: KICK,
    instruction: 'This is your KICK — the low, booming drum you play with your foot pedal (or the bottom pad on a practice pad). Give it a few solid hits.',
    song: segmentSong(KICK, 'Tutorial — Kick'),
  },
  {
    name: 'Snare',
    lane: SNARE,
    instruction: "This is your SNARE — the sharp, crisp drum right in front of you. It's the backbone of most beats.",
    song: segmentSong(SNARE, 'Tutorial — Snare'),
  },
  {
    name: 'Hi-Hat',
    lane: HIHAT,
    instruction: 'This is your HI-HAT — the pair of cymbals usually to your left. Great for keeping steady time.',
    song: segmentSong(HIHAT, 'Tutorial — Hi-Hat'),
  },
  {
    name: '16" Crash',
    lane: CRASH_16,
    instruction: 'This is your CRASH cymbal — a big, loud accent used to punctuate the music.',
    song: segmentSong(CRASH_16, 'Tutorial — Crash'),
  },
  {
    name: 'Floor Tom',
    lane: FLOOR_TOM,
    instruction: 'This is your FLOOR TOM — the deep, low tom near your knee. Often used in fills.',
    song: segmentSong(FLOOR_TOM, 'Tutorial — Floor Tom'),
  },
];

// Synthetic "notes" covering every lane taught in the tutorial — passed to
// setSongLanes() once at tutorial start so the whole basic set stays on
// screen for all five segments, instead of narrowing to one lane each time.
export const basicSetNotes = segments.map(seg => ({ lane: seg.lane }));
