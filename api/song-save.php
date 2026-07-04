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

// Validate JSON notes
$decoded = json_decode($notes, true);
if (!is_array($decoded)) { http_response_code(400); echo json_encode(['error' => 'Notes must be a JSON array']); exit; }
$notes = json_encode($decoded);

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
