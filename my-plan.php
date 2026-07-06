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
  .plan-session.done .plan-body h3 { text-decoration: line-through; opacity: .7; }
  .plan-check input { width: 22px; height: 22px; margin-top: .2rem; cursor: pointer; }
  .plan-body { flex: 1; min-width: 0; }
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
  <h1>🥁 My Practice Plan</h1>

  <?php if ($plan): ?>
    <?php
      $total = count($plan['sessions']);
      $done  = 0; foreach ($plan['sessions'] as $s) if ($s['completed']) $done++;
      $pct   = $total ? round($done / $total * 100) : 0;
    ?>
    <?php if ($plan['intro'] !== ''): ?><p class="plan-intro"><?= htmlspecialchars($plan['intro']) ?></p><?php endif; ?>

    <div class="plan-progress">
      Progress: <?= $done ?> / <?= $total ?> sessions this month
      <div class="plan-bar"><span id="plan-bar-fill" style="width: <?= $pct ?>%"></span></div>
    </div>

    <?php foreach ($plan['sessions'] as $s): ?>
    <div class="plan-session <?= $s['completed'] ? 'done' : '' ?>" data-session="<?= (int) $s['session_no'] ?>">
      <label class="plan-check">
        <input type="checkbox" class="plan-done" data-session="<?= (int) $s['session_no'] ?>" <?= $s['completed'] ? 'checked' : '' ?>>
      </label>
      <div class="plan-body">
        <h3>Session <?= (int) $s['session_no'] ?>: <?= htmlspecialchars($s['title']) ?></h3>
        <?php if ($s['focus'] !== ''): ?><p class="plan-focus"><?= htmlspecialchars($s['focus']) ?></p><?php endif; ?>
        <?php if ($s['drills']): ?>
        <ul>
          <?php foreach ($s['drills'] as $d): ?><li><?= htmlspecialchars($d) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

  <?php elseif (!$aiOn): ?>
    <div class="alert alert-info">Your coach is preparing your plan — please check back soon.</div>

  <?php else: ?>
    <!-- No plan yet: my-plan.js generates it from the placement test, then reloads. -->
    <div id="plan-generating" class="plan-loading">
      <div class="plan-spinner">🥁</div>
      <p>Building your personalized 10-session plan from your placement test…<br>This takes a few seconds.</p>
      <div id="plan-error" class="alert alert-error" style="display:none;text-align:left;"></div>
    </div>
  <?php endif; ?>
</div>
</main>

<script src="/assets/js/my-plan.js?v=20260706"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
