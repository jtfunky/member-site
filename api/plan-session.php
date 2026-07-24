<?php
/**
 * Serve a practice-plan session's playable chart (generating it on first call).
 * Hard-lock enforced server-side: a session is only served if it's unlocked.
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

$sessionNo = (int) ($_GET['session'] ?? 0);
if ($sessionNo < 1 || $sessionNo > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session.']);
    exit;
}

if (!planSessionUnlocked((int) $user['id'], $sessionNo)) {
    http_response_code(403);
    echo json_encode(['error' => 'This session is locked — finish the previous one first.']);
    exit;
}

$res = getSessionChart((int) $user['id'], $sessionNo);
if (is_string($res)) {
    http_response_code(400);
    echo json_encode(['error' => $res]);
    exit;
}
echo json_encode($res);
