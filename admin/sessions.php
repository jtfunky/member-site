<?php
/**
 * Admin → Sessions. Set available date/time slots, see who booked what, and
 * approve session top-up payments (which add credits to a student).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/sessions.php';

$user = $admin = requireAdmin();
$db   = db();

$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'open_blocks') {
        $date   = trim($_POST['slot_date'] ?? '');
        $blocks = (array) ($_POST['blocks'] ?? []);
        $valid  = array_column(sessionBlocks(), 'start'); // allowed block start times
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Please choose a valid date.';
        } elseif (!$blocks) {
            $error = 'Select at least one time block to open.';
        } else {
            $opened = 0;
            foreach ($blocks as $start) {
                if (!in_array($start, $valid, true)) continue;      // only the fixed blocks
                $ts = strtotime("$date $start:00");
                if (!$ts || $ts <= time()) continue;                 // skip past/invalid
                $dt = date('Y-m-d H:i:s', $ts);
                if (slotAt($dt)) continue;                            // skip already-open
                addSlot($dt, SESSION_MINUTES);
                $opened++;
            }
            $message = $opened > 0
                ? "Opened {$opened} session block" . ($opened === 1 ? '' : 's') . '.'
                : 'No new blocks were opened (already open or in the past).';
            $slotDefault = $date; // keep the calendar focused on this day
        }

    } elseif ($action === 'del_slot') {
        deleteSlotIfOpen((int) ($_POST['slot_id'] ?? 0))
            ? $message = 'Slot removed.'
            : $error = 'That slot is booked — cancel the booking first.';

    } elseif ($action === 'close_all') {
        $n = closeAllOpenSlots();
        $message = "Closed {$n} open slot" . ($n === 1 ? '' : 's') . '. Booked sessions were kept.';

    } elseif ($action === 'approve_topup') {
        $tid  = (int) ($_POST['topup_id'] ?? 0);
        $info = $db->prepare('SELECT t.credits, st.email FROM session_topups t JOIN students st ON st.id = t.student_id WHERE t.id = ?');
        $info->execute([$tid]);
        $ti = $info->fetch();
        approveTopup($tid, (int) $admin['id']);
        $message = 'Top-up approved — sessions added to the student.';
        if ($ti && !empty($ti['email'])) {
            sendMail($ti['email'], 'Sessions added to your account',
                "Hi,\n\nYour payment was confirmed and " . (int) $ti['credits'] . " session(s) were added to your account.\nBook your next session here: " . SITE_URL . "/my-sessions.php\n\n— " . SITE_NAME);
        }

    } elseif ($action === 'reject_topup') {
        rejectTopup((int) ($_POST['topup_id'] ?? 0), (int) $admin['id']);
        $message = 'Top-up rejected.';
    }
}

// Calendar month + selected day (server-rendered, CSP-safe).
$slotDefault = $slotDefault ?? '';
$ym = (string) ($_GET['ym'] ?? $_POST['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = $slotDefault !== '' ? substr($slotDefault, 0, 7) : date('Y-m');
}
$selDate = (string) ($_GET['date'] ?? $_POST['date'] ?? $slotDefault);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) $selDate = '';

$monTs       = strtotime($ym . '-01');
$daysInMonth = (int) date('t', $monTs);
$startDow    = (int) date('w', $monTs);
$prevYm      = date('Y-m', strtotime('-1 month', $monTs));
$nextYm      = date('Y-m', strtotime('+1 month', $monTs));
$todayYmd    = date('Y-m-d');

$byDate = [];
foreach (adminSlotsForMonth($ym) as $s) {
    $byDate[date('Y-m-d', strtotime($s['starts_at']))][] = $s;
}

$topups = pendingTopups();

// All upcoming slots that a student has actually booked (for the overview table).
$upcoming = array_values(array_filter(adminUpcomingSlots(), fn($s) => !empty($s['booking_id'])));

function fmtSlotA(string $dt): string { return date('D, M j, Y · g:i A', strtotime($dt)); }

$pageTitle = 'Sessions — Admin';
$pageCss   = ['main', 'admin', 'calendar'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>
<main class="container">
<div class="admin-header">
  <h1>Sessions</h1>
  <div class="admin-nav-pills">
    <a href="/admin/">Overview</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/students.php">Students</a>
    <a href="/admin/sessions.php" class="active">Sessions</a>
    <a href="/admin/songs.php">Songs</a>
    <a href="/admin/placement-tests.php">Placement Tests</a>
    <a href="/admin/investor-agreement.php">Investor Agreement</a>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="two-col">
<div>
<!-- Upcoming booked sessions -->
<h2>Upcoming Booked Sessions <span class="text-sm">(<?= count($upcoming) ?>)</span></h2>
<?php if (!$upcoming): ?>
  <p class="field-hint">No upcoming bookings yet.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="data-table stack-mobile">
    <thead><tr><th>When</th><th>Student</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $s):
      $st = strtotime($s['starts_at']);
      $en = $st + (int) $s['duration_min'] * 60;
    ?>
      <tr>
        <td data-label="When"><?= htmlspecialchars(date('D, M j, Y', $st)) ?> · <?= date('g:i A', $st) ?> – <?= date('g:i A', $en) ?></td>
        <td data-label="Student"><a href="/admin/students.php?edit=<?= (int) $s['student_id'] ?>"><?= htmlspecialchars($s['student_name'] ?: 'student') ?></a></td>
        <td data-label="Status"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($s['booking_status'] ?? 'scheduled')))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
<div>
<!-- Pending top-up payments -->
<h2>Pending Payments <span class="text-sm">(<?= count($topups) ?>)</span></h2>
<?php if (!$topups): ?>
  <p class="field-hint">No pending session payments.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="data-table stack-mobile">
    <thead><tr><th>Student</th><th>Package</th><th>Sessions</th><th>Amount</th><th>Proof</th><th>Submitted</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($topups as $t): ?>
      <tr>
        <td data-label="Student"><?= htmlspecialchars($t['student_name'] ?: $t['student_email']) ?></td>
        <td data-label="Package"><?= htmlspecialchars($t['program']) ?></td>
        <td data-label="Sessions"><?= (int) $t['credits'] ?></td>
        <td data-label="Amount"><?= $t['amount'] !== null ? htmlspecialchars($t['currency'] . ' ' . number_format((float) $t['amount'], 0)) : '—' ?></td>
        <td data-label="Proof"><?php if ($t['proof_url']): ?><a href="<?= htmlspecialchars($t['proof_url']) ?>" target="_blank" rel="noopener">view ↗</a><?php else: ?>—<?php endif; ?></td>
        <td data-label="Submitted" style="white-space:nowrap"><?= htmlspecialchars(date('M j, Y', strtotime($t['created_at']))) ?></td>
        <td data-label="" style="white-space:nowrap">
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve_topup">
            <input type="hidden" name="topup_id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="btn btn-primary btn-xs">✓ Approve</button>
          </form>
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject_topup">
            <input type="hidden" name="topup_id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-xs">Reject</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
</div><!-- .two-col -->

<style>
  /* Inline (ships with the page) so it isn't affected by CSS-file caching. */
  .block-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: .6rem; margin: .5rem 0 1rem; }
  .block { display: flex; align-items: center; gap: .5rem; padding: .55rem .7rem; border: 1px solid var(--border, #2a2a3a); border-radius: 10px; }
  .block-body { display: flex; flex-direction: column; gap: .1rem; }
  .block-time { font-weight: 600; font-size: .9rem; }
  .block-status { font-size: .75rem; opacity: .75; }
  .block--pick { cursor: pointer; }
  .block--pick:hover { border-color: color-mix(in srgb, var(--primary, #7c5cff) 55%, transparent); }
  .block--open   { border-color: color-mix(in srgb, var(--primary, #7c5cff) 55%, transparent); background: color-mix(in srgb, var(--primary, #7c5cff) 12%, transparent); }
  .block--booked { border-color: color-mix(in srgb, #37b24d 55%, transparent); background: color-mix(in srgb, #37b24d 12%, transparent); }
  .block--past   { opacity: .45; }
  .block--open .btn { margin-left: auto; }
  /* Calendar on the left, the day's session blocks on the right. */
  .schedule-layout { display: flex; gap: 1.75rem; align-items: flex-start; flex-wrap: wrap; }
  .schedule-layout .cal-wrap { flex: 1 1 380px; max-width: 460px; margin-bottom: 0; }
  .schedule-blocks { flex: 1 1 320px; min-width: 280px; }
  .schedule-blocks > h3:first-child { margin-top: 0; }
  /* Upcoming bookings + Pending payments side by side. */
  .two-col { display: flex; gap: 1.75rem; flex-wrap: wrap; align-items: flex-start; }
  .two-col > div { flex: 1 1 320px; min-width: 280px; }
  .two-col > div > h2:first-child { margin-top: 0; }
  /* Phones: force a single full-width column so nothing overflows sideways. */
  @media (max-width: 640px) {
    .two-col > div,
    .schedule-blocks,
    .schedule-layout .cal-wrap { flex-basis: 100%; max-width: 100%; min-width: 0; }
    /* Wide tables become stacked cards — each row a card, no sideways scrolling. */
    .stack-mobile thead { display: none; }
    .stack-mobile, .stack-mobile tbody, .stack-mobile tr, .stack-mobile td { display: block; width: 100%; }
    .stack-mobile tr { border: 1px solid var(--border, #2a2a3a); border-radius: 10px; padding: .4rem .75rem; margin-bottom: .75rem; }
    .stack-mobile td { border: none; padding: .3rem 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem; text-align: right; }
    .stack-mobile td::before { content: attr(data-label); font-weight: 600; opacity: .65; text-align: left; white-space: nowrap; }
    .stack-mobile td[data-label=""]::before { content: ""; }
  }
</style>

<!-- Schedule calendar -->
<div class="admin-header" style="margin-top:2rem;">
  <h2>Schedule</h2>
  <form method="POST" data-confirm="Remove ALL open (unbooked) slots? Booked sessions are kept.">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="close_all">
    <button type="submit" class="btn btn-ghost btn-sm">Close all open slots</button>
  </form>
</div>
<div class="schedule-layout">
<div class="cal-wrap">
  <div class="cal-topbar">
    <a class="btn btn-ghost btn-xs" href="?ym=<?= $prevYm ?>" aria-label="Previous month">←</a>
    <h3><?= date('F Y', $monTs) ?></h3>
    <a class="btn btn-ghost btn-xs" href="?ym=<?= $nextYm ?>" aria-label="Next month">→</a>
  </div>
  <div class="cal-grid">
    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
      <div class="cal-dow"><?= $dow ?></div>
    <?php endforeach; ?>
    <?php for ($i = 0; $i < $startDow; $i++): ?><div class="cal-day cal-day--blank"></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
      $date     = sprintf('%s-%02d', $ym, $d);
      $daySlots = $byDate[$date] ?? [];
      $total    = count($daySlots);
      $booked   = 0; foreach ($daySlots as $ds) { if ($ds['booking_id']) $booked++; }
      $past     = $date < $todayYmd;
      $cls = 'cal-day';
      if ($past)              $cls .= ' cal-day--muted';
      elseif ($total)         $cls .= ' cal-day--has';
      if ($date === $selDate) $cls .= ' cal-day--active';
    ?>
      <?php if (!$past): // any future day is clickable, so the admin can open blocks on it ?>
        <a class="<?= $cls ?>" href="?ym=<?= $ym ?>&amp;date=<?= $date ?>"><?= $d ?><?php if ($total): ?><span class="cal-badge"><?= $booked ?>/<?= $total ?></span><?php endif; ?></a>
      <?php else: ?>
        <div class="<?= $cls ?>"><?= $d ?></div>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <p class="field-hint" style="margin-top:.5rem;">Badge shows <strong>booked / total</strong> slots that day.</p>
</div>

<div class="schedule-blocks">
<?php if ($selDate):
    $daySlots = $byDate[$selDate] ?? [];
    $slotByStart = [];
    foreach ($daySlots as $ds) { $slotByStart[date('H:i', strtotime($ds['starts_at']))] = $ds; }
    $isPastDay = $selDate < $todayYmd;
?>
  <h3 style="margin-top:1.5rem;">Sessions on <?= htmlspecialchars(date('D, M j, Y', strtotime($selDate))) ?></h3>
  <p class="field-hint">Each session is <?= SESSION_MINUTES ?> minutes. Tick the blocks you want to open, then save.
    Booked blocks can't be closed here.</p>

  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action"    value="open_blocks">
    <input type="hidden" name="ym"        value="<?= htmlspecialchars($ym) ?>">
    <input type="hidden" name="date"      value="<?= htmlspecialchars($selDate) ?>">
    <input type="hidden" name="slot_date" value="<?= htmlspecialchars($selDate) ?>">
    <div class="block-grid">
      <?php foreach (sessionBlocks() as $b):
        $existing = $slotByStart[$b['start']] ?? null;
        $isPast   = strtotime("$selDate {$b['start']}") <= time();
      ?>
        <?php if ($existing && $existing['booking_id']): ?>
          <div class="block block--booked">
            <div class="block-body">
              <span class="block-time"><?= htmlspecialchars($b['label']) ?></span>
              <span class="block-status">Booked — <a href="/admin/students.php?edit=<?= (int) $existing['student_id'] ?>"><?= htmlspecialchars($existing['student_name'] ?: 'student') ?></a></span>
            </div>
          </div>
        <?php elseif ($existing): ?>
          <div class="block block--open">
            <div class="block-body">
              <span class="block-time"><?= htmlspecialchars($b['label']) ?></span>
              <span class="block-status">Open</span>
            </div>
            <button type="submit" form="close-<?= (int) $existing['id'] ?>" class="btn btn-ghost btn-xs">Close</button>
          </div>
        <?php elseif ($isPast): ?>
          <div class="block block--past">
            <div class="block-body">
              <span class="block-time"><?= htmlspecialchars($b['label']) ?></span>
              <span class="block-status">Past</span>
            </div>
          </div>
        <?php else: ?>
          <label class="block block--pick">
            <input type="checkbox" name="blocks[]" value="<?= htmlspecialchars($b['start']) ?>">
            <div class="block-body">
              <span class="block-time"><?= htmlspecialchars($b['label']) ?></span>
              <span class="block-status">Tap to open</span>
            </div>
          </label>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php if (!$isPastDay): ?>
      <button type="submit" class="btn btn-primary">Open selected blocks</button>
    <?php endif; ?>
  </form>

  <?php // Hidden close forms per open block — referenced by the Close buttons (avoids nested forms). ?>
  <?php foreach ($daySlots as $ds): if ($ds['booking_id']) continue; ?>
    <form id="close-<?= (int) $ds['id'] ?>" method="POST" style="display:none" data-confirm="Close this open block?">
      <?= csrfField() ?>
      <input type="hidden" name="action"  value="del_slot">
      <input type="hidden" name="slot_id" value="<?= (int) $ds['id'] ?>">
      <input type="hidden" name="ym"      value="<?= htmlspecialchars($ym) ?>">
      <input type="hidden" name="date"    value="<?= htmlspecialchars($selDate) ?>">
    </form>
  <?php endforeach; ?>
<?php else: ?>
  <p class="field-hint">Pick a day on the calendar above to open <?= SESSION_MINUTES ?>-minute session blocks.</p>
<?php endif; ?>
</div><!-- .schedule-blocks -->
</div><!-- .schedule-layout -->

</main>
<script src="/assets/js/sessions-admin.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
