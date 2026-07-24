<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = requireAdmin();
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'revoke') {
        $id = (int) ($_POST['device_id'] ?? 0);
        revokeAdminDevice((int) $admin['id'], $id);
        $message = 'Device revoked.';
    } elseif ($action === 'revoke_others') {
        revokeOtherAdminDevices((int) $admin['id']);
        $message = 'All other devices were revoked.';
    }

    // If this browser is no longer a trusted device, drop the session pass + cookie
    // so the gate re-triggers on the next admin page.
    if (ADMIN_DEVICE_LOCK && !adminDeviceTrusted((int) $admin['id'])) {
        unset($_SESSION['admin_device_ok']);
        setcookie(ADMIN_DEVICE_COOKIE, '', time() - 3600, '/');
    }
}

$devices = listAdminDevices((int) $admin['id']);

$pageTitle = 'My Devices — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>My Trusted Devices</h1>
  <div class="admin-nav-pills">
    <a href="/admin/">Overview</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/devices.php" class="active">My Devices</a>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<p class="muted">
  These browsers can reach the admin area without an email code. Device lock is currently
  <strong><?= ADMIN_DEVICE_LOCK ? 'ON' : 'OFF' ?></strong>. Revoke any device you don't recognize
  or no longer use — it'll need a fresh email code next time.
</p>

<?php if (!$devices): ?>
  <p class="muted">No trusted devices yet.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Device</th><th>IP</th><th>First seen</th><th>Last used</th><th style="text-align:right">Action</th></tr></thead>
  <tbody>
  <?php foreach ($devices as $d): $cur = isCurrentAdminDevice($d); ?>
    <tr>
      <td><?= htmlspecialchars($d['label'] ?: 'Unknown device') ?><?= $cur ? ' <span class="badge">this device</span>' : '' ?></td>
      <td class="muted"><?= htmlspecialchars($d['ip'] ?: '—') ?></td>
      <td class="muted" style="font-size:.85rem"><?= htmlspecialchars(date('M j, Y', strtotime($d['created_at']))) ?></td>
      <td class="muted" style="font-size:.85rem"><?= $d['last_used_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($d['last_used_at']))) : '—' ?></td>
      <td style="text-align:right">
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
          <button class="btn btn-danger btn-xs"><?= $cur ? 'Revoke (sign out this device)' : 'Revoke' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<form method="POST" style="margin-top:1rem">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="revoke_others">
  <button class="btn btn-ghost btn-sm">Revoke all other devices</button>
</form>
<?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
