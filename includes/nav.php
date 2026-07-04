<?php
// Shared top navigation — include after $user is set
require_once __DIR__ . '/programs.php';
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Web-access-only students have no session credits, so hide "My Sessions".
$navStudent = null;
try {
    $st = db()->prepare('SELECT program, session_credits FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) ($user['id'] ?? 0)]);
    $navStudent = $st->fetch() ?: null;
} catch (\Throwable $e) { /* students table optional */ }
$navShowSessions = studentHasSessions($navStudent);
?>
<nav class="top-nav">
  <div class="nav-brand">
    <a href="/dashboard.php"><span class="drum-icon">🥁</span> <?= SITE_NAME ?></a>
  </div>
  <ul class="nav-links">
    <?php if (($user['subscription_status'] ?? '') === 'pending'): ?>
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
