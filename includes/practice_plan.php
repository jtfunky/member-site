<?php
/**
 * AI-generated 10-session practice plan, built from a student's placement test.
 * Reuses the Claude transport + metrics prompt from ai_feedback.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_feedback.php';

if (!defined('PLAN_PASS_ACCURACY')) define('PLAN_PASS_ACCURACY', 80);

// Lanes a generated exercise may use: 0 kick,1 snare,2 hat,3 tom1,4 tom2,6 floor,7 crash,9 ride.
const PLAN_LANES      = [0, 1, 2, 3, 4, 6, 7, 9];
const PLAN_LOOP_BEATS = 8;       // the AI writes a 2-bar (8-beat) 4/4 loop
const PLAN_TARGET_MS  = 66000;   // tile the loop to ~66s of exercise

function practicePlanEnabled(): bool { return aiFeedbackEnabled(); }

// Fetch a user's plan (intro + ordered sessions) or null if none exists.
function getPracticePlan(int $userId): ?array {
    try {
        $p = db()->prepare('SELECT * FROM practice_plans WHERE user_id = ? LIMIT 1');
        $p->execute([$userId]);
        $plan = $p->fetch();
        if (!$plan) return null;

        // SELECT * so the optional game columns (best_accuracy, chart, bpm) work
        // whether or not migrate-plan-games.sql has run.
        $s = db()->prepare('SELECT * FROM plan_sessions WHERE plan_id = ? ORDER BY session_no');
        $s->execute([(int) $plan['id']]);
        $sessions = [];
        foreach ($s->fetchAll() as $row) {
            $drills = json_decode($row['drills'] ?? '[]', true);
            $sessions[] = [
                'session_no'    => (int) $row['session_no'],
                'title'         => $row['title'],
                'focus'         => $row['focus'],
                'drills'        => is_array($drills) ? $drills : [],
                'completed'     => (int) $row['completed'] === 1,
                'best_accuracy' => (float) ($row['best_accuracy'] ?? 0),
            ];
        }
        return [
            'intro'        => (string) $plan['intro'],
            'mode'         => (($plan['mode'] ?? 'kit') === 'pad') ? 'pad' : 'kit',
            'generated_at' => $plan['generated_at'],
            'sessions'     => $sessions,
        ];
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

    // A pad placement test (input_method='pad') yields a single-pad plan.
    $mode = (($test['input_method'] ?? '') === 'pad') ? 'pad' : 'kit';

    $raw = callClaudePlan($test, $level, $mode);
    if (str_starts_with($raw, '__ERROR__')) return substr($raw, 9);

    $data = parsePlanJson($raw);
    if (!$data) return 'The plan could not be generated. Please try again in a moment.';

    try {
        savePracticePlan($userId, (int) $test['id'], $data, $mode);
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
function callClaudePlan(array $test, string $level, string $mode = 'kit'): string {
    $padLine = $mode === 'pad'
        ? "IMPORTANT: this student only has a PRACTICE PAD (one surface) — every session must be doable on a single pad: "
          . "timing, subdivisions (8ths/16ths/triplets), rudiment rhythms, dynamics and consistency. Do NOT reference kit "
          . "pieces (kick, hi-hat, toms, cymbals) or coordination between limbs.\n\n"
        : "";
    $system = "You are \"Coach Zach\", an expert, encouraging drum instructor. From a student's placement-test "
        . "results, design a personalised ONE-MONTH practice plan of EXACTLY 10 sessions with progressive difficulty.\n\n"
        . $padLine
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

function savePracticePlan(int $userId, int $testId, array $data, string $mode = 'kit'): void {
    $db = db();
    // `mode` is optional until its migration runs.
    $hasMode = false;
    try { $hasMode = (bool) $db->query("SHOW COLUMNS FROM practice_plans LIKE 'mode'")->fetch(); } catch (\Throwable $e) {}

    $db->beginTransaction();
    try {
        // Remove any existing plan for this user (one plan per user).
        $old = $db->prepare('SELECT id FROM practice_plans WHERE user_id = ?');
        $old->execute([$userId]);
        foreach ($old->fetchAll(\PDO::FETCH_COLUMN) as $pid) {
            $db->prepare('DELETE FROM plan_sessions WHERE plan_id = ?')->execute([(int) $pid]);
        }
        $db->prepare('DELETE FROM practice_plans WHERE user_id = ?')->execute([$userId]);

        if ($hasMode) {
            $db->prepare('INSERT INTO practice_plans (user_id, mode, drum_test_id, intro) VALUES (?, ?, ?, ?)')
               ->execute([$userId, $mode === 'pad' ? 'pad' : 'kit', $testId, $data['intro']]);
        } else {
            $db->prepare('INSERT INTO practice_plans (user_id, drum_test_id, intro) VALUES (?, ?, ?)')
               ->execute([$userId, $testId, $data['intro']]);
        }
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

// ── Session games (playable exercises + hard-lock progression) ─────────────────

function planStudentLevel(int $userId): string {
    try {
        $ls = db()->prepare('SELECT experience FROM students WHERE user_id = ? LIMIT 1');
        $ls->execute([$userId]);
        return (string) ($ls->fetchColumn() ?: '');
    } catch (\Throwable $e) { return ''; }
}

// 'pad' if this user's plan is a single-pad plan, else 'kit'.
function planMode(int $userId): string {
    try {
        $st = db()->prepare('SELECT mode FROM practice_plans WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        return (($st->fetchColumn() ?: 'kit') === 'pad') ? 'pad' : 'kit';
    } catch (\Throwable $e) { return 'kit'; }
}

// Hard lock: a session is playable only if it's the first or its predecessor is passed.
function planSessionUnlocked(int $userId, int $sessionNo): bool {
    if ($sessionNo <= 1) return true;
    try {
        $st = db()->prepare(
            'SELECT ps.completed FROM plan_sessions ps
             JOIN practice_plans pp ON pp.id = ps.plan_id
             WHERE pp.user_id = ? AND ps.session_no = ? LIMIT 1'
        );
        $st->execute([$userId, $sessionNo - 1]);
        return (int) ($st->fetchColumn() ?: 0) === 1;
    } catch (\Throwable $e) { return false; }
}

function getPlanSessionRow(int $userId, int $sessionNo): ?array {
    try {
        $st = db()->prepare(
            'SELECT ps.* FROM plan_sessions ps
             JOIN practice_plans pp ON pp.id = ps.plan_id
             WHERE pp.user_id = ? AND ps.session_no = ? LIMIT 1'
        );
        $st->execute([$userId, $sessionNo]);
        return $st->fetch() ?: null;
    } catch (\Throwable $e) { return null; }
}

/**
 * Playable chart for a session — generated + cached on first call.
 * Returns ['session_no','title','bpm','notes','passAccuracy'] or an error string.
 */
function getSessionChart(int $userId, int $sessionNo): array|string {
    $row = getPlanSessionRow($userId, $sessionNo);
    if (!$row) return 'Session not found.';
    $pad = planMode($userId) === 'pad';

    $cached = json_decode($row['chart'] ?? 'null', true);
    if (is_array($cached) && $cached) {
        return ['session_no' => $sessionNo, 'title' => $row['title'], 'bpm' => (int) $row['bpm'],
                'notes' => $cached, 'pad' => $pad, 'passAccuracy' => (int) PLAN_PASS_ACCURACY];
    }

    // Generate (AI, with a guaranteed fallback groove so a session is always playable).
    $parsed = null;
    if (practicePlanEnabled()) {
        $raw = callClaudeChart($row, planStudentLevel($userId), $pad);
        if (!str_starts_with($raw, '__ERROR__')) $parsed = parseChartJson($raw);
    }
    if (!$parsed) $parsed = ['bpm' => 90, 'beats' => $pad ? fallbackPadGroove() : fallbackGroove()];

    [$bpm, $notes] = buildTiledNotes($parsed, $pad);
    try {
        db()->prepare('UPDATE plan_sessions SET chart = ?, bpm = ? WHERE id = ?')
            ->execute([json_encode($notes), $bpm, (int) $row['id']]);
    } catch (\Throwable $e) { /* cache write optional */ }

    return ['session_no' => $sessionNo, 'title' => $row['title'], 'bpm' => $bpm,
            'notes' => $notes, 'pad' => $pad, 'passAccuracy' => (int) PLAN_PASS_ACCURACY];
}

/**
 * Record a finished session play. Accuracy is RECOMPUTED server-side against the
 * session's own note count (anti-cheat), using the game's weighting (good = 50%).
 * Passing (>= PLAN_PASS_ACCURACY) marks the session completed → unlocks the next.
 * Returns ['passed','accuracy','best','passAccuracy','nextUnlocked'] or ['error'].
 */
function markSessionResult(int $userId, int $sessionNo, int $perfect, int $good, int $miss, int $total): array {
    if (!planSessionUnlocked($userId, $sessionNo)) return ['error' => 'This session is locked.'];
    $row = getPlanSessionRow($userId, $sessionNo);
    if (!$row) return ['error' => 'Session not found.'];

    $chart      = json_decode($row['chart'] ?? 'null', true);
    $chartTotal = is_array($chart) ? count($chart) : 0;
    $denom      = $chartTotal > 0 ? $chartTotal : max(1, $total);

    $perfect = max(0, min($perfect, $denom));
    $good    = max(0, min($good, $denom - $perfect));
    $acc     = min(100.0, max(0.0, ($perfect * 100 + $good * 50) / ($denom * 100) * 100));
    $pass    = $acc >= (float) PLAN_PASS_ACCURACY;
    $best    = max((float) $row['best_accuracy'], $acc);

    try {
        if ($pass) {
            db()->prepare('UPDATE plan_sessions SET best_accuracy = ?, completed = 1, completed_at = IFNULL(completed_at, NOW()) WHERE id = ?')
                ->execute([$best, (int) $row['id']]);
        } else {
            db()->prepare('UPDATE plan_sessions SET best_accuracy = ? WHERE id = ?')
                ->execute([$best, (int) $row['id']]);
        }
    } catch (\Throwable $e) { return ['error' => 'Could not save progress.']; }

    return ['passed' => $pass, 'accuracy' => round($acc, 1), 'best' => round($best, 1),
            'passAccuracy' => (int) PLAN_PASS_ACCURACY, 'nextUnlocked' => $pass && $sessionNo < 10];
}

// ── Chart generation internals ────────────────────────────────────────────────
function callClaudeChart(array $session, string $level, bool $pad = false): string {
    $lanes = $pad
        ? 'This is a PRACTICE-PAD exercise: ONE surface only — every note MUST use "lane":1. Make it a RHYTHM (timing/subdivisions/rudiment feel) for the focus; no kit pieces.'
        : 'Allowed lanes ONLY: 0=kick, 1=snare, 2=hi-hat, 3=hi tom, 4=mid tom, 6=floor tom, 7=crash, 9=ride.';
    $system = "You generate a SHORT drum practice exercise as a rhythm-game pattern. "
        . "Return ONLY minified JSON — no prose, no markdown, no code fences — EXACTLY: "
        . '{"bpm":<int 40-200>,"beats":[{"b":<float 0-8 in .25 steps>,"lane":<int>}]}. '
        . "The pattern is a 2-bar (8-beat) 4/4 loop that will be repeated. " . $lanes . " "
        . "Make it musical and playable for the student's level and match the session focus. "
        . "12-40 notes. Output the JSON object and NOTHING else.";
    $drills = json_decode($session['drills'] ?? '[]', true);
    $prompt = 'Session: ' . ($session['title'] ?? '') . "\nFocus: " . ($session['focus'] ?? '')
        . "\nDrills: " . implode('; ', is_array($drills) ? $drills : [])
        . "\nStudent level: " . ($level ?: 'unknown');

    $body = json_encode([
        'model'      => defined('AI_FEEDBACK_MODEL') ? AI_FEEDBACK_MODEL : 'claude-haiku-4-5',
        'max_tokens' => 1200,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        // Keep the first-play wait short — if the model is slow/unreachable, fall
        // back to a built-in groove instead of a long loading screen.
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) return '__ERROR__chart generation failed';

    $json = json_decode($raw, true);
    $text = '';
    foreach ($json['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return trim($text) ?: '__ERROR__empty';
}

function parseChartJson(string $raw): ?array {
    $s = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $s = preg_replace('/\s*```$/', '', $s);
    if (($a = strpos($s, '{')) !== false && ($b = strrpos($s, '}')) !== false && $b > $a) {
        $s = substr($s, $a, $b - $a + 1);
    }
    $d = json_decode($s, true);
    return (is_array($d) && !empty($d['beats']) && is_array($d['beats'])) ? $d : null;
}

// Validate the AI loop and tile it to a full exercise. Returns [bpm, notes[{time,lane}]].
// For a pad exercise, every note is forced to lane 1 (single surface).
function buildTiledNotes(array $parsed, bool $pad = false): array {
    $bpm     = (int) round($parsed['bpm'] ?? 90);
    $bpm     = max(40, min(200, $bpm ?: 90));
    $allowed = array_flip(PLAN_LANES);

    $loop = [];
    foreach ($parsed['beats'] as $n) {
        if (!is_array($n)) continue;
        $b    = (float) ($n['b'] ?? -1);
        $lane = $pad ? 1 : (int) ($n['lane'] ?? -1);
        if ($b < 0 || $b >= PLAN_LOOP_BEATS || !isset($allowed[$lane])) continue;
        $loop[] = ['b' => round($b * 4) / 4, 'lane' => $lane];   // snap to 16ths
    }
    if (!$loop) $loop = $pad ? fallbackPadGroove() : fallbackGroove();

    $beatMs = 60000 / $bpm;
    $loopMs = PLAN_LOOP_BEATS * $beatMs;
    $reps   = max(4, min(64, (int) ceil(PLAN_TARGET_MS / $loopMs)));

    $notes = [];
    for ($k = 0; $k < $reps; $k++) {
        foreach ($loop as $n) {
            $notes[] = ['time' => (int) round(($n['b'] + PLAN_LOOP_BEATS * $k) * $beatMs), 'lane' => $n['lane']];
        }
    }
    usort($notes, fn($a, $b) => $a['time'] <=> $b['time'] ?: $a['lane'] <=> $b['lane']);
    return [$bpm, $notes];
}

// A guaranteed-playable 2-bar single-pad rhythm (steady 8th notes, one surface).
function fallbackPadGroove(): array {
    $g = [];
    for ($b = 0; $b < PLAN_LOOP_BEATS; $b += 0.5) $g[] = ['b' => $b, 'lane' => 1];
    return $g;
}

// A guaranteed-playable 2-bar rock beat (kick 1&3, snare 2&4, hi-hat 8ths).
function fallbackGroove(): array {
    $g = [];
    for ($bar = 0; $bar < 2; $bar++) {
        $o = $bar * 4;
        for ($h = 0; $h < 8; $h++) $g[] = ['b' => $o + $h * 0.5, 'lane' => 2];
        $g[] = ['b' => $o + 0, 'lane' => 0];
        $g[] = ['b' => $o + 2, 'lane' => 0];
        $g[] = ['b' => $o + 1, 'lane' => 1];
        $g[] = ['b' => $o + 3, 'lane' => 1];
    }
    return $g;
}
