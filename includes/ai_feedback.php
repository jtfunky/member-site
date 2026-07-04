<?php
/**
 * AI drum coaching via the Claude Messages API (Anthropic).
 * Uses raw cURL — same transport as includes/oauth.php — so there's no Composer
 * dependency to install on shared hosting.
 */
require_once __DIR__ . '/config.php';

// Feature is off until an API key is configured (like an unconfigured OAuth provider).
function aiFeedbackEnabled(): bool {
    return defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
}

// Build the user-facing metrics summary the model reasons over. `$t` is a
// drum_tests row (assoc array); `$level` is the student's skill level or ''.
function buildFeedbackPrompt(array $t, string $level = ''): string {
    $bias = (int) $t['avg_offset_ms'];
    $biasWord = $bias < -8 ? "rushing (playing early)" : ($bias > 8 ? "dragging (playing late)" : "well-centered");

    $lines = [];
    $lines[] = "A drum student just completed a placement test in a rhythm game (notes scroll and they hit the matching pad in time). Here are their results.";
    $lines[] = "";
    if ($level !== '') $lines[] = "Self-reported level: {$level}";
    $lines[] = "Input device: " . ($t['input_method'] ?: 'unknown');
    $lines[] = "Song/exercise: " . ($t['song'] ?: 'placement exercise');
    $lines[] = "Grade: " . ($t['grade'] ?: 'n/a') . "  |  Accuracy: " . rtrim(rtrim(number_format((float) $t['accuracy'], 1), '0'), '.') . "%";
    $lines[] = "Notes: {$t['total_notes']} total — {$t['perfect']} perfect, {$t['good']} good (slightly off), {$t['miss']} missed";
    $lines[] = "Longest combo without a miss: {$t['max_combo']}";
    $lines[] = "Average timing offset: {$bias} ms ({$biasWord}). Negative = early/rushing, positive = late/dragging.";
    $lines[] = "Timing consistency (spread of hit timing): {$t['timing_consistency_ms']} ms — lower is steadier.";

    $metrics = json_decode($t['metrics'] ?? '', true);
    if (!empty($metrics['pads']) && is_array($metrics['pads'])) {
        $lines[] = "";
        $lines[] = "Per-pad breakdown:";
        foreach ($metrics['pads'] as $p) {
            $acc = isset($p['accuracy']) ? $p['accuracy'] . '%' : 'n/a';
            $off = isset($p['avg_offset_ms']) ? $p['avg_offset_ms'] . 'ms' : 'n/a';
            $lines[] = "  - {$p['pad']}: acc {$acc}, timing {$off}, "
                     . "{$p['perfect']} perfect / {$p['good']} good / {$p['miss']} miss";
        }
    }
    return implode("\n", $lines);
}

// Returns the coaching markdown string on success, or an error string.
function generateDrumFeedback(array $test, string $level = ''): string {
    if (!aiFeedbackEnabled()) return 'AI coaching is not configured yet.';

    $system = "You are \"Coach Zach\", a warm, encouraging personal drum instructor speaking directly to your "
        . "student about their placement test. Write in the first person (\"I\") and address them as \"you\" — "
        . "supportive but honest, like a real coach who believes in them.\n\n"
        . "Structure your reply as GitHub-flavored markdown:\n"
        . "1. Open with ONE short, warm sentence.\n"
        . "2. Then exactly these three bold-headed sections, each with 1-3 bullets:\n"
        . "**What's working**\n**What to improve**\n**How to practice**\n"
        . "3. End with one brief encouraging line, signed exactly: — Coach Zach\n\n"
        . "Ground every point in the numbers provided (timing bias, weak pads, misses, consistency). Be concrete "
        . "— name the pad, say rush vs drag. Keep the whole thing under 180 words. Never invent metrics that "
        . "weren't provided.";

    $body = json_encode([
        'model'      => defined('AI_FEEDBACK_MODEL') ? AI_FEEDBACK_MODEL : 'claude-haiku-4-5',
        'max_tokens' => 700,
        'system'     => $system,
        'messages'   => [
            ['role' => 'user', 'content' => buildFeedbackPrompt($test, $level)],
        ],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,   // generation can take a few seconds
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
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return 'Could not reach the coaching service: ' . ($err ?: 'network error');
    $json = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $json['error']['message'] ?? "AI service returned HTTP {$code}.";
        return 'Coaching is unavailable right now: ' . $msg;
    }

    // Concatenate any text blocks; guard against a safety refusal.
    if (($json['stop_reason'] ?? '') === 'refusal') {
        return 'The coaching model could not generate feedback for this test.';
    }
    $text = '';
    foreach ($json['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    return $text !== '' ? $text : 'No feedback was returned. Please try again.';
}

// Minimal, safe markdown -> HTML for the coaching text (escape first, then
// convert the small subset the model is asked to emit: bold, bullets, breaks).
function feedbackToHtml(string $md): string {
    $out   = [];
    $inUl  = false;
    foreach (preg_split('/\r?\n/', $md) as $line) {
        $t = trim($line);
        if ($t === '') { if ($inUl) { $out[] = '</ul>'; $inUl = false; } continue; }
        $esc = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $esc = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $esc);
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            if (!$inUl) { $out[] = '<ul>'; $inUl = true; }
            $item = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item);
            $out[] = '<li>' . $item . '</li>';
        } else {
            if ($inUl) { $out[] = '</ul>'; $inUl = false; }
            $out[] = '<p>' . $esc . '</p>';
        }
    }
    if ($inUl) $out[] = '</ul>';
    return implode('', $out);
}
