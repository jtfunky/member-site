<?php
// Dummy payment processor — simulates a successful subscription
// Replace this entire file with Stripe webhook handler when going live
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/coupons.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/payment.php');
    exit;
}

verifyCsrf();
$user     = requireLogin();
$currency = $user['currency'] ?: getUserCurrency();
$amount   = getPrice($currency);
$txId     = 'DUMMY-' . strtoupper(bin2hex(random_bytes(8)));

// Coupon re-validated here, server-side, from scratch — never trust the
// discounted price payment.php merely displayed to the browser.
$couponCode = trim($_POST['coupon'] ?? '');
$coupon     = null;
if ($couponCode !== '') {
    $result = validateCoupon($couponCode);
    if (is_array($result)) {
        $coupon = $result;
        $amount = applyCoupon($coupon, $amount);
    }
}

recordPayment($user['id'], $amount, $currency, $txId, 'dummy');

if ($coupon) {
    $paymentId = (int) db()->lastInsertId();
    redeemCoupon((int) $coupon['id'], $user['id'], $paymentId);
}

header('Location: ' . SITE_URL . '/payment-success.php');
exit;
