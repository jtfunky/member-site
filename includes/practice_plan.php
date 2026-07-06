<?php
/**
 * AI-generated 10-session practice plan, built from a student's placement test.
 * Reuses the Claude transport + metrics prompt from ai_feedback.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_feedback.php';

function practicePlanEnabled(): bool { return aiFeedbackEnabled(); }

// Fetch a user's plan (intro + ordered sessions) or null if none exists.
function getPracticePlan(int $userId): ?array {
    try {
        $p = db()->prepare('SELECT * FROM practice_plans WHERE user_id = ? LIMIT 1');
        $p->execute([$userId]);
        $plan = $p->fetch();
        if (!$plan) return null;

        $s = db()->prepare('SELECT session_no, title, focus, drills, completed FROM plan_sessions WHERE plan_id = ? ORDER BY session_no');
        $s->execute([(int) $plan['id']]);
        $sessions = [];
        foreach ($s->fetchAll() as $row) {
            $drills = json_decode($row['drills'] ?? '[]', true);
            $sessions[] = [
                'session_no' => (int) $row['session_no'],
                'title'      => $row['title'],
                'focus'      => $row['focus'],
                'drills'     => is_array($drills) ? $drills : [],
                'completed'  => (int) $row['completed'] === 1,
            ];
        }
        return ['intro' => (string) $plan['intro'], 'generated_at' => $plan['generated_at'], 'sessions' => $sessions];
    } catch (\Throwable $e) {
        return null; // tables not migrated yet
    }
}

// Generate (or regenerate) the plan for a user. Returns the plan array or an error string.
function generatePracticePlan(int $userId): array|string {
    if (!practicePlanEnabled()) return 'AI plan generation is not configured yet.';

    $st = db()->prepare('SELECT * FROM drum_tests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $st->execute([$userId]);
    $test = $st->fetch();
    if (!$test) return 'No placement test found — please take the placement test first.';

    $level = '';
    try {
        $ls = db()->prepare('SELECT experience FROM students WHERE user_id = ? LIMIT 1');
        $ls->execute([$userId]);
        $level = (string) ($ls->fetchColumn() ?: '');
    } catch (\Throwable $e) { /* students optional */ }

    $raw = callClaudePlan($test, $level);
    if (str_starts_with($raw, '__ERROR__')) return substr($raw, 9);

    $data = parsePlanJson($raw);
    if (!$data) return 'The plan could not be generated. Please try again in a moment.';

    try {
        savePracticePlan($userId, (int) $test['id'], $data);
    } catch (\Throwable $e) {
        return 'Could not save your plan — the plan tables may not be set up yet.';
    }
    return getPracticePlan($userId) ?? 'Plan saved but could not be loaded.';
}

// Coach edit: update the plan's intro + each session's title/focus/drills by
// session_no, WITHOUT touching the student's completed progress. Returns true on
// success. $sessions is keyed by session_no => ['title','focus','drills'=>[]].
function updatePlanContent(int $userId, string $intro, array $sessions): bool {
    $db = db();
    try {
        $p = $db->prepare('SELECT id FROM practice_plans WHERE user_id = ? LIMIT 1');
        $p->execute([$userId]);
        $planId = (int) ($p->fetchColumn() ?: 0);
        if (!$planId) return false;

        $db->beginTransaction();
        $db->prepare('UPDATE practice_plans SET intro = ? WHERE id = ?')
           ->execute([mb_substr($intro, 0, 1000), $planId]);

        $upd = $db->prepare('UPDATE plan_sessions SET title = ?, focus = ?, drills = ? WHERE plan_id = ? AND session_no = ?');
        foreach ($sessions as $no => $s) {
            $drills = array_filter(array_map('trim', (array) ($s['drills'] ?? [])), fn($x) => $x !== '');
            $drills = array_slice(array_map(fn($x) => mb_substr($x, 0, 300), array_values($drills)), 0, 6);
            $upd->execute([
                mb_substr((string) ($s['title'] ?? ''), 0, 200),
                mb_substr((string) ($s['focus'] ?? ''), 0, 255),
                json_encode($drills),
                $planId, (int) $no,
            ]);
        }
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

// Toggle a session's completed flag (ownership-checked). Returns true on success.
function togglePlanSession(int $userId, int $sessionNo, bool $done): bool {
    try {
        $p = db()->prepare('SELECT id FROM practice_plans WHERE user_id = ? LIMIT 1');
        $p->execute([$userId]);
        $planId = (int) ($p->fetchColumn() ?: 0);
        if (!$planId) return false;

        $u = db()->prepare('UPDATE plan_sessions SET completed = ?, completed_at = ? WHERE plan_id = ? AND session_no = ?');
        return $u->execute([$done ? 1 : 0, $done ? date('Y-m-d H:i:s') : null, $planId, $sessionNo]);
    } catch (\Throwable $e) {
        return false;
    }
}

// ── Internals ─────────────────────────────────────────────
function callClaudePlan(array $test, string $level): string {
    $system = "You are \"Coach Zach\", an expert, encouraging drum instructor. From a student's placement-test "
        . "results, design a personalised ONE-MONTH practice plan of EXACTLY 10 sessions with progressive difficulty.\n\n"
        . "Return ONLY valid minified JSON — no prose, no markdown, no code fences — in EXACTLY this shape:\n"
        . '{"intro":"<2-3 warm sentences to the student about their plan>","sessions":[{"title":"<short name>","focus":"<one line>","drills":["<drill>","<drill>","<drill>"]}]}'
        . "\n\nRules:\n"
        . "- EXACTLY 10 session objects, ordered easy to hard, each building on the last.\n"
        . "- Each session: a short title, a one-line focus, and 2-4 concrete at-home drills.\n"
        . "- Anchor the early sessions in their weak areas from the test (timing rush/drag, weak pads, misses, consistency).\n"
        . "- Address the student as \"you\"; keep it practical and motivating. Never invent metrics that weren't provided.\n"
        . "- Output the JSON object and NOTHING else.";

    $body = json_encode([
        'model'      => defined('AI_FEEDBACK_MODEL') ? AI_FEEDBACK_MODEL : 'claude-haiku-4-5',
        'max_tokens' => 2000,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => buildFeedbackPrompt($test, $level)]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 45,
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

    if ($raw === false) return '__ERROR__Could not reach the plan service: ' . ($err ?: 'network error');
    $json = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        return '__ERROR__Plan generation is unavailable right now: ' . ($json['error']['message'] ?? "HTTP {$code}");
    }
    if (($json['stop_reason'] ?? '') === 'refusal') return '__ERROR__The model declined to generate a plan.';

    $text = '';
    foreach ($json['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return trim($text);
}

// Parse the model's JSON (tolerating stray code fences / surrounding text).
function parsePlanJson(string $raw): ?array {
    $s = trim($raw);
    // Strip ```json fences if present, then grab the outermost {...}.
    $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
    $s = preg_replace('/\s*```$/', '', $s);
    if (($a = strpos($s, '{')) !== false && ($b = strrpos($s, '}')) !== false && $b > $a) {
        $s = substr($s, $a, $b - $a + 1);
    }
    $data = json_decode($s, true);
    if (!is_array($data) || empty($data['sessions']) || !is_array($data['sessions'])) return null;

    $sessions = [];
    foreach (array_slice($data['sessions'], 0, 10) as $sess) {
        if (!is_array($sess)) continue;
        $drills = [];
        if (!empty($sess['drills']) && is_array($sess['drills'])) {
            foreach (array_slice($sess['drills'], 0, 6) as $d) $drills[] = substr(trim((string) $d), 0, 300);
        }
        $sessions[] = [
            'title'  => substr(trim((string) ($sess['title'] ?? '')), 0, 200),
            'focus'  => substr(trim((string) ($sess['focus'] ?? '')), 0, 255),
            'drills' => $drills,
        ];
    }
    if (!$sessions) return null;
    return ['intro' => substr(trim((string) ($data['intro'] ?? '')), 0, 1000), 'sessions' => $sessions];
}

function savePracticePlan(int $userId, int $testId, array $data): void {
    $db = db();
    $db->beginTransaction();
    try {
        // Remove any existing plan for this user (one plan per user).
        $old = $db->prepare('SELECT id FROM practice_plans WHERE user_id = ?');
        $old->execute([$userId]);
        foreach ($old->fetchAll(\PDO::FETCH_COLUMN) as $pid) {
            $db->prepare('DELETE FROM plan_sessions WHERE plan_id = ?')->execute([(int) $pid]);
        }
        $db->prepare('DELETE FROM practice_plans WHERE user_id = ?')->execute([$userId]);

        $db->prepare('INSERT INTO practice_plans (user_id, drum_test_id, intro) VALUES (?, ?, ?)')
           ->execute([$userId, $testId, $data['intro']]);
        $planId = (int) $db->lastInsertId();

        $ins = $db->prepare('INSERT INTO plan_sessions (plan_id, session_no, title, focus, drills) VALUES (?, ?, ?, ?, ?)');
        foreach ($data['sessions'] as $i => $sess) {
            $ins->execute([$planId, $i + 1, $sess['title'], $sess['focus'], json_encode($sess['drills'])]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
