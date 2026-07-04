<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/programs.php';

$user = requireLogin();

$sq = db()->prepare('SELECT * FROM students WHERE user_id = ? OR email = ? ORDER BY user_id IS NULL LIMIT 1');
$sq->execute([$user['id'], $user['email']]);
$student = $sq->fetch() ?: null;

$PACKAGES = programSessionCredits();  // program => credits
$AMOUNTS  = coursePrograms();         // program => amount
$error = ''; $submitted = false;

if ($student && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (tooManyRequests('sessiontopup', 5, 15)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $program = trim($_POST['program'] ?? '');
        $proof   = $_FILES['proof'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $check   = validateProofUpload($proof);

        if (!isset($PACKAGES[$program])) {
            $error = 'Please choose a valid package.';
        } elseif ($check !== true) {
            $error = $check;
        } else {
            if (!is_dir(UPLOAD_PROOF_DIR)) @mkdir(UPLOAD_PROOF_DIR, 0755, true);
            $ext      = strtolower(pathinfo($proof['name'], PATHINFO_EXTENSION));
            $filename = 'topup_' . (int) $student['id'] . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($proof['tmp_name'], UPLOAD_PROOF_DIR . $filename)) {
                $error = 'Could not save your proof file. Please try again.';
            } else {
                $proofUrl = UPLOAD_PROOF_URL . $filename;
                db()->prepare(
                    'INSERT INTO session_topups (student_id, user_id, program, credits, amount, currency, proof_url)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int) $student['id'], $user['id'], $program,
                    (int) $PACKAGES[$program], (float) ($AMOUNTS[$program] ?? 0), 'PHP', $proofUrl,
                ]);
                $submitted = true;
                $sname = trim(($student['full_name'] ?? '') ?: (($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
                notifyAdmins('New session payment proof',
                    "$sname submitted proof of payment for \"$program\" (" . (int) $PACKAGES[$program] . " session(s)). Review it in Admin → Sessions.");
            }
        }
    }
}

$credits = $student ? (int) $student['session_credits'] : 0;
$method  = (($_GET['method'] ?? '') === 'card') ? 'card' : 'bank'; // bank is the live method

$pageTitle = 'Buy Sessions — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard', 'payment'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>
<main class="container container--narrow">
<style>
  .pay-tabs { display: flex; gap: .5rem; margin: 1rem 0; }
  .pay-tab { flex: 1; text-align: center; padding: .6rem .75rem; border: 1px solid var(--border, #2a2a3a); border-radius: 10px; text-decoration: none; color: inherit; opacity: .8; }
  .pay-tab.active { border-color: var(--primary, #7c5cff); background: color-mix(in srgb, var(--primary, #7c5cff) 14%, transparent); opacity: 1; font-weight: 600; }
</style>

<div class="admin-header"><h1>Buy More Sessions</h1></div>

<?php if (!$student): ?>
  <div class="alert alert-info">We couldn't find an enrollment for your account. <a href="/student-signup.php">Enroll first →</a></div>
<?php elseif ($submitted): ?>
  <div class="alert alert-success">
    ✅ Thanks! Your payment proof was submitted. An admin will review it and add your sessions once confirmed.
    <a href="/my-sessions.php">Back to my sessions →</a>
  </div>
<?php else: ?>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="alert alert-info">You currently have <strong><?= $credits ?></strong> session credit<?= $credits === 1 ? '' : 's' ?>.</div>

  <div class="pay-tabs">
    <a class="pay-tab <?= $method === 'bank' ? 'active' : '' ?>" href="?method=bank">🏦 Bank Transfer</a>
    <a class="pay-tab <?= $method === 'card' ? 'active' : '' ?>" href="?method=card">💳 Card</a>
  </div>

  <?php if ($method === 'card'): ?>
  <div class="payment-card">
    <h2>Pay by Card</h2>
    <p class="field-hint" style="margin-top:.5rem;">💳 Card payments are coming soon. For now please use
      <a href="?method=bank">Bank Transfer</a> — an admin confirms your payment and adds your sessions.</p>
  </div>
  <?php else: ?>
  <div class="payment-card">
    <h2>Payment Instructions</h2>
    <p>Send your payment for the package you want, then upload your proof below. An
       admin will confirm it and add the sessions to your account.</p>
    <ul class="plan-features">
      <li><strong>PayPal:</strong> <a href="https://www.paypal.me/zachalcasid" target="_blank" rel="noopener">paypal.me/zachalcasid</a></li>
      <li><strong>GCash:</strong> 0956 675 6845 — Zach Machiavell L. Alcasid</li>
      <li><strong>Bank transfer (BPI):</strong> Acct 0319213457 — Zach Machiavell L. Alcasid</li>
      <li>Include your name in the reference/notes so we can match your payment.</li>
    </ul>

    <form method="POST" action="/session-payment.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <div class="form-group">
        <label>Package <span class="required">*</span></label>
        <select name="program" required>
          <option value="">— Select a package —</option>
          <?php foreach ($PACKAGES as $prog => $cr): ?>
            <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?> — <?= $cr ?> session<?= $cr === 1 ? '' : 's' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Proof of Payment <span class="required">*</span></label>
        <input type="file" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" required>
        <span class="field-hint">Image or PDF, up to 5 MB.</span>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Submit Payment Proof</button>
    </form>
  </div>
  <?php endif; ?>

<?php endif; ?>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
