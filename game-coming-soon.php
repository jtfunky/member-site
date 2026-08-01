<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();

// Already launched? Don't leave this page reachable — send them into the game.
if (gameBetaHasLaunched() || ($user['role'] ?? '') === 'admin' || isGameBetaBypassEmail($user['email'] ?? '')) {
    header('Location: ' . SITE_URL . '/game.php');
    exit;
}

$launchDate = new DateTime(GAME_BETA_LAUNCH_AT, new DateTimeZone('Asia/Manila'));
$launchAt   = $launchDate->format('F j, Y');
// Epoch millis, passed to JS so the countdown ticks the actual instant in
// time regardless of the visitor's own timezone/clock display.
$launchAtMs = $launchDate->getTimestamp() * 1000;

$pageTitle = 'Coming Soon — ' . SITE_NAME;
$pageCss   = ['main'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<style>
  .countdown { display: flex; justify-content: center; gap: .9rem; margin: 1.75rem 0; flex-wrap: wrap; }
  .countdown-unit {
    background: var(--bg3); border: 1px solid var(--border); border-radius: 10px;
    padding: .9rem 1.1rem; min-width: 4.5rem; text-align: center;
  }
  .countdown-num { font-size: 1.9rem; font-weight: 700; color: var(--accent); line-height: 1; font-variant-numeric: tabular-nums; }
  .countdown-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: var(--text-dim); margin-top: .35rem; }
</style>

<main class="container" style="max-width: 640px; text-align: center; padding-top: 3rem;">
  <h1>The game is almost here</h1>

  <div class="countdown" id="launch-countdown" data-launch-at="<?= $launchAtMs ?>">
    <div class="countdown-unit"><div class="countdown-num" data-unit="days">--</div><div class="countdown-label">Days</div></div>
    <div class="countdown-unit"><div class="countdown-num" data-unit="hours">--</div><div class="countdown-label">Hours</div></div>
    <div class="countdown-unit"><div class="countdown-num" data-unit="minutes">--</div><div class="countdown-label">Minutes</div></div>
    <div class="countdown-unit"><div class="countdown-num" data-unit="seconds">--</div><div class="countdown-label">Seconds</div></div>
  </div>

  <div class="alert alert-info" style="text-align: left;">
    Groove Quest launches in beta on <strong><?= htmlspecialchars($launchAt) ?></strong>.
    Your account is already registered — there's nothing else you need to do.
    Come back on launch day and it'll be ready for you.
  </div>
  <a href="/dashboard.php" class="btn btn-primary">Back to Dashboard</a>
</main>

<script>
(function () {
  var el = document.getElementById('launch-countdown');
  var launchAt = parseInt(el.dataset.launchAt, 10);
  var nums = {
    days: el.querySelector('[data-unit="days"]'),
    hours: el.querySelector('[data-unit="hours"]'),
    minutes: el.querySelector('[data-unit="minutes"]'),
    seconds: el.querySelector('[data-unit="seconds"]'),
  };

  function pad(n) { return String(n).padStart(2, '0'); }

  function tick() {
    var diff = launchAt - Date.now();
    if (diff <= 0) {
      // Launch time reached while they're sitting on the page — send them in.
      window.location.href = '/game.php';
      return;
    }
    var totalSeconds = Math.floor(diff / 1000);
    nums.days.textContent    = Math.floor(totalSeconds / 86400);
    nums.hours.textContent   = pad(Math.floor((totalSeconds % 86400) / 3600));
    nums.minutes.textContent = pad(Math.floor((totalSeconds % 3600) / 60));
    nums.seconds.textContent = pad(totalSeconds % 60);
  }

  tick();
  setInterval(tick, 1000);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
