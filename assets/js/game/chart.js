let songData   = null;
let cursor     = 0;
const activeNotes = []; // notes currently on-screen

export function loadSong(song) {
  songData = song;
  cursor   = 0;
  activeNotes.length = 0;
}

export function getSongData() { return songData; }

export function updateChart(songPosition, lookAheadMs) {
  if (!songData) return;
  while (cursor < songData.notes.length &&
         songData.notes[cursor].time <= songPosition + lookAheadMs) {
    activeNotes.push({ ...songData.notes[cursor], hit: false, missed: false });
    cursor++;
  }
}

export function getActiveNotes() { return activeNotes; }

export function removeNote(index) {
  activeNotes.splice(index, 1);
}

export function reset() {
  cursor = 0;
  activeNotes.length = 0;
}

// Find closest unhit note in a lane for hit detection
export function findClosestNote(lane, songPosition, windowMs) {
  let bestIdx  = -1;
  let bestDiff = Infinity;
  for (let i = 0; i < activeNotes.length; i++) {
    const n = activeNotes[i];
    if (n.lane !== lane || n.hit || n.missed) continue;
    const diff = Math.abs(n.time - songPosition);
    if (diff < bestDiff && diff <= windowMs) {
      bestDiff = diff;
      bestIdx  = i;
    }
  }
  return { index: bestIdx, diff: bestDiff };
}

// Mark notes as missed if they're past the expire window
export function expireMissed(songPosition, expireMs) {
  const missedIdx   = [];
  const missedLanes = [];
  for (let i = 0; i < activeNotes.length; i++) {
    const n = activeNotes[i];
    if (!n.hit && !n.missed && songPosition - n.time > expireMs) {
      n.missed = true;
      missedIdx.push(i);
      missedLanes.push(n.lane);
    }
  }
  // Remove expired notes (iterate in reverse to keep indices valid)
  for (let i = missedIdx.length - 1; i >= 0; i--) {
    activeNotes.splice(missedIdx[i], 1);
  }
  return missedLanes; // lanes of the notes that just expired
}
