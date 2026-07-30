<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user  = $admin = requireAdmin();
$db    = db();

$message = '';
$error   = '';

// ── Handle form submissions ────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? 'TempPass@123';
        $first    = trim($_POST['first_name'] ?? '');
        $last     = trim($_POST['last_name']  ?? '');

        $role   = in_array($_POST['role'] ?? 'admin', ['admin', 'editor', 'partner'], true) ? $_POST['role'] : 'admin';
        $result = registerUser($username, $email, $password, $first, $last);
        if (is_int($result)) {
            // Staff accounts created here have full/scoped access, no expiry.
            $db->prepare(
                'UPDATE users SET role=?, registration_type="admin",
                 subscription_status="active", access_expires_at=NULL WHERE id=?'
            )->execute([$role, $result]);
            $message = ucfirst($role) . " account {$username} created. Temp password: {$password}";
        } else {
            $error = $result;
        }

    } elseif ($action === 'set_role') {
        $userId  = (int) ($_POST['user_id'] ?? 0);
        $newRole = in_array($_POST['role'] ?? '', ['admin', 'editor', 'partner'], true) ? $_POST['role'] : '';
        if ($userId === (int) $admin['id']) {
            $error = 'You cannot change your own role.';
        } elseif ($newRole) {
            $db->prepare('UPDATE users SET role=? WHERE id=?')->execute([$newRole, $userId]);
            $message = 'Role updated to ' . $newRole . '.';
        }

    } elseif ($action === 'grant') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $days   = (int)($_POST['grant_days'] ?? 30);
        $note   = trim($_POST['note'] ?? '');
        grantAdminAccess($userId, $days, $admin['id'], $note);
        $message = "Granted {$days} days access.";

    } elseif ($action === 'deactivate') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)$admin['id']) {
            $error = 'You cannot deactivate your own account.';
        } else {
            $db->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$userId]);
            $message = 'Account deactivated.';
        }

    } elseif ($action === 'activate') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $db->prepare('UPDATE users SET is_active=1 WHERE id=?')->execute([$userId]);
        $message = 'Account reactivated.';

    } elseif ($action === 'reset_password') {
        $userId      = (int)($_POST['user_id'] ?? 0);
        $newPassword = trim($_POST['new_password'] ?? '');
        if (strlen($newPassword) >= 8) {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $userId]);
            $message = 'Password updated.';
        } else {
            $error = 'Password must be at least 8 characters.';
        }
    }
}

// ── Load users ─────────────────────────────────────────────

$editId   = (int)($_GET['edit'] ?? 0);
$editUser = null;
if ($editId) {
    $editUser = $db->prepare('SELECT * FROM users WHERE id=?');
    $editUser->execute([$editId]);
    $editUser = $editUser->fetch();

    // users.php manages administrators only. A member account is managed on the
    // Students page, so route there instead of showing it here.
    if ($editUser && !in_array($editUser['role'] ?? '', ['admin', 'editor', 'partner'], true)) {
        header('Location: ' . SITE_URL . '/admin/students.php?q=' . urlencode($editUser['email']));
        exit;
    }

    // Load grants
    $grants = $db->prepare(
        'SELECT ag.*, u.username AS granted_by_name
         FROM access_grants ag JOIN users u ON u.id=ag.granted_by
         WHERE ag.user_id=? ORDER BY ag.granted_at DESC LIMIT 20'
    );
    $grants->execute([$editId]);
    $grants = $grants->fetchAll();

    // Load payments
    $payments = $db->prepare(
        'SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC LIMIT 20'
    );
    $payments->execute([$editId]);
    $payments = $payments->fetchAll();
}

// This page manages the site administrators. Members/students are managed on
// the Students page, so list only admin accounts here.
$users = $db->query(
    'SELECT id, username, email, first_name, last_name, role, subscription_status,
     access_expires_at, is_active, registration_type, country, created_at
     FROM users WHERE role IN ("admin","editor","partner") ORDER BY created_at DESC'
)->fetchAll();

$pageTitle = 'Users — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Users</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Create Staff Account -->
<details class="collapsible" <?= $error && isset($_POST['action']) && $_POST['action'] === 'create' ? 'open' : '' ?>>
  <summary class="btn btn-primary">+ Create Staff Account</summary>
  <form method="POST" class="inline-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group"><label>Username *</label><input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
      <div class="form-group"><label>Email *</label><input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"></div>
      <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Role *</label>
        <select name="role">
          <option value="admin">Admin — full access</option>
          <option value="editor">Editor — Song Tags / Requests / Chart Editor</option>
          <option value="partner">Partner — Partner page only</option>
        </select>
      </div>
      <div class="form-group">
        <label>Temporary Password</label>
        <input type="text" name="password" value="TempPass@123" required>
        <span class="field-hint">They should change this after login.</span>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Create Account</button>
  </form>
</details>

<!-- User Detail View -->
<?php if ($editUser): ?>
<div class="user-detail-card">
  <div class="user-detail-header">
    <h2><?= htmlspecialchars($editUser['username']) ?></h2>
    <span class="badge badge-<?= $editUser['subscription_status'] ?>"><?= $editUser['subscription_status'] ?></span>
    <a href="/admin/users.php" class="btn btn-ghost btn-sm">← Back</a>
  </div>

  <div class="detail-grid">
    <div>
      <p><strong>Email:</strong> <?= htmlspecialchars($editUser['email']) ?></p>
      <p><strong>Name:</strong> <?= htmlspecialchars($editUser['first_name'] . ' ' . $editUser['last_name']) ?></p>
      <p><strong>Joined:</strong> <?= formatManilaDate($editUser['created_at']) ?></p>
      <p><strong>Type:</strong> <?= $editUser['registration_type'] ?></p>
      <p><strong>Role:</strong> <?= htmlspecialchars($editUser['role']) ?></p>
      <p><strong>Access Expires:</strong> <?= $editUser['access_expires_at'] ? date('M j, Y H:i', strtotime($editUser['access_expires_at'])) : 'None' ?></p>
      <p><strong>Active:</strong> <?= $editUser['is_active'] ? 'Yes' : 'No' ?></p>
    </div>

    <div>
      <!-- Grant Access -->
      <form method="POST" class="mini-form">
        <?= csrfField() ?>
        <input type="hidden" name="action"  value="grant">
        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
        <h3>Grant Free Access</h3>
        <select name="grant_days">
          <option value="30">30 days</option>
          <option value="90">3 months</option>
          <option value="180">6 months</option>
          <option value="365">12 months</option>
        </select>
        <input type="text" name="note" placeholder="Note (optional)">
        <button type="submit" class="btn btn-primary btn-sm">Grant</button>
      </form>

      <!-- Reset Password -->
      <form method="POST" class="mini-form">
        <?= csrfField() ?>
        <input type="hidden" name="action"  value="reset_password">
        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
        <h3>Reset Password</h3>
        <input type="text" name="new_password" placeholder="New password (min 8 chars)" required>
        <button type="submit" class="btn btn-secondary btn-sm">Set Password</button>
      </form>

      <!-- Change Role -->
      <?php if ((int) $editUser['id'] !== (int) $admin['id']): ?>
      <form method="POST" class="mini-form">
        <?= csrfField() ?>
        <input type="hidden" name="action"  value="set_role">
        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
        <h3>Change Role</h3>
        <select name="role">
          <option value="admin"   <?= $editUser['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
          <option value="editor"  <?= $editUser['role'] === 'editor'  ? 'selected' : '' ?>>Editor</option>
          <option value="partner" <?= $editUser['role'] === 'partner' ? 'selected' : '' ?>>Partner</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Update Role</button>
      </form>
      <?php endif; ?>

      <!-- Activate / Deactivate -->
      <form method="POST" class="mini-form">
        <?= csrfField() ?>
        <input type="hidden" name="action"  value="<?= $editUser['is_active'] ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
        <button type="submit" class="btn btn-<?= $editUser['is_active'] ? 'danger' : 'primary' ?> btn-sm">
          <?= $editUser['is_active'] ? 'Deactivate Account' : 'Reactivate Account' ?>
        </button>
      </form>
    </div>
  </div>

  <!-- Grant History -->
  <?php if ($grants): ?>
  <h3>Access Grant History</h3>
  <table class="data-table data-table--sm">
    <thead><tr><th>Days</th><th>Granted By</th><th>Note</th><th>Expires</th><th>Granted</th></tr></thead>
    <tbody>
    <?php foreach ($grants as $g): ?>
      <tr>
        <td><?= $g['duration_days'] ?></td>
        <td><?= htmlspecialchars($g['granted_by_name']) ?></td>
        <td><?= htmlspecialchars($g['note']) ?></td>
        <td><?= date('M j, Y', strtotime($g['expires_at'])) ?></td>
        <td><?= date('M j, Y', strtotime($g['granted_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Payment History -->
  <?php if ($payments): ?>
  <h3>Payment History</h3>
  <table class="data-table data-table--sm">
    <thead><tr><th>Amount</th><th>Method</th><th>Tx ID</th><th>Period</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= $p['currency'] === 'PHP' ? '₱' : '$' ?><?= number_format((float)$p['amount'], 2) ?></td>
        <td><?= htmlspecialchars($p['payment_method']) ?></td>
        <td class="font-mono text-sm"><?= htmlspecialchars($p['transaction_id']) ?></td>
        <td><?= date('M j', strtotime($p['period_start'])) ?> – <?= date('M j, Y', strtotime($p['period_end'])) ?></td>
        <td><?= formatManilaDate($p['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

</div>
<?php endif; ?>

<!-- Staff Table -->
<h2>Staff Accounts</h2>
<div class="table-scroll">
<table class="data-table">
  <thead>
    <tr><th>User</th><th>Role</th><th>Email</th><th>Country</th><th>Type</th><th>Status</th><th>Expires</th><th>Active</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <?php $isExpired = $u['access_expires_at'] && strtotime($u['access_expires_at']) < time(); ?>
    <tr class="<?= !$u['is_active'] ? 'row-inactive' : '' ?>">
      <td><?= htmlspecialchars($u['username']) ?></td>
      <td><span class="badge"><?= htmlspecialchars($u['role']) ?></span></td>
      <td><?= htmlspecialchars($u['email']) ?></td>
      <td><?= !empty($u['country']) ? htmlspecialchars($u['country']) : '—' ?></td>
      <td><?= $u['registration_type'] === 'admin' ? '<i class="ti ti-user"></i> Admin-added' : '<i class="ti ti-world"></i> Self-reg' ?></td>
      <td>
        <span class="badge badge-<?= $isExpired ? 'expired' : $u['subscription_status'] ?>">
          <?= $isExpired ? 'expired' : $u['subscription_status'] ?>
        </span>
      </td>
      <td><?= $u['access_expires_at'] ? date('M j, Y', strtotime($u['access_expires_at'])) : '—' ?></td>
      <td><?= $u['is_active'] ? '<i class="ti ti-check" style="color:var(--success)"></i>' : '<i class="ti ti-x" style="color:var(--danger)"></i>' ?></td>
      <td><a href="/admin/users.php?edit=<?= $u['id'] ?>" class="btn btn-ghost btn-xs">Manage</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
