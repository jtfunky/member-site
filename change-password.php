<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user    = requireLogin();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        db()->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')
             ->execute([$hash, $user['id']]);
        $success = 'Password changed successfully.';
    }
}

$pageTitle = 'Change Password — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<main class="container container--narrow">
<h1>Change Password</h1>

<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<form method="POST" action="/change-password.php" style="margin-top:1.5rem">
  <?= csrfField() ?>
  <div class="form-group">
    <label>Current Password</label>
    <input type="password" name="current_password" required autofocus>
  </div>
  <div class="form-group">
    <label>New Password</label>
    <input type="password" name="new_password" required minlength="8">
    <span class="field-hint">Minimum 8 characters</span>
  </div>
  <div class="form-group">
    <label>Confirm New Password</label>
    <input type="password" name="confirm_password" required>
  </div>
  <button type="submit" class="btn btn-primary">Change Password</button>
  <a href="/profile.php" class="btn btn-ghost" style="margin-left:.75rem">Cancel</a>
</form>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
