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
