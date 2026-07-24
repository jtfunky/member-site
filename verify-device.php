<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
if (($user['role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . roleHome($user['role'] ?? 'user'));
    exit;
}

$next = safeRedirectPath($_POST['next'] ?? $_GET['next'] ?? '/admin/');

// Nothing to verify if the lock is off or this device is already trusted.
if (!ADMIN_DEVICE_LOCK || adminDeviceTrusted((int) $user['id'])) {
    sessionStart();
    $_SESSION['admin_device_ok'] = true;
    header('Location: ' . SITE_URL . $next);
    exit;
}

sessionStart();
$error = '';
$notice = '';

// Generate + email a fresh 6-digit code, stored (hashed) in the session.
function sendDeviceCode(array $user): bool {
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['dev_chal'] = [
        'hash'     => password_hash($code, PASSWORD_BCRYPT),
        'expires'  => time() + 600,   // 10 minutes
        'sent_at'  => time(),
        'attempts' => 0,
    ];
    return sendMail(
        $user['email'],
        SITE_NAME . ' — device verification code',
        "Your admin device verification code is: {$code}\n\n"
        . "It expires in 10 minutes. If you didn't just try to sign in to the admin area, "
        . "change your password immediately."
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $ch = $_SESSION['dev_chal'] ?? null;
        if ($ch && (time() - $ch['sent_at']) < 60) {
            $error = 'Please wait a moment before requesting another code.';
        } else {
            $notice = sendDeviceCode($user) ? 'A new code has been emailed to you.'
                                            : ($error = 'Could not send the email. Please contact support.');
            if ($error) $notice = '';
        }
    } else {
        $ch   = $_SESSION['dev_chal'] ?? null;
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        if (!$ch || time() > $ch['expires']) {
            $error = 'That code has expired — request a new one.';
        } elseif (($ch['attempts'] ?? 0) >= 5) {
            $error = 'Too many attempts. Please request a new code.';
        } elseif ($code !== '' && password_verify($code, $ch['hash'])) {
            registerAdminDevice((int) $user['id']);
            $_SESSION['admin_device_ok'] = true;
            unset($_SESSION['dev_chal']);
            header('Location: ' . SITE_URL . $next);
            exit;
        } else {
            $_SESSION['dev_chal']['attempts'] = ($ch['attempts'] ?? 0) + 1;
            $error = 'Incorrect code. Please try again.';
        }
    }
}

// First visit (no pending challenge) → send a code automatically.
if (empty($_SESSION['dev_chal']) && !$error) {
    if (!sendDeviceCode($user)) {
        $error = 'We couldn’t email your verification code. Please make sure email is configured, or contact support.';
    }
}

$maskedEmail = (function (string $e): string {
    $p = explode('@', $e);
    if (count($p) !== 2) return $e;
    $n = $p[0];
    return mb_substr($n, 0, 1) . str_repeat('*', max(1, mb_strlen($n) - 1)) . '@' . $p[1];
})($user['email']);

$pageTitle = 'Verify this device — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$showNav   = false;
require __DIR__ . '/includes/header.php';
?>
<main class="container" style="max-width:440px;margin:3rem auto;">
  <h1><i class="ti ti-shield-lock" aria-hidden="true"></i> Verify this device</h1>
  <p class="text-muted">For security, admin access from a new device needs a one-time code.
    We emailed a 6-digit code to <strong><?= htmlspecialchars($maskedEmail) ?></strong>.</p>

  <?php if ($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
  <?php if ($error):  ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" action="/verify-device.php" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <div class="form-group">
      <label for="code">Verification code</label>
      <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
             autocomplete="one-time-code" autofocus placeholder="123456" style="letter-spacing:.3em;font-size:1.2rem">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Verify &amp; trust this device</button>
  </form>

  <form method="POST" action="/verify-device.php" style="margin-top:.75rem;text-align:center">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="resend">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <button type="submit" class="btn btn-ghost btn-sm">Resend code</button>
    <a href="/logout.php" class="btn btn-ghost btn-sm">Log out</a>
  </form>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
