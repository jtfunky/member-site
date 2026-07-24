<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Admins + editors. The editor is a full-screen tool, so it renders its own shell
// (the standalone editor's CSS styles <body>) rather than the site header/nav.
$user = requireRoles(['admin', 'editor']);
$csrf = csrfToken();
$v    = '20260729';

// Optional ?song=ID → load that song into the editor for editing. Its metadata,
// notes, and audio URL are passed to the JS via data-* attributes below.
$songId   = (int) ($_GET['song'] ?? 0);
$editSong = null;
if ($songId) {
    $st = db()->prepare('SELECT * FROM songs WHERE id = ?');
    $st->execute([$songId]);
    $editSong = $st->fetch();
}
$editAudioUrl = ($editSong && $editSong['audio_filename'])
    ? rtrim(UPLOAD_AUDIO_URL, '/') . '/' . $editSong['audio_filename']
    : '';

// Admin-managed tags (optional feature). Load the list grouped, plus this song's
// current tag ids so its checkboxes render pre-checked. Silently empty if the tag
// tables aren't migrated yet.
$TAG_GROUPS  = ['genre' => 'Genre', 'skill' => 'Skill / Lesson', 'folder' => 'Custom Folder'];
$allTags     = [];
$songTagIds  = [];
try {
    // SELECT * so the optional parent_id (sub-categories) rides along when migrated.
    $allTags = db()->query('SELECT * FROM song_tags ORDER BY tag_group, name')->fetchAll();
    if ($editSong) {
        $st = db()->prepare('SELECT tag_id FROM song_tag_map WHERE song_id = ?');
        $st->execute([(int) $editSong['id']]);
        $songTagIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
} catch (\Throwable $e) { $allTags = []; }
// Order parents first with their children right after (children get a ↳ label).
$tagsByGroup = [];
$tagKids = [];
$tagIds  = array_column($allTags, 'id');
foreach ($allTags as $t) {
    $pid = (int) ($t['parent_id'] ?? 0);
    if ($pid && in_array($pid, $tagIds)) $tagKids[$pid][] = $t;
}
foreach ($allTags as $t) {
    $pid = (int) ($t['parent_id'] ?? 0);
    if ($pid && isset($tagKids[$pid])) continue;   // children are emitted under their parent
    $tagsByGroup[$t['tag_group']][] = $t;
    foreach ($tagKids[$t['id']] ?? [] as $c) { $c['_child'] = true; $tagsByGroup[$t['tag_group']][] = $c; }
}

// Charting a paid song request (?request=ID): prefill the YouTube link + title and
// mark the save as exclusive + linked to the request (see chart-editor.js).
$requestId = (int) ($_GET['request'] ?? 0);
$reqData   = null;
if ($requestId && !$editSong) {
    try {
        $rq = db()->prepare('SELECT * FROM song_requests WHERE id = ?');
        $rq->execute([$requestId]);
        $reqData = $rq->fetch() ?: null;
    } catch (\Throwable $e) { $reqData = null; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chart Editor — Admin — <?= htmlspecialchars(SITE_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/icons.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/css/chart-editor.css?v=<?= $v ?>">
<style>
  .ce-back { margin-left: 1rem; color: #93c5fd; text-decoration: none; font-size: .82rem; align-self: center; }
  .ce-back:hover { text-decoration: underline; }
  #btn-save-library { background: #16a34a; color: #fff; border: 1px solid #16a34a; font-weight: 700; margin-left: .75rem; padding: .4rem .9rem; border-radius: 6px; cursor: pointer; }
  #btn-save-library:hover { background: #15803d; }
  #btn-save-library:disabled { opacity: .6; cursor: default; }
  .ce-save-status { font-size: .78rem; margin-left: .3rem; align-self: center; }
  .ce-save-status.ok  { color: #4ade80; }
  .ce-save-status.err { color: #f87171; }
  .ce-audio-current { display: block; margin-top: .3rem; font-size: .73rem; color: var(--dim, #9ca3af); }
  .ce-audio-current.replace { color: #fcd34d; }

  /* ── Tags (admin-managed categories) ── */
  .tags-panel { margin: .4rem .75rem; padding: .5rem .8rem; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.1); border-radius: 8px; display: flex; align-items: center; gap: .7rem; flex-wrap: wrap; }
  .tags-panel > strong { font-size: .85rem; }
  .tags-manage { font-size: .74rem; color: #93c5fd; text-decoration: none; }
  .tags-manage:hover { text-decoration: underline; }
  .tags-empty { font-size: .76rem; color: var(--dim, #9ca3af); }
  .tags-group { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
  .tags-group-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: var(--dim, #9ca3af); }
  .tag-chip { display: inline-flex; align-items: center; gap: .3rem; font-size: .78rem; background: rgba(0,0,0,.25); border: 1px solid rgba(255,255,255,.12); border-radius: 999px; padding: .18rem .6rem; cursor: pointer; }
  .tag-chip input { margin: 0; }
</style>
</head>
<body>

<!-- CSRF + save-target for the library integration (CSP blocks inline scripts).
     When ?song=ID is given, the song's data rides along in data-* attributes so
     chart-editor.js can load it for editing. -->
<div id="ce-config"
     data-csrf="<?= htmlspecialchars($csrf) ?>"
     data-song-id="<?= $editSong ? (int) $editSong['id'] : '' ?>"
     data-title="<?= $editSong ? htmlspecialchars($editSong['title']) : '' ?>"
     data-artist="<?= $editSong ? htmlspecialchars((string) $editSong['artist']) : '' ?>"
     data-bpm="<?= $editSong ? htmlspecialchars((string) $editSong['bpm']) : '' ?>"
     data-duration="<?= $editSong ? (int) $editSong['duration_ms'] : '' ?>"
     data-notes="<?= $editSong ? htmlspecialchars($editSong['notes'] ?? '[]') : '' ?>"
     data-audio-url="<?= htmlspecialchars($editAudioUrl) ?>"
     data-audio-name="<?= $editSong ? htmlspecialchars((string) $editSong['audio_filename']) : '' ?>"
     data-category="<?= $editSong ? htmlspecialchars($editSong['category'] ?? 'kit') : '' ?>"
     data-youtube="<?= $editSong ? htmlspecialchars((string) ($editSong['youtube_url'] ?? '')) : '' ?>"
     data-request-id="<?= $reqData ? (int) $requestId : '' ?>"
     data-req-title="<?= $reqData ? htmlspecialchars((string) $reqData['title']) : '' ?>"
     data-req-youtube="<?= $reqData ? htmlspecialchars((string) $reqData['youtube_url']) : '' ?>"
     hidden></div>

<!-- Hidden audio element -->
<audio id="audio" preload="auto"></audio>
<input type="file" id="file-input" accept="audio/*" hidden>

<!-- ── Header ── -->
<header class="app-header">
  <div class="app-title"><i class="ti ti-music" aria-hidden="true"></i> <?= htmlspecialchars(SITE_NAME) ?> <span>Chart Editor</span></div>
  <a href="<?= $user['role'] === 'editor' ? '/admin/song-requests.php' : '/admin/songs.php' ?>" class="ce-back">← Back</a>
  <div id="restore-bar" style="display:none;align-items:center;gap:.6rem;margin-left:1rem;font-size:.8rem;color:#fcd34d;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:.3rem .75rem;">
    <span id="restore-info"></span>
    <button id="btn-restore" style="background:#f59e0b;border:none;border-radius:4px;color:#000;padding:.2rem .6rem;cursor:pointer;font-size:.78rem;font-weight:600;">Restore</button>
    <button id="btn-discard" style="background:transparent;border:1px solid rgba(245,158,11,.4);border-radius:4px;color:#fcd34d;padding:.2rem .5rem;cursor:pointer;font-size:.78rem;">Discard</button>
  </div>
</header>

<!-- ── Song info + transport ── -->
<div class="controls-row">
  <div class="song-info">
    <div class="row">
      <label>Title</label>
      <input id="title" type="text" placeholder="Song title">
    </div>
    <div class="row">
      <label>Artist</label>
      <input id="artist" type="text" placeholder="Artist name">
    </div>
    <div class="row">
      <label>Category</label>
      <select id="song-category" style="flex:1">
        <option value="kit">Kit (E-Drums / Acoustic)</option>
        <option value="pad">Practice Pad</option>
      </select>
    </div>
    <div class="row">
      <label>YouTube</label>
      <input id="song-youtube" type="text" placeholder="Optional link — casual play-along audio" style="flex:1">
      <button id="btn-load-youtube" class="load-btn" type="button" title="Load this video to chart against it (no upload)" style="margin-left:.4rem"><i class="ti ti-brand-youtube" aria-hidden="true"></i> Chart to it</button>
    </div>
    <div id="ce-yt-wrap" style="display:none; margin:.35rem 0;">
      <div id="ce-yt-player" style="width:320px; max-width:100%; aspect-ratio:16/9;"></div>
      <span class="ce-audio-current">Charting against YouTube — use ▶ Play / ● Rec below. Set BPM manually.</span>
    </div>
    <div class="row">
      <label>BPM</label>
      <input id="bpm" type="number" value="120" min="30" max="400" style="width:70px;flex:unset">
      <label style="margin-left:.5rem">Dur (ms)</label>
      <input id="duration" type="number" value="0" min="0" style="width:90px;flex:unset">
    </div>
    <button id="btn-load-audio" class="load-btn" type="button"><i class="ti ti-folder-open" aria-hidden="true"></i> Load Audio File</button>
    <span id="audio-current" class="ce-audio-current"></span>
  </div>

  <div class="transport">
    <div class="transport-top">
      <button id="btn-play"   class="btn btn-play"><i class="ti ti-player-play" aria-hidden="true"></i> Play</button>
      <button id="btn-stop"   class="btn btn-stop"><i class="ti ti-player-stop" aria-hidden="true"></i> Stop</button>
      <button id="btn-record" class="btn btn-record">● Rec</button>
      <div class="time-display" id="time-display">0:00.000 / 0:00.000</div>
      <button id="btn-save-library" class="btn btn-save" title="Save this chart + audio straight into the members song library"><i class="ti ti-device-floppy" aria-hidden="true"></i> Save to Songs</button>
      <span id="save-status" class="ce-save-status"></span>
    </div>
    <div class="seek-row">
      <input id="seek-bar" type="range" class="seek-bar" min="0" max="100" step="0.01" value="0">
      <span id="note-count" class="note-count">0 notes</span>
    </div>
  </div>
</div>

<!-- ── Tags (admin-managed categories) ── -->
<div class="tags-panel">
  <strong>Tags</strong>
  <a class="tags-manage" href="/admin/song-tags.php" target="_blank" rel="noopener">manage ↗</a>
  <?php if (!$allTags): ?>
    <span class="tags-empty">No tags yet — create some in <a class="tags-manage" href="/admin/song-tags.php" target="_blank" rel="noopener">Song Tags</a>.</span>
  <?php else: foreach ($TAG_GROUPS as $gk => $glabel): if (empty($tagsByGroup[$gk])) continue; ?>
    <div class="tags-group">
      <span class="tags-group-label"><?= htmlspecialchars($glabel) ?></span>
      <?php foreach ($tagsByGroup[$gk] as $t): ?>
        <label class="tag-chip">
          <input type="checkbox" class="song-tag-cb" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $songTagIds, true) ? 'checked' : '' ?>>
          <?= !empty($t['_child']) ? '↳ ' : '' ?><?= htmlspecialchars($t['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endforeach; endif; ?>
</div>

<!-- ── MIDI bar ── -->
<div class="midi-bar">
  <span class="midi-label"><i class="ti ti-usb" aria-hidden="true"></i> E-Drum</span>
  <select id="midi-device-sel"><option value="">— No MIDI devices —</option></select>
  <span id="midi-status" class="midi-status">Not connected</span>
  <span id="midi-hit-flash" class="midi-hit-flash"></span>
  <button id="btn-map-pads" class="midi-btn"><i class="ti ti-adjustments" aria-hidden="true"></i> Map Pads</button>
  <button id="btn-sound" class="midi-btn"><i class="ti ti-volume" aria-hidden="true"></i> Pad sounds</button>
  <button id="btn-playback" class="midi-btn" title="Play your recorded notes as drum sounds while the song plays (preview only — not saved)"><i class="ti ti-headphones" aria-hidden="true"></i> Hear chart</button>
  <span id="midi-last-note" class="midi-last-note"></span>
  <span style="margin-left:auto;font-size:.73rem;color:var(--dim)">Keyboard shortcuts still work as fallback</span>
</div>

<!-- ── MIDI pad-mapping panel ── -->
<div id="midi-map-panel" class="midi-map-panel" style="display:none">
  <div class="midi-map-head">
    <strong>Assign E-Drum Pads</strong>
    <span class="midi-map-hint">Click <em>Assign</em>, then strike that pad on your kit. Saved automatically · Esc cancels.</span>
    <button id="btn-midi-reset" class="midi-btn"><i class="ti ti-refresh" aria-hidden="true"></i> Reset to default</button>
  </div>
  <div id="midi-map-grid" class="midi-map-grid"></div>
</div>

<!-- ── Timeline ── -->
<div class="timeline-wrap">
  <canvas id="timeline"></canvas>
</div>
<div id="pan-wrap" class="pan-wrap" style="display:none">
  <input id="pan-bar" type="range" class="pan-bar" min="0" max="100" step="0.1" value="0">
</div>

<!-- ── Drum pads ── -->
<div class="pads-section">
  <div class="pads-grid">
    <div class="pad" data-lane="0">
      KICK<br><span class="key">Space</span>
    </div>
    <div class="pad" data-lane="1">
      SNARE<br><span class="key">Z</span>
    </div>
    <div class="pad" data-lane="2">
      HI-HAT<br><span class="key">X</span>
    </div>
    <div class="pad" data-lane="3">
      HI TOM 1<br><span class="key">C</span>
    </div>
    <div class="pad" data-lane="4">
      HI TOM 2<br><span class="key">V</span>
    </div>
    <div class="pad" data-lane="6">
      FLOOR TOM<br><span class="key">B</span>
    </div>
    <div class="pad" data-lane="7">
      CRASH<br><span class="key">N</span>
    </div>
    <div class="pad" data-lane="9">
      RIDE<br><span class="key">M</span>
    </div>
  </div>
</div>

<!-- ── Toolbar ── -->
<div class="toolbar">
  <label>Latency</label>
  <input id="latency-slider" type="range" min="-200" max="200" step="1" value="0">
  <span id="latency-val">+0ms</span>

  <div class="sep"></div>

  <label>Zoom</label>
  <button id="btn-zoom-out" class="tool-btn" title="Zoom out"><i class="ti ti-zoom-out" aria-hidden="true"></i></button>
  <span id="zoom-val">1×</span>
  <button id="btn-zoom-in" class="tool-btn" title="Zoom in (Ctrl + scroll on the grid)"><i class="ti ti-zoom-in" aria-hidden="true"></i></button>
  <button id="btn-zoom-fit" class="tool-btn" title="Fit the whole song">Fit</button>

  <div class="sep"></div>

  <label>Quantize</label>
  <select id="quantize-sel">
    <option value="none">None</option>
    <option value="beat">Beat</option>
    <option value="8th">8th note</option>
    <option value="16th">16th note</option>
  </select>
  <button id="btn-quantize-apply" class="tool-btn" title="Snap all recorded notes to the selected grid">Quantize Notes</button>

  <div class="sep"></div>

  <button id="btn-select-all" class="tool-btn" title="Select every note (Ctrl+A)"><i class="ti ti-select-all" aria-hidden="true"></i> Select All</button>
  <button id="btn-copy-notes"  class="tool-btn" title="Copy selected hits (Ctrl+C)"><i class="ti ti-copy" aria-hidden="true"></i> Copy</button>
  <button id="btn-paste-notes" class="tool-btn" title="Paste hits at the playhead (Ctrl+V)"><i class="ti ti-clipboard" aria-hidden="true"></i> Paste</button>
  <button id="btn-undo"     class="tool-btn"><i class="ti ti-arrow-back-up" aria-hidden="true"></i> Undo</button>
  <button id="btn-clear"    class="tool-btn danger"><i class="ti ti-trash" aria-hidden="true"></i> Clear All</button>

  <div class="sep" style="margin-left:auto"></div>
  <span style="color:var(--dim);font-size:.75rem">
    Ctrl+scroll = zoom · box-select or Shift-click · Ctrl+A = all · Ctrl+C / Ctrl+V copy &amp; paste at playhead · drag to move (Alt = ignore grid) · Arrow keys nudge (Shift = fine) · Delete · Ctrl+Z
  </span>
</div>

<div class="toast" id="toast"></div>

<script src="/assets/js/chart-editor.js?v=<?= $v ?>"></script>
</body>
</html>
