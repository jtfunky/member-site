<?php
/**
 * Returns the logged-in member's song-play progress:
 *   bests:  { song_id: { score, grade, accuracy } }   — best score per song
 *   recent: [ { song_title, score, grade, accuracy, input_method, played_at }, … ]
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
$uid = (int) $user['id'];

$bests = [];
$recent = [];
try {
    // Best score per song (with the grade/accuracy of that best play).
    $st = db()->prepare(
        'SELECT sp.song_id, sp.score, sp.grade, sp.accuracy
           FROM song_plays sp
           JOIN (SELECT song_id, MAX(score) AS mx
                   FROM song_plays WHERE user_id = ? GROUP BY song_id) m
             ON sp.song_id = m.song_id AND sp.score = m.mx
          WHERE sp.user_id = ?
          GROUP BY sp.song_id'
    );
    $st->execute([$uid, $uid]);
    foreach ($st->fetchAll() as $r) {
        $bests[$r['song_id']] = [
            'score'    => (int) $r['score'],
            'grade'    => $r['grade'],
            'accuracy' => (float) $r['accuracy'],
        ];
    }

    $rs = db()->prepare(
        'SELECT song_title, score, grade, accuracy, input_method, played_at
           FROM song_plays WHERE user_id = ? ORDER BY played_at DESC LIMIT 10'
    );
    $rs->execute([$uid]);
    foreach ($rs->fetchAll() as $r) {
        $recent[] = [
            'song_title'   => $r['song_title'],
            'score'        => (int) $r['score'],
            'grade'        => $r['grade'],
            'accuracy'     => (float) $r['accuracy'],
            'input_method' => $r['input_method'],
            'played_at'    => $r['played_at'],
        ];
    }
} catch (\Throwable $e) {
    // Table not migrated yet — return empty so the game still works.
}

echo json_encode(['bests' => $bests, 'recent' => $recent]);
