<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/countries.php';

$user = requireLogin();

// Load this user's student record. No record (e.g. admin) → nothing to complete.
$student = null;
try {
    $st = db()->prepare('SELECT * FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) $user['id']]);
    $student = $st->fetch() ?: null;
} catch (\Throwable $e) { /* table optional */ }

if (!$student) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }
if ((int) ($student['profile_completed'] ?? 1) === 1) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$error = '';
$old   = $student; // prefill with existing values, overridden by POST on error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $old   = $_POST;
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $mi    = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_POST['middle_initial'] ?? ''), 0, 1));
    $level = trim($_POST['experience'] ?? '');
    $ctry  = trim($_POST['address'] ?? '');
    $dobIn = trim($_POST['date_of_birth'] ?? '');
    $dial  = preg_replace('/[^\d+]/', '', $_POST['country_code'] ?? '');
    $num   = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $phone = $num !== '' ? trim($dial . ' ' . $num) : '';

    // Validate + derive age from DOB.
    $dob = null; $age = null;
    $d = $dobIn !== '' ? DateTime::createFromFormat('!Y-m-d', $dobIn) : false;
    if ($d && $d->format('Y-m-d') === $dobIn && $d <= new DateTime('today')) {
        $dob = $dobIn;
        $age = (int) $d->diff(new DateTime('today'))->y;
    }
    $guardian = ($age !== null && $age < 18) ? trim($_POST['parent_guardian'] ?? '') : '';

    if ($first === '' || $last === '') {
        $error = 'Please enter your first and last name.';
    } elseif ($dob === null) {
        $error = 'Please enter a valid date of birth.';
    } elseif ($phone === '') {
        $error = 'Please enter your mobile number.';
    } elseif (!in_array($level, ['Beginner', 'Intermediate', 'Advanced'], true)) {
        $error = 'Please select your level.';
    } elseif ($ctry === '') {
        $error = 'Please select your country.';
    } elseif ($age !== null && $age < 18 && $guardian === '') {
        $error = 'A parent/guardian name is required for students under 18.';
    } else {
        $full = trim($last . ', ' . $first . ($mi !== '' ? ' ' . $mi : ''));
        db()->prepare(
            'UPDATE students
                SET first_name = ?, last_name = ?, middle_initial = ?, full_name = ?,
                    age = ?, date_of_birth = ?, parent_guardian = ?, address = ?, phone = ?,
                    experience = ?, profile_completed = 1
              WHERE id = ?'
        )->execute([$first, $last, $mi, $full, $age, $dob, $guardian, $ctry, $phone, $level, (int) $student['id']]);

        // Keep the login account's name in sync.
        db()->prepare('UPDATE users SET first_name = ?, last_name = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$first, $last, (int) $user['id']]);

        header('Location: ' . SITE_URL . '/dashboard.php?welcome=1');
        exit;
    }
}

$curDial = dialByIso(getUserCountry()) ?: '+63';
$curCtry = trim((string) ($old['address'] ?? '')) ?: getUserCountryName();

$pageTitle = 'Complete Your Profile — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card auth-card--wide">
  <a class="auth-logo" href="/"><?= SITE_NAME ?></a>
  <h1>Complete your profile</h1>
  <p class="auth-sub">Please fill in a few details to finish setting up your account
    and unlock the lessons and drum game.</p>

  <?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/complete-profile.php">
    <?= csrfField() ?>

    <div class="form-row">
      <div class="form-group">
        <label>First Name <span class="required">*</span></label>
        <input type="text" name="first_name" required value="<?= htmlspecialchars($old['first_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Last Name <span class="required">*</span></label>
        <input type="text" name="last_name" required value="<?= htmlspecialchars($old['last_name'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Middle Initial</label>
        <input type="text" name="middle_initial" maxlength="1" pattern="[A-Za-z]" title="A single letter" style="text-transform:uppercase" value="<?= htmlspecialchars($old['middle_initial'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Date of Birth <span class="required">*</span></label>
        <input type="date" name="date_of_birth" required value="<?= htmlspecialchars($old['date_of_birth'] ?? '') ?>">
      </div>
    </div>

    <div class="form-group">
      <label>Mobile Number <span class="required">*</span></label>
      <div style="display:flex; gap:.5rem;">
        <select name="country_code" style="flex:0 0 auto; max-width:11rem;">
          <?php $picked = false; foreach (getCountries() as $c):
              $sel = (!$picked && $c['dial'] === $curDial) ? 'selected' : '';
              if ($sel) $picked = true; ?>
            <option value="<?= htmlspecialchars($c['dial']) ?>" <?= $sel ?>><?= flagEmoji($c['iso']) ?> <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['dial']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <input type="tel" name="phone" inputmode="numeric" placeholder="Mobile number" style="flex:1;" required value="<?= htmlspecialchars($old['phone'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Level <span class="required">*</span></label>
        <select name="experience" required>
          <option value="">— Select —</option>
          <?php foreach (['Beginner', 'Intermediate', 'Advanced'] as $lvl): ?>
            <option value="<?= $lvl ?>" <?= ($old['experience'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Country <span class="required">*</span></label>
        <select name="address" required>
          <option value="">— Select —</option>
          <?php foreach (getCountries() as $c): ?>
            <option value="<?= htmlspecialchars($c['name']) ?>" <?= $curCtry === $c['name'] ? 'selected' : '' ?>><?= flagEmoji($c['iso']) ?> <?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Parent / Guardian <span class="field-hint">(required if under 18)</span></label>
      <input type="text" name="parent_guardian" value="<?= htmlspecialchars($old['parent_guardian'] ?? '') ?>">
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Save & Continue</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
