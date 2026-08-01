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

// The beta free-access rule (see gameLaunchFreeAccessUntil()) can guarantee
// Groove Quest access past the account's own trial/subscription expiry —
// show that later date instead of the raw trial countdown when it applies,
// so "days remaining" reflects when access actually ends, not just the
// shorter of the two numbers.
if ($hasAccess) {
    $betaUntil = gameLaunchFreeAccessUntil($user);
    if ($betaUntil) {
        $rawUntil = $user['access_expires_at'] ? new DateTime($user['access_expires_at'], new DateTimeZone('UTC')) : null;
        if (!$rawUntil || $betaUntil > $rawUntil) {
            $now  = new DateTime('now', new DateTimeZone('UTC'));
            $days = max($days, (int) ceil(($betaUntil->getTimestamp() - $now->getTimestamp()) / 86400));
        }
    }
}
$currency = $user['currency'] ?: getUserCurrency();
$price    = formatPrice(getPrice($currency), $currency);
$welcome  = isset($_GET['welcome']);

// Membership tile hidden site-wide during the free beta — reappears 1 week
// before GAME_FREE_ACCESS_UNTIL, the beta's shared free-access end date. The
// "expires in X days / cancel before renewal" alert above is untouched, so
// existing subscribers can still cancel even while this tile is hidden.
$membershipTileVisible = (new DateTime('now', new DateTimeZone('UTC')))
    >= (new DateTime(GAME_FREE_ACCESS_UNTIL, new DateTimeZone('Asia/Manila')))->modify('-7 days');

// Web-access-only students don't have one-on-one sessions; session students do.
$student = null;
try {
    $st = db()->prepare('SELECT program, session_credits, payment_status FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) $user['id']]);
    $student = $st->fetch() ?: null;
} catch (\Throwable $e) { /* students table optional */ }
$hasSessions = studentHasSessions($student);

// "Groove Quest" segment: self-registered users with no one-on-one enrollment
// at all. Admins and real one-on-one students keep the classic header nav
// (see includes/nav.php) and don't need these duplicated here as tiles.
$isGrooveQuestUser = ($user['role'] === 'user') && !$hasSessions;
$showLaunchGated = ($user['role'] === 'admin')
    || isGameBetaBypassEmail($user['email'] ?? '')
    || gameBetaHasLaunched();
$takenPlacement = hasTakenPlacementTest((int) $user['id']);

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
    <div class="tile-icon"><i class="ti ti-player-play" aria-hidden="true"></i></div>
    <h3>Groove Quest</h3>
    <p>Play songs with your e-drum kit or acoustic drums</p>
    <?php if (!$hasAccess): ?><span class="badge-locked">Members Only</span><?php endif; ?>
  </a>

  <?php if ($isGrooveQuestUser && $showLaunchGated): ?>
  <a href="<?= $hasAccess ? '/request-song.php' : '/payment.php' ?>" class="tile <?= $hasAccess ? '' : 'tile--locked' ?>">
    <div class="tile-icon"><i class="ti ti-music" aria-hidden="true"></i></div>
    <h3>Request a Song</h3>
    <p>Ask for a track to be added to the game</p>
    <?php if (!$hasAccess): ?><span class="badge-locked">Members Only</span><?php endif; ?>
  </a>
  <?php endif; ?>

  <?php if ($isGrooveQuestUser && !$takenPlacement): ?>
  <a href="/placement-test.php" class="tile">
    <div class="tile-icon"><i class="ti ti-clipboard" aria-hidden="true"></i></div>
    <h3>Placement Test</h3>
    <p>Find your starting skill level</p>
  </a>
  <?php elseif ($isGrooveQuestUser && $showLaunchGated): ?>
  <a href="/my-plan.php" class="tile">
    <div class="tile-icon"><i class="ti ti-clipboard" aria-hidden="true"></i></div>
    <h3>My Plan</h3>
    <p>Your personalized practice plan</p>
  </a>
  <?php endif; ?>

  <a href="<?= $hasAccess ? '/profile.php' : '/payment.php' ?>" class="tile <?= $hasAccess ? '' : 'tile--locked' ?>">
    <div class="tile-icon"><i class="ti ti-user" aria-hidden="true"></i></div>
    <h3>My Profile</h3>
    <p>Edit your name, avatar, and bio</p>
    <?php if (!$hasAccess): ?><span class="badge-locked">Members Only</span><?php endif; ?>
  </a>

  <?php if (!$isGrooveQuestUser): ?>
  <a href="<?= $hasAccess ? '/exclusive/' : '/payment.php' ?>" class="tile <?= $hasAccess ? '' : 'tile--locked' ?>">
    <div class="tile-icon"><i class="ti ti-star" aria-hidden="true"></i></div>
    <h3>Exclusive Content</h3>
    <p>Member-only lessons and resources</p>
    <?php if (!$hasAccess): ?><span class="badge-locked">Members Only</span><?php endif; ?>
  </a>
  <?php endif; ?>

  <a href="/profile.php#referral" class="tile">
    <div class="tile-icon"><i class="ti ti-users" aria-hidden="true"></i></div>
    <h3>Refer a Friend</h3>
    <p>Get <?= REFERRAL_REWARD_DAYS ?> free days for every signup through your link</p>
  </a>

  <?php if ($hasAccess && $hasSessions): ?>
  <a href="/my-sessions.php" class="tile">
    <div class="tile-icon"><i class="ti ti-calendar" aria-hidden="true"></i></div>
    <h3>My Sessions</h3>
    <p>Book and manage your one-on-one sessions<?php
      $cr = (int) ($student['session_credits'] ?? 0);
      if ($cr > 0) echo ' · ' . $cr . ' credit' . ($cr !== 1 ? 's' : '') . ' left';
    ?></p>
  </a>
  <?php endif; ?>

  <?php if ($membershipTileVisible && (!$hasAccess || $days <= CANCEL_WINDOW_DAYS)): ?>
  <a href="/payment.php" class="tile tile--cta">
    <div class="tile-icon"><i class="ti ti-credit-card" aria-hidden="true"></i></div>
    <h3><?= $hasAccess ? 'Manage Membership' : 'Activate Membership' ?></h3>
    <p><?= $price ?>/month · Cancel anytime</p>
  </a>
  <?php endif; ?>

</div>

<?php if ($user['role'] === 'admin'): ?>
<div class="admin-shortcut">
  <a href="/admin/" class="btn btn-secondary"><i class="ti ti-settings" aria-hidden="true"></i> Admin Panel</a>
</div>
<?php endif; ?>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
