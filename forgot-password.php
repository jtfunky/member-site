<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');

    if (tooManyRequests('pwreset', 5, 15)) {
        $error = 'Too many requests. Please wait a few minutes and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $st = db()->prepare('SELECT id, username FROM users WHERE email = ? AND is_active = 1');
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user) {
            // Invalidate any existing tokens for this user
            db()->prepare('UPDATE password_resets SET used=1 WHERE user_id=?')->execute([$user['id']]);

            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires   = date('Y-m-d H:i:s', strtotime('+1 hour'));

            db()->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
            )->execute([$user['id'], $tokenHash, $expires]);

            $resetUrl = SITE_URL . '/reset-password.php?token=' . urlencode($token) . '&uid=' . $user['id'];

            $subject = SITE_NAME . ' — Password Reset';
            $body    = "Hi {$user['username']},\n\n"
                     . "You requested a password reset. Click the link below (valid for 1 hour):\n\n"
                     . $resetUrl . "\n\n"
                     . "If you didn't request this, you can ignore this email.\n\n"
                     . "— " . SITE_NAME;

            $headers = 'From: ' . SITE_NAME . ' <' . MAIL_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8";
            @mail($email, $subject, $body, $headers);
        }

        // Always show the same message to prevent email enumeration
        $sent = true;
    }
}

$pageTitle = 'Forgot Password — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Forgot Password</h1>

  <?php if ($sent): ?>
  <div class="alert alert-success">
    If that email is registered, a reset link has been sent. Check your inbox (and spam folder).
  </div>
  <p class="auth-switch"><a href="/login.php">Back to Login</a></p>

  <?php else: ?>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <p style="color:var(--text-dim);font-size:.9rem;margin-bottom:1.25rem">
    Enter your email and we'll send you a link to reset your password.
  </p>
  <form method="POST" action="/forgot-password.php">
    <?= csrfField() ?>
    <div class="form-group">
      <label>Email Address</label>
      <input type="email" name="email" required autofocus
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
  </form>
  <p class="auth-switch"><a href="/login.php">Back to Login</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
