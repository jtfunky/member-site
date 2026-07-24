// GM MIDI drum note → lane mapping
// Lane: 0=Kick, 1=Snare, 2=Hi-Hat, 3=HiTom1, 4=HiTom2, 5=MidTom, 6=FloorTom,
//       7=Crash16, 8=Crash18, 9=Ride22
const DEFAULT_MAP = {
  35: 0, 36: 0,              // Bass Drum 2 + 1 → Kick
  38: 1, 40: 1,              // Acoustic Snare + Electric Snare
  42: 2, 44: 2, 46: 2,      // HH Closed + Pedal + Open
  50: 3,                     // High Tom → Hi Tom 1
  48: 4,                     // Hi-Mid Tom → Hi Tom 2
  47: 5, 45: 5,              // Low-Mid Tom + Low Tom → Mid Tom
  43: 6, 41: 6,              // High Floor Tom + Low Floor Tom
  49: 7,                     // Crash Cymbal 1 → 16" Crash
  57: 8, 52: 8, 55: 8,      // Crash Cymbal 2 + Chinese + Splash → 18" Crash
  51: 9, 53: 9, 59: 9,      // Ride Cymbal 1 + Ride Bell + Ride 2 → 22" Ride
};

let noteMap     = { ...DEFAULT_MAP };
let channelFilter = -1; // -1 = any channel

export function setNoteMap(map) {
  noteMap = { ...DEFAULT_MAP, ...map };
}

export function setChannelFilter(ch) {
  channelFilter = ch;
}

// Filters + maps a raw MIDI event to a lane, synchronously, with nothing
// buffered — a hit used to sit in a queue that only got drained once per
// animation frame (up to ~16ms after the physical hit, since frames run at
// ~60fps), which is audible as the hit sound trailing the real drum. The
// caller processes this the instant it's returned, same as the hardware
// event itself arrived instantly.
export function mapMidiEvent(event) {
  if (channelFilter !== -1 && event.channel !== channelFilter) return null;
  const lane = noteMap[event.note];
  if (lane === undefined) return null;
  return { lane, velocity: event.velocity, timestamp: event.timestamp };
}

export function getDefaultMap() {
  return { ...DEFAULT_MAP };
}

export function getLaneNames() {
  return [
    'KICK',
    'SNARE',
    'HI-HAT',
    'HI TOM 1',
    'HI TOM 2',
    'MID TOM',
    'FLOOR TOM',
    '16" CRASH',
    '18" CRASH',
    '22" RIDE',
  ];
}

export function getLaneColors() {
  return [
    '#ef4444', // Kick        — red
    '#f5f5f5', // Snare       — white
    '#eab308', // Hi-Hat      — yellow
    '#60a5fa', // Hi Tom 1    — light blue
    '#3b82f6', // Hi Tom 2    — blue
    '#22c55e', // Mid Tom     — green
    '#16a34a', // Floor Tom   — dark green
    '#06b6d4', // 16" Crash   — cyan
    '#0891b2', // 18" Crash   — dark cyan
    '#f97316', // 22" Ride    — orange
  ];
}
