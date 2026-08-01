<?php
/**
 * POST /api/chatbot.php — one turn of the support chat widget.
 * Body: {"messages": [{"role":"user"|"assistant","content":"..."}, ...]}
 * (client-owned history, oldest first, ending in the newest user message).
 * Returns {"reply": "..."} or {"error": "..."}.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/chatbot.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }

verifyCsrf();

if (!chatbotEnabled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Chat support isn’t configured yet.']);
    exit;
}

if (tooManyRequests('chatbot', 30, 15)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many messages — please wait a few minutes.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$raw   = is_array($input['messages'] ?? null) ? $input['messages'] : [];

// Validate + trim: only user/assistant roles, non-empty string content, keep
// the last few turns so a long-running chat can't balloon the request.
$messages = [];
foreach ($raw as $m) {
    $role = $m['role'] ?? '';
    $text = trim((string) ($m['content'] ?? ''));
    if (!in_array($role, ['user', 'assistant'], true) || $text === '') continue;
    $messages[] = ['role' => $role, 'content' => mb_substr($text, 0, 2000)];
}
$messages = array_slice($messages, -12);

if (!$messages || end($messages)['role'] !== 'user') {
    http_response_code(400);
    echo json_encode(['error' => 'No message to send.']);
    exit;
}

$reply = generateChatbotReply($messages);

// Our own error strings start with a recognizable prefix; a real reply won't.
$isError = (bool) preg_match('/^(Chat support|Could not reach|Sorry, I)/', $reply);
if ($isError) {
    http_response_code(502);
    echo json_encode(['error' => $reply]);
    exit;
}

echo json_encode(['reply' => $reply]);
