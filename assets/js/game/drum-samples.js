import { getSharedCtx, getRecordDestination } from './audio-context.js?v=20260720c';

// Real recorded hits instead of synthesized oscillator clicks. The pack only
// has 7 distinct samples for 10 lanes — Hi Tom 1/2 and Mid Tom share the one
// tom sample at different playback rates (pitch), and 18" Crash reuses the
// 16" Crash sample at a slightly lower rate ("bigger" cymbal). Kick, Snare,
// Hi-Hat, Floor Tom, and Ride all have their own dedicated sample.
const LANE_SAMPLES = [
  { url: 'kick',     rate: 1    }, // 0 Kick
  { url: 'snare',    rate: 1    }, // 1 Snare
  { url: 'hats',     rate: 1    }, // 2 Hi-Hat
  { url: 'hitom',    rate: 1.15 }, // 3 Hi Tom 1
  { url: 'hitom',    rate: 1    }, // 4 Hi Tom 2
  { url: 'hitom',    rate: 0.85 }, // 5 Mid Tom
  { url: 'floortom', rate: 1    }, // 6 Floor Tom
  { url: 'crash16',  rate: 1    }, // 7 16" Crash
  { url: 'crash16',  rate: 0.9  }, // 8 18" Crash
  { url: 'ride',     rate: 1    }, // 9 22" Ride
];

const buffers = new Map(); // sample name -> AudioBuffer (decoded once, reused across lanes)

// Kicked off on script load — decoding doesn't need a running/resumed
// AudioContext (only playback does), so there's no need to wait for the
// first user gesture. Missing/failed samples just leave that entry absent;
// playDrumSample() falls back silently rather than throwing.
export function preloadDrumSamples() {
  const names = [...new Set(LANE_SAMPLES.map(s => s.url))];
  const ac = getSharedCtx();

  names.forEach(name => {
    fetch(`/assets/audio/drums/${name}.mp3`)
      .then(res => res.arrayBuffer())
      .then(data => ac.decodeAudioData(data))
      .then(buf => buffers.set(name, trimLeadingSilence(buf)))
      .catch(() => {}); // stays absent — playDrumSample() falls back to the synth click
  });
}

// MP3 (LAME) encoding pads a short block of silence onto the very start of
// the file as an artifact of the codec's frame windowing — browsers don't
// always strip it perfectly on decode, so the decoded buffer can start with
// a real (if brief) silent gap before the actual hit. That reads as "the
// sound plays late" even though scheduling/output-latency are unrelated to
// it. Fixed once here, on the decoded PCM itself, rather than depending on
// exactly how much padding any particular browser's decoder leaves behind.
// Threshold is relative to the sample's own peak rather than a fixed absolute
// level — a quiet sample and a loud sample both get trimmed right up to their
// actual attack transient instead of one flat number under- or over-trimming
// depending on how the sample happens to be normalized.
function trimLeadingSilence(buffer, relThreshold = 0.06) {
  const data = buffer.getChannelData(0);
  let peak = 0;
  for (let i = 0; i < data.length; i++) { const a = Math.abs(data[i]); if (a > peak) peak = a; }
  const threshold = peak * relThreshold;

  let start = 0;
  while (start < data.length && Math.abs(data[start]) < threshold) start++;
  if (start === 0 || start >= data.length) return buffer;

  const ac = getSharedCtx();
  const trimmed = ac.createBuffer(buffer.numberOfChannels, buffer.length - start, buffer.sampleRate);
  for (let ch = 0; ch < buffer.numberOfChannels; ch++) {
    trimmed.copyToChannel(buffer.getChannelData(ch).subarray(start), ch);
  }
  return trimmed;
}

export function isDrumSampleReady(lane) {
  const sample = LANE_SAMPLES[lane];
  return !!(sample && buffers.has(sample.url));
}

// atTime: an explicit AudioContext time to start at (defaults to "right now").
// Passing a precise scheduled time — the note's own due instant, computed the
// same way the metronome's beat times are — is what lets this play in tight
// sync with the song instead of at the mercy of MIDI/driver/output jitter.
export function playDrumSample(lane, atTime) {
  const sample = LANE_SAMPLES[lane];
  const buffer = sample && buffers.get(sample.url);
  if (!buffer) return false;

  try {
    const ac  = getSharedCtx();
    const src = ac.createBufferSource();
    const env = ac.createGain();
    src.buffer = buffer;
    src.playbackRate.value = sample.rate;
    env.gain.value = 0.8;
    src.connect(env);
    env.connect(ac.destination);
    env.connect(getRecordDestination());
    src.start(atTime ?? ac.currentTime);
    return true;
  } catch (_) {
    return false;
  }
}
