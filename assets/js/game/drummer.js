'use strict';
// ── Technique drummer (top-down drum map) ─────────────────────────────────────
// A drawn kit seen from above: drums as real drums (chrome hoop + head + tension
// lugs + a colored ring matching the note colour), cymbals as metallic bronze
// discs with a bell. Each drum FLASHES when its note is hit — no player, no
// sticks; the kit itself shows which drum and when. Pure SVG + Web Animations
// (off-thread), so it never affects game timing.
import { getLaneColors } from './input.js?v=20260720a';

const COLORS = getLaneColors();

// Kit layout (viewBox 0..200; kit seen from above). Exported for reuse by
// renderer.js's in-game hit-zone kit graphic — the canonical top-view kit
// layout lives here, not duplicated.
export const DRUM = {
  0: { x: 100, y: 106, r: 26, cym: false }, // kick
  1: { x: 78,  y: 138, r: 13, cym: false }, // snare
  2: { x: 46,  y: 120, r: 14, cym: true  }, // hi-hat
  3: { x: 86,  y: 76,  r: 12, cym: false }, // hi tom 1
  4: { x: 114, y: 76,  r: 12, cym: false }, // hi tom 2
  5: { x: 136, y: 92,  r: 13, cym: false }, // mid tom
  6: { x: 150, y: 134, r: 16, cym: false }, // floor tom
  7: { x: 56,  y: 54,  r: 15, cym: true  }, // 16" crash
  8: { x: 142, y: 50,  r: 16, cym: true  }, // 18" crash
  9: { x: 170, y: 88,  r: 18, cym: true  }, // ride
};

let container = null, svgEl = null, handed = 'right', visible = false, nextIdx = 0;
let drumsEl = {};

// Left-handed = mirror the kit's x positions (data-level, so nothing renders backwards).
const mx = x => (handed === 'left' ? 200 - x : x);
const n  = v => Number(v.toFixed(1));

function lugs(x, y, r) {
  let s = '';
  for (let i = 0; i < 8; i++) {
    const a = i * Math.PI / 4;
    s += `<circle cx="${n(x + Math.cos(a) * (r + 1))}" cy="${n(y + Math.sin(a) * (r + 1))}" r="1.7" fill="#5b6470"/>`;
  }
  return s;
}

function drumHead(lane, x, y, r, color) {
  return `<g class="dm-drum" data-lane="${lane}" style="transform-box:view-box;transform-origin:${x}px ${y}px">
    <circle cx="${x}" cy="${y}" r="${r + 2.5}" fill="#aab2bd"/>
    <circle cx="${x}" cy="${y}" r="${r + 1}"   fill="#8b939e"/>
    ${lugs(x, y, r)}
    <circle cx="${x}" cy="${y}" r="${r}" fill="url(#dmHead)"/>
    <circle cx="${x}" cy="${y}" r="${n(r - 2)}" fill="none" stroke="${color}" stroke-width="2.5" opacity="0.9"/>
    <circle class="dm-flash" cx="${x}" cy="${y}" r="${r}" fill="${color}" opacity="0"/>
  </g>`;
}

function cymbal(lane, x, y, r, color) {
  return `<g class="dm-drum" data-lane="${lane}" style="transform-box:view-box;transform-origin:${x}px ${y}px">
    <circle cx="${x}" cy="${y}" r="${r}" fill="url(#dmCym)" stroke="#6f571a" stroke-width="0.5"/>
    <circle cx="${x}" cy="${y}" r="${n(r * 0.72)}" fill="none" stroke="rgba(0,0,0,0.18)"/>
    <circle cx="${x}" cy="${y}" r="${n(r * 0.46)}" fill="none" stroke="rgba(0,0,0,0.18)"/>
    <circle cx="${x}" cy="${y}" r="${n(r * 0.18)}" fill="url(#dmBell)"/>
    <circle cx="${x}" cy="${y}" r="${n(r - 1)}" fill="none" stroke="${color}" stroke-width="2" opacity="0.85"/>
    <circle class="dm-flash" cx="${x}" cy="${y}" r="${r}" fill="${color}" opacity="0"/>
  </g>`;
}

function build() {
  if (!container) return;

  let kit = '';
  for (let l = 0; l < 10; l++) {
    const d = DRUM[l], x = mx(d.x);
    kit += d.cym ? cymbal(l, x, d.y, d.r, COLORS[l]) : drumHead(l, x, d.y, d.r, COLORS[l]);
  }

  container.innerHTML = `<svg viewBox="0 0 200 200" class="drummer-svg" aria-hidden="true">
    <defs>
      <radialGradient id="dmHead" cx="40%" cy="35%" r="75%">
        <stop offset="0%" stop-color="#fbfbf6"/><stop offset="70%" stop-color="#e6e6dd"/><stop offset="100%" stop-color="#c9c9bf"/>
      </radialGradient>
      <radialGradient id="dmCym" cx="42%" cy="38%" r="70%">
        <stop offset="0%" stop-color="#f4e6a8"/><stop offset="55%" stop-color="#d3ac45"/><stop offset="100%" stop-color="#8a6b1e"/>
      </radialGradient>
      <radialGradient id="dmBell" cx="40%" cy="35%" r="70%">
        <stop offset="0%" stop-color="#fff6d0"/><stop offset="100%" stop-color="#c9a23a"/>
      </radialGradient>
    </defs>
    ${kit}
  </svg>`;

  svgEl = container.querySelector('svg');
  drumsEl = {}; container.querySelectorAll('.dm-drum').forEach(g => { drumsEl[+g.dataset.lane] = g; });
  if (!visible && svgEl) svgEl.style.display = 'none';
}

export function initDrummer(c) { container = c; build(); }
export function setHanded(h)   { handed = (h === 'left') ? 'left' : 'right'; build(); }
export function reset()        { nextIdx = 0; }
export function show()         { visible = true;  if (svgEl) svgEl.style.display = ''; }
export function hide()         { visible = false; if (svgEl) svgEl.style.display = 'none'; }
export function isVisible()    { return visible; }

// Flash the struck drum: a quick swell + a colored glow.
export function strike(lane) {
  const g = drumsEl[lane];
  if (!g) return;
  g.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.14)', offset: 0.35 }, { transform: 'scale(1)' }],
    { duration: 230, easing: 'ease-out' });
  const fl = g.querySelector('.dm-flash');
  if (fl) fl.animate([{ opacity: 0 }, { opacity: 0.9, offset: 0.3 }, { opacity: 0 }],
    { duration: 260, easing: 'ease-out' });
}

const CONTACT_LEAD = 130;   // ms before note.time to flash, so it lands on the beat

export function update(songPos, notes) {
  if (!visible || !notes) return;
  while (nextIdx < notes.length && (notes[nextIdx].time - CONTACT_LEAD) <= songPos) {
    strike(notes[nextIdx].lane);
    nextIdx++;
  }
}
