export const TIMING = {
  PERFECT: 40,
  GOOD: 100,
  MISS_EXPIRE: 200,
};

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
  let judgment;
  if (diff <= TIMING.PERFECT)      judgment = 'PERFECT';
  else if (diff <= TIMING.GOOD)    judgment = 'GOOD';
  else                             return null; // outside window

  const mult   = getMultiplier(state.combo);
  state.score += SCORE_VALUES[judgment] * mult;
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
