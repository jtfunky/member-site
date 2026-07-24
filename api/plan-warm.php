<?php
/**
 * Background warm-up: generate + cache ONE not-yet-generated session chart for the
 * logged-in student, so sessions play instantly later. The client calls this
 * repeatedly (once per session) after the plan page loads. Bypasses the hard lock
 * (it only generates/caches — it never unlocks anything).
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

// Find the first session with no cached chart yet.
try {
    $st = db()->prepare(
        'SELECT ps.session_no, ps.chart FROM plan_sessions ps
         JOIN practice_plans pp ON pp.id = ps.plan_id
         WHERE pp.user_id = ? ORDER BY ps.session_no'
    );
    $st->execute([(int) $user['id']]);
    $rows = $st->fetchAll();
} catch (\Throwable $e) {
    echo json_encode(['done' => true]);   // chart column not migrated — nothing to warm
    exit;
}

$next = null;
$remaining = 0;
foreach ($rows as $r) {
    $c = json_decode($r['chart'] ?? 'null', true);
    if (!is_array($c) || !$c) {
        if ($next === null) $next = (int) $r['session_no'];
        $remaining++;
    }
}

if ($next === null) { echo json_encode(['done' => true]); exit; }

getSessionChart((int) $user['id'], $next);   // generates + caches (AI, or fallback groove)

echo json_encode(['done' => $remaining <= 1, 'generated' => $next, 'remaining' => max(0, $remaining - 1)]);
