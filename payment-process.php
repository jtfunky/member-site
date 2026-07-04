<?php
// Dummy payment processor — simulates a successful subscription
// Replace this entire file with Stripe webhook handler when going live
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/geo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/payment.php');
    exit;
}

verifyCsrf();
$user     = requireLogin();
$currency = $user['currency'] ?: getUserCurrency();
$amount   = getPrice($currency);
$txId     = 'DUMMY-' . strtoupper(bin2hex(random_bytes(8)));

recordPayment($user['id'], $amount, $currency, $txId, 'dummy');

header('Location: ' . SITE_URL . '/payment-success.php');
exit;
