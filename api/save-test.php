<?php
/**
 * Store a completed drum placement test (posted as JSON by the game engine).
 * Returns { id } of the new drum_tests row.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }
verifyCsrf();
if (tooManyRequests('drumtest', 30, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many attempts. Please wait a few minutes.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'Invalid payload.']); exit; }

$clampInt = fn($v, $min, $max) => max($min, min($max, (int) $v));

$score       = $clampInt($data['score'] ?? 0, 0, 1000000000);
$accuracy    = max(0.0, min(100.0, (float) ($data['accuracy'] ?? 0)));
$grade       = preg_replace('/[^A-S]/', '', strtoupper(substr((string) ($data['grade'] ?? ''), 0, 1)));
$perfect     = $clampInt($data['perfect'] ?? 0, 0, 1000000);
$good        = $clampInt($data['good'] ?? 0, 0, 1000000);
$miss        = $clampInt($data['miss'] ?? 0, 0, 1000000);
$combo       = $clampInt($data['max_combo'] ?? 0, 0, 1000000);
$total       = $clampInt($data['total_notes'] ?? 0, 0, 1000000);
$avgOffset   = $clampInt($data['avg_offset_ms'] ?? 0, -100000, 100000);
$consistency = $clampInt($data['timing_consistency_ms'] ?? 0, 0, 100000);
$song        = substr(trim((string) ($data['song'] ?? '')), 0, 255);
$input       = substr(preg_replace('/[^a-z]/', '', strtolower((string) ($data['input_method'] ?? ''))), 0, 20);

// Sanitize the per-pad breakdown before storing it as JSON.
$pads = [];
if (!empty($data['pads']) && is_array($data['pads'])) {
    foreach (array_slice($data['pads'], 0, 32) as $p) {
        if (!is_array($p)) continue;
        $pads[] = [
            'pad'           => substr((string) ($p['pad'] ?? ''), 0, 40),
            'perfect'       => $clampInt($p['perfect'] ?? 0, 0, 1000000),
            'good'          => $clampInt($p['good'] ?? 0, 0, 1000000),
            'miss'          => $clampInt($p['miss'] ?? 0, 0, 1000000),
            'accuracy'      => (isset($p['accuracy']) && is_numeric($p['accuracy'])) ? round((float) $p['accuracy'], 1) : null,
            'avg_offset_ms' => (isset($p['avg_offset_ms']) && is_numeric($p['avg_offset_ms'])) ? (int) $p['avg_offset_ms'] : null,
        ];
    }
}
$metrics = json_encode(['pads' => $pads]);

// Link a student enrollment record if one exists for this user.
$sid = null;
try {
    $st = db()->prepare('SELECT id FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) $user['id']]);
    $found = $st->fetchColumn();
    if ($found) $sid = (int) $found;
} catch (\Throwable $e) { /* students table optional */ }

try {
    $st = db()->prepare(
        'INSERT INTO drum_tests
           (user_id, student_id, song, input_method, score, accuracy, grade, perfect, good, miss,
            max_combo, total_notes, avg_offset_ms, timing_consistency_ms, metrics)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        (int) $user['id'], $sid, $song, $input, $score, $accuracy, $grade, $perfect, $good, $miss,
        $combo, $total, $avgOffset, $consistency, $metrics,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the test. The drum_tests table may not exist yet.']);
    exit;
}

echo json_encode(['id' => (int) db()->lastInsertId()]);
