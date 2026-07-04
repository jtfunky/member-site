<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();

$confirmed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $confirmed = ($_POST['confirm'] ?? '') === '1';
}
if ($confirmed) {
    cancelSubscription($user['id']);
    header('Location: ' . SITE_URL . '/dashboard.php?cancelled=1');
    exit;
}

$expires = $user['access_expires_at']
    ? date('F j, Y', strtotime($user['access_expires_at']))
    : 'N/A';

$pageTitle = 'Cancel Subscription — ' . SITE_NAME;
$pageCss   = ['main', 'payment'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<main class="container container--narrow">
<div class="payment-card">
  <h1>Cancel Subscription</h1>
  <p>Are you sure you want to cancel your subscription?</p>

  <div class="cancel-info">
    <p>✅ Your access continues until <strong><?= $expires ?></strong></p>
    <p>❌ Your subscription will not renew after that date</p>
    <p>💳 You will not be charged again</p>
  </div>

  <form method="POST" action="/cancel-subscription.php">
    <?= csrfField() ?>
    <input type="hidden" name="confirm" value="1">
    <button type="submit" class="btn btn-danger btn-block">Yes, Cancel My Subscription</button>
  </form>
  <a href="/dashboard.php" class="btn btn-ghost btn-block">No, Keep My Membership</a>
</div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
