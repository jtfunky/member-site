<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
verifyCsrf();

$id       = (int)($_POST['id']       ?? 0);
$title    = trim($_POST['title']     ?? '');
$artist   = trim($_POST['artist']    ?? '');
$bpm      = (float)($_POST['bpm']   ?? 120);
$duration = (int)($_POST['duration'] ?? 0);
$notes    = $_POST['notes']          ?? '[]';

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

if ($id) {
    // Update
    $st = $db->prepare(
        'UPDATE songs SET title=?, artist=?, bpm=?, duration_ms=?, notes=?'
        . ($audioFilename ? ', audio_filename=?' : '')
        . ', updated_at=NOW() WHERE id=?'
    );
    $params = [$title, $artist, $bpm, $duration, $notes];
    if ($audioFilename) {
        // Delete old file
        $old = $db->prepare('SELECT audio_filename FROM songs WHERE id=?');
        $old->execute([$id]);
        $old = $old->fetchColumn();
        if ($old && $old !== $audioFilename && file_exists(UPLOAD_AUDIO_DIR . $old)) {
            unlink(UPLOAD_AUDIO_DIR . $old);
        }
        $params[] = $audioFilename;
    }
    $params[] = $id;
    $st->execute($params);
    echo json_encode(['id' => $id, 'saved' => true]);
} else {
    // Insert
    $st = $db->prepare(
        'INSERT INTO songs (title, artist, bpm, duration_ms, notes, audio_filename)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$title, $artist, $bpm, $duration, $notes, $audioFilename ?? '']);
    echo json_encode(['id' => (int)$db->lastInsertId(), 'saved' => true]);
}
