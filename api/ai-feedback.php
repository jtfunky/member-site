<?php
/**
 * Return AI coaching for a drum test: GET /api/ai-feedback.php?id=<test id>.
 * Generates on first request and caches the result on the row so re-views don't
 * re-bill the API. Only the test's owner (or an admin) can read it.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai_feedback.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing test id.']); exit; }

$st = db()->prepare('SELECT * FROM drum_tests WHERE id = ? LIMIT 1');
$st->execute([$id]);
$test = $st->fetch();
if (!$test) { http_response_code(404); echo json_encode(['error' => 'Test not found.']); exit; }

// Owner or admin only.
if ((int) $test['user_id'] !== (int) $user['id'] && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not allowed.']);
    exit;
}

// Serve cached feedback if we already generated it.
if (!empty($test['ai_feedback'])) {
    echo json_encode([
        'feedback'      => $test['ai_feedback'],
        'feedback_html' => feedbackToHtml($test['ai_feedback']),
        'cached'        => true,
    ]);
    exit;
}

if (!aiFeedbackEnabled()) {
    http_response_code(503);
    echo json_encode(['error' => 'AI coaching is not configured yet.']);
    exit;
}

// Cap fresh generations per user (each one is a paid API call).
if (tooManyRequests('aifeedback', 10, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many feedback requests. Please wait a few minutes.']);
    exit;
}

// Pull the student's level for a little extra context, if available.
$level = '';
try {
    $ls = db()->prepare('SELECT experience FROM students WHERE user_id = ? LIMIT 1');
    $ls->execute([(int) $test['user_id']]);
    $level = (string) ($ls->fetchColumn() ?: '');
} catch (\Throwable $e) { /* optional */ }

$feedback = generateDrumFeedback($test, $level);

// Cache only a genuine result — not our own error strings (they start lowercase
// or with "Could/AI/Coaching…"; a real answer starts with the "**What's working**" heading).
if (strpos($feedback, '**') !== false) {
    try {
        $up = db()->prepare('UPDATE drum_tests SET ai_feedback = ?, ai_model = ? WHERE id = ?');
        $up->execute([$feedback, defined('AI_FEEDBACK_MODEL') ? AI_FEEDBACK_MODEL : '', $id]);
    } catch (\Throwable $e) { /* non-fatal: still return the text */ }
    echo json_encode(['feedback' => $feedback, 'feedback_html' => feedbackToHtml($feedback), 'cached' => false]);
    exit;
}

// Otherwise it's an error/refusal message — surface it, don't cache it.
http_response_code(502);
echo json_encode(['error' => $feedback]);
