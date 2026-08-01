<?php
// Automated raffle draw — run via server crontab twice daily, 12:00 PM and
// 6:00 PM Asia/Manila (04:00 UTC / 10:00 UTC). Draws up to 5 new winners for
// whatever raffle campaign is currently active (getActiveCampaign() —
// self-limiting once a campaign's end_at passes, or if there's no active
// campaign at all), emails each one (ticket PDF attached when the campaign
// has one configured), and regenerates the public winners page on
// zachalcasid.com. CLI-only — refuses to run over HTTP so it can't be
// triggered by an anonymous request.
//
// No longer posts to the Facebook Page (removed 2026-08-01) — the templated,
// multiple-times-a-day auto-posts were getting ~0 engagement against the
// account's real content (100-300+ shares), and the broken "link in
// comments" step (missing pages_manage_engagement scope) was actively
// hurting reach. Winners still get their email + the public winners page.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/includes/bootstrap.php';

$campaign = getActiveCampaign();
if (!$campaign) {
    echo date('Y-m-d H:i:s') . " — no active campaign, nothing to draw.\n";
    exit(0);
}

// Attribute the grant to the campaign's own creator (an admin), rather than
// requiring a hardcoded admin id that could go stale.
$adminId = (int) $campaign['created_by'];

$winners = drawWinners((int) $campaign['id'], 5, $adminId);
echo date('Y-m-d H:i:s') . ' — ' . count($winners) . " winner(s) drawn for \"{$campaign['name']}\".\n";
foreach ($winners as $w) {
    $ticket = $w['ticket_file'] ? " + {$w['ticket_day']} ticket ({$w['ticket_file']})" : ' (no ticket file available)';
    echo "  - {$w['first_name']} <{$w['email']}>{$ticket}\n";
}

regenerateRaffleWinnersPage();
echo date('Y-m-d H:i:s') . " — public winners page regenerated.\n";
