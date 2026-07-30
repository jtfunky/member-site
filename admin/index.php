<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = requireAdmin();

// Quick stats
$totalUsers  = db()->query('SELECT COUNT(*) FROM users WHERE role="user"')->fetchColumn();
$activeUsers = db()->query('SELECT COUNT(*) FROM users WHERE role="user" AND access_expires_at > NOW()')->fetchColumn();
$totalSongs  = db()->query('SELECT COUNT(*) FROM songs')->fetchColumn();
$totalPaid   = db()->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="success"')->fetchColumn();

// Students enrollment table is created by import-students.sql; may not exist yet.
$hasStudents   = (bool) db()->query("SHOW TABLES LIKE 'students'")->fetch();
$totalStudents = $hasStudents ? (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn() : 0;

// Recent sign-ups
$recentUsers = db()->query(
    'SELECT id, username, email, subscription_status, access_expires_at, created_at
     FROM users WHERE role="user" ORDER BY created_at DESC LIMIT 10'
)->fetchAll();

$pageTitle = 'Admin Dashboard — ' . SITE_NAME;
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Admin Dashboard</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-value"><?= $totalUsers ?></div>
    <div class="stat-label">Total Members</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $activeUsers ?></div>
    <div class="stat-label">Active Now</div>
  </div>
  <?php if ($hasStudents): ?>
  <a class="stat-card" href="/admin/students.php" style="text-decoration:none">
    <div class="stat-value"><?= $totalStudents ?></div>
    <div class="stat-label">Students</div>
  </a>
  <?php endif; ?>
  <div class="stat-card">
    <div class="stat-value"><?= $totalSongs ?></div>
    <div class="stat-label">Songs</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format((float)$totalPaid, 2) ?></div>
    <div class="stat-label">Total Revenue</div>
  </div>
</div>

<h2>Recent Sign-ups</h2>
<div class="table-scroll">
<table class="data-table">
  <thead>
    <tr>
      <th>User</th><th>Email</th><th>Status</th><th>Access Expires</th><th>Joined</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($recentUsers as $u): ?>
    <?php
      $expired = $u['access_expires_at'] && strtotime($u['access_expires_at']) < time();
      $statusClass = $expired ? 'badge-expired' : 'badge-' . $u['subscription_status'];
    ?>
    <tr>
      <td><?= htmlspecialchars($u['username']) ?></td>
      <td><?= htmlspecialchars($u['email']) ?></td>
      <td><span class="badge <?= $statusClass ?>"><?= $expired ? 'expired' : $u['subscription_status'] ?></span></td>
      <td><?= $u['access_expires_at'] ? date('M j, Y', strtotime($u['access_expires_at'])) : '—' ?></td>
      <td><?= formatManilaDate($u['created_at']) ?></td>
      <td><a href="/admin/users.php?edit=<?= $u['id'] ?>" class="btn btn-ghost btn-xs">Manage</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
