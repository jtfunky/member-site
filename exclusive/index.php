<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = requireLogin();
requireProfileComplete($user); // self-signups must finish their profile first
requirePlacementTest($user);   // new signups take the placement test first
requirePremium($user);

// One-on-one students only — self-registered "Groove Quest" users don't get
// this. Mirrors the same students-table check used to gate "My Sessions".
if (($user['role'] ?? '') !== 'admin') {
    $exclusiveStudent = null;
    try {
        $st = db()->prepare('SELECT program, session_credits, payment_status FROM students WHERE user_id = ? LIMIT 1');
        $st->execute([(int) $user['id']]);
        $exclusiveStudent = $st->fetch() ?: null;
    } catch (\Throwable $e) { /* students table optional */ }
    if (!studentHasSessions($exclusiveStudent)) {
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit;
    }
}

$pageTitle = 'Exclusive Content — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container">
<h1>Exclusive Content</h1>
<p class="text-muted">Member-only lessons, tips, and resources.</p>

<div class="tile-grid">
  <div class="tile tile--placeholder">
    <div class="tile-icon"><i class="ti ti-school" aria-hidden="true"></i></div>
    <h3>Beginner Lessons</h3>
    <p>Coming soon — fundamental drumming techniques for beginners.</p>
  </div>
  <div class="tile tile--placeholder">
    <div class="tile-icon"><i class="ti ti-music" aria-hidden="true"></i></div>
    <h3>Advanced Patterns</h3>
    <p>Coming soon — complex rhythmic patterns for experienced players.</p>
  </div>
  <div class="tile tile--placeholder">
    <div class="tile-icon"><i class="ti ti-playlist" aria-hidden="true"></i></div>
    <h3>Backing Tracks</h3>
    <p>Coming soon — drum-free tracks to practice along with.</p>
  </div>
  <div class="tile tile--placeholder">
    <div class="tile-icon"><i class="ti ti-video" aria-hidden="true"></i></div>
    <h3>Video Tutorials</h3>
    <p>Coming soon — video lessons from professional drummers.</p>
  </div>
</div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
