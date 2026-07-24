<?php
/**
 * Mark that a student has seen the game tutorial (kick/snare/hi-hat/crash/
 * tom walkthrough shown before their first placement test). No payload
 * needed — just flips the flag for the logged-in user.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }
verifyCsrf();

try {
    db()->prepare('UPDATE students SET tutorial_completed = 1 WHERE user_id = ?')
        ->execute([(int) $user['id']]);
} catch (\Throwable $e) { /* column not migrated yet — non-fatal */ }

echo json_encode(['ok' => true]);
