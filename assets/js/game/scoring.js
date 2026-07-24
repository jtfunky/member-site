// Two window presets — Normal (default, forgiving) and Precision (an
// opt-in challenge mode for players who want it tight, roughly in line with
// hardcore rhythm games like ITG's tighter judgment tiers). TIMING itself
// stays a single mutable object (rather than swapping which object is
// exported) so every other module's `TIMING.PERFECT` etc. reads always see
// whichever preset is currently active without re-importing anything.
const TIMING_NORMAL    = { PERFECT: 70, GOOD: 170, MISS_EXPIRE: 280 };
const TIMING_PRECISION = { PERFECT: 25, GOOD: 60,  MISS_EXPIRE: 110 };

export const TIMING = { ...TIMING_NORMAL };

export function setPrecisionMode(enabled) {
  Object.assign(TIMING, enabled ? TIMING_PRECISION : TIMING_NORMAL);
}

export const SCORE_VALUES = { PERFECT: 100, GOOD: 50, MISS: 0 };

const COMBO_THRESHOLDS = [
  [50, 4],
  [25, 3],
  [10, 2],
  [0,  1],
];

export function getMultiplier(combo) {
  for (const [threshold, mult] of COMBO_THRESHOLDS) {
    if (combo >= threshold) return mult;
  }
  return 1;
}

export function createScoreState(totalNotes = 0) {
  return {
    score: 0,
    combo: 0,
    maxCombo: 0,
    counts: { PERFECT: 0, GOOD: 0, MISS: 0 },
    totalNotes,
    // Per-play timing detail for post-play analysis (AI coaching).
    offsets: [],   // signed ms per judged hit: − = early (rushing), + = late (dragging)
    lanes: {},     // per-lane: { perfect, good, miss, offsetSum, offsetCount }
  };
}

// Lazily create (and return) the per-lane accumulator for `lane`, or null if
// no lane was supplied (keeps judgeHit/judgeMiss usable without lane data).
function laneBucket(state, lane) {
  if (lane === null || lane === undefined) return null;
  if (!state.lanes[lane]) {
    state.lanes[lane] = { perfect: 0, good: 0, miss: 0, offsetSum: 0, offsetCount: 0 };
  }
  return state.lanes[lane];
}

// `diff` is the absolute timing error (drives the judgment). `signedDiff` and
// `lane` are optional and only feed the post-play analysis — omitting them
// preserves the original scoring behaviour.
export function judgeHit(state, diff, signedDiff = null, lane = null) {
  if (diff > TIMING.GOOD) return null; // outside window entirely — a miss

  const judgment = diff <= TIMING.PERFECT ? 'PERFECT' : 'GOOD';
  // Lenient on whether a hit counts (the full GOOD window still registers,
  // keeps the combo alive, no punishing misses) but strict on what it's
  // worth — points fall off with the CUBE of how far off you were, not
  // linearly, so only genuine precision scores well. A hit halfway across
  // the window scores ~12 points, not ~70 like a straight-line falloff would.
  const t      = diff / TIMING.GOOD; // 0 (dead-on) .. 1 (edge of window)
  const points = Math.round(100 * Math.pow(1 - t, 3));

  const mult   = getMultiplier(state.combo);
  state.score += points * mult;
  state.combo += 1;
  if (state.combo > state.maxCombo) state.maxCombo = state.combo;
  state.counts[judgment]++;

  const bucket = laneBucket(state, lane);
  if (bucket) bucket[judgment === 'PERFECT' ? 'perfect' : 'good']++;
  if (signedDiff !== null && Number.isFinite(signedDiff)) {
    state.offsets.push(signedDiff);
    if (bucket) { bucket.offsetSum += signedDiff; bucket.offsetCount++; }
  }
  return judgment;
}

export function judgeMiss(state, lane = null) {
  state.combo = 0;
  state.counts.MISS++;
  const bucket = laneBucket(state, lane);
  if (bucket) bucket.miss++;
  return 'MISS';
}

export function calcGrade(state) {
  const maxPossible = state.totalNotes * SCORE_VALUES.PERFECT * 4; // max multiplier
  if (maxPossible === 0) return 'S';
  const pct = state.score / maxPossible;
  if (pct >= 0.95) return 'S';
  if (pct >= 0.80) return 'A';
  if (pct >= 0.65) return 'B';
  if (pct >= 0.50) return 'C';
  return 'D';
}

export function calcAccuracy(state) {
  const { PERFECT, GOOD, MISS } = state.counts;
  const total = PERFECT + GOOD + MISS;
  if (total === 0) return 100;
  return ((PERFECT * 100 + GOOD * 50) / (total * 100)) * 100;
}
