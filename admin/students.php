<?php
/**
 * Admin → Students. View, search, edit and delete enrollment records
 * imported from the Google-Form exports (see import-students.sql).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/programs.php';

$user = $admin = requireAdmin();
$db   = db();

$message = '';
$error   = '';

// The students table is created by import-students.sql. If it isn't there yet,
// show a friendly notice instead of a fatal error.
$hasTable = (bool) $db->query("SHOW TABLES LIKE 'students'")->fetch();

// ── Handle form submissions ────────────────────────────────
if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['student_id'] ?? 0);
        $db->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
        $message = 'Enrollment record deleted.';

    } elseif ($action === 'bulk_delete') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($i) => $i > 0));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM students WHERE id IN ($ph)")->execute($ids);
            $n = count($ids);
            $message = "$n enrollment record" . ($n !== 1 ? 's' : '') . ' deleted.';
        } else {
            $message = 'No students were selected.';
        }

    } elseif ($action === 'remind') {
        // Email one enrollee a payment reminder.
        $sid = (int) ($_POST['student_id'] ?? 0);
        $st  = $db->prepare('SELECT * FROM students WHERE id = ?');
        $st->execute([$sid]);
        $s = $st->fetch();
        if ($s && sendPaymentReminder($s)) {
            $message = 'Payment reminder sent to ' . $s['email'] . '.';
        } else {
            $error = 'Could not send the reminder — the student has no valid email, or the mailer failed.';
        }

    } elseif ($action === 'remind_pending') {
        // Email every enrollee whose course payment is still unconfirmed.
        // Pending = a session-granting (paid) program + payment not confirmed —
        // the same predicate as the site-wide "payment pending" banner.
        $rows = $db->query('SELECT * FROM students WHERE email IS NOT NULL AND email <> ""')->fetchAll();
        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($rows as $s) {
            if (creditsForProgram(trim((string) ($s['program'] ?? ''))) <= 0) continue; // not a paid course
            if (studentPaymentConfirmed($s)) continue;                                   // already paid
            // Don't double-send within ~20h (when the tracking column exists).
            $last = $s['payment_reminded_at'] ?? null;
            if ($last && (time() - strtotime($last)) < 20 * 3600) { $skipped++; continue; }
            if (sendPaymentReminder($s)) $sent++; else $failed++;
        }
        $message = "Payment reminders sent: {$sent}"
                 . ($skipped ? " · skipped {$skipped} (already reminded today)" : '')
                 . ($failed ? " · {$failed} failed" : '');

    } elseif ($action === 'update') {
        $id    = (int) ($_POST['student_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $sur   = trim($_POST['last_name'] ?? '');
        $first = trim($_POST['first_name'] ?? '');
        $mi    = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_POST['middle_initial'] ?? ''), 0, 1));
        $dobIn = trim($_POST['date_of_birth'] ?? '');
        $amtIn = trim($_POST['program_amount'] ?? '');

        // Date of birth is required; derive age from it ('!' zeroes the time).
        $dob = null; $age = null;
        $d = $dobIn !== '' ? DateTime::createFromFormat('!Y-m-d', $dobIn) : false;
        if ($d && $d->format('Y-m-d') === $dobIn && $d <= new DateTime('today')) {
            $dob = $dobIn;
            $age = (int) $d->diff(new DateTime('today'))->y;
        }

        if ($sur === '' || $first === '') {
            $error = 'Last Name and First Name are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($dob === null) {
            $error = 'Please enter a valid date of birth.';
        } else {
            $amt  = ($amtIn !== '' && is_numeric($amtIn)) ? (float) $amtIn : null;
            // Rebuild the display name from the parts: "Surname, First M.I."
            $full = $sur . ', ' . $first . ($mi !== '' ? ' ' . $mi : '');
            // Parent/guardian only applies to minors (under 18).
            $guardian = ($age < 18) ? trim($_POST['parent_guardian'] ?? '') : '';
            // Combine country code + national number into the phone column,
            // stored as "+63 9171234567" so it can be split cleanly when editing.
            $dial  = preg_replace('/[^\d+]/', '', $_POST['country_code'] ?? '');
            $num   = preg_replace('/\D/', '', $_POST['phone'] ?? '');
            $phone = $num !== '' ? trim($dial . ' ' . $num) : '';
            $db->prepare(
                'UPDATE students SET
                   first_name=?, last_name=?, middle_initial=?, full_name=?, email=?,
                   date_of_birth=?, age=?, parent_guardian=?, address=?, phone=?, experience=?,
                   program=?, program_amount=?, currency=?, proof_url=?,
                   payment_status=?, enrolled_at=?
                 WHERE id=?'
            )->execute([
                $first,
                $sur,
                $mi,
                $full,
                $email,
                $dob,
                $age,
                $guardian,
                trim($_POST['address'] ?? ''),
                $phone,
                trim($_POST['experience'] ?? ''),
                trim($_POST['program'] ?? ''),
                $amt,
                trim($_POST['currency'] ?? 'PHP') ?: 'PHP',
                trim($_POST['proof_url'] ?? ''),
                trim($_POST['payment_status'] ?? ''),
                (trim($_POST['enrolled_at'] ?? '') !== '') ? trim($_POST['enrolled_at']) : null,
                $id,
            ]);
            $message = 'Enrollment updated.';
        }

    } elseif ($action === 'create') {
        $email = trim($_POST['email'] ?? '');
        $sur   = trim($_POST['last_name'] ?? '');
        $first = trim($_POST['first_name'] ?? '');
        $mi    = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_POST['middle_initial'] ?? ''), 0, 1));
        $dobIn = trim($_POST['date_of_birth'] ?? '');
        $amtIn = trim($_POST['program_amount'] ?? '');

        // Date of birth is required; derive age from it ('!' zeroes the time).
        $dob = null; $age = null;
        $d = $dobIn !== '' ? DateTime::createFromFormat('!Y-m-d', $dobIn) : false;
        if ($d && $d->format('Y-m-d') === $dobIn && $d <= new DateTime('today')) {
            $dob = $dobIn;
            $age = (int) $d->diff(new DateTime('today'))->y;
        }

        if ($sur === '' || $first === '') {
            $error = 'Last Name and First Name are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($dob === null) {
            $error = 'Please enter a valid date of birth.';
        } else {
            $amt  = ($amtIn !== '' && is_numeric($amtIn)) ? (float) $amtIn : null;
            $full = $sur . ', ' . $first . ($mi !== '' ? ' ' . $mi : '');
            $guardian = ($age < 18) ? trim($_POST['parent_guardian'] ?? '') : '';
            $dial  = preg_replace('/[^\d+]/', '', $_POST['country_code'] ?? '');
            $num   = preg_replace('/\D/', '', $_POST['phone'] ?? '');
            $phone = $num !== '' ? trim($dial . ' ' . $num) : '';
            $enrolled = (trim($_POST['enrolled_at'] ?? '') !== '')
                ? trim($_POST['enrolled_at']) : date('Y-m-d H:i:s');
            $db->prepare(
                'INSERT INTO students
                   (first_name, last_name, middle_initial, full_name, email,
                    date_of_birth, age, parent_guardian, address, phone, experience,
                    program, program_amount, currency, proof_url, payment_status,
                    enrolled_at, source_file)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"admin")'
            )->execute([
                $first,
                $sur,
                $mi,
                $full,
                $email,
                $dob,
                $age,
                $guardian,
                trim($_POST['address'] ?? ''),
                $phone,
                trim($_POST['experience'] ?? ''),
                trim($_POST['program'] ?? ''),
                $amt,
                trim($_POST['currency'] ?? 'PHP') ?: 'PHP',
                trim($_POST['proof_url'] ?? ''),
                trim($_POST['payment_status'] ?? ''),
                $enrolled,
            ]);
            $message = 'New student enrollment created.';
        }

    } elseif ($action === 'grant') {
        $uid  = (int) ($_POST['user_id'] ?? 0);
        $days = (int) ($_POST['grant_days'] ?? 30);
        $note = trim($_POST['note'] ?? '');
        if ($uid) {
            grantAdminAccess($uid, $days, (int) $admin['id'], $note);
            $message = "Granted {$days} days access.";
        }

    } elseif ($action === 'approve') {
        // Confirm an enrollee's payment: mark the enrollment Paid and grant
        // their linked login account access.
        $uid  = (int) ($_POST['user_id'] ?? 0);
        $sid  = (int) ($_POST['student_id'] ?? 0);
        $days = (int) ($_POST['grant_days'] ?? 365);
        if ($uid && $sid) {
            // Only grant session credits on the first approval (unpaid → paid),
            // so re-clicking Approve doesn't stack credits.
            $cur = $db->prepare('SELECT program, payment_status, email FROM students WHERE id = ?');
            $cur->execute([$sid]);
            $row = $cur->fetch();
            $wasPaid = $row && in_array($row['payment_status'], ['Yes','yes','Paid','paid','Done','done'], true);

            $db->prepare("UPDATE students SET payment_status = 'Paid' WHERE id = ?")->execute([$sid]);
            grantAdminAccess($uid, $days, (int) $admin['id'], 'Enrollment payment confirmed.');

            $cr = 0;
            if (!$wasPaid) {
                $cr = creditsForProgram((string) ($row['program'] ?? ''));
                if ($cr > 0) {
                    $db->prepare('UPDATE students SET session_credits = session_credits + ? WHERE id = ?')
                       ->execute([$cr, $sid]);
                }
            }
            $message = "Payment confirmed — {$days} days access"
                     . ($cr > 0 ? " + {$cr} session credit" . ($cr === 1 ? '' : 's') : '') . ' granted.';

            if (!empty($row['email'])) {
                sendMail($row['email'], 'Your enrollment is confirmed',
                    "Hi,\n\nYour payment has been confirmed and your access is now active"
                    . ($cr > 0 ? " with {$cr} session credit" . ($cr === 1 ? '' : 's') : '') . ".\n"
                    . 'Book your sessions here: ' . SITE_URL . "/my-sessions.php\n\n— " . SITE_NAME);
            }
        }

    } elseif ($action === 'set_credits') {
        $sid = (int) ($_POST['student_id'] ?? 0);
        $n   = max(0, (int) ($_POST['session_credits'] ?? 0));
        if ($sid) {
            $db->prepare('UPDATE students SET session_credits = ? WHERE id = ?')->execute([$n, $sid]);
            $message = 'Session credits updated.';
        }

    } elseif ($action === 'reset_password') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $np  = trim($_POST['new_password'] ?? '');
        if (strlen($np) >= 8) {
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($np, PASSWORD_BCRYPT), $uid]);
            $message = 'Password updated.';
        } else {
            $error = 'Password must be at least 8 characters.';
        }

    } elseif ($action === 'deactivate') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid === (int) $admin['id']) {
            $error = 'You cannot deactivate your own account.';
        } else {
            $db->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$uid]);
            $message = 'Account deactivated.';
        }

    } elseif ($action === 'activate') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $db->prepare('UPDATE users SET is_active=1 WHERE id=?')->execute([$uid]);
        $message = 'Account reactivated.';

    } elseif ($action === 'set_status') {
        $uid    = (int) ($_POST['user_id'] ?? 0);
        $status = $_POST['subscription_status'] ?? '';
        if (!in_array($status, ['pending', 'trial', 'active', 'cancelled', 'expired'], true)) {
            $error = 'Invalid status.';
        } elseif ($uid) {
            $db->prepare('UPDATE users SET subscription_status = ? WHERE id = ?')->execute([$status, $uid]);
            $message = 'Status updated to ' . $status . '.';
        }
    }
}

// ── Load data ──────────────────────────────────────────────
$students = [];
$total    = 0;
$stats    = ['count' => 0, 'paid' => 0, 'linked' => 0];
$q        = trim($_GET['q'] ?? '');
$level    = trim($_GET['level'] ?? '');
if (!in_array($level, ['Beginner', 'Intermediate', 'Advanced'], true)) $level = '';
$program  = trim($_GET['program'] ?? '');
$validPrograms = array_merge(array_keys(coursePrograms()), [WEB_ACCESS_PROGRAM]);
if ($program !== '' && !in_array($program, $validPrograms, true)) $program = '';
$perPage  = 25;
$page     = max(1, (int) ($_GET['p'] ?? 1));
$pages    = 1;

$editId      = (int) ($_GET['edit'] ?? 0);
$creating    = isset($_GET['new']);
$editStudent = null;
$linkedUser  = null;

if ($hasTable) {
    $stats['count']  = (int) $db->query('SELECT COUNT(*) FROM students')->fetchColumn();
    $stats['paid']   = (int) $db->query(
        "SELECT COUNT(*) FROM students WHERE payment_status IN ('Yes','yes','Paid','paid','Done','done')"
    )->fetchColumn();
    $stats['linked'] = (int) $db->query('SELECT COUNT(*) FROM students WHERE user_id IS NOT NULL')->fetchColumn();

    if ($editId) {
        $st = $db->prepare('SELECT * FROM students WHERE id = ?');
        $st->execute([$editId]);
        $editStudent = $st->fetch();
        // Imported rows may store NULL for blank fields; treat as '' for display.
        if ($editStudent) {
            foreach ($editStudent as $k => $v) { if ($v === null) $editStudent[$k] = ''; }
        }
        // Pull the linked login account (for grant / reset / deactivate controls).
        if ($editStudent && $editStudent['user_id']) {
            $lu = $db->prepare(
                'SELECT id, username, is_active, access_expires_at, subscription_status
                 FROM users WHERE id = ?'
            );
            $lu->execute([(int) $editStudent['user_id']]);
            $linkedUser = $lu->fetch() ?: null;
        }
    }

    // Blank template so the same form markup can render an "Add Student" panel.
    if ($creating && !$editStudent) {
        $editStudent = [
            'id' => 0, 'user_id' => null, 'enrolled_at' => '',
            'email' => '', 'last_name' => '', 'first_name' => '',
            'middle_initial' => '', 'full_name' => '', 'age' => '',
            'date_of_birth' => '', 'parent_guardian' => '', 'address' => '',
            'phone' => '', 'experience' => '', 'program' => '',
            'program_amount' => '', 'currency' => 'PHP', 'proof_url' => '',
            'payment_status' => '',
        ];
    }

    // Optional search + level filter, shared by the count and the page query.
    $clauses = [];
    $params  = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $clauses[] = '(full_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?
                       OR email LIKE ? OR program LIKE ? OR phone LIKE ?)';
        $params = array_merge($params, array_fill(0, 6, $like));
    }
    if ($level !== '') {
        $clauses[] = 'experience = ?';
        $params[]  = $level;
    }
    if ($program !== '') {
        $clauses[] = 'program = ?';
        $params[]  = $program;
    }
    $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

    $cst = $db->prepare("SELECT COUNT(*) FROM students $where");
    $cst->execute($params);
    $total = (int) $cst->fetchColumn();

    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // $perPage and $offset are integers we control, so inlining is injection-safe
    // (and sidesteps the native-prepare LIMIT-binding pitfall).
    $lst = $db->prepare("SELECT * FROM students $where ORDER BY enrolled_at DESC, id DESC LIMIT $perPage OFFSET $offset");
    $lst->execute($params);
    $students = $lst->fetchAll();
}

// Email a payment reminder to a pending enrollee and record when it was sent.
// Returns true once the mail is handed to the transport.
function sendPaymentReminder(array $s): bool {
    if (empty($s['email']) || !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) return false;
    $first  = trim((string) ($s['first_name'] ?? '')) ?: 'there';
    $prog   = trim((string) ($s['program'] ?? '')) ?: 'your enrollment';
    $amount = ($s['program_amount'] ?? '') !== '' && $s['program_amount'] !== null
        ? trim(($s['currency'] ?? '') . ' ' . number_format((float) $s['program_amount'], 0))
        : '';
    $body = "Hi {$first},\n\n"
        . "Just a friendly reminder that your enrollment payment for \"{$prog}\""
        . ($amount !== '' ? " ({$amount})" : '') . " is still pending.\n\n"
        . "Once you've paid, please submit your proof of payment here so we can confirm it and activate your sessions:\n"
        . SITE_URL . "/my-membership.php\n\n"
        . "Already settled it? Please disregard this email — we may simply not have confirmed it yet.\n\n"
        . "Thank you!\n— " . SITE_NAME;

    $ok = sendMail($s['email'], SITE_NAME . ' — payment reminder', $body);
    if ($ok) {
        try {
            db()->prepare('UPDATE students SET payment_reminded_at = NOW() WHERE id = ?')
                ->execute([(int) $s['id']]);
        } catch (\Throwable $e) { /* tracking column not migrated — the mail still went out */ }
    }
    return $ok;
}

function moneyFmt(?string $amount, string $currency): string {
    if ($amount === null || $amount === '') return '—';
    $sym = $currency === 'PHP' ? '₱' : ($currency === 'USD' ? '$' : '');
    return $sym . number_format((float) $amount, 0);
}

function studentPageUrl(int $n, string $q): string {
    global $level, $program;
    $p = ['p' => $n];
    if ($q !== '') $p['q'] = $q;
    if ($level !== '') $p['level'] = $level;
    if ($program !== '') $p['program'] = $program;
    return '/admin/students.php?' . http_build_query($p);
}

$pageTitle = 'Students — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Students</h1>
  <div class="admin-nav-pills">
    <a href="/admin/">Overview</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/students.php" class="active">Students</a>
    <a href="/admin/sessions.php">Sessions</a>
    <a href="/admin/songs.php">Songs</a>
    <a href="/admin/placement-tests.php">Placement Tests</a>
    <a href="/admin/investor-agreement.php">Investor Agreement</a>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$hasTable): ?>
  <div class="alert alert-error">
    The <code>students</code> table doesn't exist yet. Import
    <code>import-students.sql</code> via phpMyAdmin first, then reload this page.
  </div>
<?php else: ?>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-value"><?= $stats['count'] ?></div><div class="stat-label">Enrollments</div></div>
  <div class="stat-card"><div class="stat-value"><?= $stats['paid'] ?></div><div class="stat-label">Marked Paid</div></div>
  <div class="stat-card"><div class="stat-value"><?= $stats['linked'] ?></div><div class="stat-label">Linked to Account</div></div>
</div>

<!-- Edit panel -->
<?php if ($editStudent): ?>
<div class="user-detail-card">
  <div class="user-detail-header">
    <h2><?= $creating ? 'Add Student' : 'Edit Enrollment' ?></h2>
    <?php if (!$creating && $editStudent['user_id']): ?>
      <a href="/admin/users.php?edit=<?= (int) $editStudent['user_id'] ?>" class="btn btn-ghost btn-sm">View login account →</a>
    <?php endif; ?>
    <a href="/admin/students.php" class="btn btn-ghost btn-sm">← Back</a>
  </div>

  <form method="POST" class="inline-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="<?= $creating ? 'create' : 'update' ?>">
    <?php if (!$creating): ?><input type="hidden" name="student_id" value="<?= (int) $editStudent['id'] ?>"><?php endif; ?>

    <div class="form-row">
      <div class="form-group"><label>Last Name *</label><input type="text" name="last_name" required value="<?= htmlspecialchars($editStudent['last_name']) ?>"></div>
      <div class="form-group"><label>First Name *</label><input type="text" name="first_name" required value="<?= htmlspecialchars($editStudent['first_name']) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Middle Initial</label><input type="text" name="middle_initial" maxlength="1" pattern="[A-Za-z]" title="A single letter" style="text-transform:uppercase" value="<?= htmlspecialchars($editStudent['middle_initial'] ?? '') ?>"></div>
      <div class="form-group"><label>Email *</label><input type="email" name="email" required value="<?= htmlspecialchars($editStudent['email']) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Date of Birth *</label><input type="date" id="dobField" name="date_of_birth" required value="<?= htmlspecialchars($editStudent['date_of_birth'] ?? '') ?>"></div>
      <?php
        // Split a stored "+63 9171234567" into dial + number; otherwise default
        // the dial code from the visitor's location (falls back to PH).
        $phoneRaw = $editStudent['phone'] ?? '';
        if (preg_match('/^(\+\d{1,4})\s+(.*)$/', $phoneRaw, $pm)) {
            $curDial = $pm[1]; $curNum = $pm[2];
        } else {
            $curDial = dialByIso(getUserCountry()) ?: '+63';
            $curNum  = $phoneRaw;
        }
      ?>
      <div class="form-group">
        <label>Mobile Number</label>
        <div style="display:flex; gap:.5rem;">
          <select name="country_code" style="flex:0 0 auto; max-width:11rem;">
            <?php $picked = false; foreach (getCountries() as $c):
                $sel = (!$picked && $c['dial'] === $curDial) ? 'selected' : '';
                if ($sel) $picked = true; ?>
              <option value="<?= htmlspecialchars($c['dial']) ?>" <?= $sel ?>><?= flagEmoji($c['iso']) ?> <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['dial']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <input type="tel" name="phone" inputmode="numeric" value="<?= htmlspecialchars($curNum) ?>" placeholder="Mobile number" style="flex:1;">
        </div>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" id="guardianField"><label>Parent / Guardian</label><input type="text" name="parent_guardian" value="<?= htmlspecialchars($editStudent['parent_guardian']) ?>"></div>
      <div class="form-group">
        <label>Level</label>
        <?php $levels = ['Beginner', 'Intermediate', 'Advanced']; $curLevel = $editStudent['experience'] ?? ''; ?>
        <select name="experience">
          <option value="">— Select —</option>
          <?php foreach ($levels as $lvl): ?>
            <option value="<?= htmlspecialchars($lvl) ?>" <?= $curLevel === $lvl ? 'selected' : '' ?>><?= htmlspecialchars($lvl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Country</label>
        <?php
          // The student's country is captured from their own IP at sign-up
          // (see captureStudentCountry()). Here we just show what's stored;
          // an empty value stays "— Select —" rather than guessing from the
          // admin's IP.
          $curCountry = trim($editStudent['address'] ?? '');
        ?>
        <select name="address">
          <option value="">— Select —</option>
          <?php foreach (getCountries() as $c): ?>
            <option value="<?= htmlspecialchars($c['name']) ?>" <?= $curCountry === $c['name'] ? 'selected' : '' ?>><?= flagEmoji($c['iso']) ?> <?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Program</label>
        <?php
          // Offer the shared program list; keep any legacy/unlisted value the
          // record already holds so editing never silently drops it.
          $progMap  = coursePrograms();
          $progOpts = array_keys($progMap);
          $curProg  = $editStudent['program'] ?? '';
          if ($curProg !== '' && !in_array($curProg, $progOpts, true)) $progOpts[] = $curProg;
        ?>
        <select name="program" id="programSelect" data-amount-target="amountField" data-amount-mode="value" data-amount-onchange>
          <option value="">— Select —</option>
          <?php foreach ($progOpts as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"<?= isset($progMap[$p]) ? ' data-amount="' . (int) $progMap[$p] . '"' : '' ?> <?= $curProg === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Amount</label><input type="number" step="0.01" name="program_amount" id="amountField" value="<?= htmlspecialchars((string) $editStudent['program_amount']) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Currency</label>
        <select name="currency">
          <option value="PHP" <?= $editStudent['currency'] === 'PHP' ? 'selected' : '' ?>>PHP ₱</option>
          <option value="USD" <?= $editStudent['currency'] === 'USD' ? 'selected' : '' ?>>USD $</option>
        </select>
      </div>
      <div class="form-group"><label>Payment Status</label><input type="text" name="payment_status" value="<?= htmlspecialchars($editStudent['payment_status']) ?>" placeholder="e.g. Yes / Pending"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Enrolled At</label><input type="text" name="enrolled_at" value="<?= htmlspecialchars((string) $editStudent['enrolled_at']) ?>" placeholder="YYYY-MM-DD HH:MM:SS"></div>
      <div class="form-group">
        <label>Proof of Payment URL</label>
        <input type="text" name="proof_url" value="<?= htmlspecialchars($editStudent['proof_url']) ?>">
        <?php if ($editStudent['proof_url']): ?>
          <span class="field-hint"><a href="<?= htmlspecialchars($editStudent['proof_url']) ?>" target="_blank" rel="noopener">open current proof ↗</a></span>
        <?php endif; ?>
      </div>
    </div>
    <button type="submit" class="btn btn-primary"><?= $creating ? 'Create Student' : 'Save Changes' ?></button>
  </form>

  <script src="/assets/js/program-amount.js"></script>

  <?php if (!$creating): ?>
  <!-- Session credits -->
  <hr>
  <h3>Session Credits</h3>
  <p class="field-hint">Current balance: <strong><?= (int) ($editStudent['session_credits'] ?? 0) ?></strong>.
     Auto-granted from the program when you confirm payment; set manually here if needed.</p>
  <form method="POST" class="mini-form" style="display:flex;gap:.5rem;align-items:center;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="set_credits">
    <input type="hidden" name="student_id" value="<?= (int) $editStudent['id'] ?>">
    <input type="number" name="session_credits" min="0" value="<?= (int) ($editStudent['session_credits'] ?? 0) ?>" style="max-width:8rem;">
    <button type="submit" class="btn btn-secondary btn-sm">Set Credits</button>
  </form>
  <?php endif; ?>

  <!-- Linked login account controls -->
  <?php if ($linkedUser): ?>
    <?php $luExpired = $linkedUser['access_expires_at'] && strtotime($linkedUser['access_expires_at']) < time(); ?>
    <hr>
    <h3>Login Account
      <span class="badge badge-<?= $luExpired ? 'expired' : $linkedUser['subscription_status'] ?>">
        <?= $luExpired ? 'expired' : $linkedUser['subscription_status'] ?>
      </span>
    </h3>
    <div class="detail-grid">
      <div>
        <p><strong>Username:</strong> <?= htmlspecialchars($linkedUser['username']) ?></p>
        <p><strong>Access Expires:</strong> <?= $linkedUser['access_expires_at'] ? date('M j, Y H:i', strtotime($linkedUser['access_expires_at'])) : 'None' ?></p>
        <p><strong>Active:</strong> <?= $linkedUser['is_active'] ? 'Yes' : 'No' ?></p>
      </div>
      <div>
        <!-- Confirm Payment & Grant Access -->
        <form method="POST" action="/admin/students.php?edit=<?= (int) $editStudent['id'] ?>" class="mini-form">
          <?= csrfField() ?>
          <input type="hidden" name="action"     value="approve">
          <input type="hidden" name="user_id"    value="<?= (int) $linkedUser['id'] ?>">
          <input type="hidden" name="student_id" value="<?= (int) $editStudent['id'] ?>">
          <h3>Confirm Payment</h3>
          <p class="field-hint">Current status: <strong><?= htmlspecialchars($editStudent['payment_status'] ?: '—') ?></strong></p>
          <select name="grant_days">
            <option value="30">30 days</option>
            <option value="90">3 months</option>
            <option value="180">6 months</option>
            <option value="365" selected>12 months</option>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">✓ Confirm Payment &amp; Grant Access</button>
        </form>

        <!-- Grant Access -->
        <form method="POST" action="/admin/students.php?edit=<?= (int) $editStudent['id'] ?>" class="mini-form">
          <?= csrfField() ?>
          <input type="hidden" name="action"  value="grant">
          <input type="hidden" name="user_id" value="<?= (int) $linkedUser['id'] ?>">
          <h3>Grant Free Access</h3>
          <select name="grant_days">
            <option value="30">30 days</option>
            <option value="90">3 months</option>
            <option value="180">6 months</option>
            <option value="365">12 months</option>
          </select>
          <input type="text" name="note" placeholder="Note (optional)">
          <button type="submit" class="btn btn-primary btn-sm">Grant</button>
        </form>

        <!-- Change Status -->
        <form method="POST" action="/admin/students.php?edit=<?= (int) $editStudent['id'] ?>" class="mini-form">
          <?= csrfField() ?>
          <input type="hidden" name="action"  value="set_status">
          <input type="hidden" name="user_id" value="<?= (int) $linkedUser['id'] ?>">
          <h3>Status</h3>
          <select name="subscription_status">
            <?php foreach (['pending', 'trial', 'active', 'cancelled', 'expired'] as $st): ?>
              <option value="<?= $st ?>" <?= ($linkedUser['subscription_status'] ?? '') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary btn-sm">Update Status</button>
        </form>

        <!-- Reset Password -->
        <form method="POST" action="/admin/students.php?edit=<?= (int) $editStudent['id'] ?>" class="mini-form">
          <?= csrfField() ?>
          <input type="hidden" name="action"  value="reset_password">
          <input type="hidden" name="user_id" value="<?= (int) $linkedUser['id'] ?>">
          <h3>Reset Password</h3>
          <input type="text" name="new_password" placeholder="New password (min 8 chars)" required>
          <button type="submit" class="btn btn-secondary btn-sm">Set Password</button>
        </form>

        <!-- Activate / Deactivate -->
        <form method="POST" action="/admin/students.php?edit=<?= (int) $editStudent['id'] ?>" class="mini-form">
          <?= csrfField() ?>
          <input type="hidden" name="action"  value="<?= $linkedUser['is_active'] ? 'deactivate' : 'activate' ?>">
          <input type="hidden" name="user_id" value="<?= (int) $linkedUser['id'] ?>">
          <button type="submit" class="btn btn-<?= $linkedUser['is_active'] ? 'danger' : 'primary' ?> btn-sm">
            <?= $linkedUser['is_active'] ? 'Deactivate Account' : 'Reactivate Account' ?>
          </button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <hr>
    <p class="field-hint">This enrollment isn't linked to a login account, so account controls aren't available.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Search -->
<form method="GET" class="inline-form" style="margin:1rem 0;">
  <div class="form-row">
    <div class="form-group">
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name, email, program, phone…">
    </div>
    <div class="form-group">
      <select name="level">
        <option value="">All Levels</option>
        <?php foreach (['Beginner', 'Intermediate', 'Advanced'] as $lvl): ?>
          <option value="<?= $lvl ?>" <?= $level === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <select name="program">
        <option value="">All Programs</option>
        <?php foreach (array_keys(coursePrograms()) as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>" <?= $program === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
        <?php endforeach; ?>
        <option value="<?= WEB_ACCESS_PROGRAM ?>" <?= $program === WEB_ACCESS_PROGRAM ? 'selected' : '' ?>>Website access</option>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if ($q !== '' || $level !== '' || $program !== ''): ?><a href="/admin/students.php" class="btn btn-ghost">Clear</a><?php endif; ?>
  </div>
</form>

<div class="user-detail-header">
  <h2><?= $q !== '' ? 'Results' : 'All Enrollments' ?> <span class="text-sm">(<?= $total ?>)</span></h2>
  <a href="/admin/students.php?new=1" class="btn btn-primary btn-sm">+ Add Student</a>
</div>
<div style="margin:.5rem 0; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
  <form id="bulk-delete-form" method="POST" data-confirm="Delete the selected enrollment records? This cannot be undone." style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_delete">
    <button type="submit" id="bulk-delete-btn" class="btn btn-danger btn-sm" disabled><i class="ti ti-trash" aria-hidden="true"></i> Delete selected (<span id="bulk-count">0</span>)</button>
  </form>
  <form method="POST" style="display:inline" data-confirm="Email a payment reminder to every enrollee with a pending course payment? (Anyone already reminded in the last day is skipped.)">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="remind_pending">
    <button type="submit" class="btn btn-ghost btn-sm" title="Emails everyone on a paid program whose payment isn't confirmed yet"><i class="ti ti-mail" aria-hidden="true"></i> Remind pending payments</button>
  </form>
</div>
<div class="table-scroll">
<table class="data-table">
  <thead>
    <tr>
      <th style="width:1%"><input type="checkbox" id="check-all" title="Select all"></th>
      <th>Name</th><th>Email</th><th>Mobile</th><th>Age</th><th>Level</th><th>Country</th><th>Program</th><th>Amount</th>
      <th>Payment</th><th>Proof</th><th>Enrolled</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($students as $s): ?>
    <tr>
      <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $s['id'] ?>" form="bulk-delete-form"></td>
      <td>
        <?= htmlspecialchars($s['full_name'] ?: trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?>
        <?php if ($s['user_id']): ?><span class="badge badge-active" title="Has login account">acct</span><?php endif; ?>
      </td>
      <td><?= htmlspecialchars($s['email'] ?? '') ?></td>
      <td style="white-space:nowrap"><?= !empty($s['phone']) ? htmlspecialchars($s['phone']) : '—' ?></td>
      <td><?= $s['age'] !== null ? (int) $s['age'] : '—' ?></td>
      <td><?= !empty($s['experience']) ? htmlspecialchars($s['experience']) : '—' ?></td>
      <td><?= !empty($s['address']) ? htmlspecialchars($s['address']) : '—' ?></td>
      <td><?= htmlspecialchars($s['program'] ?? '') ?></td>
      <td><?= moneyFmt($s['program_amount'], $s['currency']) ?></td>
      <td>
        <?php if (!empty($s['payment_status'])): ?>
          <span class="badge"><?= htmlspecialchars($s['payment_status']) ?></span>
        <?php else: ?>—<?php endif; ?>
        <?php if (!empty($s['payment_reminded_at'])): ?>
          <div style="opacity:.65;font-size:.72rem" title="Last payment reminder emailed">reminded <?= date('M j', strtotime($s['payment_reminded_at'])) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($s['proof_url']): ?>
          <a href="<?= htmlspecialchars($s['proof_url']) ?>" target="_blank" rel="noopener">view ↗</a>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td><?= $s['enrolled_at'] ? date('M j, Y', strtotime($s['enrolled_at'])) : '—' ?></td>
      <td style="white-space:nowrap">
        <?php $rowPending = creditsForProgram(trim((string) ($s['program'] ?? ''))) > 0
            && !studentPaymentConfirmed($s) && !empty($s['email']); ?>
        <?php if ($rowPending): ?>
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="remind">
          <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-xs" title="Email a payment reminder to this student"><i class="ti ti-mail" aria-hidden="true"></i> Remind</button>
        </form>
        <?php endif; ?>
        <a href="/admin/students.php?edit=<?= (int) $s['id'] ?>" class="btn btn-ghost btn-xs">Edit</a>
        <form method="POST" style="display:inline" data-confirm="Delete this enrollment record? This cannot be undone.">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
          <button type="submit" class="btn btn-danger btn-xs">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$students): ?>
    <tr><td colspan="13" style="text-align:center">No enrollments found.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="pagination" style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;margin-top:1rem;">
  <?php if ($page > 1): ?>
    <a class="btn btn-ghost btn-xs" href="<?= studentPageUrl($page - 1, $q) ?>">← Prev</a>
  <?php endif; ?>

  <?php
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
  ?>
  <?php if ($start > 1): ?>
    <a class="btn btn-ghost btn-xs" href="<?= studentPageUrl(1, $q) ?>">1</a>
    <?php if ($start > 2): ?><span>…</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a class="btn btn-<?= $i === $page ? 'primary' : 'ghost' ?> btn-xs" href="<?= studentPageUrl($i, $q) ?>"><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $pages): ?>
    <?php if ($end < $pages - 1): ?><span>…</span><?php endif; ?>
    <a class="btn btn-ghost btn-xs" href="<?= studentPageUrl($pages, $q) ?>"><?= $pages ?></a>
  <?php endif; ?>

  <?php if ($page < $pages): ?>
    <a class="btn btn-ghost btn-xs" href="<?= studentPageUrl($page + 1, $q) ?>">Next →</a>
  <?php endif; ?>

  <span class="text-sm" style="margin-left:.5rem">Page <?= $page ?> of <?= $pages ?></span>
</div>
<?php endif; ?>

<?php endif; /* hasTable */ ?>

</main>
<script src="/assets/js/students-admin.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
