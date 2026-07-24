<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/practice_plan.php';

$user = requireLogin();
requireProfileComplete($user);

// The plan is built from the placement test — must have taken it first.
if (!hasTakenPlacementTest((int) $user['id'])) {
    header('Location: ' . SITE_URL . '/placement-test.php');
    exit;
}

$plan = getPracticePlan((int) $user['id']);
$aiOn = practicePlanEnabled();

$pageTitle = 'My Plan — ' . SITE_NAME;
$pageCss   = ['main'];
$showNav   = true;
$pageHead  = '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken()) . '">';
require __DIR__ . '/includes/header.php';
?>
<style>
  .plan-wrap { max-width: 760px; margin: 0 auto; }
  .plan-intro { color: var(--text-dim); margin: .25rem 0 1rem; }
  .plan-progress { margin-bottom: 1.25rem; font-weight: 600; }
  .plan-bar { height: 8px; background: var(--bg3); border-radius: 99px; overflow: hidden; margin-top: .4rem; }
  .plan-bar > span { display: block; height: 100%; background: var(--accent); transition: width .2s; }
  .plan-session { display: flex; gap: .9rem; align-items: flex-start; border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 1rem 1.15rem; margin-bottom: .85rem; }
  .plan-session.done { border-color: rgba(34,197,94,.4); background: rgba(34,197,94,.06); }
  .plan-session.locked { opacity: .6; }
  .plan-state { width: 34px; text-align: center; font-size: 1.4rem; margin-top: .1rem; }
  .plan-body { flex: 1; min-width: 0; }
  .plan-actions { margin-top: .6rem; display: flex; align-items: center; gap: .8rem; flex-wrap: wrap; }
  .plan-best { font-size: .82rem; color: var(--text-dim); }
  .plan-locked-note { font-size: .82rem; color: var(--text-dim); }
  .plan-body h3 { margin: 0 0 .2rem; font-size: 1.05rem; }
  .plan-focus { color: var(--text-dim); font-size: .9rem; margin: 0 0 .5rem; }
  .plan-body ul { margin: 0 0 0 1.1rem; }
  .plan-body li { margin: .2rem 0; }
  .plan-loading { text-align: center; padding: 2rem 1rem; color: var(--text-dim); }
  .plan-spinner { font-size: 2rem; animation: spin 1.2s linear infinite; display: inline-block; }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>

<main class="container">
<div class="plan-wrap">
  <h1><i class="ti ti-music" aria-hidden="true"></i> My Practice Plan</h1>

  <?php if ($plan): ?>
    <?php
      $total = count($plan['sessions']);
      $done  = 0; foreach ($plan['sessions'] as $s) if ($s['completed']) $done++;
      $pct   = $total ? round($done / $total * 100) : 0;
    ?>
    <?php if (($plan['mode'] ?? 'kit') === 'pad'): ?>
      <p class="plan-intro" style="color:#a5b4fc"><strong><i class="ti ti-target" aria-hidden="true"></i> Practice-pad plan</strong> — single-surface exercises focused on timing &amp; rudiments. Play each on the Practice Pad input.</p>
    <?php endif; ?>
    <?php if ($plan['intro'] !== ''): ?><p class="plan-intro"><?= htmlspecialchars($plan['intro']) ?></p><?php endif; ?>

    <div class="plan-progress">
      Progress: <?= $done ?> / <?= $total ?> sessions passed
      <div class="plan-bar"><span id="plan-bar-fill" style="width: <?= $pct ?>%"></span></div>
      <div class="plan-locked-note" style="font-weight:400;margin-top:.35rem">
        Play each session and score <strong><?= (int) PLAN_PASS_ACCURACY ?>%</strong> or higher to unlock the next one.
      </div>
    </div>

    <?php
      // Hard lock: a session unlocks once the previous one is passed.
      $prevDone = true;
      foreach ($plan['sessions'] as $s):
        $unlocked = ($s['session_no'] === 1) || $prevDone;
        $state    = $s['completed'] ? 'done' : ($unlocked ? '' : 'locked');
    ?>
    <div class="plan-session <?= $state ?>" data-session="<?= (int) $s['session_no'] ?>">
      <div class="plan-state"><?= $s['completed'] ? '<i class="ti tif ti-circle-check" style="color:var(--success)"></i>' : ($unlocked ? '<i class="ti ti-music" style="color:var(--accent)"></i>' : '<i class="ti tif ti-lock" style="color:var(--text-dim)"></i>') ?></div>
      <div class="plan-body">
        <h3>Session <?= (int) $s['session_no'] ?>: <?= htmlspecialchars($s['title']) ?></h3>
        <?php if ($s['focus'] !== ''): ?><p class="plan-focus"><?= htmlspecialchars($s['focus']) ?></p><?php endif; ?>
        <?php if ($s['drills']): ?>
        <ul>
          <?php foreach ($s['drills'] as $d): ?><li><?= htmlspecialchars($d) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <div class="plan-actions">
          <?php if ($s['completed']): ?>
            <a class="btn btn-ghost btn-sm" href="/game.php?plan=<?= (int) $s['session_no'] ?>"><i class="ti ti-repeat" aria-hidden="true"></i> Replay</a>
            <span class="plan-best">Passed · best <?= number_format($s['best_accuracy'], 0) ?>%</span>
          <?php elseif ($unlocked): ?>
            <a class="btn btn-primary btn-sm" href="/game.php?plan=<?= (int) $s['session_no'] ?>"><i class="ti ti-player-play" aria-hidden="true"></i> Play session</a>
            <?php if ($s['best_accuracy'] > 0): ?><span class="plan-best">Best so far <?= number_format($s['best_accuracy'], 0) ?>%</span><?php endif; ?>
          <?php else: ?>
            <span class="plan-locked-note"><i class="ti tif ti-lock" aria-hidden="true"></i> Pass the previous session to unlock this.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php $prevDone = $s['completed']; endforeach; ?>

  <?php elseif (!$aiOn): ?>
    <div class="alert alert-info">Your coach is preparing your plan — please check back soon.</div>

  <?php else: ?>
    <!-- No plan yet: my-plan.js generates it from the placement test, then reloads. -->
    <div id="plan-generating" class="plan-loading">
      <div class="plan-spinner"><i class="ti ti-music" aria-hidden="true"></i></div>
      <p>Building your personalized 10-session plan from your placement test…<br>This takes a few seconds.</p>
      <div id="plan-error" class="alert alert-error" style="display:none;text-align:left;"></div>
    </div>
  <?php endif; ?>
</div>
</main>

<script src="/assets/js/my-plan.js?v=20260710"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
