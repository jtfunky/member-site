<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Admin only. The editor is a full-screen tool, so it renders its own shell
// (the standalone editor's CSS styles <body>) rather than the site header/nav.
$user = requireAdmin();
$csrf = csrfToken();
$v    = '20260706';

// Optional ?song=ID → load that song into the editor for editing. Its metadata,
// notes, and audio URL are passed to the JS via data-* attributes below.
$songId   = (int) ($_GET['song'] ?? 0);
$editSong = null;
if ($songId) {
    $st = db()->prepare('SELECT id, title, artist, bpm, duration_ms, notes, audio_filename FROM songs WHERE id = ?');
    $st->execute([$songId]);
    $editSong = $st->fetch();
}
$editAudioUrl = ($editSong && $editSong['audio_filename'])
    ? rtrim(UPLOAD_AUDIO_URL, '/') . '/' . $editSong['audio_filename']
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chart Editor — Admin — <?= htmlspecialchars(SITE_NAME) ?></title>
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
     hidden></div>

<!-- Hidden audio element -->
<audio id="audio" preload="auto"></audio>
<input type="file" id="file-input" accept="audio/*" hidden>

<!-- ── Header ── -->
<header class="app-header">
  <div class="app-title">🥁 <?= htmlspecialchars(SITE_NAME) ?> <span>Chart Editor</span></div>
  <a href="/admin/songs.php" class="ce-back">← Back to Songs</a>
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
      <label>BPM</label>
      <input id="bpm" type="number" value="120" min="30" max="400" style="width:70px;flex:unset">
      <label style="margin-left:.5rem">Dur (ms)</label>
      <input id="duration" type="number" value="0" min="0" style="width:90px;flex:unset">
    </div>
    <button id="btn-load-audio" class="load-btn" type="button">📂 Load Audio File</button>
    <span id="audio-current" class="ce-audio-current"></span>
  </div>

  <div class="transport">
    <div class="transport-top">
      <button id="btn-play"   class="btn btn-play">▶ Play</button>
      <button id="btn-stop"   class="btn btn-stop">⏹ Stop</button>
      <button id="btn-record" class="btn btn-record">● Rec</button>
      <div class="time-display" id="time-display">0:00.000 / 0:00.000</div>
      <button id="btn-save-library" class="btn btn-save" title="Save this chart + audio straight into the members song library">💾 Save to Songs</button>
      <span id="save-status" class="ce-save-status"></span>
    </div>
    <div class="seek-row">
      <input id="seek-bar" type="range" class="seek-bar" min="0" max="100" step="0.01" value="0">
      <span id="note-count" class="note-count">0 notes</span>
    </div>
  </div>
</div>

<!-- ── MIDI bar ── -->
<div class="midi-bar">
  <span class="midi-label">🥁 E-Drum</span>
  <select id="midi-device-sel"><option value="">— No MIDI devices —</option></select>
  <span id="midi-status" class="midi-status">Not connected</span>
  <span id="midi-hit-flash" class="midi-hit-flash"></span>
  <button id="btn-map-pads" class="midi-btn">⚙ Map Pads</button>
  <button id="btn-sound" class="midi-btn">🔊 Pad sounds</button>
  <button id="btn-playback" class="midi-btn" title="Play your recorded notes as drum sounds while the song plays (preview only — not saved)">🎧 Hear chart</button>
  <span id="midi-last-note" class="midi-last-note"></span>
  <span style="margin-left:auto;font-size:.73rem;color:var(--dim)">Keyboard shortcuts still work as fallback</span>
</div>

<!-- ── MIDI pad-mapping panel ── -->
<div id="midi-map-panel" class="midi-map-panel" style="display:none">
  <div class="midi-map-head">
    <strong>Assign E-Drum Pads</strong>
    <span class="midi-map-hint">Click <em>Assign</em>, then strike that pad on your kit. Saved automatically · Esc cancels.</span>
    <button id="btn-midi-reset" class="midi-btn">↺ Reset to default</button>
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
  <button id="btn-zoom-out" class="tool-btn" title="Zoom out">🔍−</button>
  <span id="zoom-val">1×</span>
  <button id="btn-zoom-in" class="tool-btn" title="Zoom in (Ctrl + scroll on the grid)">🔍+</button>
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

  <button id="btn-select-all" class="tool-btn" title="Select every note (Ctrl+A)">⬚ Select All</button>
  <button id="btn-undo"     class="tool-btn">↩ Undo</button>
  <button id="btn-clear"    class="tool-btn danger">🗑 Clear All</button>

  <div class="sep" style="margin-left:auto"></div>
  <span style="color:var(--dim);font-size:.75rem">
    Ctrl+scroll = zoom · box-select or Shift-click · Ctrl+A = all · drag any selected note to move the group (Alt = ignore grid) · Arrow keys nudge (Shift = fine) · Delete · Ctrl+Z
  </span>
</div>

<div class="toast" id="toast"></div>

<script src="/assets/js/chart-editor.js?v=<?= $v ?>"></script>
</body>
</html>
