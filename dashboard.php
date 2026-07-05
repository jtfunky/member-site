<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/programs.php';

$user     = requireLogin();
requireProfileComplete($user); // self-signups must finish their profile first

// Enrollees awaiting payment confirmation only see their enrollment page.
if (($user['subscription_status'] ?? '') === 'pending') {
    header('Location: ' . SITE_URL . '/my-membership.php');
    exit;
}

requirePlacementTest($user); // new signups take the placement test before content

$hasAccess = hasPremiumAccess($user);
$days     = getDaysRemaining($user);
$currency = $user['currency'] ?: getUserCurrency();
$price    = formatPrice(getPrice($currency), $currency);
$welcome  = isset($_GET['welcome']);

// Web-access-only students don't have one-on-one sessions; session students do.
$student = null;
try {
    $st = db()->prepare('SELECT program, session_credits, payment_status FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) $user['id']]);
    $student = $st->fetch() ?: null;
} catch (\Throwable $e) { /* students table optional */ }
$hasSessions = studentHasSessions($student);

$pageTitle = 'Dashboard — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<main class="container">

<?php if ($welcome): ?>
<div class="alert alert-success">
  Welcome to <?= SITE_NAME ?>! You have <?= TRIAL_DAYS_SELF ?> days of free access. Enjoy!
</div>
<?php endif; ?>

<?php if (!$hasAccess): ?>
<div class="alert alert-warning">
  Your access has expired. <a href="/payment.php">Renew your membership</a> to continue.
</div>
<?php elseif ($days <= CANCEL_WINDOW_DAYS): ?>
<div class="alert alert-info">
  Your membership expires in <strong><?= $days ?> day<?= $days !== 1 ? 's' : '' ?></strong>.
  <?php if ($user['subscription_status'] !== 'cancelled'): ?>
    Renewal is automatic. <a href="/cancel-subscription.php">Cancel before renewal</a> if you don't want to be charged.
  <?php else: ?>
    Your subscription is cancelled and will not renew.
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="dashboard-header">
  <h1>Hello, <?= htmlspecialchars($user['first_name'] ?: $user['username']) ?>!</h1>
  <p class="text-muted">
    <?php if ($hasAccess): ?>
      Membership active · <?= $days ?> day<?= $days !== 1 ? 's' : '' ?> remaining
    <?php else: ?>
      No active membership
    <?php endif; ?>
  </p>
</div>

<div class="tile-grid">

  <a href="<?= $hasAccess ? '/game.php' : '/payment.php' ?>" class="tile <?= $hasAccess ? '' : 'tile--locked' ?>">
    <div class="tile-icon">🥁</div>
    <h3>Drum Game</h3>
    <p>Play songs with your e-drum kit or acoustic drums</p>
    <?php if (!$hasAccess): ?><span class="badge-locked">Members Only</span><?php endif; ?>
  </a>

  <a href="<?= $hasAccess ? '/profile.php' : '/payment.php' ?>" class="tile <?= $hasAccess ? '' : 'tile--locked' ?>">
    <div class="tile-icon">👤</div>
    <h3>My Profile</h3>
    <p>Edit your name, avatar, and bio</p>
    <?php if (!$hasAccess): ?><span class="badge-locked">Members Only</span><?php endif; ?>
  </a>

  <a href="<?= $hasAccess ? '/exclusive/' : '/payment.php' ?>" class="tile <?= $hasAccess ? '' : 'tile--locked' ?>">
    <div class="tile-icon">⭐</div>
    <h3>Exclusive Content</h3>
    <p>Member-only lessons and resources</p>
    <?php if (!$hasAccess): ?><span class="badge-locked">Members Only</span><?php endif; ?>
  </a>

  <?php if ($hasAccess && $hasSessions): ?>
  <a href="/my-sessions.php" class="tile">
    <div class="tile-icon">📅</div>
    <h3>My Sessions</h3>
    <p>Book and manage your one-on-one sessions<?php
      $cr = (int) ($student['session_credits'] ?? 0);
      if ($cr > 0) echo ' · ' . $cr . ' credit' . ($cr !== 1 ? 's' : '') . ' left';
    ?></p>
  </a>
  <?php endif; ?>

  <?php if (!$hasAccess || $days <= CANCEL_WINDOW_DAYS): ?>
  <a href="/payment.php" class="tile tile--cta">
    <div class="tile-icon">💳</div>
    <h3><?= $hasAccess ? 'Manage Membership' : 'Activate Membership' ?></h3>
    <p><?= $price ?>/month · Cancel anytime</p>
  </a>
  <?php endif; ?>

</div>

<?php if ($user['role'] === 'admin'): ?>
<div class="admin-shortcut">
  <a href="/admin/" class="btn btn-secondary">⚙️ Admin Panel</a>
</div>
<?php endif; ?>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
