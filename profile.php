<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/oauth.php';

$user = requireLogin();

$error   = '';
$success = '';

// Results of a link round-trip (oauth-callback redirects back here)
if (isset($_GET['linked'])) {
    $p = oauthProvider($_GET['linked']);
    if ($p) $success = $p['name'] . ' connected.';
}
if (isset($_GET['link_error'])) {
    $error = substr((string) $_GET['link_error'], 0, 200);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (($_POST['oauth_action'] ?? '') === 'unlink') {
        // Disconnect a linked social account
        $res = oauthUnlink((int) $user['id'], $_POST['provider'] ?? '');
        if ($res === true) { $success = 'Social account disconnected.'; $user = getCurrentUser(); }
        else               { $error   = $res; }
    } elseif (($_POST['promo_action'] ?? '') === 'redeem') {
        $code  = strtoupper(trim($_POST['promo_code'] ?? ''));
        $promo = validatePromoCode($code);
        if (is_string($promo)) {
            $error = $promo;
        } elseif (redeemPromoCode((int) $promo['id'], (int) $user['id'], (int) $promo['bonus_days'])) {
            $user    = getCurrentUser(); // refresh — access_expires_at just changed
            $success = 'Promo code applied — ' . ((int) $promo['bonus_days'] >= 36500 ? 'unlimited access' : (int) $promo['bonus_days'] . ' bonus days') . ' added!';
        } else {
            $error = "You've already redeemed this promo code.";
        }
    } else {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name']  ?? '');
        $bio   = trim($_POST['bio']        ?? '');

        // Handle avatar upload
        $avatarFile = '';
        if (!empty($_FILES['avatar']['tmp_name'])) {
            $validation = validateImageUpload($_FILES['avatar']);
            if ($validation !== true) {
                $error = $validation;
            } else {
                $ext      = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                $filename = 'avatar_' . $user['id'] . '_' . time() . '.' . strtolower($ext);
                $dest     = UPLOAD_AVATAR_DIR . $filename;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                    // Delete old avatar
                    if ($user['avatar'] && file_exists(UPLOAD_AVATAR_DIR . $user['avatar'])) {
                        unlink(UPLOAD_AVATAR_DIR . $user['avatar']);
                    }
                    $avatarFile = $filename;
                } else {
                    $error = 'Failed to save avatar. Check upload folder permissions.';
                }
            }
        }

        if (!$error) {
            $st = db()->prepare(
                'UPDATE users SET first_name=?, last_name=?, bio=?, updated_at=NOW()
                 ' . ($avatarFile ? ', avatar=?' : '') . '
                 WHERE id=?'
            );
            $params = [$first, $last, $bio];
            if ($avatarFile) $params[] = $avatarFile;
            $params[] = $user['id'];
            $st->execute($params);

            $user    = getCurrentUser(); // refresh
            $success = 'Profile updated successfully.';
        }
    }
}

$avatarUrl = $user['avatar']
    ? UPLOAD_AVATAR_URL . htmlspecialchars($user['avatar'])
    : '/assets/img/avatar-default.svg';

// Accounts created before the referral migration ran won't have a code yet —
// backfill lazily on first profile view rather than requiring a bulk update.
if (empty($user['referral_code'])) {
    $newCode = generateReferralCode();
    db()->prepare('UPDATE users SET referral_code=? WHERE id=?')->execute([$newCode, $user['id']]);
    $user['referral_code'] = $newCode;
}
$referralLink = SITE_URL . '/register.php?ref=' . urlencode($user['referral_code']);

$pageTitle = 'My Profile — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<main class="container container--narrow">
<h1>My Profile</h1>

<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<form method="POST" action="/profile.php" enctype="multipart/form-data" class="profile-form">
<?= csrfField() ?>

  <div class="avatar-section">
    <img id="avatar-preview" src="<?= $avatarUrl ?>" alt="Avatar" class="avatar-lg">
    <label class="btn btn-secondary btn-sm">
      Change Photo
      <input type="file" name="avatar" accept="image/*" id="avatar-input" hidden>
    </label>
    <p class="field-hint">JPG, PNG, GIF, WebP · Max 2 MB</p>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label>First Name</label>
      <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>">
    </div>
    <div class="form-group">
      <label>Last Name</label>
      <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>">
    </div>
  </div>

  <div class="form-group">
    <label>Username</label>
    <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled class="input-disabled">
    <span class="field-hint">Username cannot be changed.</span>
  </div>

  <div class="form-group">
    <label>Email</label>
    <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled class="input-disabled">
  </div>

  <div class="form-group">
    <label>Bio</label>
    <textarea name="bio" rows="4"><?= htmlspecialchars($user['bio']) ?></textarea>
  </div>

  <div class="form-actions-row">
    <button type="submit" class="btn btn-primary">Save Changes</button>
    <a href="/change-password.php" class="btn btn-ghost">Change Password</a>
  </div>
</form>

<section class="connected-accounts" id="referral">
  <h2>Your referral link</h2>
  <p class="field-hint">Share this link — when someone signs up through it, you get <?= REFERRAL_REWARD_DAYS ?> days of free access.</p>
  <div style="display:flex; gap:.75rem; align-items:center">
    <input type="text" id="referral-link-input" value="<?= htmlspecialchars($referralLink) ?>" readonly class="input-disabled font-mono" style="flex:1; margin:0">
    <button type="button" class="btn btn-secondary btn-sm" style="flex:none" onclick="navigator.clipboard.writeText(document.getElementById('referral-link-input').value)">Copy</button>
  </div>
</section>

<section class="connected-accounts" id="promo">
  <h2>Have a promo code?</h2>
  <p class="field-hint">Enter a code from the team to add bonus days to your account.</p>
  <form method="POST" action="/profile.php" class="conn-form" style="display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap">
    <?= csrfField() ?>
    <input type="hidden" name="promo_action" value="redeem">
    <div class="form-group" style="margin:0; flex:1; min-width:200px">
      <label>Promo Code</label>
      <input type="text" name="promo_code" placeholder="e.g. LAUNCH2026" style="text-transform:uppercase">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
  </form>
</section>

<?php
$enabledProviders = oauthEnabledProviders();
if ($enabledProviders):
  $connected   = oauthAccountsForUser($user['id']);
  $hasPassword = !empty($user['password_hash']);
?>
<section class="connected-accounts">
  <h2>Connected accounts</h2>
  <p class="field-hint">Link a social account to sign in faster next time.</p>

  <?php foreach ($enabledProviders as $p):
        $meta       = oauthProvider($p);
        $isOn       = isset($connected[$p]);
        $onlyMethod = $isOn && !$hasPassword && count($connected) <= 1;
  ?>
  <div class="conn-row">
    <span class="conn-name"><?= htmlspecialchars($meta['name']) ?></span>

    <?php if ($isOn): ?>
      <span class="conn-status conn-on">✓ Connected<?= $connected[$p]['email'] ? ' · ' . htmlspecialchars($connected[$p]['email']) : '' ?></span>
      <?php if ($onlyMethod): ?>
        <span class="conn-note" title="Set a password before disconnecting your only sign-in method.">Only sign-in method</span>
      <?php else: ?>
        <form method="POST" action="/profile.php" class="conn-form">
          <?= csrfField() ?>
          <input type="hidden" name="oauth_action" value="unlink">
          <input type="hidden" name="provider" value="<?= htmlspecialchars($p) ?>">
          <button type="submit" class="btn btn-ghost btn-sm">Disconnect</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <span class="conn-status">Not connected</span>
      <form method="POST" action="/oauth-start.php" class="conn-form">
        <?= csrfField() ?>
        <input type="hidden" name="oauth_action" value="link">
        <input type="hidden" name="provider" value="<?= htmlspecialchars($p) ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Connect</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
</main>

<script src="/assets/js/profile.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
