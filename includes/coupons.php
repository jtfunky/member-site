<?php
// Checkout discount codes (percent or fixed amount off) for the paid
// membership subscription, redeemed on payment.php / payment-process.php.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Returns the coupons row on success, or a user-facing error string.
function validateCoupon(string $code): array|string {
    $st = db()->prepare('SELECT * FROM coupons WHERE code = ?');
    $st->execute([$code]);
    $coupon = $st->fetch();
    if (!$coupon) return 'That coupon code was not found.';
    if (!$coupon['is_active']) return 'That coupon code is no longer active.';
    if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) return 'That coupon code has expired.';
    if ($coupon['max_uses'] !== null && (int) $coupon['uses_count'] >= (int) $coupon['max_uses']) {
        return 'That coupon code has already been fully redeemed.';
    }
    return $coupon;
}

function applyCoupon(array $coupon, float $amount): float {
    if ($coupon['discount_type'] === 'percent') {
        return round($amount * (1 - ((float) $coupon['discount_value'] / 100)), 2);
    }
    return max(0.0, round($amount - (float) $coupon['discount_value'], 2));
}

function redeemCoupon(int $couponId, int $userId, ?int $paymentId): void {
    db()->prepare(
        'INSERT INTO coupon_redemptions (coupon_id, user_id, payment_id) VALUES (?, ?, ?)'
    )->execute([$couponId, $userId, $paymentId]);
    db()->prepare('UPDATE coupons SET uses_count = uses_count + 1 WHERE id = ?')->execute([$couponId]);
}
