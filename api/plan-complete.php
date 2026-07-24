<?php
/**
 * Record a finished practice-plan session play. Accuracy is recomputed server-side
 * (anti-cheat); passing marks the session completed and unlocks the next one.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/practice_plan.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user || !hasPremiumAccess($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
verifyCsrf();

$data      = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionNo = (int) ($data['session_no'] ?? 0);
if ($sessionNo < 1 || $sessionNo > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session.']);
    exit;
}

$perfect = (int) ($data['perfect'] ?? 0);
$good    = (int) ($data['good'] ?? 0);
$miss    = (int) ($data['miss'] ?? 0);
$total   = (int) ($data['total'] ?? 0);

$res = markSessionResult((int) $user['id'], $sessionNo, $perfect, $good, $miss, $total);
if (isset($res['error'])) {
    http_response_code(400);
    echo json_encode($res);
    exit;
}

// Record a play row so it shows in the member's progress (non-fatal).
try {
    db()->prepare(
        'INSERT INTO song_plays
           (user_id, song_id, song_title, score, accuracy, grade, max_combo, perfect, good, miss, total_notes, input_method)
         VALUES (?, ?, ?, 0, ?, "", 0, ?, ?, ?, ?, "plan")'
    )->execute([
        (int) $user['id'], 'plan-s' . $sessionNo, 'Practice Session ' . $sessionNo,
        $res['accuracy'], $perfect, $good, $miss, $total,
    ]);
} catch (\Throwable $e) { /* progress logging optional */ }

echo json_encode($res);
