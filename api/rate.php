<?php
/**
 * POST /api/rate.php — submit (or update) a 5-star experience rating.
 * Body: {"context": "request-song"|"my-plan"|"song", "ref_id": "" or a song id, "rating": 1-5}
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ratings.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }

verifyCsrf();

$input   = json_decode(file_get_contents('php://input'), true);
$context = (string) ($input['context'] ?? '');
$refId   = mb_substr((string) ($input['ref_id'] ?? ''), 0, 20);
$rating  = (int) ($input['rating'] ?? 0);

if (!submitRating((int) $user['id'], $context, $refId, $rating)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid rating.']);
    exit;
}

echo json_encode(['ok' => true]);
