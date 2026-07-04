<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
verifyCsrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$id = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

$st = db()->prepare('SELECT audio_filename FROM songs WHERE id=?');
$st->execute([$id]);
$song = $st->fetch();

if (!$song) { http_response_code(404); echo json_encode(['error' => 'Song not found']); exit; }

if ($song['audio_filename'] && file_exists(UPLOAD_AUDIO_DIR . $song['audio_filename'])) {
    unlink(UPLOAD_AUDIO_DIR . $song['audio_filename']);
}

db()->prepare('DELETE FROM songs WHERE id=?')->execute([$id]);
echo json_encode(['deleted' => true]);
