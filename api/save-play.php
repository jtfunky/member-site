<?php
/**
 * Record a completed song play (posted as JSON by the game engine).
 * Members-only; the placement test uses save-test.php instead.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/access.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user || !hasPremiumAccess($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
verifyCsrf();
if (tooManyRequests('songplay', 120, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many plays. Please wait a moment.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'Invalid payload.']); exit; }

$clampInt = fn($v, $min, $max) => max($min, min($max, (int) $v));

$songId = substr(trim((string) ($data['song_id'] ?? '')), 0, 64);
$title  = substr(trim((string) ($data['song_title'] ?? '')), 0, 200);
if ($songId === '') { http_response_code(400); echo json_encode(['error' => 'Missing song.']); exit; }

$score   = $clampInt($data['score'] ?? 0, 0, 1000000000);
$acc     = max(0.0, min(100.0, (float) ($data['accuracy'] ?? 0)));
$grade   = preg_replace('/[^A-S]/', '', strtoupper(substr((string) ($data['grade'] ?? ''), 0, 1)));
$combo   = $clampInt($data['max_combo'] ?? 0, 0, 1000000);
$perfect = $clampInt($data['perfect'] ?? 0, 0, 1000000);
$good    = $clampInt($data['good'] ?? 0, 0, 1000000);
$miss    = $clampInt($data['miss'] ?? 0, 0, 1000000);
$total   = $clampInt($data['total_notes'] ?? 0, 0, 1000000);
$input   = substr(preg_replace('/[^a-z]/', '', strtolower((string) ($data['input_method'] ?? ''))), 0, 20);

try {
    db()->prepare(
        'INSERT INTO song_plays
           (user_id, song_id, song_title, score, accuracy, grade, max_combo, perfect, good, miss, total_notes, input_method)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int) $user['id'], $songId, $title, $score, $acc, $grade, $combo, $perfect, $good, $miss, $total, $input,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the play. The song_plays table may not exist yet.']);
    exit;
}

echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId()]);
