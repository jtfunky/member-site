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
    // Scoped staff (editor/partner) aren't students — send them to their own home
    // rather than the student payment flow.
    $role = $user['role'] ?? 'user';
    if ($role === 'editor' || $role === 'partner') {
        header('Location: ' . SITE_URL . roleHome($role));
        exit;
    }
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

    // Allow the placement pages themselves (the intro + the tutorial + the test play).
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path === '/placement-test.php' || ($path === '/game.php' && (!empty($_GET['test']) || !empty($_GET['tutorial'])))) return;

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

// True once "now" (Asia/Manila) has reached the game's beta launch date.
function gameBetaHasLaunched(): bool {
    $now    = new \DateTime('now', new \DateTimeZone('UTC'));
    $launch = new \DateTime(GAME_BETA_LAUNCH_AT, new \DateTimeZone('Asia/Manila'));
    return $now >= $launch;
}

// GAME_BETA_BYPASS_EMAIL may hold one email or a comma-separated list, so
// QA can add temporary test accounts without touching code — just the
// config constant on the server.
function isGameBetaBypassEmail(string $email): bool {
    foreach (explode(',', GAME_BETA_BYPASS_EMAIL) as $candidate) {
        if ($email !== '' && strcasecmp($email, trim($candidate)) === 0) return true;
    }
    return false;
}

// Hard-gates the whole game behind the beta launch date — nothing in
// game.php is reachable before it, not even the placement test/tutorial.
// Admins and the designated test account(s) always get through.
function requireGameLaunched(array $user): void {
    if (($user['role'] ?? '') === 'admin') return;
    if (isGameBetaBypassEmail($user['email'] ?? '')) return;
    if (gameBetaHasLaunched()) return;

    header('Location: ' . SITE_URL . '/game-coming-soon.php');
    exit;
}

// Free/trial access to the game specifically, computed purely from the
// user's own registration date — independent of hasPremiumAccess(), which
// stays scoped to real paid subscriptions. Registering before
// GAME_FREE_ACCESS_CUTOFF grants free access through GAME_FREE_ACCESS_UNTIL;
// registering on/after it grants a flat GAME_LAUNCH_TRIAL_DAYS-day trial
// from the registration date instead.
// The date the beta free-access rule covers this user until — same two-tier
// logic hasGameLaunchFreeAccess() checks against "now", exposed separately
// so callers (e.g. the dashboard) can display the actual date rather than
// just a yes/no.
function gameLaunchFreeAccessUntil(array $user): ?\DateTime {
    if (empty($user['created_at'])) return null;

    $regTime = new \DateTime($user['created_at'], new \DateTimeZone('UTC'));
    $cutoff  = new \DateTime(GAME_FREE_ACCESS_CUTOFF, new \DateTimeZone('Asia/Manila'));

    if ($regTime < $cutoff) {
        return new \DateTime(GAME_FREE_ACCESS_UNTIL, new \DateTimeZone('Asia/Manila'));
    }

    return (clone $regTime)->modify('+' . GAME_LAUNCH_TRIAL_DAYS . ' days');
}

function hasGameLaunchFreeAccess(array $user): bool {
    $until = gameLaunchFreeAccessUntil($user);
    if (!$until) return false;
    $now = new \DateTime('now', new \DateTimeZone('UTC'));
    return $now <= $until;
}

// Same gate/redirect behavior as requirePremium(), except access is also
// granted by hasGameLaunchFreeAccess() — the beta/launch free window — not
// just a real paid subscription. Scoped to the game only; exclusive/index.php
// and everything else keeps using plain requirePremium().
function requireGameAccess(array $user): void {
    $role = $user['role'] ?? 'user';
    if ($role === 'editor' || $role === 'partner') {
        header('Location: ' . SITE_URL . roleHome($role));
        exit;
    }
    if (hasPremiumAccess($user) || hasGameLaunchFreeAccess($user)) return;

    if (($user['subscription_status'] ?? '') === 'pending') {
        header('Location: ' . SITE_URL . '/my-membership.php');
        exit;
    }

    header('Location: ' . SITE_URL . '/payment.php');
    exit;
}

// The post-login landing page for a regular user: game-coming-soon.php while
// the game is gated pre-launch (the bypass account goes to dashboard as
// normal), dashboard.php once the beta has launched. Only used as the DEFAULT
// when no explicit deep-link (?next=) was requested — those are always honored.
// Admins are routed by roleHome() before this is ever called.
function defaultPostLoginRedirect(array $user): string {
    if (($user['role'] ?? 'user') === 'admin') return roleHome('admin');
    if (isGameBetaBypassEmail($user['email'] ?? '')) return '/dashboard.php';
    return gameBetaHasLaunched() ? '/dashboard.php' : '/game-coming-soon.php';
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
