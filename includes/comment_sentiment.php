<?php
/**
 * Comment sentiment classification via the Claude Messages API — used by the
 * Facebook auto-like cron (cron-facebook-engage.php) to decide which comments
 * on a freshly-posted video are positive enough to auto-like. Same raw-cURL
 * transport/conventions as includes/ai_feedback.php.
 */
require_once __DIR__ . '/config.php';

function classifyCommentSentiment(string $commentText): string {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
        return 'neutral';
    }
    $commentText = trim($commentText);
    if ($commentText === '') {
        return 'neutral';
    }

    $body = json_encode([
        'model'      => defined('AI_FEEDBACK_MODEL') ? AI_FEEDBACK_MODEL : 'claude-haiku-4-5',
        'max_tokens' => 10,
        'system'     => 'Classify the sentiment of a Facebook comment left on a drum tutorial video. '
            . 'Reply with exactly one word: positive, neutral, or negative. Praise, excitement, '
            . 'compliments, or emoji-only enthusiasm (e.g. 🔥❤️🥁) count as positive. Questions, '
            . 'plain factual remarks, or requests count as neutral. Criticism, complaints, or '
            . 'negativity count as negative. Reply with nothing but the single word.',
        'messages'   => [
            ['role' => 'user', 'content' => $commentText],
        ],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return 'neutral';
    }
    $json = json_decode($raw, true);
    if (($json['stop_reason'] ?? '') === 'refusal') {
        return 'neutral';
    }

    $text = '';
    foreach ($json['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = strtolower(trim($text));

    return in_array($text, ['positive', 'neutral', 'negative'], true) ? $text : 'neutral';
}
