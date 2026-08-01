<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');

    if (tooManyRequests('email-verify', 5, 15)) {
        $error = 'Too many requests. Please wait a few minutes and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $st = db()->prepare('SELECT id, first_name FROM users WHERE email = ? AND is_active = 1 AND email_verified_at IS NULL');
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user) {
            sendVerificationEmail((int) $user['id'], $email, $user['first_name']);
        }

        // Always show the same message to prevent email enumeration — this
        // also covers the "already verified" and "no such account" cases.
        $sent = true;
    }
}

$pageTitle = 'Resend Verification — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Resend Verification Email</h1>

  <?php if ($sent): ?>
  <div class="alert alert-success">
    If that email needs verifying, a new link has been sent. Check your inbox (and spam folder).
  </div>
  <p class="auth-switch"><a href="/login.php">Back to Login</a></p>

  <?php else: ?>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <p style="color:var(--text-dim);font-size:.9rem;margin-bottom:1.25rem">
    Enter your email and we'll send you a new verification link.
  </p>
  <form method="POST" action="/resend-verification.php">
    <?= csrfField() ?>
    <div class="form-group">
      <label>Email Address</label>
      <input type="email" name="email" required autofocus
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Send Verification Link</button>
  </form>
  <p class="auth-switch"><a href="/login.php">Back to Login</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
