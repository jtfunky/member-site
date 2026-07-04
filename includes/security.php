<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// ── CSRF ──────────────────────────────────────────────────

function csrfToken(): string {
    sessionStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Invalid or missing security token. Please go back and try again.');
    }
}

// ── Rate limiting / account lockout ───────────────────────

function recordLoginAttempt(string $ip, string $identifier): void {
    $db = db();
    // Upsert attempt record
    $db->prepare(
        'INSERT INTO login_attempts (ip, identifier, attempts, last_attempt)
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE
           attempts     = IF(last_attempt < NOW() - INTERVAL ? MINUTE, 1, attempts + 1),
           last_attempt = NOW(),
           locked_until = IF(
             IF(last_attempt < NOW() - INTERVAL ? MINUTE, 1, attempts + 1) >= ?,
             NOW() + INTERVAL ? MINUTE, NULL
           )'
    )->execute([
        $ip, $identifier,
        LOGIN_LOCKOUT_RESET_MINUTES,
        LOGIN_LOCKOUT_RESET_MINUTES,
        MAX_LOGIN_ATTEMPTS,
        LOGIN_LOCKOUT_MINUTES,
    ]);
}

function clearLoginAttempts(string $ip, string $identifier): void {
    db()->prepare(
        'DELETE FROM login_attempts WHERE ip = ? AND identifier = ?'
    )->execute([$ip, $identifier]);
}

// Generic per-IP rate limiter for non-login actions (password reset, signup).
// Each call counts as one request; returns true once this IP exceeds $max
// requests for $action within the rolling $windowMinutes. Reuses the
// login_attempts table with a namespaced identifier ("rl:<action>") and never
// sets locked_until, so it can't interfere with login lockout logic.
function tooManyRequests(string $action, int $max, int $windowMinutes): bool {
    $ip = getClientIp();
    $id = 'rl:' . $action;
    $db = db();

    $db->prepare(
        'INSERT INTO login_attempts (ip, identifier, attempts, last_attempt)
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE
           attempts     = IF(last_attempt < NOW() - INTERVAL ? MINUTE, 1, attempts + 1),
           last_attempt = NOW()'
    )->execute([$ip, $id, $windowMinutes]);

    $st = $db->prepare(
        'SELECT attempts FROM login_attempts
         WHERE ip = ? AND identifier = ? AND last_attempt > NOW() - INTERVAL ? MINUTE'
    );
    $st->execute([$ip, $id, $windowMinutes]);
    $row = $st->fetch();
    return $row && (int) $row['attempts'] > $max;
}

function isLockedOut(string $ip, string $identifier): bool {
    $row = db()->prepare(
        'SELECT locked_until FROM login_attempts
         WHERE (ip = ? OR identifier = ?) AND locked_until > NOW()'
    );
    $row->execute([$ip, $identifier]);
    return (bool) $row->fetch();
}

function getRemainingLockout(string $ip, string $identifier): int {
    $row = db()->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS secs
         FROM login_attempts
         WHERE (ip = ? OR identifier = ?) AND locked_until > NOW()
         ORDER BY locked_until DESC LIMIT 1'
    );
    $row->execute([$ip, $identifier]);
    $r = $row->fetch();
    return $r ? max(0, (int) $r['secs']) : 0;
}

function getClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '127.0.0.1';
}

// ── Safe redirect target ───────────────────────────────────
// Only allow same-site absolute paths to block open-redirect / header injection.
// Rejects "@evil.com", "//evil.com", "/\evil.com", and CR/LF-injected values.
function safeRedirectPath(string $path, string $fallback = '/dashboard.php'): string {
    if ($path === '' || $path[0] !== '/') return $fallback;
    if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) return $fallback;
    if (strpos($path, '\\') !== false) return $fallback;
    if (preg_match('/[\x00-\x1F\x7F]/', $path)) return $fallback; // control chars / CRLF
    return $path;
}

// ── Session timeout ────────────────────────────────────────

function checkSessionTimeout(): void {
    if (empty($_SESSION['user_id'])) return;
    $last = $_SESSION['last_activity'] ?? time();
    if ((time() - $last) > SESSION_TIMEOUT_SECONDS) {
        session_destroy();
        header('Location: ' . SITE_URL . '/login.php?expired=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// ── File upload validation ─────────────────────────────────

const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'flac', 'm4a'];
const AUDIO_SIGNATURES  = [
    'mp3'  => ["\xFF\xFB", "\xFF\xF3", "\xFF\xF2", "ID3"],
    'ogg'  => ["OggS"],
    'flac' => ["fLaC"],
    'wav'  => ["RIFF"],
    'm4a'  => ["\x00\x00\x00\x20\x66\x74\x79\x70", "\x00\x00\x00\x18\x66\x74\x79\x70"],
];

function validateAudioUpload(array $file): string|true {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload error code: ' . $file['error'];
    }
    if ($file['size'] > MAX_AUDIO_SIZE_MB * 1024 * 1024) {
        return 'File too large. Maximum ' . MAX_AUDIO_SIZE_MB . ' MB.';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, AUDIO_EXTENSIONS, true)) {
        return 'Invalid file type. Allowed: ' . implode(', ', AUDIO_EXTENSIONS) . '.';
    }

    // Read first 12 bytes and check magic signature
    $fh  = fopen($file['tmp_name'], 'rb');
    $sig = fread($fh, 12);
    fclose($fh);

    // MP3 — also accept ID3 tag header
    if ($ext === 'mp3') {
        $valid = str_starts_with($sig, "\xFF\xFB")
              || str_starts_with($sig, "\xFF\xF3")
              || str_starts_with($sig, "\xFF\xF2")
              || str_starts_with($sig, "ID3");
        if (!$valid) return 'File does not appear to be a valid MP3.';
        return true;
    }

    if (isset(AUDIO_SIGNATURES[$ext])) {
        foreach (AUDIO_SIGNATURES[$ext] as $magic) {
            if (str_starts_with($sig, $magic)) return true;
        }
        return 'File does not match its extension. Please check the file.';
    }

    return true;
}

// ── Image upload validation ────────────────────────────────

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

function validateImageUpload(array $file): string|true {
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload error code: ' . $file['error'];
    if ($file['size'] > 2 * 1024 * 1024) return 'Image must be under 2 MB.';

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, IMAGE_EXTENSIONS, true)) {
        return 'Invalid image type. Allowed: jpg, png, gif, webp.';
    }

    // Use getimagesize() to verify it is really an image
    if (!@getimagesize($file['tmp_name'])) {
        return 'File does not appear to be a valid image.';
    }

    return true;
}

// Proof-of-payment upload: an image (screenshot/photo) or a PDF receipt,
// up to 5 MB. Returns true on success or an error message.
function validateProofUpload(array $file): string|true {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Please attach your proof of payment.';
    }
    if ($file['size'] > 5 * 1024 * 1024) return 'Proof file must be under 5 MB.';

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) {
        return 'Invalid file type. Allowed: jpg, png, gif, webp, pdf.';
    }

    // Images must be real images; PDFs must start with the %PDF magic bytes.
    if ($ext === 'pdf') {
        $fh = @fopen($file['tmp_name'], 'rb');
        $head = $fh ? fread($fh, 5) : '';
        if ($fh) fclose($fh);
        if (strpos((string) $head, '%PDF-') !== 0) return 'File does not appear to be a valid PDF.';
    } elseif (!@getimagesize($file['tmp_name'])) {
        return 'File does not appear to be a valid image.';
    }

    return true;
}

// ── Admin IP allowlist ─────────────────────────────────────

function checkAdminIp(): void {
    if (empty(ADMIN_ALLOWED_IPS)) return; // empty = allow all
    $ip = getClientIp();
    if (!in_array($ip, ADMIN_ALLOWED_IPS, true)) {
        http_response_code(403);
        die('Access denied.');
    }
}
