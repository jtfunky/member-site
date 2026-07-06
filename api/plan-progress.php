<?php
/**
 * Toggle a practice-plan session's completed flag for the logged-in student.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/practice_plan.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }
verifyCsrf();

$data      = json_decode(file_get_contents('php://input'), true);
$sessionNo = (int) ($data['session_no'] ?? 0);
$done      = !empty($data['done']);
if ($sessionNo < 1 || $sessionNo > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session.']);
    exit;
}

$ok = togglePlanSession((int) $user['id'], $sessionNo, $done);
echo json_encode(['ok' => $ok]);
