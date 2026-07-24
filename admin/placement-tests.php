<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai_feedback.php';

$user = requireAdmin();

$hasTable = (bool) db()->query("SHOW TABLES LIKE 'drum_tests'")->fetch();
$tests = [];
if ($hasTable) {
    $tests = db()->query(
        'SELECT dt.*, u.username, u.email, s.full_name
         FROM drum_tests dt
         LEFT JOIN users u    ON u.id = dt.user_id
         LEFT JOIN students s ON s.id = dt.student_id
         ORDER BY dt.created_at DESC
         LIMIT 100'
    )->fetchAll();
}

$pageTitle = 'Placement Tests — ' . SITE_NAME;
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>
<main class="container container--wide">
<div class="admin-header">
  <h1>Placement Tests</h1>
  <div class="admin-nav-pills">
    <a href="/admin/">Overview</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/students.php">Students</a>
    <a href="/admin/sessions.php">Sessions</a>
    <a href="/admin/songs.php">Songs</a>
    <a href="/admin/placement-tests.php" class="active">Placement Tests</a>
    <a href="/admin/investor-agreement.php">Investor Agreement</a>
  </div>
</div>

<?php if (!$hasTable): ?>
  <div class="alert alert-error">The <code>drum_tests</code> table doesn't exist yet — run
    <code>migrate-drum-tests.sql</code> in phpMyAdmin first.</div>
<?php elseif (!$tests): ?>
  <p>No placement tests have been taken yet.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="data-table">
    <thead>
      <tr>
        <th>Student</th><th>Grade</th><th>Accuracy</th>
        <th>P / G / M</th><th>Combo</th><th>Timing</th><th>Date</th><th>Coaching</th><th>Plan</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tests as $t):
        $name = $t['full_name'] ?: ($t['username'] ?: ('User #' . (int) $t['user_id']));
        $bias = (int) $t['avg_offset_ms'];
        $biasTxt = $bias < 0 ? "rushes {$bias}ms" : ($bias > 0 ? "drags +{$bias}ms" : 'centered');
    ?>
      <tr>
        <td><?= htmlspecialchars($name) ?><br><small><?= htmlspecialchars($t['email'] ?? '') ?></small></td>
        <td><strong><?= htmlspecialchars($t['grade'] ?: '–') ?></strong></td>
        <td><?= number_format((float) $t['accuracy'], 1) ?>%</td>
        <td><?= (int) $t['perfect'] ?> / <?= (int) $t['good'] ?> / <?= (int) $t['miss'] ?></td>
        <td><?= (int) $t['max_combo'] ?></td>
        <td><?= htmlspecialchars($biasTxt) ?><br><small>±<?= (int) $t['timing_consistency_ms'] ?>ms</small></td>
        <td><small><?= htmlspecialchars(date('M j, Y', strtotime($t['created_at']))) ?></small></td>
        <td>
          <?php if (!empty($t['ai_feedback'])): ?>
            <details>
              <summary>View</summary>
              <div class="ai-feedback-body"><?= feedbackToHtml($t['ai_feedback']) ?></div>
            </details>
          <?php else: ?>
            <small>—</small>
          <?php endif; ?>
        </td>
        <td><a href="/admin/plan-edit.php?user=<?= (int) $t['user_id'] ?>" class="btn btn-ghost btn-xs">Edit Plan</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
