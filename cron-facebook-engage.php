<?php
// Auto-likes positive comments on the Page's own posts, but only during each
// post's first hour — run this frequently (every 5-10 min) via Hostinger
// cron, not once daily like cron-raffle-draw.php. CLI-only — refuses to run
// over HTTP so it can't be triggered by an anonymous request.
//
// Deliberately auto-LIKE only, never auto-reply — a wrong AI-generated reply
// going out publicly under the Page's name carries real reputational risk;
// liking a genuinely positive comment does not.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/facebook.php';
require_once __DIR__ . '/includes/comment_sentiment.php';

if (!defined('FB_PAGE_ACCESS_TOKEN') || FB_PAGE_ACCESS_TOKEN === '') {
    echo date('Y-m-d H:i:s') . " — FB_PAGE_ACCESS_TOKEN not configured, nothing to do.\n";
    exit(0);
}

$result = fbRunEngagementSweep(60);
if (!$result['ok']) {
    echo date('Y-m-d H:i:s') . ' — could not fetch recent posts: ' . $result['error'] . "\n";
    exit(1);
}

$liked = count(array_filter($result['processed'], fn($p) => $p['liked']));
echo date('Y-m-d H:i:s') . ' — ' . count($result['processed']) . " new comment(s) classified, {$liked} liked, across "
    . $result['posts_checked'] . " recent post(s).\n";
