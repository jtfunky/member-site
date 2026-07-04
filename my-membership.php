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

$pageTitle = 'My Enrollment — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>
<main class="container">

<?php if ($submitted): ?>
<div class="alert alert-success">
  Thanks! Your enrollment was submitted. We'll review your proof of payment and unlock your access once it's confirmed.
</div>
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
  <div class="alert alert-<?= $hasAccess ? 'success' : 'warning' ?>">
    <?php if ($hasAccess): ?>
      ✅ Your payment is confirmed and your access is active.
      <a href="/dashboard.php">Go to your dashboard →</a>
    <?php else: ?>
      ⏳ <strong>Awaiting payment confirmation.</strong>
      An admin will review your proof of payment. You'll get full access once it's approved.
    <?php endif; ?>
  </div>

  <div class="user-detail-card">
    <div class="detail-grid">
      <div>
        <p><strong>Name:</strong> <?= htmlspecialchars($student['full_name'] ?: trim($student['first_name'] . ' ' . $student['last_name'])) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($student['email']) ?></p>
        <p><strong>Mobile:</strong> <?= htmlspecialchars($student['phone'] ?: '—') ?></p>
        <p><strong>Date of Birth:</strong> <?= htmlspecialchars((string) ($student['date_of_birth'] ?: '—')) ?></p>
        <p><strong>Level:</strong> <?= htmlspecialchars($student['experience'] ?: '—') ?></p>
        <p><strong>Country:</strong> <?= htmlspecialchars($student['address'] ?: '—') ?></p>
      </div>
      <div>
        <p><strong>Program:</strong> <?= htmlspecialchars($student['program'] ?: '—') ?></p>
        <p><strong>Amount:</strong> <?= $student['program_amount'] !== null ? htmlspecialchars($student['currency'] . ' ' . number_format((float) $student['program_amount'], 0)) : '—' ?></p>
        <p><strong>Payment Status:</strong> <?= $paid ? '✅ ' : '' ?><?= htmlspecialchars($student['payment_status'] ?: 'Pending') ?></p>
        <p><strong>Submitted:</strong> <?= htmlspecialchars((string) ($student['enrolled_at'] ?: '—')) ?></p>
        <?php if (!empty($student['proof_url'])): ?>
          <p><strong>Proof:</strong> <a href="<?= htmlspecialchars($student['proof_url']) ?>" target="_blank" rel="noopener">view uploaded proof ↗</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
