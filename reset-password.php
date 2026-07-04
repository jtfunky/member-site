<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$token  = $_GET['token'] ?? '';
$uid    = (int)($_GET['uid'] ?? 0);
$error  = '';
$done   = false;

// Validate token
$tokenHash = hash('sha256', $token);
$st = db()->prepare(
    'SELECT id FROM password_resets
     WHERE user_id=? AND token_hash=? AND used=0 AND expires_at > NOW()'
);
$st->execute([$uid, $tokenHash]);
$reset = $st->fetch();

if (!$reset) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $new)) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[0-9]/', $new)) {
        $error = 'Password must contain at least one number.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        db()->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')
             ->execute([$hash, $uid]);
        db()->prepare('UPDATE password_resets SET used=1 WHERE user_id=? AND token_hash=?')
             ->execute([$uid, $tokenHash]);
        $done = true;
    }
}

$pageTitle = 'Reset Password — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Reset Password</h1>

  <?php if ($done): ?>
  <div class="alert alert-success">Your password has been reset successfully.</div>
  <a href="/login.php" class="btn btn-primary btn-block">Log In Now</a>

  <?php elseif ($error && !$reset): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <a href="/forgot-password.php" class="btn btn-primary btn-block">Request New Link</a>

  <?php else: ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <p style="color:var(--text-dim);font-size:.88rem;margin-bottom:1.25rem">
    Must be 8+ characters with an uppercase letter and a number.
  </p>
  <form method="POST" action="/reset-password.php?token=<?= urlencode($token) ?>&uid=<?= $uid ?>">
    <?= csrfField() ?>
    <div class="form-group">
      <label>New Password</label>
      <input type="password" name="new_password" required minlength="8" autofocus>
    </div>
    <div class="form-group">
      <label>Confirm Password</label>
      <input type="password" name="confirm_password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Set New Password</button>
  </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
