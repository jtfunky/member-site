<?php
/**
 * Top scores for one song, across all students — each player's own best
 * score only (song_plays is a full attempt log, not a best-score table, so
 * this dedupes the same way api/my-plays.php does for a single user, just
 * grouped by user instead of by song).
 *
 * Returns: [ { first_name, score, grade, accuracy } , … ] ordered by score
 * desc, capped at 10.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/access.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user || !hasPremiumAccess($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Admin-toggleable — off by default until there's enough data for rankings
// to mean anything (see admin/settings.php).
if (!isLeaderboardEnabled()) {
    echo json_encode(['leaders' => []]);
    exit;
}

$songId = trim($_GET['song_id'] ?? '');
if ($songId === '') {
    echo json_encode(['leaders' => []]);
    exit;
}

$leaders = [];
try {
    $st = db()->prepare(
        'SELECT u.first_name, sp.score, sp.grade, sp.accuracy
           FROM song_plays sp
           JOIN (SELECT user_id, MAX(score) AS mx
                   FROM song_plays WHERE song_id = ? GROUP BY user_id) m
             ON sp.user_id = m.user_id AND sp.score = m.mx
           JOIN users u ON u.id = sp.user_id
          WHERE sp.song_id = ?
          GROUP BY sp.user_id
          ORDER BY sp.score DESC
          LIMIT 10'
    );
    $st->execute([$songId, $songId]);
    foreach ($st->fetchAll() as $r) {
        $leaders[] = [
            'first_name' => $r['first_name'],
            'score'      => (int) $r['score'],
            'grade'      => $r['grade'],
            'accuracy'   => (float) $r['accuracy'],
        ];
    }
} catch (\Throwable $e) {
    // Table not migrated yet — return empty so the game still works.
}

echo json_encode(['leaders' => $leaders]);
