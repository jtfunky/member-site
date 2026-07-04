<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (tooManyRequests('register', 5, 15)) {
        $error = 'Too many sign-up attempts. Please wait a few minutes and try again.';
    } else {
        $email     = trim($_POST['email']     ?? '');
        $password  = $_POST['password']       ?? '';
        $password2 = $_POST['password2']      ?? '';
        $first     = trim($_POST['first_name'] ?? '');
        $last      = trim($_POST['last_name']  ?? '');

        if ($password !== $password2) {
            $error = 'Passwords do not match.';
        } else {
            $username = uniqueUsernameFromEmail($email); // auto-generated — no username field
            $result   = registerUser($username, $email, $password, $first, $last);
            if (is_int($result)) {
                require_once __DIR__ . '/includes/programs.php';
                ensureWebAccessStudent($result); // track this account as a Website-access student
                // Auto-login
                loginUser($email, $password);
                header('Location: ' . SITE_URL . '/dashboard.php?welcome=1');
                exit;
            }
            $error = $result;
        }
    }
}

$currency = getUserCurrency();
$price    = formatPrice(getPrice($currency), $currency);

$pageTitle = 'Create Account — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card auth-card--wide">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Create your account</h1>
  <p class="auth-sub"><?= TRIAL_DAYS_SELF ?> days free, then <?= $price ?>/month. Cancel anytime.</p>
  <p class="auth-sub" style="font-size:.8rem;margin-top:.25rem">Password must be 8+ characters with an uppercase letter and a number.</p>

  <?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/register.php">
    <?= csrfField() ?>
    <div class="form-row">
      <div class="form-group">
        <label>First Name</label>
        <input type="text" name="first_name" autofocus value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Last Name</label>
        <input type="text" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Email <span class="required">*</span></label>
      <input type="email" name="email" required
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Password <span class="required">*</span></label>
      <input type="password" name="password" required minlength="8">
      <span class="field-hint">Minimum 8 characters</span>
    </div>
    <div class="form-group">
      <label>Confirm Password <span class="required">*</span></label>
      <input type="password" name="password2" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    <p class="terms-note">By creating an account you agree to our terms of service.</p>
  </form>

  <?php require __DIR__ . '/includes/social-buttons.php'; ?>

  <p class="auth-switch">Already have an account? <a href="/login.php">Log in</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
