<?php
require_once __DIR__ . '/config.php';

function getUserIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', $_SERVER[$key])[0]);
        }
    }
    return '127.0.0.1';
}

function getUserCountry(): string {
    $ip = getUserIp();
    if (in_array($ip, ['127.0.0.1', '::1'], true)) return 'PH'; // localhost = PH for dev

    $cached = $_SESSION['geo_country'] ?? null;
    if ($cached) return $cached;

    // Fast path: if a CDN/proxy (e.g. Cloudflare) already resolved the country,
    // use that header — zero latency, no external call, no rate limit. 'XX'/'T1'
    // mean unknown/Tor, so fall through to the lookup in those cases.
    $cf = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if (preg_match('/^[A-Z]{2}$/', $cf) && !in_array($cf, ['XX', 'T1'], true)) {
        return $_SESSION['geo_country'] = $cf;
    }

    // Fallback: look the IP up, but bound it HARD so a slow or rate-limited
    // provider can never pin a PHP worker (ip-api's free tier is ~45 req/min per
    // server IP). Whatever the outcome, cache it for the session so we make at
    // most one external call per visitor.
    $country = 'US';
    if (function_exists('curl_init')) {
        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = $body ? json_decode($body, true) : null;
        if (!empty($data['countryCode'])) $country = $data['countryCode'];
    }
    return $_SESSION['geo_country'] = $country;
}

function getUserCurrency(): string {
    return getUserCountry() === 'PH' ? 'PHP' : 'USD';
}

// The visitor's country *name* (e.g. "Philippines") from their IP, or '' if
// unknown. Wraps the ISO lookup + name resolution used at sign-up time.
function getUserCountryName(): string {
    require_once __DIR__ . '/countries.php';
    return nameByIso(getUserCountry());
}

// Store the visitor's country (from their own IP) on their own user row.
// Resilient: if the `country` column doesn't exist yet, the sign-up still
// succeeds.
function captureUserCountry(int $userId): void {
    if ($userId <= 0) return;
    $country = getUserCountryName();
    if ($country === '') return;

    try {
        db()->prepare('UPDATE users SET country = ? WHERE id = ?')
            ->execute([$country, $userId]);
    } catch (\Throwable $e) {
        // country column may not exist yet — don't let this break sign-up.
    }
}

// On login, fill in a user's country (and matching student record) from their
// IP when it was never recorded — backfills accounts created before country
// capture existed. Never overwrites an existing value, and only does the geo
// lookup when there is actually something blank to fill.
function backfillUserCountry(int $userId): void {
    if ($userId <= 0) return;

    try {
        $u = db()->prepare('SELECT email, country FROM users WHERE id = ?');
        $u->execute([$userId]);
        $row = $u->fetch();
    } catch (\Throwable $e) {
        return; // country column may not exist yet
    }
    if (!$row) return;

    $email     = (string) ($row['email'] ?? '');
    $userNeeds = trim((string) ($row['country'] ?? '')) === '';

    // Nothing to fill on the user row and no email to match a student → skip
    // the geo lookup entirely.
    if (!$userNeeds && $email === '') return;

    $country = getUserCountryName();
    if ($country === '') return;

    if ($userNeeds) {
        try {
            db()->prepare('UPDATE users SET country = ? WHERE id = ?')
                ->execute([$country, $userId]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    // captureStudentCountry only writes when the student's country is blank.
    if ($email !== '') captureStudentCountry($email);
}

// Capture the visitor's country (from their own IP) onto a matching student
// enrollment record, by email, if one exists and has no country set yet.
// Called at sign-up time so the stored country reflects the student, not the
// admin who later opens the edit form. Stored in the students.address column.
function captureStudentCountry(string $email): void {
    $email = trim($email);
    if ($email === '') return;

    $country = getUserCountryName();
    if ($country === '') return;

    try {
        $st = db()->prepare(
            "UPDATE students SET address = ?
             WHERE email = ? AND (address IS NULL OR address = '')"
        );
        $st->execute([$country, $email]);
    } catch (\Throwable $e) {
        // students table may not exist yet — don't let this break sign-up.
    }
}

function getPrice(string $currency): float {
    return $currency === 'PHP' ? PRICE_PHP : PRICE_USD;
}

function formatPrice(float $amount, string $currency): string {
    return $currency === 'PHP'
        ? '₱' . number_format($amount, 2)
        : '$' . number_format($amount, 2);
}
