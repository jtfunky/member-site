<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = requireAdmin();
$db    = db();

// "Online" = touched last_seen_at (see touchLastSeen() in auth.php) within
// the last 5 minutes — same window admin/index.php's stat tile uses.
$hasColumn = false;
try {
    $db->query('SELECT last_seen_at FROM users LIMIT 1');
    $hasColumn = true;
} catch (\Throwable $e) { /* migrate-users-last-seen.sql not run yet */ }

$online = $hasColumn ? $db->query(
    'SELECT id, username, email, role, last_seen_at
     FROM users
     WHERE last_seen_at > NOW() - INTERVAL 5 MINUTE
     ORDER BY last_seen_at DESC'
)->fetchAll() : [];

$pageTitle = 'Online Now — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Online Now</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if (!$hasColumn): ?>
  <p class="field-hint">Online tracking isn't set up yet — run <code>migrate-users-last-seen.sql</code> on the database.</p>
<?php else: ?>
  <p class="field-hint">Anyone who's loaded a page in the last 5 minutes. Refreshes each time you reload this page.</p>

  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>User</th><th>Role</th><th>Last seen</th></tr></thead>
    <tbody>
    <?php if (!$online): ?>
      <tr><td colspan="3">No one's online right now.</td></tr>
    <?php endif; ?>
    <?php foreach ($online as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['username']) ?> <span class="text-muted"><?= htmlspecialchars($u['email']) ?></span></td>
        <td><?= htmlspecialchars($u['role']) ?></td>
        <td><?= (int) round((time() - strtotime($u['last_seen_at'])) / 60) ?>m ago</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
