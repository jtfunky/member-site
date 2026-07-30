<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/practice_plan.php';

@set_time_limit(60); // "Generate with AI" makes a synchronous Claude call
$admin = requireAdmin();

$uid = (int) ($_GET['user'] ?? $_POST['user'] ?? 0);

// Who is this plan for?
$who = null;
if ($uid) {
    $q = db()->prepare(
        'SELECT u.username, u.email, s.full_name
           FROM users u LEFT JOIN students s ON s.user_id = u.id
          WHERE u.id = ? LIMIT 1'
    );
    $q->execute([$uid]);
    $who = $q->fetch() ?: null;
}
$name = $who ? ($who['full_name'] ?: ($who['username'] ?: ('User #' . $uid))) : '';

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uid) {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'generate') {
        $r = generatePracticePlan($uid);
        if (is_string($r)) $error = $r; else $message = 'Plan generated from the placement test.';
    } elseif ($action === 'save') {
        $sessions = [];
        foreach (($_POST['s'] ?? []) as $no => $s) {
            $sessions[(int) $no] = [
                'title'  => $s['title'] ?? '',
                'focus'  => $s['focus'] ?? '',
                'drills' => preg_split('/\r?\n/', (string) ($s['drills'] ?? '')),
            ];
        }
        if (updatePlanContent($uid, (string) ($_POST['intro'] ?? ''), $sessions)) {
            $message = 'Plan saved.';
        } else {
            $error = 'Could not save — no plan exists yet (generate one first).';
        }
    }
}

$plan = $uid ? getPracticePlan($uid) : null;

$pageTitle = 'Edit Plan — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
$pageHead  = '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken()) . '">';
require __DIR__ . '/../includes/header.php';
?>
<style>
  .pe-session { border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1rem 1.15rem; margin-bottom: .85rem; }
  .pe-session.done { border-color: rgba(34,197,94,.4); }
  .pe-session h3 { margin: 0 0 .6rem; font-size: 1rem; }
  .pe-session .done-badge { font-size: .72rem; color: #4ade80; margin-left: .5rem; }
  .pe-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: .6rem; }
  .pe-actions { display: flex; gap: .75rem; align-items: center; margin: 1rem 0; flex-wrap: wrap; }
  textarea.pe-drills { min-height: 80px; }
  @media (max-width: 640px) { .pe-grid { grid-template-columns: 1fr; } }
</style>

<main class="container container--wide">
<div class="admin-header">
  <h1>Practice Plan</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$uid || !$who): ?>
  <div class="alert alert-error">No student specified. Open a plan from
    <a href="/admin/placement-tests.php">Placement Tests</a>.</div>
<?php else: ?>
  <p><strong>Student:</strong> <?= htmlspecialchars($name) ?>
     <?php if (!empty($who['email'])): ?><small>(<?= htmlspecialchars($who['email']) ?>)</small><?php endif; ?>
     · <a href="/admin/placement-tests.php">← all tests</a></p>

  <div class="pe-actions">
    <form method="POST"<?= $plan ? ' data-confirm="Regenerate the plan with AI? This replaces the current sessions and resets progress."' : '' ?>>
      <?= csrfField() ?>
      <input type="hidden" name="user" value="<?= $uid ?>">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn btn-secondary">
        <?= $plan ? '↻ Regenerate with AI' : '✨ Generate plan with AI' ?>
      </button>
    </form>
    <?php if (!practicePlanEnabled()): ?><span class="field-hint">AI key not configured — you can still edit by hand once a plan exists.</span><?php endif; ?>
  </div>

  <?php if (!$plan): ?>
    <div class="alert alert-info">No plan yet for this student. Generate one with AI above, or it appears here after they open “My Plan”.</div>
  <?php else: ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="user" value="<?= $uid ?>">
      <input type="hidden" name="action" value="save">

      <div class="form-group">
        <label>Intro message to the student</label>
        <textarea name="intro" rows="3"><?= htmlspecialchars($plan['intro']) ?></textarea>
      </div>

      <?php foreach ($plan['sessions'] as $s): $no = (int) $s['session_no']; ?>
      <div class="pe-session <?= $s['completed'] ? 'done' : '' ?>">
        <h3>Session <?= $no ?><?php if ($s['completed']): ?><span class="done-badge">✓ done by student</span><?php endif; ?></h3>
        <div class="pe-grid">
          <div class="form-group">
            <label>Title</label>
            <input type="text" name="s[<?= $no ?>][title]" value="<?= htmlspecialchars($s['title']) ?>">
          </div>
          <div class="form-group">
            <label>Focus (one line)</label>
            <input type="text" name="s[<?= $no ?>][focus]" value="<?= htmlspecialchars($s['focus']) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Drills — one per line</label>
          <textarea class="pe-drills" name="s[<?= $no ?>][drills]"><?= htmlspecialchars(implode("\n", $s['drills'])) ?></textarea>
        </div>
      </div>
      <?php endforeach; ?>

      <button type="submit" class="btn btn-primary">Save Plan</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
</main>

<script src="/assets/js/students-admin.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
