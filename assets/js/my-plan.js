/* My Plan page — generate the plan on first visit, and persist session progress.
   External file (no inline scripts) to comply with the site CSP. */
(function () {
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

  // ── Generate the plan if none exists yet ──────────────────
  const gen = document.getElementById('plan-generating');
  if (gen) {
    fetch('/api/generate-plan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    })
      .then(r => r.json().then(d => ({ ok: r.ok, d })))
      .then(({ ok, d }) => {
        if (ok && d.ok) { window.location.reload(); return; }
        showError(d.error || 'Could not build your plan. Please try again later.');
      })
      .catch(() => showError('Network error while building your plan. Please try again.'));
  }

  function showError(msg) {
    const box = document.getElementById('plan-error');
    const spin = document.querySelector('.plan-spinner');
    if (spin) spin.style.display = 'none';
    if (box) { box.textContent = msg; box.style.display = ''; }
  }

  // ── Persist session completion ────────────────────────────
  document.querySelectorAll('.plan-done').forEach(cb => {
    cb.addEventListener('change', () => {
      const sessionNo = parseInt(cb.dataset.session, 10);
      const done = cb.checked;
      const card = cb.closest('.plan-session');
      if (card) card.classList.toggle('done', done);
      updateProgress();

      fetch('/api/plan-progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ session_no: sessionNo, done }),
      }).catch(() => { /* revert on failure */
        cb.checked = !done;
        if (card) card.classList.toggle('done', !done);
        updateProgress();
      });
    });
  });

  function updateProgress() {
    const all  = document.querySelectorAll('.plan-done');
    const done = document.querySelectorAll('.plan-done:checked').length;
    const fill = document.getElementById('plan-bar-fill');
    if (fill && all.length) fill.style.width = Math.round(done / all.length * 100) + '%';
    const label = document.querySelector('.plan-progress');
    if (label) label.childNodes[0].textContent = `Progress: ${done} / ${all.length} sessions this month`;
  }
})();
