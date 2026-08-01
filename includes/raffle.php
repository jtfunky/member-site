<?php
// Groove Quest raffle promo — campaigns entrants sign up for via a dedicated
// landing page (raffle-signup.php); an admin later draws N random winners,
// each granted prize_days of free access, plus prize_ticket_count/desc
// (mentioned in the email only, no fulfillment tracking) if configured.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function getActiveCampaign(): ?array {
    $st = db()->prepare(
        'SELECT * FROM raffle_campaigns WHERE is_active = 1 AND NOW() BETWEEN start_at AND end_at
         ORDER BY start_at DESC LIMIT 1'
    );
    $st->execute();
    return $st->fetch() ?: null;
}

function getCampaignBySlug(string $slug): ?array {
    $st = db()->prepare('SELECT * FROM raffle_campaigns WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function campaignStatus(array $campaign): string {
    $now = time();
    if ($now < strtotime($campaign['start_at'])) return 'upcoming';
    if ($now > strtotime($campaign['end_at']))   return 'ended';
    return 'open';
}

// Returns true if a new entry was actually inserted (false if this user was
// already entered — INSERT IGNORE against uniq_campaign_user).
function enterRaffle(int $campaignId, int $userId): bool {
    $st = db()->prepare(
        'INSERT IGNORE INTO raffle_entries (campaign_id, user_id) VALUES (?, ?)'
    );
    $st->execute([$campaignId, $userId]);
    return $st->rowCount() > 0;
}

function entryCount(int $campaignId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM raffle_entries WHERE campaign_id = ?');
    $st->execute([$campaignId]);
    return (int) $st->fetchColumn();
}

function winnerCount(int $campaignId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM raffle_entries WHERE campaign_id = ? AND is_winner = 1');
    $st->execute([$campaignId]);
    return (int) $st->fetchColumn();
}

function listEntrants(int $campaignId): array {
    $st = db()->prepare(
        'SELECT re.*, u.first_name, u.last_name, u.email
         FROM raffle_entries re JOIN users u ON u.id = re.user_id
         WHERE re.campaign_id = ? ORDER BY re.created_at DESC'
    );
    $st->execute([$campaignId]);
    return $st->fetchAll();
}

// Physical ticket PDFs live outside public_html at RAFFLE_TICKETS_DIR/<day>/
// (one folder per festival day). raffle_ticket_grants is the permanent record
// of which specific file went to whom — its UNIQUE(ticket_day, ticket_file)
// key is what actually guarantees a ticket can never be granted twice, even
// across separate runs.
function grantedTicketFiles(string $day): array {
    $st = db()->prepare('SELECT ticket_file FROM raffle_ticket_grants WHERE ticket_day = ?');
    $st->execute([$day]);
    return array_column($st->fetchAll(), 'ticket_file');
}

function availableTicketFiles(string $day): array {
    $dir = RAFFLE_TICKETS_DIR . $day . '/';
    if (!is_dir($dir)) return [];
    $all = array_values(array_filter(scandir($dir), fn($f) => str_ends_with($f, '.pdf')));
    return array_values(array_diff($all, grantedTicketFiles($day)));
}

function countAvailableTickets(string $day): int {
    return count(availableTicketFiles($day));
}

// Randomly tries day1/day2 each call, picking whichever still has stock —
// over several winners in one draw this naturally mixes both days rather
// than draining one before touching the other.
function pickAvailableTicketDay(): ?string {
    $days = ['day1', 'day2'];
    shuffle($days);
    foreach ($days as $day) {
        if (countAvailableTickets($day) > 0) return $day;
    }
    return null; // both days fully granted out
}

function pickAvailableTicketFile(string $day): ?string {
    $files = availableTicketFiles($day);
    return $files ? $files[array_rand($files)] : null;
}

// Records the permanent grant. False (not thrown) if the unique constraint
// blocks it — same file already granted — or the table isn't migrated yet.
function grantRaffleTicket(int $campaignId, int $userId, string $day, string $filename): bool {
    try {
        db()->prepare(
            'INSERT INTO raffle_ticket_grants (campaign_id, user_id, ticket_day, ticket_file) VALUES (?, ?, ?, ?)'
        )->execute([$campaignId, $userId, $day, $filename]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

// Draws min($n, eligible) random winners from non-winner entrants, grants
// each the campaign's prize_days via the existing grantAdminAccess(), and
// emails them. Returns the list of winner rows (each with first_name/email
// merged in) for the admin page's confirmation view. Empty array (not an
// error) if there are no eligible entrants left.
function drawWinners(int $campaignId, int $n, int $adminId): array {
    $campaign = db()->prepare('SELECT * FROM raffle_campaigns WHERE id = ?');
    $campaign->execute([$campaignId]);
    $campaign = $campaign->fetch();
    if (!$campaign) return [];

    // Anyone can register and enter (enterRaffle() is untouched) — but only
    // Philippines-based entrants are eligible to actually be drawn, since the
    // prize is physical festival tickets nobody outside PH could attend for.
    // users.country is IP-geolocated on signup/login (includes/geo.php), so
    // this naturally excludes entrants who never resolved a country too.
    $st = db()->prepare(
        "SELECT u.id, u.first_name, u.email FROM raffle_entries re
         JOIN users u ON u.id = re.user_id
         WHERE re.campaign_id = ? AND re.is_winner = 0 AND u.country = 'Philippines'"
    );
    $st->execute([$campaignId]);
    $eligible = $st->fetchAll();
    if (!$eligible) return [];

    $n = min($n, count($eligible));
    $pickedKeys = (array) array_rand($eligible, $n); // array_rand returns a scalar key if $n === 1
    $winners = array_map(fn($k) => $eligible[$k], $pickedKeys);

    foreach ($winners as &$winner) {
        db()->prepare(
            'UPDATE raffle_entries SET is_winner = 1, won_at = NOW(), grant_days = ?
             WHERE campaign_id = ? AND user_id = ?'
        )->execute([$campaign['prize_days'], $campaignId, $winner['id']]);

        grantAdminAccess(
            (int) $winner['id'],
            (int) $campaign['prize_days'],
            $adminId,
            'Groove Quest raffle winner — ' . $campaign['name']
        );

        $ticketCount = (int) ($campaign['prize_ticket_count'] ?? 0);
        $ticketLine  = '';
        $ticketDay   = null;
        $ticketFile  = null;

        if ($ticketCount > 0) {
            $desc = $campaign['prize_ticket_desc'] ?: 'tickets';
            $ticketDay = pickAvailableTicketDay();
            if ($ticketDay) {
                $ticketFile = pickAvailableTicketFile($ticketDay);
                if ($ticketFile && !grantRaffleTicket($campaignId, (int) $winner['id'], $ticketDay, $ticketFile)) {
                    $ticketFile = null; // grant lost a race (unique constraint) — fall back below
                }
            }
            $ticketLine = $ticketFile
                ? "and you've also won " . ($ticketCount === 1 ? 'a ticket' : "{$ticketCount} tickets")
                    . " ({$desc}) — it's attached to this email as a PDF. Bring it (printed or on your phone) to the gate.\n"
                : "and you've also won {$ticketCount} " . ($ticketCount === 1 ? 'ticket' : 'tickets')
                    . " ({$desc}) — we'll be in touch separately with the details.\n";
        }

        $subject = 'You won the ' . $campaign['name'] . ' raffle!';
        $body    = "Hi {$winner['first_name']},\n\n"
                 . "Congratulations — you're a winner of the {$campaign['name']} raffle on " . SITE_NAME . "!\n"
                 . "We've added {$campaign['prize_days']} days of free access to your account, effective immediately,\n"
                 . $ticketLine
                 . "Log in any time: " . SITE_URL . "/login.php\n\n"
                 . "Thanks for participating!";

        if ($ticketFile) {
            sendMailWithAttachment($winner['email'], $subject, $body, RAFFLE_TICKETS_DIR . $ticketDay . '/' . $ticketFile, $ticketFile);
        } else {
            sendMail($winner['email'], $subject, $body);
        }

        $winner['ticket_day']  = $ticketDay;
        $winner['ticket_file'] = $ticketFile;
    }
    unset($winner); // break the reference now that the loop's done

    return $winners;
}

// Regenerates the public static winners page at RAFFLE_WINNERS_PAGE_PATH —
// written straight into zachalcasid.com's own docroot (same account/
// filesystem), so that site never needs to run PHP or touch this DB itself.
// Shows full names (explicit call) + which festival day's ticket, never the
// specific ticket file/number. Call after every drawWinners().
function regenerateRaffleWinnersPage(): void {
    $st = db()->prepare(
        "SELECT u.first_name, u.last_name, re.won_at, rc.name AS campaign_name, tg.ticket_day
         FROM raffle_entries re
         JOIN users u ON u.id = re.user_id
         JOIN raffle_campaigns rc ON rc.id = re.campaign_id
         LEFT JOIN raffle_ticket_grants tg ON tg.campaign_id = re.campaign_id AND tg.user_id = re.user_id
         WHERE re.is_winner = 1
         ORDER BY re.won_at DESC"
    );
    $st->execute();
    $winners = $st->fetchAll();

    $rows = '';
    foreach ($winners as $w) {
        $name = htmlspecialchars(trim($w['first_name'] . ' ' . $w['last_name']));
        $date = htmlspecialchars(date('F j, Y', strtotime($w['won_at'])));
        $campaignName = htmlspecialchars($w['campaign_name']);
        $badge = '';
        if ($w['ticket_day'] === 'day1') $badge = '<span class="badge">Day 1 Ticket</span>';
        if ($w['ticket_day'] === 'day2') $badge = '<span class="badge">Day 2 Ticket</span>';
        $rows .= "    <li><span class=\"wname\">{$name}</span><span class=\"wmeta\">{$date} &middot; {$campaignName} {$badge}</span></li>\n";
    }
    if (!$winners) {
        $rows = "    <li class=\"empty\">No winners drawn yet &mdash; check back soon!</li>\n";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Raffle Winners &mdash; Zach Alcasid</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0e17; --bg2: #17172a; --border: #2c2c44;
    --text: #f1eef7; --text-dim: #9992ad; --accent: #8b5cf6; --hot: #d946ef;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: Georgia, 'Iowan Old Style', 'Times New Roman', serif; line-height: 1.6; padding: 3rem 1.5rem; }
  .wrap { max-width: 720px; margin: 0 auto; }
  h1 { font-family: 'Anton', sans-serif; text-transform: uppercase; font-size: clamp(1.8rem, 5vw, 2.6rem); letter-spacing: .02em; margin-bottom: .5rem; color: var(--hot); }
  .sub { color: var(--text-dim); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin-bottom: 2rem; }
  ul { list-style: none; }
  li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.25rem; background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; margin-bottom: .6rem; flex-wrap: wrap; }
  .wname { font-weight: 700; font-size: 1.05rem; }
  .wmeta { color: var(--text-dim); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: .85rem; }
  .badge { display: inline-block; margin-left: .5rem; padding: .15rem .55rem; background: color-mix(in srgb, var(--accent) 20%, transparent); color: var(--accent); border-radius: 999px; font-size: .75rem; font-weight: 600; }
  .empty { color: var(--text-dim); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; justify-content: center; }
  a.back { display: inline-block; margin-top: 2rem; color: var(--accent); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Raffle Winners</h1>
  <p class="sub">Groove Quest &times; Tribal Ink Art &amp; Music Fest &mdash; updated daily.</p>
  <ul>
{$rows}  </ul>
  <a class="back" href="/">&larr; Back to zachalcasid.com</a>
</div>
</body>
</html>
HTML;

    file_put_contents(RAFFLE_WINNERS_PAGE_PATH, $html);

    // Small JSON feed for the zachalcasid.com hero ticker (fetched client-side
    // — see index.html). Capped to the most recent 20 so the ticker loop
    // doesn't grow unbounded as the raffle runs for weeks. Full/first names
    // only, never the ticket file/number.
    $ticker = array_map(fn($w) => [
        'name'       => trim($w['first_name'] . ' ' . $w['last_name']),
        'ticket_day' => $w['ticket_day'],
    ], array_slice($winners, 0, 20));
    file_put_contents(RAFFLE_WINNERS_JSON_PATH, json_encode($ticker, JSON_UNESCAPED_UNICODE));
}
