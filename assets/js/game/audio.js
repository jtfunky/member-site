import { getSharedCtx, getRecordDestination, getMasterGain } from './audio-context.js?v=20260727';
import { playDrumSample } from './drum-samples.js?v=20260727';

// atTime: an explicit AudioContext time to start at (defaults to "right now").
// Real recorded hit if that lane's sample has finished loading; otherwise the
// original synthesized click (e.g. the brief window right after page load,
// or if a sample failed to fetch) — always SOME feedback, never silence.
export function playHitClick(lane, atTime) {
  const ac = getSharedCtx();
  const t  = atTime ?? ac.currentTime;

  if (playDrumSample(lane, t)) return;

  try {
    const osc = ac.createOscillator();
    const env = ac.createGain();

    // Frequency varies slightly by lane for tactile feel
    const freqs = [80, 220, 400, 300, 260, 180, 500, 450];
    osc.frequency.value = freqs[lane] ?? 300;
    osc.type = lane === 0 ? 'sine' : 'triangle'; // kick=sine, others=triangle

    env.gain.setValueAtTime(0.25, t);
    env.gain.exponentialRampToValueAtTime(0.001, t + 0.04);

    osc.connect(env);
    env.connect(getMasterGain());
    env.connect(getRecordDestination());
    osc.start(t);
    osc.stop(t + 0.04);
  } catch (_) {}
}

export function playCountdownBeep(high = false) {
  try {
    const ac  = getSharedCtx();
    const osc = ac.createOscillator();
    const env = ac.createGain();
    osc.frequency.value = high ? 1000 : 700;
    env.gain.setValueAtTime(0.3, ac.currentTime);
    env.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.1);
    osc.connect(env);
    env.connect(getMasterGain());
    env.connect(getRecordDestination());
    osc.start(ac.currentTime);
    osc.stop(ac.currentTime + 0.1);
  } catch (_) {}
}

// Short metronome tick; `accent` = the downbeat (beat 1), a bit higher/louder.
export function playMetronomeClick(accent = false) {
  scheduleMetronomeClick(getSharedCtx().currentTime, accent);
}

// Same tick, but scheduled at a precise AudioContext time instead of "now" —
// lets the caller line it up exactly with a beat instead of firing whenever
// the JS/rAF loop happens to notice the beat crossed (up to a frame late,
// which is audible as drift against the falling notes it's meant to match).
export function scheduleMetronomeClick(atTime, accent = false) {
  try {
    const ac  = getSharedCtx();
    const osc = ac.createOscillator();
    const env = ac.createGain();
    osc.type = 'square';
    osc.frequency.value = accent ? 1600 : 1100;
    env.gain.setValueAtTime(accent ? 0.22 : 0.15, atTime);
    env.gain.exponentialRampToValueAtTime(0.0005, atTime + 0.03);
    osc.connect(env);
    env.connect(getMasterGain());
    env.connect(getRecordDestination());
    osc.start(atTime);
    osc.stop(atTime + 0.03);
  } catch (_) {}
}

// Lets callers convert a performance.now()-based timestamp (the clock songPos
// and note-hit judging already run on) into the matching AudioContext time,
// so a click can be scheduled to land exactly on a note's real hit instant.
// Compensates for real audio hardware output latency — scheduling for
// ac.currentTime alone plays the *scheduled* time on the nose, but the sound
// doesn't reach the speakers until outputLatency/baseLatency later, which is
// a fixed (non-drifting) gap that reads as the click always trailing the
// falling block. Scheduling that much earlier cancels it out.
export function audioTimeFor(perfNowMs) {
  const ac = getSharedCtx();
  const outputLatency = ac.outputLatency || ac.baseLatency || 0;
  const target = ac.currentTime + (perfNowMs - performance.now()) / 1000 - outputLatency;
  return Math.max(ac.currentTime, target);
}

export function resumeContext() {
  const ac = getSharedCtx();
  if (ac.state === 'suspended') ac.resume();
}
