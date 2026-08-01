<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$error      = '';
$success    = false;
$promoNote  = '';
// Referral link (?ref=<code>) — carried through the form as a hidden field so
// it survives the POST. GET only used as the initial source.
$refCode = trim($_GET['ref'] ?? ($_POST['ref'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (tooManyRequests('register', 5, 15)) {
        $error = 'Too many sign-up attempts. Please wait a few minutes and try again.';
    } else {
        $email      = trim($_POST['email']     ?? '');
        $password   = $_POST['password']       ?? '';
        $password2  = $_POST['password2']      ?? '';
        $first      = trim($_POST['first_name'] ?? '');
        $last       = trim($_POST['last_name']  ?? '');
        $promoInput = trim($_POST['promo_code'] ?? '');

        if ($password !== $password2) {
            $error = 'Passwords do not match.';
        } else {
            $username = uniqueUsernameFromEmail($email); // auto-generated — no username field
            $result   = registerUser($username, $email, $password, $first, $last, $refCode ?: null);
            if (is_int($result)) {
                require_once __DIR__ . '/includes/programs.php';
                ensureWebAccessStudent($result); // track this account as a Website-access student

                // Any account created while a raffle campaign is running gets
                // entered automatically, not just sign-ups via the dedicated
                // raffle-signup.php splash funnel.
                $activeCampaign = getActiveCampaign();
                if ($activeCampaign) {
                    enterRaffle((int) $activeCampaign['id'], $result);
                }

                notifySignup('New user registered',
                    'A new account was just created on ' . SITE_NAME . ":\n\n"
                    . 'Name: ' . trim($first . ' ' . $last) . "\nEmail: {$email}\n\n"
                    . 'Manage students: ' . SITE_URL . '/admin/students.php');

                // Promo code: a bad/expired/exhausted code never blocks the
                // account itself — the bonus is just skipped.
                if ($promoInput !== '') {
                    $promo = validatePromoCode($promoInput);
                    if (is_array($promo)) {
                        redeemPromoCode((int) $promo['id'], $result, (int) $promo['bonus_days']);
                    }
                }

                // No auto-login — registerUser() already emailed a verification
                // link, and login is blocked until it's clicked.
                $success = true;
            } else {
                $error = $result;
            }
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

  <?php if ($success): ?>
  <h1>Check your email</h1>
  <div class="alert alert-success">
    We've sent a verification link to your email. Click it to activate your account — you won't be able to log in until you do.
  </div>
  <p class="auth-switch">Didn't get it? <a href="/resend-verification.php">Resend the link</a>.</p>
  <p class="auth-switch"><a href="/login.php">Back to Login</a></p>

  <?php else: ?>
  <h1>Create your account</h1>
  <p class="auth-sub" style="font-size:.8rem;margin-top:.25rem">Password must be 8+ characters with an uppercase letter and a number.</p>

  <?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/register.php">
    <?= csrfField() ?>
    <input type="hidden" name="ref" value="<?= htmlspecialchars($refCode) ?>">
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
    <div class="form-group">
      <label>Promo Code <span style="font-weight:400;color:var(--dim,#8a8a99)">(optional)</span></label>
      <input type="text" name="promo_code" value="<?= htmlspecialchars($_POST['promo_code'] ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    <p class="terms-note">By creating an account you agree to our terms of service.</p>
  </form>

  <?php require __DIR__ . '/includes/social-buttons.php'; ?>

  <p class="auth-switch">Already have an account? <a href="/login.php">Log in</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
