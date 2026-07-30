<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = requireAdmin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_leaderboard') {
        $enabled = !empty($_POST['enabled']);
        setSetting('leaderboard_enabled', $enabled ? '1' : '0');
        $message = 'Leaderboard is now ' . ($enabled ? 'ON — visible to students.' : 'OFF — hidden from students.');
    }
}

$leaderboardEnabled = isLeaderboardEnabled();

$pageTitle = 'Settings — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Settings</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="user-detail-card">
  <div class="user-detail-header">
    <h2>Game Leaderboard</h2>
  </div>
  <p class="field-hint">Shows top scores per song to students, below the video while playing a YouTube-backed song and on the results screen after any normal play. Off by default until there's enough players for rankings to actually mean something.</p>

  <form method="POST" class="mini-form" style="max-width:360px;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="toggle_leaderboard">
    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;">
      <input type="checkbox" name="enabled" value="1" <?= $leaderboardEnabled ? 'checked' : '' ?> style="width:auto;">
      <span><?= $leaderboardEnabled ? 'ON — students can see rankings' : 'OFF — hidden from students' ?></span>
    </label>
    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.8rem;">Save</button>
  </form>
</div>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
