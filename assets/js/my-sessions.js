/* My Sessions page interactions.
   External file (no inline scripts) to comply with the site CSP. */
(function () {
  // Confirm before submitting any form that declares data-confirm (e.g. Cancel).
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
})();
