import { getLaneColors, getLaneNames } from './input.js';

const LANE_COLORS  = getLaneColors();
const LANE_NAMES   = getLaneNames();
const LANE_COUNT   = LANE_COLORS.length;   // derived — no magic number
const HIT_ZONE_PCT = 0.82;

let canvas, ctx;
let W, H, dpr;
let laneW, hitZoneY, highwayTop;
let NOTE_RADIUS = 16; // recalculated on resize
let noteBarW = 60, noteBarH = 12; // rectangular note + target bars — recalculated on resize

// Active visual effects
const flashEffects = []; // { lane, alpha, startTime }
const judgmentTexts = []; // { text, x, y, alpha, color, startTime }

export function initRenderer(canvasEl) {
  canvas = canvasEl;
  ctx    = canvas.getContext('2d');
  resize();
  window.addEventListener('resize', resize);
}

export function resize() {
  dpr    = window.devicePixelRatio || 1;
  W      = window.innerWidth;
  H      = window.innerHeight;
  canvas.width  = W * dpr;
  canvas.height = H * dpr;
  canvas.style.width  = W + 'px';
  canvas.style.height = H + 'px';
  ctx.scale(dpr, dpr);

  laneW       = W / LANE_COUNT;
  hitZoneY    = H * HIT_ZONE_PCT;
  highwayTop  = 56;
  NOTE_RADIUS = Math.max(10, Math.min(18, Math.floor(laneW * 0.32)));
  noteBarW    = laneW * 0.66;
  noteBarH    = Math.max(8, Math.min(15, Math.round(laneW * 0.16)));
}

export function addFlash(lane) {
  // Remove existing flash for this lane and replace
  const idx = flashEffects.findIndex(f => f.lane === lane);
  if (idx !== -1) flashEffects.splice(idx, 1);
  flashEffects.push({ lane, alpha: 0.7, startTime: performance.now() });
}

export function addJudgment(text, lane) {
  const colors = { PERFECT: '#facc15', GOOD: '#86efac', MISS: '#f87171' };
  const x = lane * laneW + laneW / 2;
  judgmentTexts.push({
    text,
    x,
    y: hitZoneY - 40,
    alpha: 1,
    color: colors[text] || '#fff',
    startTime: performance.now(),
  });
}

export function drawFrame(activeNotes, songPosition, scrollTimeWindow, gameState) {
  ctx.clearRect(0, 0, W, H);

  drawBackground();
  drawLaneHeaders();
  updateAndDrawFlashes();
  drawHitZone();
  drawNotes(activeNotes, songPosition, scrollTimeWindow);
  updateAndDrawJudgments();
}

function drawBackground() {
  ctx.fillStyle = '#0f0f1a';
  ctx.fillRect(0, 0, W, H);

  // Lane dividers
  ctx.strokeStyle = 'rgba(255,255,255,0.08)';
  ctx.lineWidth = 1;
  for (let i = 1; i < LANE_COUNT; i++) {
    ctx.beginPath();
    ctx.moveTo(i * laneW, highwayTop);
    ctx.lineTo(i * laneW, H);
    ctx.stroke();
  }

  // Highway gradient overlay
  const grad = ctx.createLinearGradient(0, highwayTop, 0, hitZoneY);
  grad.addColorStop(0,   'rgba(0,0,0,0.6)');
  grad.addColorStop(0.7, 'rgba(0,0,0,0)');
  ctx.fillStyle = grad;
  ctx.fillRect(0, highwayTop, W, hitZoneY - highwayTop);
}

function drawLaneHeaders() {
  for (let i = 0; i < LANE_COUNT; i++) {
    const cx = i * laneW + laneW / 2;
    const color = LANE_COLORS[i];

    // Colored bar at top per lane
    ctx.fillStyle = color + '33'; // 20% alpha
    ctx.fillRect(i * laneW, 0, laneW, highwayTop);

    // Lane name label — scale font so it fits narrower lanes
    const fontSize = Math.max(8, Math.min(11, Math.floor(laneW / 9)));
    ctx.fillStyle    = color;
    ctx.font         = `bold ${fontSize}px monospace`;
    ctx.textAlign    = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(LANE_NAMES[i], cx, highwayTop / 2);
  }
}

function updateAndDrawFlashes() {
  const now     = performance.now();
  const FADE_MS = 200;
  for (let i = flashEffects.length - 1; i >= 0; i--) {
    const f   = flashEffects[i];
    const age = now - f.startTime;
    f.alpha   = Math.max(0, 0.7 * (1 - age / FADE_MS));
    if (f.alpha <= 0) { flashEffects.splice(i, 1); continue; }

    const color = LANE_COLORS[f.lane];
    ctx.fillStyle = hexToRgba(color, f.alpha);
    ctx.fillRect(f.lane * laneW, hitZoneY - 60, laneW, 120);
  }
}

function drawHitZone() {
  // Main hit zone bar
  const grad = ctx.createLinearGradient(0, hitZoneY - 3, 0, hitZoneY + 3);
  grad.addColorStop(0, 'rgba(255,255,255,0.9)');
  grad.addColorStop(1, 'rgba(255,255,255,0.3)');
  ctx.fillStyle = grad;
  ctx.fillRect(0, hitZoneY - 2, W, 4);

  // Glow effect
  ctx.shadowColor = 'rgba(255,255,255,0.8)';
  ctx.shadowBlur  = 12;
  ctx.fillRect(0, hitZoneY - 1, W, 2);
  ctx.shadowBlur = 0;

  // Lane target bars at the hit zone
  const r = Math.min(5, noteBarH / 2);
  for (let i = 0; i < LANE_COUNT; i++) {
    const cx = i * laneW + laneW / 2;
    roundRectPath(cx - noteBarW / 2, hitZoneY - noteBarH / 2, noteBarW, noteBarH, r);
    ctx.strokeStyle = LANE_COLORS[i] + '88';
    ctx.lineWidth   = 2;
    ctx.stroke();
  }
}

function drawNotes(activeNotes, songPosition, scrollTimeWindow) {
  if (!activeNotes) return;

  const highwayH = hitZoneY - highwayTop;

  for (const note of activeNotes) {
    if (note.hit || note.missed) continue;

    const frac   = (note.time - songPosition) / scrollTimeWindow;
    const screenY = hitZoneY - frac * highwayH;

    // Only draw if on screen
    if (screenY < highwayTop - noteBarH || screenY > hitZoneY + noteBarH * 2) continue;

    const cx    = note.lane * laneW + laneW / 2;
    const color = LANE_COLORS[note.lane];
    const x = cx - noteBarW / 2;
    const y = screenY - noteBarH / 2;
    const r = Math.min(5, noteBarH / 2);

    // Filled bar with glow
    ctx.shadowColor = color;
    ctx.shadowBlur  = 12;
    ctx.fillStyle   = color;
    roundRectPath(x, y, noteBarW, noteBarH, r);
    ctx.fill();
    ctx.shadowBlur = 0;

    // Thin top highlight
    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    roundRectPath(x + 2, y + 1.5, noteBarW - 4, Math.max(2, noteBarH * 0.28), Math.min(2, r));
    ctx.fill();
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
  ctx.fillStyle = '#0f0f1a';
  ctx.fillRect(0, 0, W, H);
  drawLaneHeaders();
  drawHitZone();

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

  ctx.fillStyle = 'rgba(15,15,26,0.92)';
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

// Build a rounded-rectangle path (native roundRect where available, else manual).
function roundRectPath(x, y, w, h, r) {
  ctx.beginPath();
  if (typeof ctx.roundRect === 'function') { ctx.roundRect(x, y, w, h, r); return; }
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}
