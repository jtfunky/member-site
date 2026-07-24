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
        and give you personalized AI coaching on what to work on next. Pick how you'll play —
        this also shapes your practice plan.</p>
    <?php endif; ?>
    <?php // Tutorial teaches 5 distinct kit pieces — only meaningful on a full kit;
          // a single practice pad can't tell those drums apart, so that path
          // always goes straight to its own (already single-lane) exercise. ?>
    <?php $startKit = ($hasTaken || $tutorialDone) ? '/game.php?test=1' : '/game.php?tutorial=1'; ?>
    <div class="pt-start">
      <a class="btn btn-primary btn-lg" href="<?= htmlspecialchars($startKit) ?>"><i class="ti ti-music" aria-hidden="true"></i> <?= $hasTaken ? 'Re-take on a full kit' : 'Full drum kit' ?></a>
      <a class="btn btn-ghost btn-lg" href="/game.php?test=1&amp;pad=1"><i class="ti ti-target" aria-hidden="true"></i> <?= $hasTaken ? 'Re-take on a practice pad' : 'Practice pad' ?></a>
    </div>
    <p class="pt-meta" style="margin-top:.5rem">A practice pad? You'll get a single-pad plan focused on timing &amp; rudiments.</p>
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
          <?= htmlspecialchars(date('M j, Y g:i A', strtotime($t['created_at']))) ?>
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
<?php require __DIR__ . '/includes/footer.php'; ?>
