<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = requireAdmin();
$db    = db();

$referrals = $db->query(
    'SELECT r.*, ru.username AS referrer_username, ru.email AS referrer_email,
     du.username AS referred_username, du.email AS referred_email
     FROM referrals r
     JOIN users ru ON ru.id = r.referrer_user_id
     JOIN users du ON du.id = r.referred_user_id
     ORDER BY r.rewarded_at DESC'
)->fetchAll();

$pageTitle = 'Referrals — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Referrals</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<p class="field-hint">Referral codes and rewards are automatic — every account gets a code, and referring someone in earns the referrer <?= REFERRAL_REWARD_DAYS ?> free days. This is a read-only log for oversight.</p>

<div class="table-scroll">
<table class="data-table">
  <thead><tr><th>Referrer</th><th>Referred</th><th>Reward</th><th>Date</th></tr></thead>
  <tbody>
  <?php if (!$referrals): ?>
    <tr><td colspan="4">No referrals yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($referrals as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['referrer_username']) ?> <span class="text-muted"><?= htmlspecialchars($r['referrer_email']) ?></span></td>
      <td><?= htmlspecialchars($r['referred_username']) ?> <span class="text-muted"><?= htmlspecialchars($r['referred_email']) ?></span></td>
      <td><?= (int) $r['reward_days'] ?> days</td>
      <td><?= date('M j, Y', strtotime($r['rewarded_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
