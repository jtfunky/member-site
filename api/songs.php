<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/access.php';

header('Content-Type: application/json');

// Premium content — only logged-in members with active access may list songs.
$user = getCurrentUser();
if (!$user || !hasPremiumAccess($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$songs = db()->query(
    'SELECT id, title, artist, bpm, duration_ms AS duration, audio_filename, notes FROM songs ORDER BY created_at ASC'
)->fetchAll();

$result = array_map(function($s) {
    return [
        'id'       => (int) $s['id'],
        'title'    => $s['title'],
        'artist'   => $s['artist'],
        'bpm'      => (float) $s['bpm'],
        'duration' => (int) $s['duration'],
        'notes'    => json_decode($s['notes'] ?? '[]', true) ?: [],
        'audioUrl' => $s['audio_filename']
            ? rtrim(UPLOAD_AUDIO_URL, '/') . '/' . $s['audio_filename']
            : null,
        'builtin'  => false,
    ];
}, $songs);

echo json_encode($result);
