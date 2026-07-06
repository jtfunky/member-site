<?php
/**
 * Generate (or regenerate) the logged-in student's AI practice plan.
 * Called by my-plan.js when no plan exists yet.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/practice_plan.php';

@set_time_limit(60); // the Claude call can take several seconds
header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }
verifyCsrf();

if (!hasTakenPlacementTest((int) $user['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Take the placement test first.']);
    exit;
}
if (tooManyRequests('genplan', 10, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Please wait a moment before trying again.']);
    exit;
}

$result = generatePracticePlan((int) $user['id']);
if (is_string($result)) {
    http_response_code(502);
    echo json_encode(['error' => $result]);
    exit;
}

echo json_encode(['ok' => true]);
