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
    return $st->fetch() ?: null;
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
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit;
    }
    checkAdminIp();
    return $user;
}

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

    loginById((int) $user['id']);
    return $user;
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

function registerUser(string $username, string $email, string $password, string $first, string $last): int|string {
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

    $st = db()->prepare(
        'INSERT INTO users (username, email, password_hash, first_name, last_name,
         role, registration_type, subscription_status, access_expires_at)
         VALUES (?, ?, ?, ?, ?, "user", "self", "trial", ?)'
    );
    $st->execute([$username, $email, $hash, $first, $last, $expires]);
    $userId = (int) db()->lastInsertId();

    // Record the registrant's country (from their own IP) on both their user
    // row and any matching student enrollment record.
    captureUserCountry($userId);
    captureStudentCountry($email);

    return $userId;
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
    return $userId;
}
