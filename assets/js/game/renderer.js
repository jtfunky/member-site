import { getLaneColors, getLaneNames } from './input.js?v=20260720a';
import { DRUM } from './drummer.js?v=20260720b';

const LANE_COLORS  = getLaneColors();
const LANE_NAMES   = getLaneNames();
const HIT_ZONE_PCT = 0.82;

// Kick renders as a full-width bar/line (target, falling notes, and flash)
// instead of a single-column shape — one pedal, not tied to any one lane —
// so it's excluded from the column layout entirely, freeing that space for
// the other lanes rather than reserving a now-unused column for it.
const KICK_LANE = 0;

// Full kit, ordered left-to-right by real position on a top-view kit
// (drummer.js's DRUM map, sorted by x) instead of raw lane-index order. This
// is the master order (kick included, for reference); the *column* order
// (below) excludes kick since it doesn't occupy a column.
const ALL_LANES = Object.keys(DRUM)
  .map(Number)
  .sort((a, b) => DRUM[a].x - DRUM[b].x);

let KIT_COLUMN_ORDER = ALL_LANES.filter(lane => lane !== KICK_LANE);
let COLUMN_COUNT     = KIT_COLUMN_ORDER.length;
let LANE_TO_COLUMN   = {};
KIT_COLUMN_ORDER.forEach((lane, col) => { LANE_TO_COLUMN[lane] = col; });

// Whether this song uses the kick at all — its full-width bar is skipped
// entirely (not just hidden/dimmed) when it isn't, same as any other unused lane.
let kickActive = true;

// Tutorial mode: dim every lane except this one, instead of hiding the rest
// of the kit — keeps the whole set on screen so students see where the
// current piece sits relative to everything else. null = no dimming.
let highlightLane = null;

export function setHighlightLane(lane) {
  highlightLane = (lane === null || lane === undefined) ? null : lane;
}

// Narrow the kit down to only the lanes a song's chart actually uses, so
// unused drums/cymbals don't sit on screen taking up space. Falls back to
// the full kit if the chart is empty/missing. Call before a song starts.
export function setSongLanes(notes) {
  const used = notes && notes.length ? new Set(notes.map(n => n.lane)) : null;
  const columnLanes = ALL_LANES.filter(lane => lane !== KICK_LANE);
  const next = used ? columnLanes.filter(lane => used.has(lane)) : columnLanes;
  KIT_COLUMN_ORDER = next.length ? next : columnLanes;
  COLUMN_COUNT      = KIT_COLUMN_ORDER.length;
  LANE_TO_COLUMN    = {};
  KIT_COLUMN_ORDER.forEach((lane, col) => { LANE_TO_COLUMN[lane] = col; });
  kickActive        = !used || used.has(KICK_LANE);
  resize(); // recompute colW etc. against the new COLUMN_COUNT
}

// Target/note circle size per lane is scaled from DRUM's real proportions
// (kick biggest/centered, toms/snare mid-sized, cymbals varying) rather than
// one shared bar size — computed against colW, so it stays responsive.
const KIT_RADIUS_DIVISOR = 65;

let canvas, ctx;
let W, H, dpr;
let colW, hitZoneY, highwayTop;

// Active visual effects
const flashEffects = []; // { lane, startTime }
const judgmentTexts = []; // { text, x, y, alpha, color, startTime }

export function initRenderer(canvasEl) {
  canvas = canvasEl;
  ctx    = canvas.getContext('2d');
  resize();
  window.addEventListener('resize', resize);
}

export function resize() {
  // Measure the canvas's own rendered box (its CSS flex-basis, 70% of the
  // gameplay body) rather than the full window — the video side panel takes
  // the other 30%. Measured before mutating width/height below.
  const rect = canvas.getBoundingClientRect();
  // The canvas is 0×0 while #screen-game is display:none (not the active
  // screen yet), and can momentarily read 0 during a fullscreen/orientation
  // transition. Ignore those — keep whatever size was last valid instead of
  // collapsing the canvas, since nothing re-triggers resize() to fix it up.
  if (rect.width === 0 || rect.height === 0) return;

  dpr    = window.devicePixelRatio || 1;
  W      = rect.width;
  H      = rect.height;
  canvas.width  = W * dpr;
  canvas.height = H * dpr;
  canvas.style.width  = W + 'px';
  canvas.style.height = H + 'px';
  ctx.scale(dpr, dpr);

  colW        = W / COLUMN_COUNT;
  hitZoneY    = H * HIT_ZONE_PCT;
  highwayTop  = 56; // single per-lane header row — kick no longer has its own strip
}

function laneColumnX(lane) {
  return LANE_TO_COLUMN[lane] * colW + colW / 2;
}

// Canvas-local pixel position of a lane's hit-zone target — lets callers
// outside the canvas (e.g. the tutorial instruction banner, an HTML overlay)
// anchor themselves next to the lane/hit area instead of a fixed spot.
export function getLaneScreenPos(lane) {
  const col = LANE_TO_COLUMN[lane];
  if (col === undefined) return { x: W / 2, y: hitZoneY };
  return { x: col * colW + colW / 2, y: hitZoneY };
}

// Column edges (not just center) plus the hit-zone target's y and radius, so
// a caller can sit an overlay beside the lane and clear its target circle
// instead of centered over it.
export function getLaneBounds(lane) {
  const col = LANE_TO_COLUMN[lane];
  if (col === undefined) return { left: 0, right: W, y: hitZoneY, r: 40 };
  return { left: col * colW, right: (col + 1) * colW, y: hitZoneY, r: targetRadius(lane) };
}

// Absolute cap independent of colW — when very few lanes are active (e.g. a
// single-drum tutorial segment), colW can be almost the full canvas width, and
// scaling purely off colW would balloon the circle to fill the screen.
const MAX_TARGET_RADIUS = 70;

function targetRadius(lane) {
  const r = colW * (DRUM[lane].r / KIT_RADIUS_DIVISOR);
  return Math.max(8, Math.min(colW * 0.44, MAX_TARGET_RADIUS, r));
}

export function addFlash(lane) {
  // Remove existing flash for this lane and replace
  const idx = flashEffects.findIndex(f => f.lane === lane);
  if (idx !== -1) flashEffects.splice(idx, 1);
  flashEffects.push({ lane, startTime: performance.now() });
}

export function addJudgment(text, lane) {
  const colors = { PERFECT: '#facc15', GOOD: '#86efac', MISS: '#f87171' };
  // Kick has no column of its own (full-width bar, like the flash effect
  // above) — laneColumnX() only knows about columned lanes, so it'd return
  // NaN and the text would silently fail to draw.
  judgmentTexts.push({
    text,
    x: lane === KICK_LANE ? W / 2 : laneColumnX(lane),
    y: hitZoneY - 40,
    alpha: 1,
    color: colors[text] || '#fff',
    startTime: performance.now(),
  });
}

export function drawFrame(activeNotes, songPosition, scrollTimeWindow, gameState) {
  ctx.clearRect(0, 0, W, H);

  drawBackground();
  drawActiveLaneHighlight();
  drawLaneHeaders();
  updateAndDrawFlashes();
  drawKitZone();
  drawNotes(activeNotes, songPosition, scrollTimeWindow);
  updateAndDrawJudgments();
}

function drawBackground() {
  ctx.fillStyle = '#0e0e17';
  ctx.fillRect(0, 0, W, H);

  // Lane dividers
  ctx.strokeStyle = 'rgba(255,255,255,0.08)';
  ctx.lineWidth = 1;
  for (let i = 1; i < COLUMN_COUNT; i++) {
    ctx.beginPath();
    ctx.moveTo(i * colW, highwayTop);
    ctx.lineTo(i * colW, H);
    ctx.stroke();
  }

  // Highway gradient overlay
  const grad = ctx.createLinearGradient(0, highwayTop, 0, hitZoneY);
  grad.addColorStop(0,   'rgba(0,0,0,0.6)');
  grad.addColorStop(0.7, 'rgba(0,0,0,0)');
  ctx.fillStyle = grad;
  ctx.fillRect(0, highwayTop, W, hitZoneY - highwayTop);
}

// Colored accent rails flanking the active lane's column, top to bottom —
// makes the highlighted lane read as "active" at a glance, not just less dim.
function drawActiveLaneHighlight() {
  if (highlightLane === null) return;
  const col = LANE_TO_COLUMN[highlightLane];
  if (col === undefined) return;

  const color = LANE_COLORS[highlightLane];
  const barW  = 5;
  const xLeft  = col * colW;
  const xRight = (col + 1) * colW - barW;

  ctx.save();
  ctx.shadowColor = color;
  ctx.shadowBlur  = 14;
  ctx.fillStyle   = color;
  ctx.fillRect(xLeft, 0, barW, H);
  ctx.fillRect(xRight, 0, barW, H);
  ctx.restore();
}

function drawLaneHeaders() {
  for (let col = 0; col < COLUMN_COUNT; col++) {
    const lane  = KIT_COLUMN_ORDER[col];
    const color = LANE_COLORS[lane];
    const dim   = highlightLane !== null && lane !== highlightLane;

    ctx.globalAlpha = dim ? 0.25 : 1;

    ctx.fillStyle = color + '33'; // 20% alpha
    ctx.fillRect(col * colW, 0, colW, highwayTop);

    // Lane name label — scale font so it fits narrower lanes
    const fontSize = Math.max(8, Math.min(11, Math.floor(colW / 9)));
    ctx.fillStyle    = color;
    ctx.font         = `bold ${fontSize}px monospace`;
    ctx.textAlign    = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(LANE_NAMES[lane], col * colW + colW / 2, highwayTop / 2);
  }
  ctx.globalAlpha = 1;
}

function updateAndDrawFlashes() {
  const now     = performance.now();
  const FADE_MS = 260;
  for (let i = flashEffects.length - 1; i >= 0; i--) {
    const f   = flashEffects[i];
    const age = now - f.startTime;
    const t   = age / FADE_MS;
    if (t >= 1) { flashEffects.splice(i, 1); continue; }

    const color = LANE_COLORS[f.lane];
    const r     = targetRadius(f.lane) * (1 + 0.25 * (1 - t)); // brief swell, settles back

    ctx.beginPath();
    if (f.lane === KICK_LANE) {
      const barH = r * 1.3;
      ctx.rect(0, hitZoneY - barH / 2, W, barH);
    } else {
      ctx.arc(laneColumnX(f.lane), hitZoneY, r, 0, Math.PI * 2);
    }
    ctx.fillStyle = hexToRgba(color, 0.6 * (1 - t));
    ctx.fill();
  }
}

function drawKitZone() {
  // Subtle timing reference line — dimmer than a bright bar so the kit
  // graphic itself reads as the dominant visual, not a highway rail.
  ctx.strokeStyle = 'rgba(255,255,255,0.15)';
  ctx.lineWidth   = 1;
  ctx.beginPath();
  ctx.moveTo(0, hitZoneY);
  ctx.lineTo(W, hitZoneY);
  ctx.stroke();

  // Kick target: a full-width line spanning every lane (matches a real kick
  // pedal — one hit, not tied to any column) instead of a per-column shape.
  // Drawn once, outside the column loop, since it no longer occupies a column.
  if (kickActive) {
    const kickColor = LANE_COLORS[KICK_LANE];
    const kickDim   = highlightLane !== null && KICK_LANE !== highlightLane;
    ctx.globalAlpha = kickDim ? 0.25 : 1;
    ctx.strokeStyle = kickColor;
    ctx.lineWidth   = 6;
    ctx.beginPath();
    ctx.moveTo(0, hitZoneY);
    ctx.lineTo(W, hitZoneY);
    ctx.stroke();
    ctx.globalAlpha = 1;
  }

  // Kit targets, left to right in real-kit order — cymbals as metallic rings,
  // drums as filled heads with a colored rim.
  for (let col = 0; col < COLUMN_COUNT; col++) {
    const lane  = KIT_COLUMN_ORDER[col];
    const cx    = col * colW + colW / 2;
    const r     = targetRadius(lane);
    const color = LANE_COLORS[lane];
    const dim   = highlightLane !== null && lane !== highlightLane;

    ctx.globalAlpha = dim ? 0.25 : 1;

    if (DRUM[lane].cym) {
      ctx.beginPath();
      ctx.arc(cx, hitZoneY, r, 0, Math.PI * 2);
      ctx.strokeStyle = color + 'aa';
      ctx.lineWidth   = 3;
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(cx, hitZoneY, r * 0.6, 0, Math.PI * 2);
      ctx.strokeStyle = color + '55';
      ctx.lineWidth   = 1.5;
      ctx.stroke();
    } else {
      ctx.beginPath();
      ctx.arc(cx, hitZoneY, r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(255,255,255,0.06)';
      ctx.fill();
      ctx.strokeStyle = color + '99';
      ctx.lineWidth   = 3;
      ctx.stroke();
    }
  }
  ctx.globalAlpha = 1;
}

function drawNotes(activeNotes, songPosition, scrollTimeWindow) {
  if (!activeNotes) return;

  const highwayH = hitZoneY - highwayTop;

  for (const note of activeNotes) {
    if (note.hit || note.missed) continue;

    const frac    = (note.time - songPosition) / scrollTimeWindow;
    const screenY = hitZoneY - frac * highwayH;
    const r       = targetRadius(note.lane); // same size as its hit target

    // Only draw if on screen
    if (screenY < highwayTop - r || screenY > hitZoneY + r * 2) continue;

    const color = LANE_COLORS[note.lane];

    if (note.lane === KICK_LANE) {
      // A plain full-width line, not a capsule — kick doesn't sit in a
      // column, so there's no "shape" to fit, just a beat marker crossing
      // the whole highway.
      ctx.beginPath();
      ctx.moveTo(0, screenY);
      ctx.lineTo(W, screenY);
      ctx.strokeStyle = color;
      ctx.lineWidth   = 6;
      ctx.stroke();
      continue;
    }

    // Capsule (stadium shape) instead of a plain circle — wider than tall,
    // fully rounded ends via a corner radius equal to half the height.
    // Still unfilled, just a thin stroke.
    const cx   = laneColumnX(note.lane);
    const capW = r * 2.2;
    const capH = r * 0.7 * 0.75; // 25% thinner
    ctx.beginPath();
    ctx.roundRect(cx - capW / 2, screenY - capH / 2, capW, capH, capH / 2);
    ctx.strokeStyle = color;
    ctx.lineWidth   = 6;
    ctx.stroke();
  }
}

function updateAndDrawJudgments() {
  const now     = performance.now();
  const FADE_MS = 700;
  const RISE_PX = 60;

  ctx.textAlign    = 'center';
  ctx.textBaseline = 'middle';

  for (let i = judgmentTexts.length - 1; i >= 0; i--) {
    const j   = judgmentTexts[i];
    const age = now - j.startTime;
    const t   = age / FADE_MS;
    if (t >= 1) { judgmentTexts.splice(i, 1); continue; }

    const alpha = 1 - t;
    const y     = j.y - t * RISE_PX;

    ctx.globalAlpha = alpha;
    ctx.font        = 'bold 16px monospace';
    ctx.fillStyle   = j.color;

    // Shadow for readability
    ctx.shadowColor = 'rgba(0,0,0,0.8)';
    ctx.shadowBlur  = 6;
    ctx.fillText(j.text, j.x, y);
    ctx.shadowBlur  = 0;
  }
  ctx.globalAlpha = 1;
}

// Draws a countdown number centered on screen
export function drawCountdown(n) {
  ctx.clearRect(0, 0, W, H);
  ctx.fillStyle = '#0e0e17';
  ctx.fillRect(0, 0, W, H);
  drawLaneHeaders();
  drawKitZone();

  ctx.textAlign    = 'center';
  ctx.textBaseline = 'middle';
  ctx.font         = 'bold 120px monospace';
  ctx.fillStyle    = '#ffffff';
  ctx.shadowColor  = '#4f46e5';
  ctx.shadowBlur   = 40;
  ctx.fillText(n === 0 ? 'GO!' : String(n), W / 2, H / 2);
  ctx.shadowBlur   = 0;
}

// Draws the results overlay
export function drawResults(scoreState, song) {
  const { calcGrade, calcAccuracy } = scoreState;

  ctx.fillStyle = 'rgba(14,14,23,0.92)';
  ctx.fillRect(0, 0, W, H);

  const cx = W / 2;
  const cy = H / 2;

  ctx.textAlign    = 'center';
  ctx.textBaseline = 'middle';

  // Grade
  const grade  = scoreState.grade;
  const gradeC = { S: '#facc15', A: '#86efac', B: '#60a5fa', C: '#c084fc', D: '#f87171' };
  ctx.font      = 'bold 100px monospace';
  ctx.fillStyle = gradeC[grade] || '#fff';
  ctx.shadowColor = gradeC[grade] || '#fff';
  ctx.shadowBlur  = 30;
  ctx.fillText(grade, cx, cy - 120);
  ctx.shadowBlur  = 0;

  // Song title
  ctx.font      = 'bold 22px sans-serif';
  ctx.fillStyle = '#fff';
  ctx.fillText(scoreState.songTitle || '', cx, cy - 30);

  // Stats
  const lines = [
    `Score: ${scoreState.score.toLocaleString()}`,
    `Accuracy: ${scoreState.accuracy.toFixed(1)}%`,
    `Max Combo: ${scoreState.maxCombo}x`,
    `PERFECT: ${scoreState.counts.PERFECT}  GOOD: ${scoreState.counts.GOOD}  MISS: ${scoreState.counts.MISS}`,
  ];
  ctx.font = '18px monospace';
  lines.forEach((line, i) => {
    ctx.fillStyle = i === 0 ? '#facc15' : '#d1d5db';
    ctx.fillText(line, cx, cy + 30 + i * 36);
  });

  ctx.font      = '14px monospace';
  ctx.fillStyle = '#6b7280';
  ctx.fillText('Press R to play again  ·  ESC to menu', cx, cy + 200);
}

function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}
