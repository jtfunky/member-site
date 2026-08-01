// Dismissible beta-testing banner (includes/nav.php). Hides for the rest of
// this browser once closed — reappears in a fresh browser/profile, which is
// fine for a low-stakes heads-up.
(function () {
  var KEY = 'zada_beta_banner_dismissed';
  var banner = document.getElementById('beta-banner');
  if (!banner) return;

  if (localStorage.getItem(KEY) === '1') {
    banner.style.display = 'none';
    return;
  }

  var closeBtn = document.getElementById('beta-banner-dismiss');
  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      banner.style.display = 'none';
      try { localStorage.setItem(KEY, '1'); } catch (e) { /* private mode — just hides for this load */ }
    });
  }
})();
