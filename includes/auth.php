<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function sessionStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function getCurrentUser(): ?array {
    sessionStart();
    if (empty($_SESSION['user_id'])) return null;
    checkSessionTimeout();
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$_SESSION['user_id']]);
    $user = $st->fetch() ?: null;
    if ($user) touchLastSeen((int) $user['id']);
    return $user;
}

// Stamps last_seen_at so the admin dashboard can count who's online right
// now. Throttled to ~once/minute per session (not per request — this can run
// several times per page load) to keep the write cheap. Tolerates a missing
// column/table so it's safe to deploy ahead of the migration.
function touchLastSeen(int $userId): void {
    $now = time();
    if (($_SESSION['_last_seen_write'] ?? 0) > $now - 60) return;
    $_SESSION['_last_seen_write'] = $now;
    try {
        db()->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$userId]);
    } catch (\Throwable $e) { /* column not migrated yet */ }
}

function isLoggedIn(): bool {
    return getCurrentUser() !== null;
}

function isAdmin(): bool {
    $u = getCurrentUser();
    return $u && $u['role'] === 'admin';
}

function requireLogin(string $redirect = '/login.php'): array {
    $user = getCurrentUser();
    if (!$user) {
        header('Location: ' . SITE_URL . $redirect . '?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    return $user;
}

function requireAdmin(): array {
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        header('Location: ' . SITE_URL . roleHome($user['role'] ?? 'user'));
        exit;
    }
    checkAdminIp();

    // Device lock: admins may only reach the admin area from a registered device.
    if (ADMIN_DEVICE_LOCK) {
        sessionStart();
        if (empty($_SESSION['admin_device_ok'])) {
            if (adminDeviceTrusted((int) $user['id'])) {
                $_SESSION['admin_device_ok'] = true;
            } else {
                header('Location: ' . SITE_URL . '/verify-device.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
                exit;
            }
        }
    }
    return $user;
}

// The landing page each role belongs on — used for post-login routing and for
// bouncing a role away from a page it isn't allowed to see.
function roleHome(string $role): string {
    switch ($role) {
        case 'admin':   return '/admin/';
        case 'editor':  return '/admin/song-requests.php';
        case 'partner': return '/partner.php';
        default:        return '/dashboard.php';
    }
}

// Require the logged-in user to hold one of $roles; otherwise send them to their
// own home. Server-side gate for scoped staff roles (editor/partner) — every
// role-restricted page must call this (nav hiding is cosmetic only).
function requireRoles(array $roles): array {
    $user = requireLogin();
    if (!in_array($user['role'] ?? 'user', $roles, true)) {
        header('Location: ' . SITE_URL . roleHome($user['role'] ?? 'user'));
        exit;
    }
    return $user;
}

// Sentinel returned by loginUser() when the credentials are correct but the
// account's email hasn't been verified yet — login.php checks for this exact
// string to show a "resend the link" option instead of a plain error.
if (!defined('EMAIL_NOT_VERIFIED')) define('EMAIL_NOT_VERIFIED', 'EMAIL_NOT_VERIFIED');

function loginUser(string $login, string $password): array|string {
    $ip = getClientIp();

    if (isLockedOut($ip, $login)) {
        $secs = getRemainingLockout($ip, $login);
        $mins = ceil($secs / 60);
        return "Too many failed attempts. Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . '.';
    }

    $st = db()->prepare(
        'SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1'
    );
    $st->execute([$login, $login]);
    $user = $st->fetch();

    // empty password_hash = social-login-only account → no password sign-in
    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        recordLoginAttempt($ip, $login);
        return 'Invalid username/email or password.';
    }

    clearLoginAttempts($ip, $login);

    // Self-registered accounts must verify their email before their first
    // login. Existing accounts were grandfathered in with email_verified_at
    // set, and OAuth accounts are auto-verified — so this only actually
    // blocks a genuinely-unverified new self-signup.
    if ($user['role'] === 'user' && empty($user['email_verified_at'])) {
        return EMAIL_NOT_VERIFIED;
    }

    loginById((int) $user['id']);
    return $user;
}

// Generates a fresh verification token (invalidating any prior ones for this
// user — same pattern as forgot-password.php's password_resets), emails the
// link, and returns nothing; failures to send never block signup itself.
function sendVerificationEmail(int $userId, string $email, string $firstName): void {
    db()->prepare('UPDATE email_verifications SET used = 1 WHERE user_id = ?')->execute([$userId]);

    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires   = date('Y-m-d H:i:s', strtotime('+24 hours'));

    db()->prepare(
        'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
    )->execute([$userId, $tokenHash, $expires]);

    $verifyUrl = SITE_URL . '/verify-email.php?token=' . urlencode($token) . '&uid=' . $userId;

    $subject = 'Verify your email — ' . SITE_NAME;
    $body    = "Hi {$firstName},\n\n"
             . "Thanks for signing up for " . SITE_NAME . "! Please confirm this is your email address by clicking the link below (valid for 24 hours):\n\n"
             . $verifyUrl . "\n\n"
             . "You won't be able to log in until it's verified.\n\n"
             . "If you didn't sign up, you can ignore this email.\n\n"
             . "— " . SITE_NAME;

    sendMail($email, $subject, $body);
}

// Establish an authenticated session for a known user id (used by password
// login above and by social login). Always regenerates the session id.
function loginById(int $userId): void {
    sessionStart();
    session_regenerate_id(true);
    $_SESSION['user_id']       = $userId;
    $_SESSION['last_activity'] = time();

    // Backfill country from the user's IP if it was never recorded (e.g.
    // accounts created before country capture existed).
    backfillUserCountry($userId);
}

function logoutUser(): void {
    sessionStart();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
}

// $refCode: an optional referrer's referral_code (from ?ref= on the public
// registration form). If it resolves to a real account, that REFERRER (not
// this new account) gets REFERRAL_REWARD_DAYS of free access — a bad/typo'd
// code is silently ignored rather than blocking registration.
function registerUser(string $username, string $email, string $password, string $first, string $last, ?string $refCode = null): int|string {
    if (strlen($username) < 3)                       return 'Username must be at least 3 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  return 'Invalid email address.';
    if (strlen($password) < 8)                       return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $password))           return 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password))           return 'Password must contain at least one number.';

    $st = db()->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
    $st->execute([$email, $username]);
    if ($st->fetch()) return 'Email or username already taken.';

    $hash    = password_hash($password, PASSWORD_BCRYPT);
    $expires = date('Y-m-d H:i:s', strtotime('+' . TRIAL_DAYS_SELF . ' days'));
    $referralCode = generateReferralCode();

    $st = db()->prepare(
        'INSERT INTO users (username, email, password_hash, first_name, last_name,
         referral_code, role, registration_type, subscription_status, access_expires_at)
         VALUES (?, ?, ?, ?, ?, ?, "user", "self", "trial", ?)'
    );
    $st->execute([$username, $email, $hash, $first, $last, $referralCode, $expires]);
    $userId = (int) db()->lastInsertId();

    // Record the registrant's country (from their own IP) on both their user
    // row and any matching student enrollment record.
    captureUserCountry($userId);
    captureStudentCountry($email);

    if ($refCode) {
        try {
            $r = db()->prepare('SELECT id FROM users WHERE referral_code = ?');
            $r->execute([$refCode]);
            $referrer = $r->fetch();
            if ($referrer && (int) $referrer['id'] !== $userId) {
                db()->prepare(
                    'INSERT INTO referrals (referrer_user_id, referred_user_id, reward_days) VALUES (?, ?, ?)'
                )->execute([$referrer['id'], $userId, REFERRAL_REWARD_DAYS]);
                grantAdminAccess((int) $referrer['id'], REFERRAL_REWARD_DAYS, (int) $referrer['id'], 'Referral reward — referred ' . $email);
            }
        } catch (\Throwable $e) {
            // referrals table/column not migrated yet, or this user was already
            // referred once (uniq_referred) — never block registration over it.
        }
    }

    sendVerificationEmail($userId, $email, $first);

    return $userId;
}

// Random 8-char referral code, collision-checked the same way
// uniqueUsernameFromEmail() checks usernames.
function generateReferralCode(): string {
    do {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $c = db()->prepare('SELECT id FROM users WHERE referral_code = ?');
        $c->execute([$code]);
    } while ($c->fetch());
    return $code;
}

// Build a unique username from an email's local-part (appends 1, 2, … on clash).
function uniqueUsernameFromEmail(string $email): string {
    $base = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0])) ?: 'user';
    $username = $base;
    for ($i = 1; ; $i++) {
        $c = db()->prepare('SELECT id FROM users WHERE username = ?');
        $c->execute([$username]);
        if (!$c->fetch()) break;
        $username = $base . $i;
    }
    return $username;
}

// Create a login account for a public enrollment (student-signup.php). Starts as
// 'pending' with no access — an admin grants access after confirming payment.
// A username is generated from the email. Returns the new user id or an error.
function createEnrolleeAccount(string $email, string $password, string $first, string $last): int|string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  return 'Please enter a valid email address.';
    if (strlen($password) < 8)                       return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $password))           return 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password))           return 'Password must contain at least one number.';

    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) return 'An account with this email already exists — please log in instead.';

    $username = uniqueUsernameFromEmail($email);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    db()->prepare(
        'INSERT INTO users (username, email, password_hash, first_name, last_name,
         role, registration_type, subscription_status, access_expires_at)
         VALUES (?, ?, ?, ?, ?, "user", "self", "pending", NULL)'
    )->execute([$username, $email, $hash, $first, $last]);

    $userId = (int) db()->lastInsertId();
    captureUserCountry($userId);
    sendVerificationEmail($userId, $email, $first);
    return $userId;
}
