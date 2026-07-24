<?php
/**
 * ONE-TIME BACKFILL — mark pre–July-1 pending course payments as Paid.
 *
 * Targets: students enrolled BEFORE 2026-07-01 on a session-granting (paid)
 * program whose payment is not confirmed. For each: payment_status='Paid',
 * program session credits added, and (if they have a login) access days granted
 * — the same effects as clicking Approve, but with NO confirmation emails.
 *
 * Shows a preview first; nothing changes until you click the button.
 * DELETE THIS FILE after running it.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/programs.php';

$admin = requireAdmin();
$db    = db();

const BACKFILL_CUTOFF = '2026-07-01';
const BACKFILL_DAYS   = 365;   // access granted to linked accounts, like Approve's default

// Qualifying students: enrolled before the cutoff, paid program, payment unconfirmed.
$rows = $db->prepare(
    'SELECT * FROM students WHERE enrolled_at < ? ORDER BY enrolled_at ASC'
);
$rows->execute([BACKFILL_CUTOFF]);
$targets = [];
foreach ($rows->fetchAll() as $s) {
    if (creditsForProgram(trim((string) ($s['program'] ?? ''))) <= 0) continue; // web-access/trial — skip
    if (studentPaymentConfirmed($s)) continue;                                   // already paid
    $targets[] = $s;
}

$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $updated = 0; $credits = 0; $granted = 0;
    foreach ($targets as $s) {
        $sid = (int) $s['id'];
        $db->prepare("UPDATE students SET payment_status = 'Paid' WHERE id = ?")->execute([$sid]);
        $cr = creditsForProgram(trim((string) $s['program']));
        if ($cr > 0) {
            $db->prepare('UPDATE students SET session_credits = session_credits + ? WHERE id = ?')
               ->execute([$cr, $sid]);
            $credits += $cr;
        }
        if (!empty($s['user_id'])) {
            grantAdminAccess((int) $s['user_id'], BACKFILL_DAYS, (int) $admin['id'],
                'Backfill: pre-' . BACKFILL_CUTOFF . ' pending payment set to Paid');
            $granted++;
        }
        $updated++;
    }
    $done = "Done: {$updated} student(s) set to Paid · {$credits} session credit(s) added · "
          . "{$granted} account(s) granted " . BACKFILL_DAYS . " days access. No emails were sent. "
          . "You can DELETE admin/backfill-paid.php now.";
    $targets = [];   // nothing left to preview
}

$pageTitle = 'Backfill: mark pending as Paid — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>
<main class="container container--wide">
<h1>Backfill — pending payments before <?= BACKFILL_CUTOFF ?> → Paid</h1>

<?php if ($done): ?>
  <div class="alert alert-success"><?= htmlspecialchars($done) ?></div>
  <p><a class="btn btn-primary" href="/admin/students.php">← Back to Students</a></p>
<?php elseif (!$targets): ?>
  <div class="alert alert-info">No qualifying students found — every paid-program enrollee from before
    <?= BACKFILL_CUTOFF ?> is already confirmed. You can delete this file.</div>
<?php else: ?>
  <p class="muted">
    These <strong><?= count($targets) ?></strong> student(s) will be set to <strong>Paid</strong>,
    receive their program's session credits, and (if they have a login) <?= BACKFILL_DAYS ?> days access.
    <strong>No emails will be sent.</strong> Trial / Website-access records are not touched.
  </p>
  <table class="table">
    <thead><tr><th>Name</th><th>Email</th><th>Program</th><th>Status now</th><th>Enrolled</th><th>Credits to add</th><th>Account</th></tr></thead>
    <tbody>
    <?php foreach ($targets as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['full_name'] ?: trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?></td>
        <td><?= htmlspecialchars($s['email'] ?? '') ?></td>
        <td><?= htmlspecialchars($s['program'] ?? '') ?></td>
        <td><span class="badge"><?= htmlspecialchars($s['payment_status'] ?: '—') ?></span></td>
        <td><?= $s['enrolled_at'] ? date('M j, Y', strtotime($s['enrolled_at'])) : '—' ?></td>
        <td><?= creditsForProgram(trim((string) $s['program'])) ?></td>
        <td><?= !empty($s['user_id']) ? 'yes' : '— (no login: status + credits only)' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="POST" data-confirm="Set all listed students to Paid (credits + access, no emails)? This cannot be undone in one click.">
    <?= csrfField() ?>
    <button type="submit" class="btn btn-primary">✓ Confirm — set <?= count($targets) ?> student(s) to Paid</button>
    <a class="btn btn-ghost" href="/admin/students.php">Cancel</a>
  </form>
<?php endif; ?>
</main>
<script src="/assets/js/students-admin.js?v=20260712"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
