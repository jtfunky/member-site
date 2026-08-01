<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();

$token = $_GET['token'] ?? '';
$uid   = (int) ($_GET['uid'] ?? 0);
$error = '';

$tokenHash = hash('sha256', $token);
$st = db()->prepare(
    'SELECT id FROM email_verifications
     WHERE user_id = ? AND token_hash = ? AND used = 0 AND expires_at > NOW()'
);
$st->execute([$uid, $tokenHash]);
$verification = $st->fetch();

if (!$verification) {
    $error = 'This verification link is invalid or has expired. Please request a new one.';
} else {
    db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$uid]);
    db()->prepare('UPDATE email_verifications SET used = 1 WHERE user_id = ?')->execute([$uid]);
    loginById($uid);
}

$pageTitle = 'Verify Email — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Verify Email</h1>

  <?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <a href="/resend-verification.php" class="btn btn-primary btn-block">Request New Link</a>

  <?php else: ?>
  <div class="alert alert-success">Your email is verified — you're all set!</div>
  <a href="/dashboard.php?welcome=1" class="btn btn-primary btn-block">Continue</a>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
