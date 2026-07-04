let ctx = null;

function getCtx() {
  if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
  return ctx;
}

export function playHitClick(lane) {
  try {
    const ac  = getCtx();
    const osc = ac.createOscillator();
    const env = ac.createGain();

    // Frequency varies slightly by lane for tactile feel
    const freqs = [80, 220, 400, 300, 260, 180, 500, 450];
    osc.frequency.value = freqs[lane] ?? 300;
    osc.type = lane === 0 ? 'sine' : 'triangle'; // kick=sine, others=triangle

    env.gain.setValueAtTime(0.25, ac.currentTime);
    env.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.04);

    osc.connect(env);
    env.connect(ac.destination);
    osc.start(ac.currentTime);
    osc.stop(ac.currentTime + 0.04);
  } catch (_) {}
}

export function playCountdownBeep(high = false) {
  try {
    const ac  = getCtx();
    const osc = ac.createOscillator();
    const env = ac.createGain();
    osc.frequency.value = high ? 1000 : 700;
    env.gain.setValueAtTime(0.3, ac.currentTime);
    env.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.1);
    osc.connect(env);
    env.connect(ac.destination);
    osc.start(ac.currentTime);
    osc.stop(ac.currentTime + 0.1);
  } catch (_) {}
}

export function resumeContext() {
  if (ctx && ctx.state === 'suspended') ctx.resume();
}
