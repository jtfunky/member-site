<?php
// Central bootstrap — loads the common includes used by page scripts.
// auth.php transitively pulls in config.php, db.php, and security.php.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/raffle.php';
require_once __DIR__ . '/promo.php';
require_once __DIR__ . '/coupons.php';
require_once __DIR__ . '/settings.php';

// Every `created_at` column is DATETIME (not TIMESTAMP), populated by
// MySQL's own CURRENT_TIMESTAMP — and both this server's MySQL and PHP run
// in UTC. Admin pages showing raw dates via plain date()/strtotime() were
// therefore up to 8 hours behind the real Asia/Manila audience — a signup
// at 8pm-midnight Manila time (already the next UTC-vs-Manila calendar day
// gap) would show the wrong date. This converts UTC-stored datetimes to
// Manila for display; it does not change anything about how they're stored.
function formatManilaDate(string $datetime, string $format = 'M j, Y'): string {
    $dt = new DateTime($datetime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
    return $dt->format($format);
}
