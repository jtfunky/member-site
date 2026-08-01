<?php
// General promo codes — admin-managed codes a new registrant can enter on
// the normal register.php flow for bonus trial days. Separate from the
// raffle (its own dedicated landing page, no code needed to enter).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Returns the promo_codes row on success, or a user-facing error string.
function validatePromoCode(string $code): array|string {
    $st = db()->prepare('SELECT * FROM promo_codes WHERE code = ?');
    $st->execute([$code]);
    $promo = $st->fetch();
    if (!$promo) return 'That promo code was not found.';
    if (!$promo['is_active']) return 'That promo code is no longer active.';
    if ($promo['expires_at'] && strtotime($promo['expires_at']) < time()) return 'That promo code has expired.';
    if ($promo['max_uses'] !== null && (int) $promo['uses_count'] >= (int) $promo['max_uses']) {
        return 'That promo code has already been fully redeemed.';
    }
    return $promo;
}

// Records the redemption, bumps uses_count, and extends the user's access —
// reuses the existing extendAccess() (from includes/access.php, loaded via
// bootstrap.php) rather than duplicating its expiry-extension logic.
// Returns false (grants nothing) if this user already redeemed this code —
// checked via the INSERT's actual row count, not just is_active/max_uses,
// so a code can't be re-applied over and over by the same account.
function redeemPromoCode(int $promoCodeId, int $userId, int $bonusDays): bool {
    $st = db()->prepare(
        'INSERT IGNORE INTO promo_code_redemptions (promo_code_id, user_id) VALUES (?, ?)'
    );
    $st->execute([$promoCodeId, $userId]);
    if ($st->rowCount() === 0) return false;

    db()->prepare('UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = ?')->execute([$promoCodeId]);
    extendAccess($userId, $bonusDays);
    return true;
}
