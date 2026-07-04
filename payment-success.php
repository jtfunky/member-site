<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$days = getDaysRemaining($user);

$pageTitle = 'Payment Successful — ' . SITE_NAME;
$pageCss   = ['main', 'payment'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<main class="container container--narrow">
<div class="success-card">
  <div class="success-icon">✅</div>
  <h1>You're all set!</h1>
  <p>Your membership is now active for the next <strong><?= $days ?> days</strong>.</p>
  <a href="/game.php" class="btn btn-primary">Play Drums Now</a>
  <a href="/dashboard.php" class="btn btn-ghost">Go to Dashboard</a>
</div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
