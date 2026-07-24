<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/song_requests.php';

header('Content-Type: application/json');

$staff = getCurrentUser();
if (!$staff || !in_array($staff['role'] ?? '', ['admin', 'editor'], true)) {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}
verifyCsrf();

$id       = (int)($_POST['id']       ?? 0);
$title    = trim($_POST['title']     ?? '');
$artist   = trim($_POST['artist']    ?? '');
$bpm      = (float)($_POST['bpm']   ?? 120);
$duration = (int)($_POST['duration'] ?? 0);
$notes    = $_POST['notes']          ?? '[]';
$category = (($_POST['category'] ?? 'kit') === 'pad') ? 'pad' : 'kit';

// Optional YouTube link (casual play-along audio). Normalize to a canonical watch
// URL (dropping tracking params) or null. Accepts watch / youtu.be / embed / shorts.
$youtubeUrl = null;
$rawYt = trim($_POST['youtube_url'] ?? '');
if ($rawYt !== '' && preg_match(
    '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~',
    $rawYt, $m
)) {
    $youtubeUrl = 'https://www.youtube.com/watch?v=' . $m[1];
}

if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); exit; }

// Validate JSON notes. Each note must be {time:>=0, lane:0-9}. An out-of-range
// lane (e.g. the old 8-lane 6=crash/7=ride scheme) has no color/name in the
// engine and crashes the game renderer, so reject bad charts up front. We also
// normalize to just {time,lane} and sort by time — updateChart() spawns notes
// with a monotonic cursor, so unsorted notes silently never appear.
$decoded = json_decode($notes, true);
if (!is_array($decoded)) { http_response_code(400); echo json_encode(['error' => 'Notes must be a JSON array']); exit; }

const LANE_COUNT = 10; // 0=kick 1=snare 2=hi-hat 3=hi-tom-1 4=hi-tom-2 5=mid-tom 6=floor-tom 7=16"crash 8=18"crash 9=22"ride
$clean = [];
foreach ($decoded as $i => $n) {
    if (!is_array($n) || !isset($n['time'], $n['lane']) || !is_numeric($n['time']) || !is_numeric($n['lane'])) {
        http_response_code(400);
        echo json_encode(['error' => "Note #$i is malformed — each note needs a numeric \"time\" (ms) and \"lane\"."]);
        exit;
    }
    $time = (int) round($n['time']);
    $lane = (int) $n['lane'];
    if ($time < 0 || $lane < 0 || $lane >= LANE_COUNT) {
        http_response_code(400);
        echo json_encode(['error' => "Note #$i is out of range — time must be ≥ 0 and lane 0–" . (LANE_COUNT - 1) . " (got time=$time, lane=$lane)."]);
        exit;
    }
    $clean[] = ['time' => $time, 'lane' => $lane];
}
usort($clean, fn($a, $b) => $a['time'] <=> $b['time'] ?: $a['lane'] <=> $b['lane']);
$notes = json_encode($clean);

// Handle audio upload
$audioFilename = null;
if (!empty($_FILES['audio']['tmp_name'])) {
    $validation = validateAudioUpload($_FILES['audio']);
    if ($validation !== true) {
        http_response_code(400);
        echo json_encode(['error' => $validation]);
        exit;
    }
    $ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
    $audioFilename = 'song_' . ($id ?: 'new') . '_' . time() . '.' . $ext;
    $dest          = UPLOAD_AUDIO_DIR . $audioFilename;
    if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save audio. Check uploads/audio/ permissions.']);
        exit;
    }
}

$db = db();

// `category` / `youtube_url` are optional until their migrations run — include
// each only if the column exists. The column names here are fixed literals (never
// user input), so interpolating them into the SQL is safe.
$optionalCols = ['category' => $category, 'youtube_url' => $youtubeUrl];
// Only touch `visibility` when the client explicitly sends it (charting a request),
// so a normal edit of an exclusive song never silently resets it to public.
if (isset($_POST['visibility'])) {
    $optionalCols['visibility'] = ($_POST['visibility'] === 'exclusive') ? 'exclusive' : 'public';
}
$optional = [];
foreach ($optionalCols as $col => $val) {
    try {
        if ($db->query("SHOW COLUMNS FROM songs LIKE '$col'")->fetch()) $optional[$col] = $val;
    } catch (\Throwable $e) {}
}

if ($id) {
    // Update — delete a replaced audio file first.
    if ($audioFilename) {
        $old = $db->prepare('SELECT audio_filename FROM songs WHERE id=?');
        $old->execute([$id]);
        $old = $old->fetchColumn();
        if ($old && $old !== $audioFilename && file_exists(UPLOAD_AUDIO_DIR . $old)) {
            unlink(UPLOAD_AUDIO_DIR . $old);
        }
    }
    $sets   = ['title=?', 'artist=?', 'bpm=?', 'duration_ms=?', 'notes=?'];
    $params = [$title, $artist, $bpm, $duration, $notes];
    foreach ($optional as $col => $val) { $sets[] = "$col=?"; $params[] = $val; }
    if ($audioFilename) { $sets[] = 'audio_filename=?'; $params[] = $audioFilename; }
    $sets[] = 'updated_at=NOW()';
    $params[] = $id;
    $db->prepare('UPDATE songs SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
} else {
    // Insert
    $cols = ['title', 'artist', 'bpm', 'duration_ms', 'notes', 'audio_filename'];
    $vals = [$title, $artist, $bpm, $duration, $notes, $audioFilename ?? ''];
    foreach ($optional as $col => $val) { $cols[] = $col; $vals[] = $val; }
    $ph = implode(', ', array_fill(0, count($cols), '?'));
    $db->prepare('INSERT INTO songs (' . implode(', ', $cols) . ") VALUES ($ph)")->execute($vals);
    $id = (int) $db->lastInsertId();
}

// Sync the song↔tag many-to-many (admin-managed tags). Ignored if the tag tables
// aren't migrated yet. Replaces the full set each save.
$tagIds = array_values(array_unique(array_map('intval', (array) ($_POST['tag_ids'] ?? []))));
try {
    $db->prepare('DELETE FROM song_tag_map WHERE song_id = ?')->execute([$id]);
    if ($tagIds) {
        $ins = $db->prepare('INSERT IGNORE INTO song_tag_map (song_id, tag_id) VALUES (?, ?)');
        foreach ($tagIds as $tid) if ($tid > 0) $ins->execute([$id, $tid]);
    }
} catch (\Throwable $e) { /* tag tables not migrated — skip */ }

// If this chart fulfills a paid song request, link it and grant every paid buyer
// of the same video access to it.
$requestId = (int) ($_POST['request_id'] ?? 0);
if ($requestId) {
    try {
        $rq = $db->prepare('SELECT yt_id FROM song_requests WHERE id = ?');
        $rq->execute([$requestId]);
        $ytId = $rq->fetchColumn();
        $db->prepare('UPDATE song_requests SET song_id = ? WHERE id = ?')->execute([$id, $requestId]);
        if ($ytId) fulfillRequestsWithSong($ytId, $id);
    } catch (\Throwable $e) { /* request tables not migrated — skip */ }
}

echo json_encode(['id' => $id, 'saved' => true]);
