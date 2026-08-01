<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user     = requireLogin();
$currency = $user['currency'] ?: getUserCurrency();
// Save detected currency to user record once
if (!$user['currency']) {
    db()->prepare('UPDATE users SET currency=? WHERE id=?')->execute([$currency, $user['id']]);
}

$hasAccess = hasPremiumAccess($user);
$days      = getDaysRemaining($user);
$price     = getPrice($currency);
$priceStr  = formatPrice($price, $currency);

// Coupon: applied via a plain page-reload (?coupon=CODE), same no-JS pattern
// as the rest of this site. Re-validated server-side again in
// payment-process.php before actually charging — never trust this display-only
// computation for the real charge.
$couponCode  = trim($_GET['coupon'] ?? '');
$coupon      = null;
$couponError = '';
$finalPrice  = $price;
$finalPriceStr = $priceStr;
if ($couponCode !== '') {
    $result = validateCoupon($couponCode);
    if (is_array($result)) {
        $coupon        = $result;
        $finalPrice    = applyCoupon($coupon, $price);
        $finalPriceStr = formatPrice($finalPrice, $currency);
    } else {
        $couponError = $result;
    }
}

$pageTitle = 'Membership — ' . SITE_NAME;
$pageCss   = ['main', 'payment'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<main class="container container--narrow">

<div class="payment-card">
  <div class="payment-plan-header">
    <h1><?= $hasAccess ? 'Your Membership' : 'Activate Membership' ?></h1>
    <?php if ($coupon): ?>
    <div class="price-display"><span style="text-decoration:line-through;opacity:.6;font-size:.7em"><?= $priceStr ?></span> <?= $finalPriceStr ?><span>/month</span></div>
    <p style="color:var(--success,#4ade80)">Coupon "<?= htmlspecialchars($coupon['code']) ?>" applied</p>
    <?php else: ?>
    <div class="price-display"><?= $priceStr ?><span>/month</span></div>
    <?php endif; ?>
    <p>Recurring monthly · Cancel anytime at least 7 days before renewal</p>
  </div>

  <?php if (!$hasAccess): ?>
  <form method="GET" action="/payment.php" class="form-row" style="align-items:flex-end;margin-bottom:1rem">
    <div class="form-group" style="flex:1">
      <label>Coupon Code</label>
      <input type="text" name="coupon" value="<?= htmlspecialchars($couponCode) ?>">
    </div>
    <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
  </form>
  <?php if ($couponError): ?>
  <div class="alert alert-error"><?= htmlspecialchars($couponError) ?></div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($hasAccess): ?>
  <div class="membership-status active">
    <div class="status-dot active"></div>
    <div>
      <strong>Active</strong>
      <span>Renews in <?= $days ?> day<?= $days !== 1 ? 's' : '' ?></span>
    </div>
  </div>
  <?php else: ?>
  <div class="membership-status expired">
    <div class="status-dot expired"></div>
    <div><strong>Expired</strong> <span>Subscribe below to continue</span></div>
  </div>
  <?php endif; ?>

  <ul class="plan-features">
    <li>✓ Full drum game with all songs</li>
    <li>✓ Edit your profile</li>
    <li>✓ Exclusive member content</li>
    <li>✓ Cancel anytime</li>
  </ul>

  <?php if (PAYMENT_MODE === 'dummy'): ?>
  <!-- DUMMY PAYMENT — replace with Stripe when ready -->
  <div class="dummy-payment">
    <div class="dummy-notice">
      <strong>Test Mode</strong> — Payment processing is simulated. No real charges.
    </div>
    <form method="POST" action="/payment-process.php">
      <?= csrfField() ?>
      <input type="hidden" name="coupon" value="<?= htmlspecialchars($couponCode) ?>">
      <div class="fake-card">
        <div class="form-group">
          <label>Card Number</label>
          <input type="text" value="4242 4242 4242 4242" disabled class="input-disabled font-mono">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Expiry</label>
            <input type="text" value="12 / 99" disabled class="input-disabled font-mono">
          </div>
          <div class="form-group">
            <label>CVC</label>
            <input type="text" value="123" disabled class="input-disabled font-mono">
          </div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">
        Subscribe — <?= $finalPriceStr ?>/month
      </button>
    </form>
  </div>
  <?php else: ?>
  <!-- STRIPE PAYMENT — coupon discount NOT wired into the actual Stripe charge
       yet; assets/js/payment.js and its backing endpoint need to read $coupon
       too before this path can be trusted with coupons in production. -->
  <div id="stripe-container">
    <button id="stripe-btn" class="btn btn-primary btn-block btn-lg"
            data-stripe-key="<?= htmlspecialchars(STRIPE_PUBLIC_KEY) ?>"
            data-currency="<?= htmlspecialchars($currency) ?>">
      Subscribe — <?= $priceStr ?>/month
    </button>
  </div>
  <script src="https://js.stripe.com/v3/"></script>
  <script src="/assets/js/payment.js"></script>
  <?php endif; ?>

  <?php if ($hasAccess && $user['subscription_status'] !== 'cancelled'): ?>
  <div class="cancel-section">
    <a href="/cancel-subscription.php" class="btn btn-ghost btn-sm">Cancel Subscription</a>
    <p class="cancel-note">Access continues until <?= date('M j, Y', strtotime($user['access_expires_at'])) ?></p>
  </div>
  <?php endif; ?>

</div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
