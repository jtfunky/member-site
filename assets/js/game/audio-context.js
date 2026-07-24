// Shared AudioContext for the whole game — audio.js (hit/metronome SFX) and
// audio-player.js (hosted backing tracks) both use this instead of their own
// private context, so a single MediaStreamAudioDestinationNode here can tap
// everything at once for gameplay recording (recorder.js). With nothing
// recording, the extra connection to that destination node is inaudible and
// effectively free.
let ctx = null;
let recordDest = null;

export function getSharedCtx() {
  // A numeric latencyHint of 0 asks for the smallest buffer the hardware will
  // allow (vs. the 'interactive' preset, which targets "small" but leaves the
  // browser some room) — without it, some browsers default to a larger buffer
  // sized for smooth background/streaming playback, which adds real, audible
  // delay between a hit and the sound reaching the speakers. This can't be
  // scheduled around like the metronome's beats can (a hit happens with zero
  // advance notice, there's nothing to schedule ahead of), so shrinking the
  // buffer itself is the only lever available for real-time-triggered sound.
  if (!ctx) {
    ctx = new (window.AudioContext || window.webkitAudioContext)({ latencyHint: 0 });
    window.__gameAudioCtx = ctx; // kept for console diagnostics
  }
  return ctx;
}

export function getRecordDestination() {
  if (!recordDest) recordDest = getSharedCtx().createMediaStreamDestination();
  return recordDest;
}
