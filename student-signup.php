<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/countries.php';
require_once __DIR__ . '/includes/programs.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

// Available programs and their amounts (PHP). Shared with the admin form.
$PROGRAMS = coursePrograms();

$error   = '';
$success = false;
$old     = $_POST; // re-fill the form on validation error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (tooManyRequests('enroll', 5, 15)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $mi    = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_POST['middle_initial'] ?? ''), 0, 1));
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';
        $dobIn = trim($_POST['date_of_birth'] ?? '');
        $level = trim($_POST['experience'] ?? '');
        $prog  = trim($_POST['program'] ?? '');

        // Derive + validate age from the date of birth.
        $dob = null; $age = null;
        $d = $dobIn !== '' ? DateTime::createFromFormat('!Y-m-d', $dobIn) : false;
        if ($d && $d->format('Y-m-d') === $dobIn && $d <= new DateTime('today')) {
            $dob = $dobIn;
            $age = (int) $d->diff(new DateTime('today'))->y;
        }

        if ($first === '' || $last === '') {
            $error = 'First and last name are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } elseif ($dob === null) {
            $error = 'Please enter a valid date of birth.';
        } elseif (!isset($PROGRAMS[$prog])) {
            $error = 'Please choose a program.';
        } elseif (($proofCheck = validateProofUpload($_FILES['proof'] ?? ['error' => UPLOAD_ERR_NO_FILE])) !== true) {
            $error = $proofCheck;
        } else {
            // Create the login account locked ("pending", no access) — proof of
            // payment is required up front now, so there's no free trial on this
            // path. An admin confirms the proof to grant access + session credits.
            $result = createEnrolleeAccount($email, $pass, $first, $last);
            if (is_string($result)) {
                $error = $result;
            } else {
                $userId = $result;

                $full     = $last . ', ' . $first . ($mi !== '' ? ' ' . $mi : '');
                $guardian = ($age < 18) ? trim($_POST['parent_guardian'] ?? '') : '';
                $dial     = preg_replace('/[^\d+]/', '', $_POST['country_code'] ?? '');
                $num      = preg_replace('/\D/', '', $_POST['phone'] ?? '');
                $phone    = $num !== '' ? trim($dial . ' ' . $num) : '';
                $country  = trim($_POST['address'] ?? '');
                if ($country === '') $country = getUserCountryName();

                $proofUrl = null;
                if (!is_dir(UPLOAD_PROOF_DIR)) @mkdir(UPLOAD_PROOF_DIR, 0755, true);
                $ext      = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
                $filename = 'proof_' . $userId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_PROOF_DIR . $filename)) {
                    $proofUrl = UPLOAD_PROOF_URL . $filename;
                }

                db()->prepare(
                    'INSERT INTO students
                       (user_id, enrolled_at, email, last_name, first_name, middle_initial,
                        full_name, age, date_of_birth, parent_guardian, address, phone,
                        experience, program, program_amount, currency, proof_url,
                        payment_status, source_file)
                     VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PHP", ?, "For review", "enroll")'
                )->execute([
                    $userId, $email, $last, $first, $mi, $full, $age, $dob,
                    $guardian, $country, $phone, $level, $prog,
                    $PROGRAMS[$prog], $proofUrl,
                ]);

                notifySignup('New enrollment submitted',
                    "$full enrolled in \"$prog\" and submitted proof of payment. Review it in Admin → Students to confirm and unlock their sessions.");

                // No auto-login — createEnrolleeAccount() already emailed a
                // verification link, and login is blocked until it's clicked.
                $success = true;
            }
        }
    }
}

$curDial = dialByIso(getUserCountry()) ?: '+63';
$curCtry = getUserCountryName();

$pageTitle = 'Enroll — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card auth-card--wide">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <p class="field-hint" style="text-align:center; margin-top:-1rem; margin-bottom:1.5rem;">Panay Ave. cor. Roces Ave., Quezon City</p>

  <?php if ($success): ?>
  <h1>Check your email</h1>
  <div class="alert alert-success">
    Your enrollment was submitted. We've sent a verification link to your email — click it to activate your account (you won't be able to log in until you do), then we'll review your proof of payment.
  </div>
  <p class="auth-switch">Didn't get it? <a href="/resend-verification.php">Resend the link</a>.</p>
  <p class="auth-switch"><a href="/login.php">Back to Login</a></p>

  <?php else: ?>
  <h1>Student Enrollment</h1>

  <?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/student-signup.php" enctype="multipart/form-data">
    <?= csrfField() ?>

    <div class="form-row">
      <div class="form-group">
        <label>First Name <span class="required">*</span></label>
        <input type="text" name="first_name" required value="<?= htmlspecialchars($old['first_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Last Name <span class="required">*</span></label>
        <input type="text" name="last_name" required value="<?= htmlspecialchars($old['last_name'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Middle Initial</label>
        <input type="text" name="middle_initial" maxlength="1" pattern="[A-Za-z]" title="A single letter" style="text-transform:uppercase" value="<?= htmlspecialchars($old['middle_initial'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Date of Birth <span class="required">*</span></label>
        <input type="date" name="date_of_birth" required value="<?= htmlspecialchars($old['date_of_birth'] ?? '') ?>">
      </div>
    </div>

    <div class="form-group">
      <label>Email <span class="required">*</span></label>
      <input type="email" name="email" required value="<?= htmlspecialchars($old['email'] ?? '') ?>">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Password <span class="required">*</span></label>
        <input type="password" name="password" required minlength="8">
        <span class="field-hint">8+ chars, one uppercase, one number</span>
      </div>
      <div class="form-group">
        <label>Confirm Password <span class="required">*</span></label>
        <input type="password" name="password2" required>
      </div>
    </div>

    <div class="form-group">
      <label>Mobile Number</label>
      <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <select name="country_code" style="flex:0 0 auto; max-width:6.5rem;">
          <?php $picked = false; foreach (getCountries() as $c):
              $sel = (!$picked && $c['dial'] === $curDial) ? 'selected' : '';
              if ($sel) $picked = true; ?>
            <option value="<?= htmlspecialchars($c['dial']) ?>" <?= $sel ?>><?= flagEmoji($c['iso']) ?> <?= htmlspecialchars($c['dial']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="tel" name="phone" inputmode="numeric" placeholder="Mobile number" style="flex:1;" value="<?= htmlspecialchars($old['phone'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Level</label>
        <select name="experience">
          <option value="">— Select —</option>
          <?php foreach (['Beginner', 'Intermediate', 'Advanced'] as $lvl): ?>
            <option value="<?= $lvl ?>" <?= ($old['experience'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Country</label>
        <select name="address">
          <option value="">— Select —</option>
          <?php $selCtry = $old['address'] ?? $curCtry; foreach (getCountries() as $c): ?>
            <option value="<?= htmlspecialchars($c['name']) ?>" <?= $selCtry === $c['name'] ? 'selected' : '' ?>><?= flagEmoji($c['iso']) ?> <?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Parent / Guardian <span class="field-hint">(if under 18)</span></label>
      <input type="text" name="parent_guardian" value="<?= htmlspecialchars($old['parent_guardian'] ?? '') ?>">
    </div>

    <div class="program-plans">
      <div class="plan-card">
        <h3>Distance Learning (Online)</h3>
        <ul class="plan-features">
          <li>1-on-1 mentorship</li>
          <li>1 hour and 30 mins Session</li>
          <li>Exclusive mentorship especially for students who would like to gain further knowledge with drumming</li>
        </ul>
        <p class="plan-price">₱2,000 <span>per session</span></p>
        <p class="plan-price">₱9,000 <span>6 sessions</span></p>
      </div>
      <div class="plan-card">
        <h3>1 on 1 Face to Face (F2F)</h3>
        <ul class="plan-features">
          <li>Access to 6 in-person drum classes</li>
          <li>1 hour and 30 mins Session</li>
          <li>Unlimited access to exclusive content</li>
          <li>Exclusive mentorship especially for students who would like to gain further knowledge with drumming</li>
        </ul>
        <p class="plan-price">₱9,000</p>
        <p class="plan-note">Downpayment fee: ₱1,000</p>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Program <span class="required">*</span></label>
        <select name="program" id="programSelect" data-amount-target="amountDisplay" data-amount-mode="display" required>
          <option value="">— Select a program —</option>
          <?php foreach ($PROGRAMS as $p => $amt): ?>
            <option value="<?= htmlspecialchars($p) ?>" data-amount="<?= (int) $amt ?>" <?= ($old['program'] ?? '') === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Amount</label>
        <input type="text" id="amountDisplay" readonly placeholder="—">
      </div>
    </div>

    <div class="plan-card">
      <h3>Payment</h3>
      <p>We <strong>ONLY</strong> accept GCash, PayPal, and Bank Transfers. <strong>STRICTLY</strong>, inaccurate screenshots will be nullified.</p>
      <ul class="pay-methods">
        <li><strong>GCash:</strong> 09566756845</li>
        <li><strong>PayPal:</strong> <a href="https://www.paypal.me/zachalcasid" target="_blank" rel="noopener">paypal.me/zachalcasid</a></li>
        <li><strong>Bank:</strong> Bank of the Philippine Islands<br>
          Account name: Zach Machiavell L. Alcasid<br>
          Account number: 0319213457</li>
      </ul>
    </div>

    <p style="text-align:center; font-weight:700;">Exact address &amp; schedules will be sent to your email upon confirmation of payment.</p>

    <div class="form-group">
      <label>Upload proof of payment <span class="required">*</span></label>
      <input type="file" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" required>
      <span class="field-hint">Image or PDF, up to 5 MB. You won't get access until we confirm it.</span>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Enroll</button>
  </form>

  <p class="auth-switch">Already enrolled? <a href="/login.php">Log in</a></p>
  <?php endif; ?>
</div>

<script src="/assets/js/program-amount.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
