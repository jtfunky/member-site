<?php
/**
 * Mark that a user has seen the first-time "how to play Groove Quest"
 * walkthrough shown on their first visit to game.php. No payload needed —
 * just flips the flag for the logged-in user.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }
verifyCsrf();

try {
    db()->prepare('UPDATE users SET game_intro_seen_at = NOW() WHERE id = ?')
        ->execute([(int) $user['id']]);
} catch (\Throwable $e) { /* column not migrated yet — non-fatal */ }

echo json_encode(['ok' => true]);
