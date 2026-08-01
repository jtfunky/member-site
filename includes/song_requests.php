<?php
/**
 * Paid song requests + per-song entitlements.
 *
 * Lifecycle: pending -> approved -> paid -> fulfilled  (or -> declined).
 * Payment is admin-confirmed for now (Maya integration pending). The single seam
 * the future Maya webhook will call is fulfillPaidRequest().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Price/currency defaults — override in includes/config.php if desired.
if (!defined('SONG_REQUEST_PRICE'))    define('SONG_REQUEST_PRICE', 149);
if (!defined('SONG_REQUEST_CURRENCY')) define('SONG_REQUEST_CURRENCY', 'PHP');
if (!defined('PAYMENT_MODE'))          define('PAYMENT_MODE', 'dummy');
if (!defined('SONG_REQUEST_FREE_UNTIL')) define('SONG_REQUEST_FREE_UNTIL', '2026-09-01 00:00:00');

// True while requests are free (beta) — no payment step, straight to charting
// once approved. Real pricing resumes automatically after SONG_REQUEST_FREE_UNTIL.
function songRequestsAreFree(): bool {
    $now   = new \DateTime('now', new \DateTimeZone('UTC'));
    $until = new \DateTime(SONG_REQUEST_FREE_UNTIL, new \DateTimeZone('Asia/Manila'));
    return $now < $until;
}

/** Extract the 11-char YouTube id from a URL (or a bare id). Null if not YouTube. */
function parseYoutubeId(string $url): ?string {
    if (preg_match(
        '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~',
        $url, $m
    )) return $m[1];
    return preg_match('~^[A-Za-z0-9_-]{11}$~', $url) ? $url : null;
}

function normalizeYoutubeUrl(string $ytId): string {
    return 'https://www.youtube.com/watch?v=' . $ytId;
}

/** True if an admin has blacklisted this video from being requested. */
function isVideoBlacklisted(string $ytId): bool {
    try {
        $st = db()->prepare('SELECT 1 FROM song_request_blacklist WHERE yt_id = ?');
        $st->execute([$ytId]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) {
        return false; // table not migrated yet -> treat as not blacklisted
    }
}

/** Blacklist a video so future requests for it are blocked at submission. */
function blacklistVideo(string $ytId, string $reason, int $adminUserId): void {
    db()->prepare(
        'INSERT INTO song_request_blacklist (yt_id, reason, blacklisted_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), blacklisted_by = VALUES(blacklisted_by)'
    )->execute([$ytId, mb_substr($reason, 0, 255), $adminUserId]);
}

function unblacklistVideo(string $ytId): void {
    db()->prepare('DELETE FROM song_request_blacklist WHERE yt_id = ?')->execute([$ytId]);
}

/**
 * Create a request (status pending). Returns [ok(bool), error(string), id(int)].
 */
function createSongRequest(int $userId, string $rawUrl, string $title, string $note): array {
    $ytId = parseYoutubeId(trim($rawUrl));
    if (!$ytId) return [false, 'Please paste a valid YouTube link.', 0];

    if (isVideoBlacklisted($ytId)) {
        return [false, 'This video can’t be requested. Please try a different one.', 0];
    }

    // Don't let the same user open a duplicate request for the same video.
    $st = db()->prepare(
        "SELECT id FROM song_requests
         WHERE user_id = ? AND yt_id = ? AND status IN ('pending','approved','paid','fulfilled')
         LIMIT 1"
    );
    $st->execute([$userId, $ytId]);
    if ($st->fetchColumn()) return [false, 'You already have a request for this song.', 0];

    $price = songRequestsAreFree() ? 0.0 : (float) SONG_REQUEST_PRICE;

    $ins = db()->prepare(
        'INSERT INTO song_requests (user_id, youtube_url, yt_id, title, note, status, price, currency)
         VALUES (?, ?, ?, ?, ?, "pending", ?, ?)'
    );
    $ins->execute([
        $userId, normalizeYoutubeUrl($ytId), $ytId,
        mb_substr(trim($title), 0, 200), mb_substr(trim($note), 0, 500),
        $price, SONG_REQUEST_CURRENCY,
    ]);
    return [true, '', (int) db()->lastInsertId()];
}

// Free-period requests skip straight to "paid" (i.e. ready to chart) since
// there's no payment step to wait for. Paid requests go through the normal
// "approved -> student pays -> admin marks paid" flow.
function approveSongRequest(int $requestId): void {
    $st = db()->prepare('SELECT price FROM song_requests WHERE id = ?');
    $st->execute([$requestId]);
    $price = (float) ($st->fetchColumn() ?: 0);

    $newStatus = $price <= 0 ? 'paid' : 'approved';
    db()->prepare("UPDATE song_requests SET status=?, decline_reason='' WHERE id=? AND status IN ('pending','declined')")
        ->execute([$newStatus, $requestId]);
}

function declineSongRequest(int $requestId, string $reason): void {
    db()->prepare("UPDATE song_requests SET status='declined', decline_reason=? WHERE id=?")
        ->execute([mb_substr($reason, 0, 255), $requestId]);
}

/** Grant a user access to an exclusive song (idempotent). */
function grantEntitlement(int $userId, int $songId, string $source = 'request'): void {
    db()->prepare('INSERT IGNORE INTO song_entitlements (user_id, song_id, source) VALUES (?, ?, ?)')
        ->execute([$userId, $songId, $source]);
}

/**
 * Confirm payment for a request and grant access. Called by the admin "Mark paid"
 * action now, and by the Maya webhook later. If the requested video already maps
 * to a charted song, the buyer is unlocked instantly (status -> fulfilled);
 * otherwise the request waits at `paid` for the admin to chart it.
 */
function fulfillPaidRequest(int $requestId, string $txId = ''): bool {
    $db = db();
    $st = $db->prepare('SELECT * FROM song_requests WHERE id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch();
    if (!$r || $r['status'] === 'declined') return false;

    // Record the payment (reuse the existing payments table).
    try {
        $db->prepare(
            'INSERT INTO payments (user_id, amount, currency, payment_method, transaction_id, status)
             VALUES (?, ?, ?, ?, ?, "success")'
        )->execute([(int) $r['user_id'], (float) $r['price'], $r['currency'], PAYMENT_MODE, $txId]);
    } catch (\Throwable $e) { /* non-fatal */ }

    // Existing charted song for this video? -> instant unlock.
    $songId = (int) ($r['song_id'] ?: 0);
    if (!$songId) {
        $s = $db->prepare('SELECT id FROM songs WHERE youtube_url = ? ORDER BY id LIMIT 1');
        $s->execute([normalizeYoutubeUrl($r['yt_id'])]);
        $songId = (int) ($s->fetchColumn() ?: 0);
    }

    if ($songId) {
        grantEntitlement((int) $r['user_id'], $songId, 'request');
        $db->prepare("UPDATE song_requests SET status='fulfilled', song_id=?, paid_at=NOW() WHERE id=?")
            ->execute([$songId, $requestId]);
    } else {
        $db->prepare("UPDATE song_requests SET status='paid', paid_at=NOW() WHERE id=?")
            ->execute([$requestId]);
    }
    return true;
}

/**
 * When the admin charts a song for a video, link + fulfill every PAID request for
 * that video and grant those buyers access. Called from the editor save path.
 */
function fulfillRequestsWithSong(string $ytId, int $songId): void {
    $db = db();
    $st = $db->prepare("SELECT id, user_id FROM song_requests WHERE yt_id = ? AND status IN ('paid','fulfilled')");
    $st->execute([$ytId]);
    foreach ($st->fetchAll() as $row) {
        grantEntitlement((int) $row['user_id'], $songId, 'request');
        $db->prepare("UPDATE song_requests SET status='fulfilled', song_id=? WHERE id=?")
            ->execute([$songId, (int) $row['id']]);
    }
}

/** Song ids the user is entitled to. Empty array if the table isn't migrated. */
function entitledSongIds(int $userId): array {
    try {
        $st = db()->prepare('SELECT song_id FROM song_entitlements WHERE user_id = ?');
        $st->execute([$userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (\Throwable $e) {
        return [];
    }
}
