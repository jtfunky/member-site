<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sessions.php';

$user = requireLogin();

// The enrollment record for this account (linked by id, or matched by email).
$sq = db()->prepare('SELECT * FROM students WHERE user_id = ? OR email = ? ORDER BY user_id IS NULL LIMIT 1');
$sq->execute([$user['id'], $user['email']]);
$student = $sq->fetch() ?: null;

$message = ''; $error = '';

if ($student && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $sname  = trim(($student['full_name'] ?? '') ?: (($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
    if ($action === 'book') {
        $r = bookSlot((int) ($_POST['slot_id'] ?? 0), $student);
        if ($r === true) {
            $message = 'Session booked. See you there!';
            $slot = slotById((int) ($_POST['slot_id'] ?? 0));
            $when = $slot ? date('D, M j, Y · g:i A', strtotime($slot['starts_at'])) : '';
            notifyAdmins('New session booked', "$sname booked a session for $when.");
            sendMail($user['email'], 'Your session is booked',
                "Hi,\n\nYour session is booked for $when.\nYou can reschedule up to " . RESCHEDULE_CUTOFF_HOURS . " hours before it.\n\n— " . SITE_NAME);
        } else { $error = $r; }
    } elseif ($action === 'reschedule') {
        $r = rescheduleBooking((int) ($_POST['booking_id'] ?? 0), (int) ($_POST['slot_id'] ?? 0), $student);
        if ($r === true) {
            $message = 'Session rescheduled.';
            $slot = slotById((int) ($_POST['slot_id'] ?? 0));
            $when = $slot ? date('D, M j, Y · g:i A', strtotime($slot['starts_at'])) : '';
            notifyAdmins('Session rescheduled', "$sname rescheduled a session to $when.");
            sendMail($user['email'], 'Your session was rescheduled',
                "Hi,\n\nYour session is now set for $when.\n\n— " . SITE_NAME);
        } else { $error = $r; }
    } elseif ($action === 'cancel') {
        $bk   = bookingById((int) ($_POST['booking_id'] ?? 0));
        $when = $bk ? date('D, M j, Y · g:i A', strtotime($bk['starts_at'])) : '';
        $r = cancelBooking((int) ($_POST['booking_id'] ?? 0), $student);
        if ($r === true) {
            $message = 'Session cancelled — your credit was refunded.';
            notifyAdmins('Session cancelled', "$sname cancelled a session" . ($when ? " ($when)" : '') . '.');
            sendMail($user['email'], 'Your session was cancelled',
                "Hi,\n\nYour session" . ($when ? " on $when" : '') . " has been cancelled and your credit refunded.\n\n— " . SITE_NAME);
        } else { $error = $r; }
    }
    // Re-fetch so the credit count and lists reflect the change.
    $sq->execute([$user['id'], $user['email']]);
    $student = $sq->fetch() ?: $student;
}

$credits  = $student ? (int) $student['session_credits'] : 0;
$open     = openSlots();
$upcoming = $student ? studentBookings((int) $student['id'], true)  : [];
$past     = $student ? studentBookings((int) $student['id'], false) : [];

// ── Calendar state (server-side; no JS, CSP-safe) ──
$slotsByDate = [];
foreach ($open as $s) { $slotsByDate[date('Y-m-d', strtotime($s['starts_at']))][] = $s; }

$ym = (string) ($_GET['ym'] ?? $_POST['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = $open ? date('Y-m', strtotime($open[0]['starts_at'])) : date('Y-m');
}
$monTs       = strtotime($ym . '-01');
$daysInMonth = (int) date('t', $monTs);
$startDow    = (int) date('w', $monTs);        // 0 = Sunday
$prevYm      = date('Y-m', strtotime('-1 month', $monTs));
$nextYm      = date('Y-m', strtotime('+1 month', $monTs));
$todayYmd    = date('Y-m-d');

$selDate = (string) ($_GET['date'] ?? $_POST['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) $selDate = '';

function fmtSlot(string $dt): string { return date('D, M j, Y · g:i A', strtotime($dt)); }

$pageTitle = 'My Sessions — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard', 'calendar'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>
<main class="container">

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="admin-header"><h1>My Sessions</h1></div>

<?php if (!$student): ?>
  <div class="alert alert-info">
    We couldn't find an enrollment for your account.
    <a href="/student-signup.php">Enroll now</a> to get started.
  </div>
<?php else: ?>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-value"><?= $credits ?></div>
      <div class="stat-label">Session credits left</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= count($upcoming) ?></div>
      <div class="stat-label">Upcoming sessions</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= htmlspecialchars($student['program'] ?: '—') ?></div>
      <div class="stat-label">Program</div>
    </div>
  </div>

  <!-- Book a new session -->
  <h2 id="book">Book a Session</h2>
  <?php if ($credits <= 0): ?>
    <div class="alert alert-warning">
      You have <strong>no session credits left</strong>. To book more sessions, please
      <a href="/session-payment.php">make a payment and upload your proof →</a>
      Once an admin confirms it, new sessions will be added.
    </div>
  <?php elseif (!$open): ?>
    <div class="alert alert-info">No available slots right now. Please check back later.</div>
  <?php else: ?>
    <p class="field-hint" style="margin-bottom:.75rem;">Booking uses 1 credit. You can reschedule or cancel up to <?= RESCHEDULE_CUTOFF_HOURS ?> hours before a session — after that, or if you don't show up, the credit is still used.</p>
    <style>
      /* Calendar on the left, available times on the right. */
      .book-layout { display: flex; gap: 1.75rem; align-items: flex-start; flex-wrap: wrap; }
      .book-layout .cal-wrap { flex: 1 1 380px; max-width: 460px; margin-bottom: 0; }
      .book-times { flex: 1 1 300px; min-width: 260px; }
      .book-times > h3:first-child { margin-top: 0; }
      @media (max-width: 640px) {
        .book-layout .cal-wrap, .book-times { flex-basis: 100%; max-width: 100%; min-width: 0; }
      }
    </style>
    <div class="book-layout">
    <div class="cal-wrap">
      <div class="cal-topbar">
        <a class="btn btn-ghost btn-xs" href="?ym=<?= $prevYm ?>#book" aria-label="Previous month">←</a>
        <h3><?= date('F Y', $monTs) ?></h3>
        <a class="btn btn-ghost btn-xs" href="?ym=<?= $nextYm ?>#book" aria-label="Next month">→</a>
      </div>
      <div class="cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
          <div class="cal-dow"><?= $dow ?></div>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $startDow; $i++): ?><div class="cal-day cal-day--blank"></div><?php endfor; ?>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
          $date = sprintf('%s-%02d', $ym, $d);
          $has  = !empty($slotsByDate[$date]);
          $past = $date < $todayYmd;
          $cls  = 'cal-day';
          if ($past)                 $cls .= ' cal-day--muted';   // gone
          elseif ($has)              $cls .= ' cal-day--has';     // opened by admin
          else                       $cls .= ' cal-day--taken';   // closed by default
          if ($date === $selDate)    $cls .= ' cal-day--active';
        ?>
          <?php if ($has && !$past): ?>
            <a class="<?= $cls ?>" href="?ym=<?= $ym ?>&amp;date=<?= $date ?>#book"><?= $d ?><span class="cal-dot"></span></a>
          <?php else: ?>
            <div class="<?= $cls ?>"><?= $d ?></div>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
      <p class="field-hint" style="margin-top:.5rem;">Only <strong>highlighted</strong> dates are open for booking. All other dates are closed until the instructor opens them.</p>
    </div>

    <div class="book-times">
    <?php if ($selDate && !empty($slotsByDate[$selDate])): ?>
      <h3 style="margin:.25rem 0 .5rem;">Times on <?= htmlspecialchars(date('D, M j, Y', strtotime($selDate))) ?></h3>
      <div class="cal-times">
        <?php foreach ($slotsByDate[$selDate] as $s): ?>
          <form method="POST" data-confirm="Cancellations are allowed up to <?= RESCHEDULE_CUTOFF_HOURS ?> hours before the session. Failing to cancel on time, or being absent, will still deduct one session from your available credits. Book this time?">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="book">
            <input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
            <input type="hidden" name="ym"      value="<?= htmlspecialchars($ym) ?>">
            <input type="hidden" name="date"    value="<?= htmlspecialchars($selDate) ?>">
            <button type="submit" class="btn btn-primary btn-sm"><?= date('g:i A', strtotime($s['starts_at'])) ?> – <?= date('g:i A', strtotime($s['starts_at']) + (int) $s['duration_min'] * 60) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php elseif ($selDate): ?>
      <p class="field-hint">No open times on that day.</p>
    <?php else: ?>
      <p class="field-hint">Pick a highlighted date to see available times.</p>
    <?php endif; ?>
    </div><!-- .book-times -->
    </div><!-- .book-layout -->
  <?php endif; ?>

  <!-- Upcoming sessions -->
  <h2>Upcoming Sessions</h2>
  <?php if (!$upcoming): ?>
    <p class="field-hint">You have no upcoming sessions booked.</p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Date &amp; Time</th><th>Duration</th><th>Manage</th></tr></thead>
      <tbody>
      <?php foreach ($upcoming as $b): ?>
        <tr>
          <td><?= htmlspecialchars(fmtSlot($b['starts_at'])) ?></td>
          <td><?= (int) $b['duration_min'] ?> min</td>
          <td>
            <?php if (!canReschedule($b['starts_at'])): ?>
              <span class="field-hint">Locked (within <?= RESCHEDULE_CUTOFF_HOURS ?>h)</span>
            <?php else: ?>
              <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <?php $others = array_filter($open, fn($s) => (int) $s['id'] !== (int) $b['slot_id']); ?>
                <?php if ($others): ?>
                  <form method="POST" class="inline-form" style="display:flex;gap:.5rem;align-items:center;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reschedule">
                    <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                    <select name="slot_id" required>
                      <option value="">Move to…</option>
                      <?php foreach ($others as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars(fmtSlot($s['starts_at'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-xs">Reschedule</button>
                  </form>
                <?php endif; ?>
                <form method="POST" data-confirm="Cancel this session? Your credit will be refunded." style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-xs">Cancel</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Past sessions -->
  <?php if ($past): ?>
    <h2>Past Sessions</h2>
    <table class="data-table">
      <thead><tr><th>Date &amp; Time</th><th>Duration</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($past as $b): ?>
        <tr>
          <td><?= htmlspecialchars(fmtSlot($b['starts_at'])) ?></td>
          <td><?= (int) $b['duration_min'] ?> min</td>
          <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $b['status']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php endif; ?>

</main>
<script src="/assets/js/my-sessions.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
