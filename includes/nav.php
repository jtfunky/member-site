<?php
// Shared top navigation — include after $user is set
require_once __DIR__ . '/programs.php';
require_once __DIR__ . '/access.php';
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$navRole = $user['role'] ?? 'user';
$navHome = roleHome($navRole);   // brand link target per role

// The placement test is a one-time assessment — hide it once taken (admins keep
// it); the "My Plan" link takes its place once the student has a result.
$navTakenPlacement = hasTakenPlacementTest((int) ($user['id'] ?? 0));
$navShowPlacement  = (($user['role'] ?? '') === 'admin') || !$navTakenPlacement;
$navShowPlan       = $navTakenPlacement;

// Web-access-only students have no session credits, so hide "My Sessions".
$navStudent = null;
try {
    $st = db()->prepare('SELECT program, session_credits, payment_status FROM students WHERE user_id = ? LIMIT 1');
    $st->execute([(int) ($user['id'] ?? 0)]);
    $navStudent = $st->fetch() ?: null;
} catch (\Throwable $e) { /* students table optional */ }
$navShowSessions = studentHasSessions($navStudent);

// "Groove Quest" segment: self-registered users with no one-on-one enrollment
// at all (no row in `students`). They get a stripped-down header — just the
// brand logo and account controls — and navigate via the Dashboard's tile
// grid instead. Admins, partners, editors, and real one-on-one students keep
// the full header unchanged.
$navMinimalHeader = ($navRole === 'user') && !$navShowSessions;

// Show "My Enrollment" to anyone with an enrollment still awaiting payment —
// legacy pending accounts, and free-trial course enrollees who haven't paid yet.
$navShowEnrollment = (($user['subscription_status'] ?? '') === 'pending')
    || ($navStudent
        && creditsForProgram(trim((string) ($navStudent['program'] ?? ''))) > 0
        && !studentPaymentConfirmed($navStudent));

// Constant payment reminder — shown site-wide to course students (lesson
// enrollees) whose payment isn't confirmed yet. Web-access / online-only users
// have no session-based program, so they never see it.
$navRemindProof = $navStudent
    && creditsForProgram(trim((string) ($navStudent['program'] ?? ''))) > 0
    && !studentPaymentConfirmed($navStudent);

// Beta disclaimer — shown to regular members for the duration of the actual
// beta window (launch through the shared free-access end date); disappears
// on its own once that date passes, no follow-up edit needed. Dismissible
// per-browser via localStorage (assets/js/beta-banner.js) so it doesn't nag
// on every page load once seen.
$navShowBetaBanner = ($navRole === 'user') && gameBetaHasLaunched()
    && (new DateTime('now', new DateTimeZone('UTC')))
        < (new DateTime(GAME_FREE_ACCESS_UNTIL, new DateTimeZone('Asia/Manila')));

// Game, Request a Song, My Plan, and Exclusive stay hidden from regular
// users' nav until the game's beta launch — admins and the bypass test
// account always see them. Auto-restores once gameBetaHasLaunched() flips.
$navShowLaunchGated = ($navRole === 'admin')
    || isGameBetaBypassEmail($user['email'] ?? '')
    || gameBetaHasLaunched();
?>
<nav class="top-nav">
  <div class="nav-brand">
    <a href="<?= htmlspecialchars($navHome) ?>"><span class="brand-name"><?= SITE_NAME ?></span><span class="brand-abbr">GrooveQuest</span></a>
  </div>
  <?php if (!$navMinimalHeader): ?>
  <input type="checkbox" id="nav-toggle" class="nav-toggle" hidden>
  <label for="nav-toggle" class="nav-burger" aria-label="Toggle menu">☰</label>
  <ul class="nav-links">
    <?php if ($navRole === 'partner'): ?>
    <li><a href="/partner.php" class="<?= $currentPath === '/partner.php' ? 'active' : '' ?>">Partner</a></li>
    <?php elseif ($navRole === 'editor'): ?>
    <li><a href="/admin/song-tags.php"     class="<?= $currentPath === '/admin/song-tags.php' ? 'active' : '' ?>">Song Tags</a></li>
    <li><a href="/admin/song-requests.php" class="<?= $currentPath === '/admin/song-requests.php' ? 'active' : '' ?>">Song Requests</a></li>
    <li><a href="/admin/song-editor.php"   class="<?= $currentPath === '/admin/song-editor.php' ? 'active' : '' ?>">Chart Editor</a></li>
    <?php else: ?>
    <?php if ($navShowEnrollment): ?>
    <li><a href="/my-membership.php" class="<?= $currentPath === '/my-membership.php' ? 'active' : '' ?>">My Enrollment</a></li>
    <?php endif; ?>
    <?php if ($navShowLaunchGated): ?>
    <li><a href="/game.php"      class="<?= $currentPath === '/game.php'    ? 'active' : '' ?>">Game</a></li>
    <li><a href="/request-song.php" class="<?= $currentPath === '/request-song.php' ? 'active' : '' ?>">Request a Song</a></li>
    <?php endif; ?>
    <?php if ($navShowPlacement): ?>
    <li><a href="/placement-test.php" class="<?= $currentPath === '/placement-test.php' ? 'active' : '' ?>">Placement Test</a></li>
    <?php endif; ?>
    <?php if ($navShowPlan && $navShowLaunchGated): ?>
    <li><a href="/my-plan.php" class="<?= $currentPath === '/my-plan.php' ? 'active' : '' ?>">My Plan</a></li>
    <?php endif; ?>
    <?php if ($navShowSessions): ?>
    <li><a href="/my-sessions.php" class="<?= $currentPath === '/my-sessions.php' ? 'active' : '' ?>">My Sessions</a></li>
    <?php endif; ?>
    <?php if ($navShowLaunchGated): ?>
    <li><a href="/exclusive/"    class="<?= str_starts_with($currentPath, '/exclusive') ? 'active' : '' ?>">Exclusive</a></li>
    <?php endif; ?>
    <li><a href="/profile.php"   class="<?= $currentPath === '/profile.php' ? 'active' : '' ?>">Profile</a></li>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <li><a href="/admin/"        class="<?= str_starts_with($currentPath, '/admin') ? 'active' : '' ?>">Admin</a></li>
    <?php endif; ?>
    <?php endif; /* role branch */ ?>
  </ul>
  <?php endif; /* !$navMinimalHeader */ ?>
  <div class="nav-user">
    <span><?= htmlspecialchars($user['username'] ?? '') ?></span>
    <a href="/logout.php" class="btn btn-ghost btn-sm">Log Out</a>
  </div>
</nav>
<?php if ($navRemindProof): ?>
<div class="alert alert-error" style="margin:0;border-radius:0;text-align:center;">
  ⚠️ <strong>Payment pending.</strong> Please complete your payment and
  <a href="/my-membership.php">submit your proof of payment</a> to confirm your enrollment and unlock your one-on-one sessions.
</div>
<?php endif; ?>
<?php if ($navShowBetaBanner): ?>
<div id="beta-banner" class="alert alert-info" style="margin:0;border-radius:0;text-align:center;">
  <strong>We're in beta.</strong> Groove Quest is still being refined — things may change or occasionally break.
  Free access continues through <?= (new DateTime(GAME_FREE_ACCESS_UNTIL, new DateTimeZone('Asia/Manila')))->format('F j, Y') ?>.
  Run into an issue? Use the Help chat or email <?= htmlspecialchars(SUPPORT_EMAIL) ?>.
  <button type="button" id="beta-banner-dismiss" class="beta-banner-close" aria-label="Dismiss">&times;</button>
</div>
<script src="/assets/js/beta-banner.js?v=<?= @filemtime(__DIR__ . '/../assets/js/beta-banner.js') ?: 1 ?>"></script>
<?php endif; ?>
