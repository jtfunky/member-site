<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_feedback.php';

$user = requireLogin();

// One-time assessment: once taken, the placement test is locked and off the menu.
// (Admins keep access.) The result is recorded and drives the student's plan.
if (($user['role'] ?? '') !== 'admin' && hasTakenPlacementTest((int) $user['id'])) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

// Recent placement tests for this student.
$tests = [];
try {
    $st = db()->prepare('SELECT * FROM drum_tests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
    $st->execute([(int) $user['id']]);
    $tests = $st->fetchAll();
} catch (\Throwable $e) { /* table may not exist yet */ }

// First-timers get a short interactive tutorial (kick/snare/hi-hat/crash/tom)
// before the real test; returning students skip straight to it. Inert until
// migrate-tutorial.sql has run (a missing column is treated as "not seen").
$tutorialDone = true;
try {
    $st = db()->prepare('SELECT tutorial_completed FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) $user['id']]);
    $row = $st->fetch();
    $tutorialDone = !$row || (int) ($row['tutorial_completed'] ?? 1) === 1;
} catch (\Throwable $e) { /* column not migrated yet — don't gate */ }

$pageTitle = 'Drum Placement Test — ' . SITE_NAME;
$pageCss   = ['main'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>
<style>
  .pt-wrap { max-width: 760px; margin: 0 auto; padding: 1rem; }
  .pt-hero { text-align: center; margin-bottom: 1.5rem; }
  .pt-card { border: 1px solid var(--border, #2a2a3a); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
  .pt-card h3 { margin: 0 0 .25rem; }
  .pt-meta { font-size: .9rem; opacity: .8; }
  .pt-grade { font-size: 2rem; font-weight: 800; margin-right: .75rem; }
  .pt-coach { margin: .6rem 0 .2rem; }
  .ai-feedback-body p { margin: .4rem 0; }
  .ai-feedback-body ul { margin: .25rem 0 .5rem 1.1rem; }
  .pt-empty { opacity: .75; }
  .pt-start { display: flex; gap: .6rem; justify-content: center; flex-wrap: wrap; }

  /* Pad-mapping panel (mirrors game.php's calibration styling) */
  .acoustic-cal { margin-top: 1rem; max-width: 34rem; margin-left: auto; margin-right: auto; text-align: left; }
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
  .cal-state { margin-left: auto; font-size: .82rem; color: var(--text-dim, #9ca3af); }
  .cal-state.ok { color: #4ade80; }
  .cal-btn {
    background: #4f46e5; color: #fff; border: none; border-radius: 6px;
    padding: .35rem .8rem; font-size: .82rem; font-weight: 600; cursor: pointer;
  }
  .cal-btn:hover { background: #4338ca; }
  .cal-btn.cal-btn-clear { background: transparent; border: 1px solid rgba(255,255,255,.2); color: var(--text-dim, #9ca3af); }

  /* First-time "map your pads?" prompt before starting on a full kit */
  .pt-modal {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(0,0,0,.6);
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
  }
  .pt-modal-card {
    position: relative;
    background: var(--bg2, #17172a);
    border: 1px solid var(--border, #2c2c44);
    border-radius: var(--radius-lg, 16px);
    padding: 1.5rem;
    max-width: 26rem;
    width: 100%;
    text-align: center;
    box-shadow: var(--shadow, 0 4px 24px rgba(0,0,0,.4));
  }
  .pt-modal-card h3 { margin: 0 0 .5rem; }
  .pt-modal-close {
    position: absolute; top: .1rem; right: .1rem;
    width: 44px; height: 44px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; color: var(--text-dim, #8d8da6);
    font-size: 1.3rem; line-height: 1; cursor: pointer;
  }
  .pt-modal-close:hover { color: var(--text, #e8e8f0); }
</style>
<div class="pt-wrap">
  <?php $hasTaken = !empty($tests); ?>
  <div class="pt-hero">
    <h1><i class="ti ti-music" aria-hidden="true"></i> Drum Placement Test</h1>
    <?php if ($hasTaken): ?>
      <p class="auth-sub">Here's your latest result and Coach Zach's feedback below.
        Re-take it anytime to see how much you've improved.</p>
    <?php else: ?>
      <p class="auth-sub">Play one short standardized exercise. We'll analyze your timing and accuracy
        and give you personalized coaching on what to work on next. Pick how you'll play —
        this also shapes your practice plan.</p>
    <?php endif; ?>
    <?php // Tutorial teaches 5 distinct kit pieces — only meaningful on a full kit;
          // a single practice pad can't tell those drums apart, so that path
          // always goes straight to its own (already single-lane) exercise. ?>
    <?php $startKit = ($hasTaken || $tutorialDone) ? '/game.php?test=1' : '/game.php?tutorial=1'; ?>
    <div class="pt-start">
      <a id="pt-start-full" class="btn btn-primary btn-lg" href="<?= htmlspecialchars($startKit) ?>"><i class="ti ti-music" aria-hidden="true"></i> <?= $hasTaken ? 'Re-take on a full kit' : 'Full drum kit' ?></a>
      <a class="btn btn-ghost btn-lg" href="/game.php?test=1&amp;pad=1"><i class="ti ti-target" aria-hidden="true"></i> <?= $hasTaken ? 'Re-take on a practice pad' : 'Practice pad' ?></a>
    </div>
    <p class="pt-meta" style="margin-top:.5rem">A practice pad? You'll get a single-pad plan focused on timing &amp; rudiments.</p>
    <div class="pt-start" style="margin-top:.5rem">
      <button type="button" id="pt-padmap-toggle" class="btn btn-ghost btn-sm"><i class="ti ti-adjustments" aria-hidden="true"></i> Map pads</button>
      <a href="https://youtu.be/qGaR57B4R0s" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><i class="ti ti-brand-youtube" aria-hidden="true"></i> How to map pads</a>
    </div>
    <div id="pt-pad-map" class="acoustic-cal" style="display:none">
      <p class="pt-meta">Only needed if a drum doesn't register: click <strong>Assign</strong>, then strike that pad on your kit once. Saved on this device — carries over into the game.</p>
      <div class="form-group">
        <label for="pt-midi-device-select">MIDI Device</label>
        <select id="pt-midi-device-select"><option value="">No MIDI detected</option></select>
      </div>
      <div id="pt-padmap-list" class="cal-list"></div>
      <button type="button" id="pt-padmap-reset" class="btn btn-ghost btn-sm" style="margin-top:.5rem"><i class="ti ti-refresh" aria-hidden="true"></i> Reset all to defaults</button>
    </div>
    <div id="pt-padmap-modal" class="pt-modal" style="display:none">
      <div class="pt-modal-card">
        <button type="button" id="pt-padmap-modal-close" class="pt-modal-close" aria-label="Close">&times;</button>
        <h3><i class="ti ti-adjustments" aria-hidden="true"></i> Playing on an e-drum kit?</h3>
        <p class="pt-meta">Map your pads first so every hit registers correctly — takes a minute, and it's saved for every session after.</p>
        <div class="pt-start" style="margin-top:1rem">
          <button type="button" id="pt-padmap-modal-map" class="btn btn-primary btn-sm"><i class="ti ti-adjustments" aria-hidden="true"></i> Map pads</button>
          <button type="button" id="pt-padmap-modal-skip" class="btn btn-ghost btn-sm">Skip, start test</button>
        </div>
      </div>
    </div>
    <?php if (!$hasTaken && $tutorialDone): ?>
      <p class="pt-meta"><a href="/game.php?tutorial=1"><i class="ti ti-refresh" aria-hidden="true"></i> Replay the tutorial</a></p>
    <?php endif; ?>
  </div>

  <h2><?= $hasTaken ? ('Your latest result' . (count($tests) > 1 ? ' &amp; history' : '')) : 'Your results' ?></h2>
  <?php if (!$tests): ?>
    <p class="pt-empty">You haven't taken the placement test yet — hit the button above to begin.</p>
  <?php else: ?>
    <?php foreach ($tests as $t): ?>
      <div class="pt-card">
        <h3>
          <span class="pt-grade"><?= htmlspecialchars($t['grade'] ?: '–') ?></span>
          <?= htmlspecialchars($t['song'] ?: 'Placement exercise') ?>
        </h3>
        <div class="pt-meta">
          <?= number_format((float) $t['accuracy'], 1) ?>% accuracy ·
          <?= (int) $t['perfect'] ?> perfect / <?= (int) $t['good'] ?> good / <?= (int) $t['miss'] ?> miss ·
          combo <?= (int) $t['max_combo'] ?> ·
          <?= (int) $t['avg_offset_ms'] < 0 ? 'rushes' : ((int) $t['avg_offset_ms'] > 0 ? 'drags' : 'centered') ?>
          (<?= (int) $t['avg_offset_ms'] ?>ms) ·
          <?= htmlspecialchars(formatManilaDate($t['created_at'], 'M j, Y g:i A')) ?>
        </div>
        <?php if (!empty($t['ai_feedback'])): ?>
          <h4 class="pt-coach">Coach Zach</h4>
          <div class="ai-feedback-body"><?= feedbackToHtml($t['ai_feedback']) ?></div>
        <?php else: ?>
          <p class="pt-meta">No AI coaching saved for this attempt.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<script type="module" src="/assets/js/pad-map-widget.js?v=<?= @filemtime(__DIR__ . '/assets/js/pad-map-widget.js') ?: 1 ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
