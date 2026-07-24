// Scroll-linked reveal: each .reveal element's opacity/scale/translateY is
// computed directly from its current distance to the viewport's vertical
// center, recalculated on every scroll frame. No CSS transition is involved
// — the value is set fresh each frame, so stopping mid-scroll just freezes
// it at whatever value the current position produced.
(function () {
  var els = Array.prototype.slice.call(document.querySelectorAll('.reveal'));
  if (!els.length) return;

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) {
    els.forEach(function (el) { el.style.opacity = 1; el.style.transform = 'none'; });
    return;
  }

  var MAX_OFFSET = 56;  // px translateY when fully faded out
  var MIN_SCALE  = 0.94;
  var ticking = false;

  function update() {
    ticking = false;
    var viewportCenter = window.innerHeight / 2;
    var fadeDistance = window.innerHeight * 0.6; // distance from center at which it's fully hidden

    els.forEach(function (el) {
      var rect = el.getBoundingClientRect();
      var elCenter = rect.top + rect.height / 2;
      var distance = Math.abs(elCenter - viewportCenter);
      var progress = 1 - Math.min(distance / fadeDistance, 1);
      // smoothstep so it doesn't feel mechanically linear
      progress = progress * progress * (3 - 2 * progress);

      el.style.opacity = progress;
      el.style.transform =
        'translateY(' + ((1 - progress) * MAX_OFFSET).toFixed(1) + 'px) ' +
        'scale(' + (MIN_SCALE + progress * (1 - MIN_SCALE)).toFixed(3) + ')';
    });
  }

  function onScroll() {
    if (!ticking) {
      ticking = true;
      requestAnimationFrame(update);
    }
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  update(); // set initial state on load, before any scrolling
})();
