<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function hasPremiumAccess(array $user): bool {
    if ($user['role'] === 'admin') return true;
    if (empty($user['access_expires_at'])) return false;
    return strtotime($user['access_expires_at']) > time();
}

function getDaysRemaining(array $user): int {
    if (empty($user['access_expires_at'])) return 0;
    $diff = strtotime($user['access_expires_at']) - time();
    return max(0, (int) ceil($diff / 86400));
}

function requirePremium(array $user): void {
    if (hasPremiumAccess($user)) return;

    // Enrollees awaiting payment confirmation can only see their own
    // registration; send them there instead of the self-serve payment page.
    if (($user['subscription_status'] ?? '') === 'pending') {
        header('Location: ' . SITE_URL . '/my-membership.php');
        exit;
    }

    header('Location: ' . SITE_URL . '/payment.php');
    exit;
}

// Redirect a student with an unfinished profile (self-signups start incomplete)
// to the completion form before they can reach content. Admins and accounts
// without a student record are never gated. Inert until migrate-profile-completion.sql
// has run (a missing column is caught and treated as "complete").
function requireProfileComplete(array $user): void {
    if (($user['role'] ?? '') === 'admin') return;

    try {
        $st = db()->prepare('SELECT profile_completed FROM students WHERE user_id = ? LIMIT 1');
        $st->execute([(int) ($user['id'] ?? 0)]);
        $row = $st->fetch();
    } catch (\Throwable $e) {
        return; // column/table not there yet → don't gate
    }

    if (!$row || (int) ($row['profile_completed'] ?? 1) === 1) return;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== '/complete-profile.php') {
        header('Location: ' . SITE_URL . '/complete-profile.php');
        exit;
    }
}

// Require new signups to take the AI drum placement test before reaching content.
// Only students flagged placement_required=1 (set by default on NEW rows; existing
// members were cleared to 0 by the migration) are gated, and only until they have a
// drum_tests row. Admins/non-students are never gated; inert until the migration runs.
function requirePlacementTest(array $user): void {
    if (($user['role'] ?? '') === 'admin') return;

    try {
        $st = db()->prepare('SELECT placement_required FROM students WHERE user_id = ? LIMIT 1');
        $st->execute([(int) ($user['id'] ?? 0)]);
        $row = $st->fetch();
    } catch (\Throwable $e) {
        return; // column/table not there yet → don't gate
    }
    if (!$row || (int) ($row['placement_required'] ?? 0) !== 1) return; // exempt / existing member

    // Already taken? (a saved drum test satisfies the requirement)
    if (hasTakenPlacementTest((int) $user['id'])) return;

    // Allow the placement pages themselves (the intro + the test play).
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path === '/placement-test.php' || ($path === '/game.php' && !empty($_GET['test']))) return;

    header('Location: ' . SITE_URL . '/placement-test.php');
    exit;
}

// True once the user has completed the placement test (has a drum_tests row).
// Used to lock the test after it's taken — it's a one-time assessment.
function hasTakenPlacementTest(int $userId): bool {
    try {
        $t = db()->prepare('SELECT 1 FROM drum_tests WHERE user_id = ? LIMIT 1');
        $t->execute([$userId]);
        return (bool) $t->fetchColumn();
    } catch (\Throwable $e) {
        return false; // drum_tests table missing → treat as not taken
    }
}

function extendAccess(int $userId, int $days): void {
    // If already has future access, extend from that date; else extend from now
    $st = db()->prepare('SELECT access_expires_at FROM users WHERE id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();

    $base = ($row && $row['access_expires_at'] && strtotime($row['access_expires_at']) > time())
        ? $row['access_expires_at']
        : date('Y-m-d H:i:s');

    $newExpiry = date('Y-m-d H:i:s', strtotime("+{$days} days", strtotime($base)));

    db()->prepare(
        'UPDATE users SET access_expires_at = ?, subscription_status = "active", updated_at = NOW() WHERE id = ?'
    )->execute([$newExpiry, $userId]);
}

function grantAdminAccess(int $userId, int $days, int $grantedBy, string $note = ''): void {
    $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));

    db()->prepare(
        'INSERT INTO access_grants (user_id, granted_by, duration_days, note, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $grantedBy, $days, $note, $expires]);

    extendAccess($userId, $days);
}

function cancelSubscription(int $userId): void {
    db()->prepare(
        'UPDATE users SET subscription_status = "cancelled", updated_at = NOW() WHERE id = ?'
    )->execute([$userId]);
}

function recordPayment(int $userId, float $amount, string $currency, string $txId = '', string $method = 'dummy'): void {
    $start = date('Y-m-d H:i:s');
    $end   = date('Y-m-d H:i:s', strtotime('+' . SUBSCRIPTION_DAYS . ' days'));

    db()->prepare(
        'INSERT INTO payments (user_id, amount, currency, payment_method, transaction_id, status, period_start, period_end)
         VALUES (?, ?, ?, ?, ?, "success", ?, ?)'
    )->execute([$userId, $amount, $currency, $method, $txId, $start, $end]);

    extendAccess($userId, SUBSCRIPTION_DAYS);
}
