<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
requireProfileComplete($user); // self-signups must finish their profile first
// The placement test is a one-time assessment — block re-entering test mode once taken.
if (!empty($_GET['test']) && ($user['role'] ?? '') !== 'admin' && hasTakenPlacementTest((int) $user['id'])) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}
requirePlacementTest($user);   // new signups take the placement test first (self-allows ?test=1)
// The placement test (?test=1) is open to any enrolled student regardless of
// membership status — it uses only the free built-in exercise. The full game
// (song library) stays members-only.
if (empty($_GET['test'])) requirePremium($user);

$pageTitle = 'Drum Game — ' . SITE_NAME;
$pageCss   = ['game'];
$bodyClass = 'game-body';
// Web-app meta so "Add to Home Screen" launches the game full-screen (no browser
// chrome) on iOS + Android. In-browser, tapping an input requests fullscreen.
$pageHead  = '<meta name="mobile-web-app-capable" content="yes">'
           . '<meta name="apple-mobile-web-app-capable" content="yes">'
           . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
           . '<meta name="apple-mobile-web-app-title" content="' . htmlspecialchars(SITE_NAME) . '">';
require __DIR__ . '/includes/header.php';
?>

<style>
  /* Cap the results panel to the viewport and let it scroll internally when the
     AI coaching is long, so the Retry/Menu buttons are always reachable. */
  .results-panel { max-height: 92vh; overflow-y: auto; }
  .results-ai { text-align: left; margin: .75rem 0; max-width: 34rem; }
  .results-ai h3 { margin: 0 0 .4rem; }
  .results-ai .ai-feedback-body p { margin: .35rem 0; }
  .results-ai .ai-feedback-body ul { margin: .25rem 0 .5rem 1.1rem; }
  .results-ai .ai-loading { opacity: .8; }
  .results-ai .ai-error { color: #ff8a8a; }
  .input-required { color: #fca5a5; margin-top: .85rem; font-weight: 600; }

  /* Acoustic calibration panel */
  .acoustic-cal { margin-top: 1rem; max-width: 34rem; }
  .acoustic-cal h3 { margin: 0 0 .25rem; }
  .cal-list { display: flex; flex-direction: column; gap: .5rem; margin-top: .75rem; }
  .cal-row {
    display: flex; align-items: center; gap: .75rem;
    padding: .55rem .75rem;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
  }
  .cal-row.cal-done { border-color: rgba(34,197,94,.5); background: rgba(34,197,94,.08); }
  .cal-row.cal-listening { border-color: #eab308; background: rgba(234,179,8,.1); }
  .cal-name { font-weight: 600; }
  .cal-req  { font-size: .7rem; color: #fca5a5; margin-left: .25rem; }
  .cal-state { margin-left: auto; font-size: .82rem; color: var(--text-dim, #9ca3af); }
  .cal-state.ok { color: #4ade80; }
  .cal-btn {
    background: #4f46e5; color: #fff; border: none; border-radius: 6px;
    padding: .35rem .8rem; font-size: .82rem; font-weight: 600; cursor: pointer;
  }
  .cal-btn:hover { background: #4338ca; }
  .cal-btn.cal-btn-clear { background: transparent; border: 1px solid rgba(255,255,255,.2); color: var(--text-dim, #9ca3af); }

  /* Per-song best + recent plays */
  .song-best { display: block; font-size: .8rem; color: #fcd34d; margin-top: .15rem; }
  .recent-plays { padding: 0 2rem 2rem; }
  .recent-plays h2 { margin: 1rem 0 .5rem; }
  .recent-list { display: flex; flex-direction: column; gap: .35rem; }
  .recent-row { display: flex; align-items: center; gap: .75rem; padding: .5rem .75rem; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 6px; font-size: .85rem; }
  .recent-song  { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .recent-grade { font-weight: 700; color: #facc15; min-width: 1.2rem; text-align: center; }
  .recent-score { min-width: 4.5rem; text-align: right; }
  .recent-acc   { color: var(--dim); min-width: 3.5rem; text-align: right; }
</style>

<!-- ── Rotate overlay (portrait mobile/tablet) ──────────── -->
<div class="rotate-overlay">
  <div class="rotate-icon">📱</div>
  <div class="rotate-title">Rotate your device</div>
  <div class="rotate-sub">This game is designed for landscape mode</div>
</div>

<!-- ── Screens ─────────────────────────────────────────── -->

<div id="screen-menu" class="screen active">
  <div class="menu-header">
    <h1>🥁 <?= SITE_NAME ?></h1>
    <div class="menu-user">
      <?= htmlspecialchars($user['first_name'] ?: $user['username']) ?>
      <a href="/dashboard.php" class="btn btn-ghost btn-sm">Dashboard</a>
    </div>
  </div>

  <div id="menu-input-select" class="input-select-section">
    <h2>Choose your input</h2>
    <div class="input-options">
      <button class="input-opt" data-input="midi">
        <span class="input-icon">🥁</span>
        <span>E-Drum Kit</span>
        <small>USB MIDI</small>
      </button>
      <button class="input-opt" data-input="acoustic">
        <span class="input-icon">🎙️</span>
        <span>Acoustic Drums</span>
        <small>Via microphone</small>
      </button>
      <button class="input-opt" data-input="pad">
        <span class="input-icon">🪘</span>
        <span>Single Drum Pad</span>
        <small>One pad, via microphone</small>
      </button>
    </div>
    <div id="acoustic-status" class="acoustic-status" style="display:none"></div>

    <!-- Acoustic calibration: teach the app each drum. Shown when Acoustic is chosen. -->
    <div id="acoustic-calibration" class="acoustic-cal" style="display:none">
      <h3>🎚️ Calibrate your kit</h3>
      <p class="field-hint">Tap a drum below, then hit it <strong>4 times</strong> so the app learns its sound. The <strong>required</strong> drums must be done before you can play; add cymbals/toms if your kit has them.</p>
      <div id="cal-list" class="cal-list"></div>
    </div>

    <div id="input-required-msg" class="input-required">🔒 Choose your input above to unlock the song library.</div>
  </div>

  <div id="song-library" class="song-library" style="display:none">
    <h2>Song Library</h2>
    <div id="song-list" class="song-list">
      <div class="loading">Loading songs…</div>
    </div>
  </div>

  <div id="recent-plays" class="recent-plays" style="display:none"></div>
</div>

<div id="screen-settings" class="screen">
  <div class="overlay-panel">
    <h2>Settings</h2>
    <div class="form-group">
      <label>Note Speed</label>
      <input type="range" id="speed-slider" min="500" max="4000" step="100" value="2000">
      <span id="speed-label">2000 ms</span>
    </div>
    <div class="form-group">
      <label>MIDI Device</label>
      <select id="midi-device-select"><option value="">No MIDI detected</option></select>
    </div>
    <div class="form-group">
      <label>MIDI Channel (0 = any)</label>
      <input type="number" id="midi-channel" min="0" max="16" value="0">
    </div>
    <div class="form-group" id="acoustic-sensitivity-group" style="display:none">
      <label>Mic Sensitivity</label>
      <input type="range" id="acoustic-sensitivity" min="1" max="10" step="1" value="5">
      <span id="acoustic-sensitivity-label">5</span>
      <span class="field-hint">Higher = detects softer hits (may cause false triggers)</span>
    </div>
    <div class="form-group">
      <label>Hit Sound</label>
      <label class="toggle">
        <input type="checkbox" id="hit-sound-toggle" checked>
        <span class="toggle-slider"></span>
      </label>
    </div>
    <button id="close-settings" class="btn btn-primary">Done</button>
  </div>
</div>

<div id="screen-countdown" class="screen">
  <div class="countdown-display">
    <div id="countdown-number">3</div>
    <div class="song-info-countdown">
      <h2 id="countdown-title"></h2>
      <p id="countdown-artist"></p>
    </div>
  </div>
</div>

<div id="screen-game" class="screen">
  <div class="hud">
    <div class="hud-left">
      <div class="hud-score">Score: <span id="hud-score">0</span></div>
      <div class="hud-combo">Combo: <span id="hud-combo">0</span></div>
    </div>
    <div class="hud-center">
      <span id="hud-title"></span>
    </div>
    <div class="hud-right">
      <button id="btn-pause" class="btn btn-ghost btn-sm">⏸ Pause</button>
      <button id="btn-fullscreen-exit" class="btn btn-exit btn-sm" title="Exit game">✕ Exit</button>
    </div>
  </div>
  <canvas id="game-canvas"></canvas>
  <div id="judgment-popup" class="judgment-popup"></div>
</div>

<div id="screen-pause" class="screen">
  <div class="overlay-panel">
    <h2>Paused</h2>
    <button id="btn-resume" class="btn btn-primary btn-block">Resume</button>
    <button id="btn-quit"   class="btn btn-ghost btn-block">Quit to Menu</button>
  </div>
</div>

<div id="screen-results" class="screen">
  <div class="overlay-panel results-panel">
    <h2>Results</h2>
    <div id="results-grade" class="results-grade"></div>
    <div id="results-song" class="results-song"></div>
    <table class="results-table">
      <tr><td>Score</td><td id="r-score"></td></tr>
      <tr><td>Accuracy</td><td id="r-accuracy"></td></tr>
      <tr><td>Perfect</td><td id="r-perfect"></td></tr>
      <tr><td>Good</td><td id="r-good"></td></tr>
      <tr><td>Miss</td><td id="r-miss"></td></tr>
      <tr><td>Max Combo</td><td id="r-combo"></td></tr>
    </table>
    <div id="results-ai" class="results-ai" style="display:none"></div>
    <button id="btn-retry"   class="btn btn-primary">Retry</button>
    <button id="btn-to-menu" class="btn btn-ghost">Menu</button>
  </div>
</div>

<button id="btn-settings" class="fab-settings" title="Settings">⚙️</button>

<div id="game-config" data-csrf="<?= htmlspecialchars(csrfToken()) ?>" hidden></div>
<?php if (!empty($_GET['test'])): ?>
<div id="drum-test-config" hidden></div>
<?php endif; ?>
<script type="module" src="/assets/js/game/main.js?v=20260711"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
