<?php
/**
 * Tiny email helper. Uses PHP mail() (same transport as the password-reset
 * flow). Notifications are best-effort — failures never block the request.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function mailFrom(): string {
    return MAIL_FROM; // defined in config.php (required above)
}

function sendMail(string $to, string $subject, string $body): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $headers = 'From: ' . SITE_NAME . ' <' . mailFrom() . ">\r\n"
             . "Content-Type: text/plain; charset=UTF-8";
    return @mail($to, $subject, $body, $headers);
}

// Same as sendMail(), plus a single file attachment (e.g. a raffle ticket
// PDF) — hand-built MIME multipart/mixed, since PHP's mail() has no native
// attachment support and this codebase avoids adding a mail library for one
// feature. attachmentPath must be readable on disk; silently fails (like
// sendMail()) rather than throwing, since notifications are best-effort.
function sendMailWithAttachment(string $to, string $subject, string $body, string $attachmentPath, string $attachmentName): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (!is_readable($attachmentPath)) return false;

    $boundary = md5(uniqid('', true));
    $headers  = 'From: ' . SITE_NAME . ' <' . mailFrom() . ">\r\n"
              . "MIME-Version: 1.0\r\n"
              . "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

    $fileContent = chunk_split(base64_encode(file_get_contents($attachmentPath)));

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $message .= $body . "\r\n\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: application/pdf; name=\"{$attachmentName}\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"{$attachmentName}\"\r\n\r\n";
    $message .= $fileContent . "\r\n";
    $message .= "--{$boundary}--";

    return @mail($to, $subject, $message, $headers);
}

// Emails of active admin accounts (recipients for staff notifications).
function adminEmails(): array {
    try {
        $rows = db()->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
        return array_values(array_filter(array_column($rows, 'email')));
    } catch (\Throwable $e) {
        return [];
    }
}

function notifyAdmins(string $subject, string $body): void {
    foreach (adminEmails() as $to) sendMail($to, $subject, $body);
}

// Extra recipients (comma-separated in config NOTIFY_CC) who should also receive
// new-signup notifications without needing an admin account.
if (!defined('NOTIFY_CC')) define('NOTIFY_CC', '');

function signupCcEmails(): array {
    return array_values(array_filter(
        array_map('trim', explode(',', NOTIFY_CC)),
        fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
    ));
}

// New-registration/enrollment notification: the registration inbox + the
// NOTIFY_CC list (not admin accounts' personal emails).
function notifySignup(string $subject, string $body): void {
    sendMail(defined('REGISTRATION_EMAIL') ? REGISTRATION_EMAIL : 'registration@zachalcasid.com', $subject, $body);
    foreach (signupCcEmails() as $to) sendMail($to, $subject, $body);
}
