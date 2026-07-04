<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$error      = '';
$socialNext = safeRedirectPath($_GET['next'] ?? '/dashboard.php');
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
        header('Location: ' . SITE_URL . safeRedirectPath($_POST['next'] ?? '/dashboard.php'));
        exit;
    }
    $error = $result; // string = error message
}

$pageTitle = 'Log In — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Welcome back</h1>

  <?php if ($error): ?>
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
  <p class="auth-switch">Enrolling in a program? <a href="/student-signup.php">Student enrollment →</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
