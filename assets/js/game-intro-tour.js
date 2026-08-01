import { getLaneNames, getLaneColors } from './game/input.js';

// First-time "how to play Groove Quest" walkthrough — shown once on a new
// user's first visit to a normal game session (game.php gates this via
// #game-config's data-show-intro, computed server-side from
// users.game_intro_seen_at). Separate from the placement-test drum-lane
// tutorial, which teaches identifying physical drums, not app usage.
const cfg = document.getElementById('game-config');
if (cfg && cfg.dataset.showIntro === '1') {
  const modal   = document.getElementById('intro-modal');
  const steps   = [...document.querySelectorAll('.intro-step')];
  const dotsEl  = document.getElementById('intro-dots');
  const nextBtn = document.getElementById('intro-next');
  const skipBtn = document.getElementById('intro-skip');
  const lanesEl = document.getElementById('intro-lanes');
  const csrf    = cfg.dataset.csrf || '';

  const names  = getLaneNames();
  const colors = getLaneColors();
  lanesEl.innerHTML = names.map((name, i) => `
    <span class="intro-lane-chip">
      <span class="intro-lane-dot" style="background:${colors[i]}"></span>${name}
    </span>
  `).join('');

  dotsEl.innerHTML = steps.map((_, i) => `<span class="intro-dot${i === 0 ? ' active' : ''}"></span>`).join('');
  const dots = [...dotsEl.children];

  let step = 0;
  function render() {
    steps.forEach((el, i) => el.classList.toggle('active', i === step));
    dots.forEach((el, i) => el.classList.toggle('active', i === step));
    nextBtn.textContent = step === steps.length - 1 ? 'Get Started' : 'Next';
  }

  function markSeen() {
    fetch('/api/game-intro-seen.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf } }).catch(() => {});
  }

  function close() {
    modal.style.display = 'none';
    markSeen();
  }

  nextBtn.addEventListener('click', () => {
    if (step === steps.length - 1) { close(); return; }
    step++;
    render();
  });
  skipBtn.addEventListener('click', close);

  modal.style.display = 'flex';
}
