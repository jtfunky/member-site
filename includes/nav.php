<?php
// Shared top navigation — include after $user is set
require_once __DIR__ . '/programs.php';
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Web-access-only students have no session credits, so hide "My Sessions".
$navStudent = null;
try {
    $st = db()->prepare('SELECT program, session_credits, payment_status FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) ($user['id'] ?? 0)]);
    $navStudent = $st->fetch() ?: null;
} catch (\Throwable $e) { /* students table optional */ }
$navShowSessions = studentHasSessions($navStudent);

// Show "My Enrollment" to anyone with an enrollment still awaiting payment —
// legacy pending accounts, and free-trial course enrollees who haven't paid yet.
$navShowEnrollment = (($user['subscription_status'] ?? '') === 'pending')
    || ($navStudent
        && creditsForProgram(trim((string) ($navStudent['program'] ?? ''))) > 0
        && !studentPaymentConfirmed($navStudent));

// Constant payment reminder — shown site-wide to course students (lesson
// enrollees) whose payment isn't confirmed yet. Web-access / online-only users
// have no session-based program, so they never see it.
$navRemindProof = $navStudent
    && creditsForProgram(trim((string) ($navStudent['program'] ?? ''))) > 0
    && !studentPaymentConfirmed($navStudent);
?>
<nav class="top-nav">
  <div class="nav-brand">
    <a href="/dashboard.php"><span class="drum-icon">🥁</span> <span class="brand-name"><?= SITE_NAME ?></span><span class="brand-abbr">ZADA</span></a>
  </div>
  <input type="checkbox" id="nav-toggle" class="nav-toggle" hidden>
  <label for="nav-toggle" class="nav-burger" aria-label="Toggle menu">☰</label>
  <ul class="nav-links">
    <?php if ($navShowEnrollment): ?>
    <li><a href="/my-membership.php" class="<?= $currentPath === '/my-membership.php' ? 'active' : '' ?>">My Enrollment</a></li>
    <?php endif; ?>
    <li><a href="/game.php"      class="<?= $currentPath === '/game.php'    ? 'active' : '' ?>">Game</a></li>
    <li><a href="/placement-test.php" class="<?= $currentPath === '/placement-test.php' ? 'active' : '' ?>">Placement Test</a></li>
    <?php if ($navShowSessions): ?>
    <li><a href="/my-sessions.php" class="<?= $currentPath === '/my-sessions.php' ? 'active' : '' ?>">My Sessions</a></li>
    <?php endif; ?>
    <li><a href="/exclusive/"    class="<?= str_starts_with($currentPath, '/exclusive') ? 'active' : '' ?>">Exclusive</a></li>
    <li><a href="/profile.php"   class="<?= $currentPath === '/profile.php' ? 'active' : '' ?>">Profile</a></li>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <li><a href="/admin/"        class="<?= str_starts_with($currentPath, '/admin') ? 'active' : '' ?>">Admin</a></li>
    <?php endif; ?>
  </ul>
  <div class="nav-user">
    <span><?= htmlspecialchars($user['username'] ?? '') ?></span>
    <a href="/logout.php" class="btn btn-ghost btn-sm">Log Out</a>
  </div>
</nav>
<?php if ($navRemindProof): ?>
<div class="alert alert-error" style="margin:0;border-radius:0;text-align:center;">
  ⚠️ <strong>Payment pending.</strong> Please complete your payment and
  <a href="/my-membership.php">submit your proof of payment</a> to confirm your enrollment and unlock your one-on-one sessions.
</div>
<?php endif; ?>
