<?php
/**
 * Provider-agnostic OAuth2 (authorization-code) social login.
 * Supported: Google, Facebook, Discord. A provider with an empty Client ID
 * in config.php is treated as disabled and its button is hidden.
 *
 * Flow:  oauth-start.php  → provider consent → oauth-callback.php → here.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/programs.php';

// Static endpoints + scopes per provider. Credentials come from config.php.
function oauthProviders(): array {
    return [
        'google' => [
            'name'      => 'Google',
            'auth_url'  => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'user_url'  => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope'     => 'openid email profile',
            'client_id' => OAUTH_GOOGLE_CLIENT_ID,
            'secret'    => OAUTH_GOOGLE_CLIENT_SECRET,
        ],
        'facebook' => [
            'name'      => 'Facebook',
            'auth_url'  => 'https://www.facebook.com/v19.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
            'user_url'  => 'https://graph.facebook.com/me?fields=id,name,first_name,last_name,email',
            'scope'     => 'email public_profile',
            'client_id' => OAUTH_FACEBOOK_CLIENT_ID,
            'secret'    => OAUTH_FACEBOOK_CLIENT_SECRET,
        ],
        'discord' => [
            'name'      => 'Discord',
            'auth_url'  => 'https://discord.com/oauth2/authorize',
            'token_url' => 'https://discord.com/api/oauth2/token',
            'user_url'  => 'https://discord.com/api/users/@me',
            'scope'     => 'identify email',
            'client_id' => OAUTH_DISCORD_CLIENT_ID,
            'secret'    => OAUTH_DISCORD_CLIENT_SECRET,
        ],
    ];
}

function oauthProvider(string $key): ?array {
    return oauthProviders()[$key] ?? null;
}

function oauthEnabled(string $key): bool {
    $p = oauthProvider($key);
    return $p !== null && $p['client_id'] !== '';
}

function oauthEnabledProviders(): array {
    return array_keys(array_filter(oauthProviders(), fn($p) => $p['client_id'] !== ''));
}

function oauthRedirectUri(string $key): string {
    return SITE_URL . '/oauth-callback.php?provider=' . urlencode($key);
}

function oauthAuthUrl(string $key, string $state): string {
    $p = oauthProvider($key);
    $params = [
        'client_id'     => $p['client_id'],
        'redirect_uri'  => oauthRedirectUri($key),
        'response_type' => 'code',
        'scope'         => $p['scope'],
        'state'         => $state,
    ];
    if ($key === 'google') {
        $params['access_type'] = 'online';
        $params['prompt']      = 'select_account';
    }
    return $p['auth_url'] . '?' . http_build_query($params);
}

// ── HTTP (cURL) ────────────────────────────────────────────
function oauthHttp(string $method, string $url, array $data = [], array $headers = []): array {
    $ch   = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        // Bound how long a PHP worker can be held waiting on the provider. On
        // shared hosting workers are scarce, so a slow Google/FB response must
        // not pin one for long: fail fast (~8s worst case) instead of ~15s.
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_URL]        = $url;
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    } else {
        if ($data) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
        $opts[CURLOPT_URL] = $url;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) return ['ok' => false, 'data' => [], 'error' => $err ?: 'Network error'];
    $json = json_decode($body, true);
    return ['ok' => $code >= 200 && $code < 300, 'data' => is_array($json) ? $json : []];
}

// Exchange the authorization code for an access token. Returns token or null.
function oauthExchangeCode(string $key, string $code): ?string {
    $p   = oauthProvider($key);
    $res = oauthHttp('POST', $p['token_url'], [
        'client_id'     => $p['client_id'],
        'client_secret' => $p['secret'],
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => oauthRedirectUri($key),
    ]);
    return ($res['ok'] && !empty($res['data']['access_token'])) ? $res['data']['access_token'] : null;
}

// Fetch the userinfo endpoint with the bearer token, normalized to a common shape.
function oauthFetchProfile(string $key, string $accessToken): ?array {
    $p   = oauthProvider($key);
    $res = oauthHttp('GET', $p['user_url'], [], ['Authorization: Bearer ' . $accessToken]);
    if (!$res['ok'] || empty($res['data'])) return null;
    return oauthNormalize($key, $res['data']);
}

// Map each provider's userinfo JSON to {id, email, verified, first, last, name}.
function oauthNormalize(string $key, array $d): ?array {
    switch ($key) {
        case 'google':
            return [
                'id'       => (string) ($d['sub'] ?? ''),
                'email'    => $d['email'] ?? '',
                'verified' => !empty($d['email_verified']),
                'first'    => $d['given_name']  ?? '',
                'last'     => $d['family_name'] ?? '',
                'name'     => $d['name']        ?? '',
            ];
        case 'facebook':
            return [
                'id'       => (string) ($d['id'] ?? ''),
                'email'    => $d['email'] ?? '',
                'verified' => !empty($d['email']),   // Meta verifies account emails
                'first'    => $d['first_name'] ?? '',
                'last'     => $d['last_name']  ?? '',
                'name'     => $d['name']       ?? '',
            ];
        case 'discord':
            $handle = $d['global_name'] ?? ($d['username'] ?? '');
            return [
                'id'       => (string) ($d['id'] ?? ''),
                'email'    => $d['email'] ?? '',
                'verified' => !empty($d['verified']),
                'first'    => $handle,
                'last'     => '',
                'name'     => $handle,
            ];
    }
    return null;
}

// ── Account resolution ─────────────────────────────────────
// Returns the user row (array) on success, or an error string to show the user.
function oauthLoginOrCreate(string $key, array $profile): array|string {
    $name = oauthProvider($key)['name'];
    if (empty($profile['id']))       return "Could not read your $name account id.";
    if (empty($profile['email']))    return "$name didn't share an email address. Please register with an email instead.";
    if (empty($profile['verified'])) return "Please verify your email with $name first, then try again.";

    $db = db();

    // 1) Already linked → log that user in.
    $st = $db->prepare(
        'SELECT u.* FROM oauth_accounts oa JOIN users u ON u.id = oa.user_id
         WHERE oa.provider = ? AND oa.provider_user_id = ? LIMIT 1'
    );
    $st->execute([$key, $profile['id']]);
    if ($user = $st->fetch()) {
        if (!$user['is_active']) return 'This account has been deactivated.';
        loginById((int) $user['id']);
        return $user;
    }

    // 2) Verified email matches an existing account → link and log in.
    $st = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $st->execute([$profile['email']]);
    if ($user = $st->fetch()) {
        if (!$user['is_active']) return 'This account has been deactivated.';
        oauthLink((int) $user['id'], $key, $profile);
        loginById((int) $user['id']);
        return $user;
    }

    // 3) New user → create with the standard free trial, link, log in.
    $userId = oauthCreateUser($key, $profile);
    oauthLink($userId, $key, $profile);
    loginById($userId);
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$userId]);
    return $st->fetch();
}

function oauthLink(int $userId, string $key, array $profile): void {
    db()->prepare(
        'INSERT IGNORE INTO oauth_accounts (user_id, provider, provider_user_id, email)
         VALUES (?, ?, ?, ?)'
    )->execute([$userId, $key, $profile['id'], $profile['email']]);
}

function oauthCreateUser(string $key, array $profile): int {
    $username = oauthUniqueUsername($profile);
    $expires  = date('Y-m-d H:i:s', strtotime('+' . TRIAL_DAYS_SELF . ' days'));
    $currency = function_exists('getUserCurrency') ? getUserCurrency() : 'USD';

    // password_hash = '' marks a social-only account (password sign-in is blocked
    // until they set one via Forgot Password).
    db()->prepare(
        'INSERT INTO users (username, email, password_hash, first_name, last_name,
         role, registration_type, subscription_status, access_expires_at, currency)
         VALUES (?, ?, "", ?, ?, "user", "self", "trial", ?, ?)'
    )->execute([$username, $profile['email'], $profile['first'], $profile['last'], $expires, $currency]);
    $userId = (int) db()->lastInsertId();

    // Record the registrant's country (from their own IP) on their user row,
    // create the Website-access student record, then stamp the student's country
    // (captureStudentCountry updates an existing student by email, so the record
    // must be created first).
    captureUserCountry($userId);
    ensureWebAccessStudent($userId);
    captureStudentCountry($profile['email'] ?? '');

    if (function_exists('notifySignup')) {
        notifySignup('New user registered',
            'A new account was just created on ' . SITE_NAME . ' via ' . ucfirst($key) . " login:\n\n"
            . 'Name: ' . trim(($profile['first'] ?? '') . ' ' . ($profile['last'] ?? '')) . "\n"
            . 'Email: ' . ($profile['email'] ?? '—') . "\n\n"
            . 'Manage students: ' . SITE_URL . '/admin/students.php');
    }

    return $userId;
}

// Build a unique username from the display name (or email local-part).
function oauthUniqueUsername(array $profile): string {
    $base = $profile['name'] !== '' ? $profile['name'] : explode('@', $profile['email'])[0];
    $base = strtolower(preg_replace('/[^a-z0-9_]/i', '', $base));
    if (strlen($base) < 3) $base = 'user' . $base;
    $base = substr($base, 0, 40);

    $st        = db()->prepare('SELECT 1 FROM users WHERE username = ?');
    $candidate = $base;
    $i         = 0;
    while (true) {
        $st->execute([$candidate]);
        if (!$st->fetch()) return $candidate;
        $i++;
        $candidate = substr($base, 0, 40 - strlen((string) $i)) . $i;
    }
}

// ── Manage links from the profile page (while logged in) ───
// provider => row(provider,email,created_at) for everything this user has linked.
function oauthAccountsForUser(int $userId): array {
    $st = db()->prepare(
        'SELECT provider, email, created_at FROM oauth_accounts WHERE user_id = ? ORDER BY created_at'
    );
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['provider']] = $r;
    return $out;
}

// Link a freshly-authenticated provider identity to the CURRENT user. Returns
// true, or an error string if that identity already belongs to someone else.
function oauthLinkToCurrentUser(int $userId, string $key, array $profile): bool|string {
    if (empty($profile['id'])) return 'Could not read your ' . oauthProvider($key)['name'] . ' account id.';

    $st = db()->prepare('SELECT user_id FROM oauth_accounts WHERE provider = ? AND provider_user_id = ? LIMIT 1');
    $st->execute([$key, $profile['id']]);
    if ($row = $st->fetch()) {
        if ((int) $row['user_id'] === $userId) return true;   // already mine
        return 'That ' . oauthProvider($key)['name'] . ' account is already linked to another ' . SITE_NAME . ' account.';
    }
    oauthLink($userId, $key, $profile);
    return true;
}

// Remove a link. Refuses if it would leave the user with no way to sign in
// (no password AND no other linked provider). Returns true or an error string.
function oauthUnlink(int $userId, string $key): bool|string {
    if (!oauthProvider($key)) return 'Unknown provider.';

    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    $hasPassword = $u && !empty($u['password_hash']);

    $st = db()->prepare('SELECT COUNT(*) AS c FROM oauth_accounts WHERE user_id = ?');
    $st->execute([$userId]);
    $count = (int) ($st->fetch()['c'] ?? 0);

    if (!$hasPassword && $count <= 1) {
        return 'This is your only way to sign in. Set a password first (use “Forgot password” on the login page) before disconnecting it.';
    }

    db()->prepare('DELETE FROM oauth_accounts WHERE user_id = ? AND provider = ?')->execute([$userId, $key]);
    return true;
}
