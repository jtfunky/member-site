// 5-star experience-rating widget. Mounts every .rating-widget on the page
// on load; exposes RatingWidget.reset() so game.php can re-target the same
// widget element at a new song each time the results screen shows.
(function () {
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content
    || document.getElementById('game-config')?.dataset.csrf
    || '';

  function paint(widget, filled) {
    widget.querySelectorAll('.rating-star').forEach(btn => {
      btn.classList.toggle('is-filled', Number(btn.dataset.value) <= filled);
    });
  }

  async function submit(widget, value) {
    const context = widget.dataset.context;
    const refId   = widget.dataset.refId || '';
    widget.dataset.committed = String(value);
    paint(widget, value);
    widget.dataset.rated = '1';
    const thanks = widget.querySelector('.rating-thanks');
    if (thanks) thanks.style.display = 'inline';
    try {
      await fetch('/api/rate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ context, ref_id: refId, rating: value }),
      });
    } catch (e) { /* non-fatal — the stars still reflect the click either way */ }
  }

  function mount(widget) {
    if (widget.dataset.mounted) return;
    widget.dataset.mounted = '1';
    widget.dataset.committed = String(widget.querySelectorAll('.rating-star.is-filled').length);
    widget.querySelectorAll('.rating-star').forEach(btn => {
      btn.addEventListener('click', () => submit(widget, Number(btn.dataset.value)));
      btn.addEventListener('mouseenter', () => paint(widget, Number(btn.dataset.value)));
    });
    widget.addEventListener('mouseleave', () => paint(widget, Number(widget.dataset.committed)));
  }

  function mountAll(root) {
    (root || document).querySelectorAll('.rating-widget').forEach(mount);
  }

  // Re-targets an existing widget element at a new context/ref (e.g. a new
  // song after Retry), clearing its visual state and re-checking whether
  // this user already rated that specific song.
  window.RatingWidget = {
    reset(widget, context, refId, label) {
      widget.dataset.context = context;
      widget.dataset.refId   = refId || '';
      widget.dataset.rated   = '0';
      widget.dataset.mounted = '';
      if (label) { const l = widget.querySelector('.rating-label'); if (l) l.textContent = label; }
      paint(widget, 0);
      const thanks = widget.querySelector('.rating-thanks');
      if (thanks) thanks.style.display = 'none';
      mount(widget);
    },
    mountAll,
  };

  document.addEventListener('DOMContentLoaded', () => mountAll());
})();
