<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user  = $admin = requireAdmin();
$db    = db();

$message = '';
$error   = '';

// ── Handle delete ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id   = (int)($_POST['song_id'] ?? 0);
    $song = $db->prepare('SELECT audio_filename FROM songs WHERE id=?');
    $song->execute([$id]);
    $song = $song->fetch();
    if ($song) {
        if ($song['audio_filename'] && file_exists(UPLOAD_AUDIO_DIR . $song['audio_filename'])) {
            unlink(UPLOAD_AUDIO_DIR . $song['audio_filename']);
        }
        $db->prepare('DELETE FROM songs WHERE id=?')->execute([$id]);
        $message = 'Song deleted.';
    }
}

$editId   = (int)($_GET['edit'] ?? 0);
$editSong = null;
if ($editId) {
    $st = $db->prepare('SELECT * FROM songs WHERE id=?');
    $st->execute([$editId]);
    $editSong = $st->fetch();
}

// `category` is optional until the migration runs.
try {
    $songs = $db->query('SELECT id, title, artist, category, bpm, duration_ms, audio_filename, created_at FROM songs ORDER BY created_at DESC')->fetchAll();
} catch (\Throwable $e) {
    $songs = $db->query('SELECT id, title, artist, bpm, duration_ms, audio_filename, created_at FROM songs ORDER BY created_at DESC')->fetchAll();
}

$pageTitle = 'Songs — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
$pageHead  = '<meta name="csrf-token" content="' . csrfToken() . '">';
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Songs</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="song-editor-wrap">
  <h2 id="editor-title"><?= $editSong ? 'Edit Song' : 'Add Song' ?></h2>
  <form id="song-form" class="song-form">
    <input type="hidden" id="dk-edit-id" value="<?= $editSong ? $editSong['id'] : '' ?>">

    <div class="form-row">
      <div class="form-group">
        <label>Title *</label>
        <input type="text" id="dk-title" required value="<?= $editSong ? htmlspecialchars($editSong['title']) : '' ?>">
      </div>
      <div class="form-group">
        <label>Artist</label>
        <input type="text" id="dk-artist" value="<?= $editSong ? htmlspecialchars($editSong['artist']) : '' ?>">
      </div>
    </div>

    <div class="form-group">
      <label>Category</label>
      <?php $editCat = $editSong['category'] ?? 'kit'; ?>
      <select id="dk-category">
        <option value="kit" <?= $editCat !== 'pad' ? 'selected' : '' ?>>Kit — E-Drums / Acoustic</option>
        <option value="pad" <?= $editCat === 'pad' ? 'selected' : '' ?>>Practice Pad</option>
      </select>
      <span class="field-hint">Which input this song is for. Pad songs only show for the Practice Pad.</span>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>BPM</label>
        <input type="number" id="dk-bpm" min="40" max="300" value="<?= $editSong ? $editSong['bpm'] : '120' ?>">
      </div>
      <div class="form-group">
        <label>Duration (ms)</label>
        <input type="number" id="dk-duration" min="0" value="<?= $editSong ? $editSong['duration_ms'] : '0' ?>">
      </div>
    </div>

    <div class="alert alert-info" style="margin-bottom:1rem">
      <i class="ti ti-music" aria-hidden="true"></i> <strong>Best way to build a chart:</strong> open the
      <a href="/admin/song-editor.php">Chart Editor</a> — load the track, play along on your
      e-drums or keyboard to record all the hits, then click <strong>Save to Library</strong>.
      Use the form below only for quick metadata edits or pasting a notes array.
    </div>

    <div class="form-group">
      <label>Audio File (MP3 / WAV / OGG)</label>
      <input type="file" id="dk-audio-file" accept="audio/*">
      <?php if ($editSong && $editSong['audio_filename']): ?>
      <p class="field-hint">Current: <strong><?= htmlspecialchars($editSong['audio_filename']) ?></strong></p>
      <?php endif; ?>
      <span class="field-hint">Selecting a file stores the track and auto-fills its <strong>duration</strong> (and title, if empty). To chart the drum hits, use the <a href="/admin/song-editor.php">Chart Editor</a>.</span>
    </div>

    <div class="form-group">
      <label>Notes (JSON)</label>
      <textarea id="dk-notes" rows="8" class="font-mono" placeholder='[{"time":500,"lane":0},{"time":1000,"lane":1}]'><?= $editSong ? htmlspecialchars($editSong['notes'] ?? '[]') : '[]' ?></textarea>
      <span class="field-hint">Array of {time: ms_from_start, lane} objects. Saved notes are validated and sorted automatically. Lanes: 0 kick · 1 snare · 2 hi-hat · 3 hi tom 1 · 4 hi tom 2 · 5 mid tom · 6 floor tom · 7 16&quot; crash · 8 18&quot; crash · 9 22&quot; ride. Generate in the <a href="/admin/song-editor.php">Chart Editor</a>, then click &quot;Copy for Admin&quot;.</span>
    </div>

    <div class="form-actions">
      <button type="submit" id="dk-save-btn" class="btn btn-primary">Save Song</button>
      <?php if ($editSong): ?>
      <a href="/admin/songs.php" class="btn btn-ghost">Cancel</a>
      <?php endif; ?>
    </div>
    <div id="dk-status" class="status-msg"></div>
  </form>
</div>

<h2>Song Library</h2>
<div class="table-scroll">
<table class="data-table" id="songs-table">
  <thead>
    <tr><th>Title</th><th>Artist</th><th>Category</th><th>BPM</th><th>Duration</th><th>Audio</th><th>Added</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($songs as $s): ?>
  <tr data-id="<?= $s['id'] ?>">
    <td><?= htmlspecialchars($s['title']) ?></td>
    <td><?= htmlspecialchars($s['artist']) ?></td>
    <td><?= (($s['category'] ?? 'kit') === 'pad') ? '<i class="ti ti-target"></i> Pad' : '<i class="ti ti-music"></i> Kit' ?></td>
    <td><?= $s['bpm'] ?></td>
    <td><?= $s['duration_ms'] ? round($s['duration_ms'] / 1000) . 's' : '—' ?></td>
    <td><?= $s['audio_filename'] ? '<i class="ti ti-check" style="color:var(--success)"></i>' : '—' ?></td>
    <td><?= formatManilaDate($s['created_at']) ?></td>
    <td class="row-actions">
      <a href="/admin/song-editor.php?song=<?= $s['id'] ?>" class="btn btn-ghost btn-xs" title="Open in the Chart Editor (play along to edit hits)"><i class="ti ti-music" aria-hidden="true"></i> Editor</a>
      <a href="/admin/songs.php?edit=<?= $s['id'] ?>" class="btn btn-ghost btn-xs">Edit</a>
      <button class="btn btn-danger btn-xs delete-song-btn" data-id="<?= $s['id'] ?>" data-title="<?= htmlspecialchars($s['title']) ?>">Delete</button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Delete form (hidden, submitted by JS) -->
<form id="delete-form" method="POST" action="/admin/songs.php" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action"  value="delete">
  <input type="hidden" name="song_id" id="delete-song-id">
</form>

</main>

<script src="/assets/js/admin.js?v=20260717"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
