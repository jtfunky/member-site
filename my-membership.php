<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();

// The enrollment record for this account (linked by id, or matched by email).
$st = db()->prepare(
    'SELECT * FROM students WHERE user_id = ? OR email = ? ORDER BY user_id IS NULL LIMIT 1'
);
$st->execute([$user['id'], $user['email']]);
$student = $st->fetch() ?: null;

$hasAccess = hasPremiumAccess($user);
$submitted = isset($_GET['submitted']);

// ── Proof-of-payment upload ───────────────────────────────
// Students enroll on a free trial, then submit their payment proof here. Saving
// it flags the enrollment "For review" and notifies admins, who confirm payment
// (which grants access + session credits) in Admin → Students.
$proofErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof' && $student) {
    verifyCsrf();
    $proof = $_FILES['proof'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $check = validateProofUpload($proof);
    if ($check !== true) {
        $proofErr = $check;
    } else {
        if (!is_dir(UPLOAD_PROOF_DIR)) @mkdir(UPLOAD_PROOF_DIR, 0755, true);
        $ext      = strtolower(pathinfo($proof['name'], PATHINFO_EXTENSION));
        $filename = 'proof_' . (int) $user['id'] . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($proof['tmp_name'], UPLOAD_PROOF_DIR . $filename)) {
            $proofErr = 'Could not save your file. Please try again.';
        } else {
            $proofUrl = UPLOAD_PROOF_URL . $filename;
            db()->prepare('UPDATE students SET proof_url = ?, payment_status = ? WHERE id = ?')
                ->execute([$proofUrl, 'For review', (int) $student['id']]);
            $who = $student['full_name'] ?: $student['email'];
            notifyAdmins('Payment proof submitted',
                "$who submitted proof of payment for \"" . ($student['program'] ?? '') . "\". Review it in Admin → Students.");
            header('Location: ' . SITE_URL . '/my-membership.php?proof=1');
            exit;
        }
    }
}
$proofDone = isset($_GET['proof']);

$pageTitle = 'My Enrollment — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>
<main class="container">

<?php if ($proofDone): ?>
  <div class="alert alert-success">✅ Your proof of payment was submitted and is under review. We'll confirm your enrollment by email.</div>
<?php endif; ?>
<?php if ($proofErr): ?>
  <div class="alert alert-error"><?= htmlspecialchars($proofErr) ?></div>
<?php endif; ?>

<div class="admin-header"><h1>My Enrollment</h1></div>

<?php if (!$student): ?>
  <div class="alert alert-info">
    We couldn't find an enrollment for your account.
    <a href="/student-signup.php">Enroll now</a> to get started.
  </div>
<?php else: ?>

  <?php
    $paid = in_array($student['payment_status'], ['Yes','yes','Paid','paid','Done','done'], true);
  ?>
  <?php if ($paid): ?>
    <div class="alert alert-success">
      ✅ Your payment is confirmed and your access is active.
      <a href="/dashboard.php">Go to your dashboard →</a>
    </div>
  <?php elseif ($hasAccess): ?>
    <div class="alert alert-success">
      ✅ Your <strong>free trial</strong> is active — you have full game access.
      <a href="/dashboard.php">Go to your dashboard →</a>
    </div>
  <?php else: ?>
    <div class="alert alert-warning">
      ⏳ <strong>Awaiting payment confirmation.</strong>
      You'll get full access once an admin approves your payment.
    </div>
  <?php endif; ?>

  <?php if (!$paid): ?>
  <div class="user-detail-card">
    <h2>Proof of Payment <span class="required">*</span></h2>
    <p>We <strong>ONLY</strong> accept GCash, PayPal, and Bank Transfers. <strong>STRICTLY</strong>, inaccurate screenshots will be nullified.</p>
    <ul class="pay-methods">
      <li><strong>GCash:</strong> 09566756845</li>
      <li><strong>PayPal:</strong> <a href="https://www.paypal.me/zachalcasid" target="_blank" rel="noopener">paypal.me/zachalcasid</a></li>
      <li><strong>Bank:</strong> Bank of the Philippine Islands<br>
        Account name: Zach Machiavell L. Alcasid<br>
        Account number: 0319213457</li>
    </ul>
    <p class="field-hint">Note: you can select your schedule once your reservation is confirmed. Please check your e-mail — including any spam or promotions folders — for the confirmation of your enrollment.</p>

    <?php if (!empty($student['proof_url'])): ?>
      <div class="alert alert-info">A proof of payment is already on file and under review. You can upload a new one below if you need to replace it.</div>
    <?php endif; ?>

    <form method="POST" action="/my-membership.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload_proof">
      <div class="form-group">
        <label>Upload proof of payment <span class="required">*</span></label>
        <input type="file" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" required>
        <span class="field-hint">Image or PDF, up to 5 MB.</span>
      </div>
      <button type="submit" class="btn btn-primary">Submit Proof of Payment</button>
    </form>
  </div>
  <?php endif; ?>

<?php endif; ?>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
