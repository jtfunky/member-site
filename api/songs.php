<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/song_requests.php';

header('Content-Type: application/json');

// Premium content — only logged-in members with active access may list songs.
$user = getCurrentUser();
if (!$user || !hasPremiumAccess($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// SELECT * so the optional `category` column works whether or not it's migrated.
$songs = db()->query('SELECT * FROM songs ORDER BY created_at ASC')->fetchAll();

// Admin-managed tags, grouped per song (with the parent tag's name when nested).
// Falls back to the flat query if parent_id isn't migrated; empty if no tag tables.
$tagsBySong = [];
try {
    try {
        $rows = db()->query(
            'SELECT m.song_id, t.name, t.tag_group, p.name AS parent_name
             FROM song_tag_map m
             JOIN song_tags t ON t.id = m.tag_id
             LEFT JOIN song_tags p ON p.id = t.parent_id
             ORDER BY t.tag_group, t.name'
        )->fetchAll();
    } catch (\Throwable $e) {
        $rows = db()->query(
            'SELECT m.song_id, t.name, t.tag_group
             FROM song_tag_map m JOIN song_tags t ON t.id = m.tag_id
             ORDER BY t.tag_group, t.name'
        )->fetchAll();
    }
    foreach ($rows as $r) {
        $tagsBySong[(int) $r['song_id']][] = [
            'name'   => $r['name'],
            'group'  => $r['tag_group'],
            'parent' => $r['parent_name'] ?? null,
        ];
    }
} catch (\Throwable $e) { /* tag tables not migrated — skip */ }

// Exclusive-song access: admins see everything; members see public songs plus the
// exclusive songs they've been granted (paid requests).
$isAdmin  = ($user['role'] ?? '') === 'admin';
$entitled = $isAdmin ? [] : entitledSongIds((int) $user['id']);

$result = [];
foreach ($songs as $s) {
    $visibility = $s['visibility'] ?? 'public';
    $exclusive  = $visibility === 'exclusive';
    if ($exclusive && !$isAdmin && !in_array((int) $s['id'], $entitled, true)) {
        continue;   // not entitled — hide it
    }
    $result[] = [
        'id'       => (int) $s['id'],
        'title'    => $s['title'],
        'artist'   => $s['artist'],
        'bpm'      => (float) $s['bpm'],
        'duration' => (int) $s['duration_ms'],
        'category' => ($s['category'] ?? 'kit') === 'pad' ? 'pad' : 'kit',
        'notes'    => json_decode($s['notes'] ?? '[]', true) ?: [],
        'audioUrl' => $s['audio_filename']
            ? rtrim(UPLOAD_AUDIO_URL, '/') . '/' . $s['audio_filename']
            : null,
        'youtubeUrl' => $s['youtube_url'] ?? null,
        'tags'     => $tagsBySong[(int) $s['id']] ?? [],
        'exclusive' => $exclusive,
        'builtin'  => false,
    ];
}

echo json_encode($result);
