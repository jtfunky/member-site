<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_feedback.php';

$user = requireLogin();

// Recent placement tests for this student.
$tests = [];
try {
    $st = db()->prepare('SELECT * FROM drum_tests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
    $st->execute([(int) $user['id']]);
    $tests = $st->fetchAll();
} catch (\Throwable $e) { /* table may not exist yet */ }

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
</style>
<div class="pt-wrap">
  <?php $hasTaken = !empty($tests); ?>
  <div class="pt-hero">
    <h1>🥁 Drum Placement Test</h1>
    <?php if ($hasTaken): ?>
      <p class="auth-sub">Here's your latest result and Coach Zach's feedback below.
        Re-take it anytime to see how much you've improved.</p>
      <a class="btn btn-primary btn-lg" href="/game.php?test=1">Re-take Test</a>
    <?php else: ?>
      <p class="auth-sub">Play one short standardized exercise. We'll analyze your timing and accuracy
        and give you personalized AI coaching on what to work on next.</p>
      <a class="btn btn-primary btn-lg" href="/game.php?test=1">Start Placement Test</a>
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
