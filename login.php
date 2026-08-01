<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) {
    $u = getCurrentUser();
    $r = $u['role'] ?? 'user';
    header('Location: ' . SITE_URL . (($r === 'editor' || $r === 'partner') ? roleHome($r) : defaultPostLoginRedirect($u)));
    exit;
}

$error      = '';
$unverified = false;
// Only carry an explicit deep-link through; an absent one is resolved to the
// right default (dashboard vs. game-coming-soon) once we know who logged in.
$socialNext = isset($_GET['next']) ? safeRedirectPath($_GET['next']) : '';
$next       = htmlspecialchars($socialNext);

if (isset($_GET['expired'])) {
    $error = 'Your session expired. Please log in again.';
}
if (isset($_GET['social_error'])) {
    $error = substr((string) $_GET['social_error'], 0, 200);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $result   = loginUser($login, $password);
    if (is_array($result)) {
        $role = $result['role'] ?? 'user';
        if ($role === 'admin' && ADMIN_DEVICE_LOCK && !adminDeviceTrusted((int) $result['id'])) {
            // Admin on an unregistered device → verify it before entering the admin area.
            $dest = '/verify-device.php?next=' . urlencode('/admin/');
        } elseif ($role === 'editor' || $role === 'partner') {
            $dest = roleHome($role);
        } else {
            $postNext = trim($_POST['next'] ?? '');
            $dest = $postNext !== '' ? safeRedirectPath($postNext) : defaultPostLoginRedirect($result);
        }
        header('Location: ' . SITE_URL . $dest);
        exit;
    }
    if ($result === EMAIL_NOT_VERIFIED) {
        $unverified = true;
    } else {
        $error = $result; // string = error message
    }
}

$pageTitle = 'Log In — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Welcome back</h1>

  <?php if ($unverified): ?>
  <div class="alert alert-error">
    Please verify your email before logging in.
    <a href="/resend-verification.php">Resend the verification link</a>.
  </div>
  <?php elseif ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/login.php">
    <?= csrfField() ?>
    <input type="hidden" name="next" value="<?= $next ?>">
    <div class="form-group">
      <label>Username or Email</label>
      <input type="text" name="login" required autofocus
             value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Log In</button>
    <p style="text-align:center;margin-top:.75rem;font-size:.88rem">
      <a href="/forgot-password.php">Forgot your password?</a>
    </p>
  </form>

  <?php require __DIR__ . '/includes/social-buttons.php'; ?>

  <p class="auth-switch">Don't have an account? <a href="/register.php">Sign up</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
