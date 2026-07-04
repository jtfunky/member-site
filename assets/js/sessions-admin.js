/* Admin → Sessions page interactions.
   External file (no inline scripts) to comply with the site CSP. Loaded at the
   bottom of the page, so the DOM is already parsed. */
(function () {
  // Confirm before submitting any form that declares data-confirm.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
})();
