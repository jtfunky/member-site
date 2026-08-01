<?php
// Dedicated raffle promo landing page — separate from the normal register.php
// flow. Creates a full member account (same as normal signup) and enters the
// new member into the campaign identified by ?c=<slug>. Only campaign WINNERS
// (drawn later in admin/raffle.php) get the free-access prize; entering here
// does not grant anything by itself.
//
// Visual design ported from the Tribal Ink Art & Music Fest flyer concept
// (assets/img/raffle/*.png) — a dark festival-poster treatment on the
// "Nocturne" design tokens, built to promote this specific festival tie-in.
// Copy is adapted from that flyer's original "your ticket is free" framing
// (which assumed everyone who signs up gets a ticket) to accurately describe
// a raffle — only drawn winners get the prize.
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$slug     = trim($_GET['c'] ?? ($_POST['c'] ?? ''));
$campaign = $slug !== '' ? getCampaignBySlug($slug) : getActiveCampaign();
$status   = $campaign ? campaignStatus($campaign) : null;
$error    = '';

// Two-step funnel: step 1 is a low-friction "name + email or phone" splash in
// front of the poster art; step 2 is the real account-creation form. Nothing
// from step 1 is stored anywhere — it only rides through the redirect URL to
// pre-fill step 2's fields, reducing retyping without a separate leads table.
$step = isset($_GET['step']) ? (int) $_GET['step'] : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['fstep'] ?? '') === 'quick') {
    // Re-check status at submit time too — a slow submitter could straddle
    // the campaign's start/end boundary between page-load and POST.
    if (!$campaign || campaignStatus($campaign) !== 'open') {
        $error = 'This promotion is no longer accepting entries.';
    } else {
        verifyCsrf();
        if (tooManyRequests('raffle_quick', 5, 15)) {
            $error = 'Too many attempts. Please wait a few minutes and try again.';
        } else {
            $qName    = trim($_POST['name']    ?? '');
            $qContact = trim($_POST['contact'] ?? '');
            if ($qName === '' || $qContact === '') {
                $error = 'Please enter your name and an email or phone number.';
            } else {
                header('Location: ' . SITE_URL . '/raffle-signup.php?c=' . urlencode($campaign['slug'])
                    . '&step=2&name=' . urlencode($qName) . '&contact=' . urlencode($qContact));
                exit;
            }
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-check status at submit time too — a slow submitter could straddle
    // the campaign's start/end boundary between page-load and POST.
    if (!$campaign || campaignStatus($campaign) !== 'open') {
        $error = 'This promotion is no longer accepting entries.';
    } else {
        verifyCsrf();
        if (tooManyRequests('raffle_signup', 5, 15)) {
            $error = 'Too many sign-up attempts. Please wait a few minutes and try again.';
        } else {
            $email     = trim($_POST['email']     ?? '');
            $password  = $_POST['password']       ?? '';
            $password2 = $_POST['password2']      ?? '';
            $first     = trim($_POST['first_name'] ?? '');
            $last      = trim($_POST['last_name']  ?? '');

            if ($password !== $password2) {
                $error = 'Passwords do not match.';
            } else {
                $username = uniqueUsernameFromEmail($email);
                $result   = registerUser($username, $email, $password, $first, $last);
                if (is_int($result)) {
                    require_once __DIR__ . '/includes/programs.php';
                    ensureWebAccessStudent($result);
                    enterRaffle((int) $campaign['id'], $result);
                    sendMail(defined('REGISTRATION_EMAIL') ? REGISTRATION_EMAIL : 'registration@zachalcasid.com', 'New raffle signup — ' . $campaign['name'],
                        'A new account entered the "' . $campaign['name'] . '" raffle on ' . SITE_NAME . ":\n\n"
                        . 'Name: ' . trim($first . ' ' . $last) . "\nEmail: {$email}\n\n"
                        . 'Manage the raffle: ' . SITE_URL . '/admin/raffle.php?campaign=' . $campaign['id']);
                    loginUser($email, $password);
                    header('Location: ' . SITE_URL . '/dashboard.php?welcome=1&raffle=1');
                    exit;
                }
                $error = $result;
            }
            $step = 2; // stay on the registration step so the error/values re-render
        }
    }
}

// Pre-fill step 2 from step 1's quick-capture redirect (GET only — never
// overrides an in-progress $_POST resubmission after a validation error).
$prefillFirst = '';
$prefillLast  = '';
$prefillEmail = '';
if (!isset($_POST['fstep']) || $_POST['fstep'] !== 'register') {
    $qName = trim($_GET['name'] ?? '');
    if ($qName !== '') {
        $parts        = explode(' ', $qName, 2);
        $prefillFirst = $parts[0];
        $prefillLast  = $parts[1] ?? '';
    }
    $qContact = trim($_GET['contact'] ?? '');
    if ($qContact !== '' && filter_var($qContact, FILTER_VALIDATE_EMAIL)) {
        $prefillEmail = $qContact;
    }
}

$pageTitle = ($campaign['name'] ?? 'Raffle') . ' — ' . SITE_NAME;
$pageCss   = ['main'];
$bodyClass = 'raffle-flyer-page';
$pageHead  = <<<'HEAD'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --color-bg: #120a09;
  --color-surface: #2a1414;
  --color-text: #ede4d9;
  --color-accent: #c9a074;
  --color-accent-900: #3a0a0a;
  --color-section: #3a1414;
  --color-section-glow: #4a1c1c;
  --color-divider: rgba(233,217,200,0.18);
  --font-heading: "Inter", system-ui, sans-serif;
  --font-body: "Inter", system-ui, sans-serif;
  --leading: 28px;
  --half: 14px;
  --edge: clamp(20px, 5vw, 72px);
  --radius-lg: 14px;
  --radius-md: 8px;
}
body.raffle-flyer-page {
  margin: 0;
  background:
    radial-gradient(1100px 700px at 50% -140px, color-mix(in srgb, var(--color-accent-900) 80%, transparent), transparent 62%),
    radial-gradient(900px 700px at 50% 100%, color-mix(in srgb, black 34%, transparent), transparent 58%),
    var(--color-bg);
  color: var(--color-text);
  font-family: var(--font-body);
  text-wrap: pretty;
}
body.raffle-flyer-page h1, body.raffle-flyer-page h2, body.raffle-flyer-page h3 {
  font-family: var(--font-heading); font-weight: 500; line-height: 1.12; letter-spacing: -0.015em; margin: 0 0 var(--half);
}
body.raffle-flyer-page p { margin: 0 0 var(--half); }
body.raffle-flyer-page a { color: var(--color-accent); }
.flyer { max-width: 900px; margin: 0 auto; padding: 0 var(--edge) calc(2 * var(--leading)); text-align: center; }
.sheet { border: 1px solid var(--color-divider); border-radius: var(--radius-lg);
  padding: calc(2 * var(--leading)) clamp(16px, 4vw, 56px) calc(2.5 * var(--leading));
  margin-top: calc(2 * var(--leading));
  background: radial-gradient(700px 420px at 50% 0, color-mix(in srgb, var(--color-accent-900) 42%, transparent), transparent 70%); }
.poster-el { display: block; width: 100%; height: auto; }
.lighten { mix-blend-mode: lighten; background-color: transparent; }
.presenter { font-size: 13px; line-height: var(--half); letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-accent); margin: 0 0 var(--leading); }
.brands { max-width: 180px; margin: 0 auto var(--half); opacity: 0.92;
  mask-image: linear-gradient(to right, transparent, #000 5% 92%, transparent); }
.art { position: relative; margin: 0 auto; max-width: 220px;
  mask-image: radial-gradient(closest-side at 52% 46%, #000 58%, transparent 97%); }
.lockup { max-width: 260px; margin: calc(-1.5 * var(--leading)) auto var(--half); position: relative;
  mask-image: linear-gradient(to bottom, #000 72%, transparent 98%), linear-gradient(to right, transparent, #000 6% 94%, transparent);
  mask-composite: intersect; }
.date { font-family: var(--font-heading); font-weight: 500; font-size: clamp(32px, 4.6vw, 56px); line-height: calc(2 * var(--leading)); letter-spacing: -0.015em; margin: 0; }
.venue { font-size: 17px; line-height: var(--leading); letter-spacing: 0.02em; color: color-mix(in srgb, var(--color-text) 78%, transparent); margin: var(--leading) 0 0; }
.claim { margin: calc(2 * var(--leading)) auto 0; max-width: 460px; }
.claim h2 { font-size: 24px; line-height: var(--leading); margin: 0; }
.claim p { font-size: 15.5px; line-height: var(--leading); margin: var(--half) 0 0; color: color-mix(in srgb, var(--color-text) 78%, transparent); }
.fineprint { font-size: 13px; line-height: var(--half); margin: var(--half) 0 0; color: color-mix(in srgb, var(--color-text) 55%, transparent); }
.flyer footer { padding: var(--half) 0 0; font-size: 13px; line-height: var(--leading); color: color-mix(in srgb, var(--color-text) 50%, transparent); }
/* — signup form, using the same tokens as the rest of the flyer — */
.rf-form { text-align: left; margin-top: var(--leading); }
.rf-row { display: flex; gap: 12px; }
.rf-row .rf-field { flex: 1; }
.rf-field { margin-bottom: 12px; }
.rf-field label { display: block; font-size: 12px; margin-bottom: 5px; color: color-mix(in srgb, var(--color-text) 70%, transparent); }
.rf-field input {
  width: 100%; min-height: 36px; padding: 6px 10px; font: inherit; font-size: 14px; color: var(--color-text);
  background: var(--color-surface); border: 1px solid var(--color-divider); border-radius: var(--radius-md); box-sizing: border-box;
}
.rf-field input:focus-visible { border-color: var(--color-accent); outline: none; }
.rf-hint { font-size: 12px; color: color-mix(in srgb, var(--color-text) 55%, transparent); margin: 4px 0 0; }
.rf-btn {
  display: block; width: 100%; margin-top: var(--half); cursor: pointer; text-decoration: none;
  font-family: var(--font-heading); font-weight: 500; font-size: 15px; text-align: center;
  color: var(--color-accent); background: color-mix(in srgb, var(--color-accent) 12%, transparent);
  border: 1px solid var(--color-accent); border-radius: var(--radius-md); padding: 11px;
}
.rf-btn:hover { background: color-mix(in srgb, var(--color-accent) 22%, transparent); }
.rf-error {
  text-align: left; font-size: 14px; color: #f5b8b8; background: rgba(244,90,90,0.1);
  border: 1px solid rgba(244,90,90,0.4); border-radius: var(--radius-md); padding: 10px 12px; margin-top: var(--leading);
}
.rf-switch { font-size: 14px; margin-top: var(--half); color: color-mix(in srgb, var(--color-text) 70%, transparent); }

/* — step 1 splash: the full poster as a background, with the quick-capture
   form inlaid into the poster's own empty red panel (the space below the
   medallion the artwork was designed to hold text in). The container's
   aspect-ratio is locked to the source image's real 1536x2048 so the
   panel's percentage offsets below always land in the same spot on the
   artwork regardless of viewport width — width itself stays fluid. */
.splash-bg {
  width: min(90vw, 480px); max-width: 100%; margin: 0 auto; aspect-ratio: 1536 / 2248;
  position: relative; container-type: inline-size;
}
/* The image lives on its own layer (a pseudo-element), kept separate from
   .splash-form-overlay so any future effects on one never affect the other.
   Filename note: Hostinger's CDN (hCDN) caches this path for 7 days and
   caches by PATH ONLY — a ?v= query string does NOT bust it (confirmed;
   unlike this site's CSS/JS versioning, which the browser itself respects).
   If this image is ever replaced again, rename the file (e.g. splash-v3.png)
   rather than just overwriting splash-v2.png, or the CDN will keep serving
   the old bytes for up to a week. */
.splash-bg::before {
  content: ""; position: absolute; inset: 0;
  background-image: url('/assets/img/raffle/splash-v2.png'); background-size: contain;
  background-position: center; background-repeat: no-repeat;
  border-radius: var(--radius-md);
}
.splash-form-overlay {
  position: absolute; left: 10.7%; right: 10.7%; top: 64.5%; bottom: 12.9%; z-index: 1;
  display: flex; flex-direction: column; justify-content: flex-start; text-align: left;
  padding: 3% 4%; box-sizing: border-box;
}
.splash-form-overlay .rf-form { margin-top: 0; }
.splash-form-overlay .rf-field { margin-bottom: clamp(5px, 1cqw, 12px); }
.splash-form-overlay .rf-field:first-child { margin-top: clamp(8px, 2cqw, 22px); }
.splash-form-overlay .rf-field input {
  min-height: clamp(24px, 5cqw, 40px); font-size: clamp(11px, 2.3cqw, 16px);
  padding: clamp(3px, 0.6cqw, 7px) clamp(7px, 1.5cqw, 14px);
  border-radius: clamp(4px, 1cqw, 8px);
  background: rgba(30, 4, 5, 0.34); border-color: rgba(214, 168, 109, 0.45);
  color: rgba(224, 190, 152, 0.95);
}
.splash-form-overlay .rf-field input::placeholder { color: rgba(224, 190, 152, 0.6); }
.splash-form-overlay .rf-btn {
  padding: clamp(5px, 1cqw, 12px); font-size: clamp(12px, 2.5cqw, 17px); margin-top: clamp(3px, 0.6cqw, 8px);
  border-radius: clamp(4px, 1cqw, 8px);
  background: rgba(30, 4, 5, 0.34); border-color: rgba(214, 168, 109, 0.45);
  color: rgba(224, 190, 152, 0.95);
}
.splash-form-overlay .rf-btn:hover { background: rgba(45, 8, 9, 0.5); }
.splash-form-overlay .rf-error { font-size: clamp(11px, 2.3cqw, 16px); padding: clamp(5px, 1cqw, 12px) clamp(7px, 1.5cqw, 14px); margin-bottom: clamp(5px, 1cqw, 12px); }
@media (max-width: 640px) {
  .lockup { margin-top: calc(-1.5 * var(--leading)); }
  .rf-row { flex-direction: column; gap: 0; }
}
</style>
HEAD;
require __DIR__ . '/includes/header.php';
?>

<div class="flyer">

  <?php if (!$campaign || $status === 'upcoming' || $status === 'ended'): ?>
  <!-- Not open — same message regardless of which step a link points at. -->
  <section class="sheet" aria-labelledby="fest">
    <p class="presenter"><?= htmlspecialchars(SITE_NAME) ?> presents Groove Quest</p>
    <figure class="lighten brands"><img class="poster-el" src="/assets/img/raffle/brands.png" alt="Numinous, Tribal Streetwear and Play Time"></figure>
    <figure class="lighten art"><img class="poster-el" src="/assets/img/raffle/art.png" alt=""></figure>
    <h1 id="fest" class="lighten lockup"><img class="poster-el" src="/assets/img/raffle/lockup.png" alt="Tribal Ink Art &amp; Music Fest"></h1>
    <p class="date">August 7 &amp; 8, 2026</p>
    <p class="venue">World Trade Center, Metro Manila</p>
    <div class="claim">
      <?php if (!$campaign): ?>
        <h2>Not available</h2>
        <p>This promotion isn't available right now.</p>
      <?php elseif ($status === 'upcoming'): ?>
        <h2><?= htmlspecialchars($campaign['name']) ?></h2>
        <p>This raffle hasn't started yet — check back <?= date('F j, Y g:ia', strtotime($campaign['start_at'])) ?>.</p>
      <?php else: ?>
        <h2><?= htmlspecialchars($campaign['name']) ?></h2>
        <p>This promotion has ended. Thanks to everyone who entered!</p>
      <?php endif; ?>
    </div>
  </section>

  <?php elseif ($step === 1): ?>
  <!-- Step 1: the splash — the poster as a full background, with the quick
       name + contact capture inlaid into the artwork's own empty panel
       below the medallion. No password yet, nothing stored; the values
       just ride the redirect to pre-fill step 2. -->
  <section aria-labelledby="fest" style="margin-top: var(--half);">
    <p class="presenter"><?= htmlspecialchars(SITE_NAME) ?> presents Groove Quest</p>
    <div id="fest" class="splash-bg" role="img" aria-label="Tribal Ink Art &amp; Music Fest">
      <div class="splash-form-overlay">
        <?php if ($error): ?>
        <div class="rf-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/raffle-signup.php?c=<?= urlencode($campaign['slug']) ?>" class="rf-form">
          <?= csrfField() ?>
          <input type="hidden" name="fstep" value="quick">
          <input type="hidden" name="c" value="<?= htmlspecialchars($campaign['slug']) ?>">
          <div class="rf-field">
            <input type="text" name="name" placeholder="Full Name" aria-label="Full Name" autofocus value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
          </div>
          <div class="rf-field">
            <input type="text" name="contact" placeholder="Email or Phone Number" aria-label="Email or Phone Number" value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>">
          </div>
          <button type="submit" class="rf-btn">Join</button>
        </form>
      </div>
    </div>
  </section>

  <?php else: ?>
  <!-- Step 2: the real account — pre-filled from step 1 where possible. -->
  <section class="sheet" aria-labelledby="fest">
    <p class="presenter"><?= htmlspecialchars(SITE_NAME) ?> presents Groove Quest</p>
    <figure class="lighten brands"><img class="poster-el" src="/assets/img/raffle/brands.png" alt="Numinous, Tribal Streetwear and Play Time"></figure>
    <figure class="lighten art"><img class="poster-el" src="/assets/img/raffle/art.png" alt=""></figure>
    <h1 id="fest" class="lighten lockup"><img class="poster-el" src="/assets/img/raffle/lockup.png" alt="Tribal Ink Art &amp; Music Fest"></h1>
    <p class="date">August 7 &amp; 8, 2026</p>
    <p class="venue">World Trade Center, Metro Manila</p>

    <div class="claim">
      <h2>Enter to win your ticket</h2>
      <p>Sign up for a free Groove Quest account and you're entered to win
        <?= (int) $campaign['prize_days'] ?> days of free access<?php if ((int) $campaign['prize_ticket_count'] > 0): ?>
        plus <?= (int) $campaign['prize_ticket_count'] ?> ticket<?= (int) $campaign['prize_ticket_count'] === 1 ? '' : 's' ?><?= $campaign['prize_ticket_desc'] ? ' (' . htmlspecialchars($campaign['prize_ticket_desc']) . ')' : '' ?><?php endif; ?>.
        Winners drawn after <?= date('F j, Y', strtotime($campaign['end_at'])) ?> and notified by email.</p>

      <?php if ($error): ?>
      <div class="rf-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="/raffle-signup.php?c=<?= urlencode($campaign['slug']) ?>&step=2" class="rf-form">
        <?= csrfField() ?>
        <input type="hidden" name="fstep" value="register">
        <input type="hidden" name="c" value="<?= htmlspecialchars($campaign['slug']) ?>">
        <div class="rf-row">
          <div class="rf-field">
            <label>First Name</label>
            <input type="text" name="first_name" autofocus value="<?= htmlspecialchars($_POST['first_name'] ?? $prefillFirst) ?>">
          </div>
          <div class="rf-field">
            <label>Last Name</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? $prefillLast) ?>">
          </div>
        </div>
        <div class="rf-field">
          <label>Email *</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? $prefillEmail) ?>">
        </div>
        <div class="rf-field">
          <label>Password *</label>
          <input type="password" name="password" required minlength="8">
          <p class="rf-hint">Minimum 8 characters, one uppercase letter, one number</p>
        </div>
        <div class="rf-field">
          <label>Confirm Password *</label>
          <input type="password" name="password2" required>
        </div>
        <button type="submit" class="rf-btn">Enter the Raffle</button>
      </form>
      <p class="rf-switch fineprint">One entry per account · Winners drawn after the promo closes · By signing up you agree to our terms of service</p>
      <p class="rf-switch">Already have an account? <a href="/login.php">Log in</a></p>
    </div>
  </section>
  <?php endif; ?>

  <footer>Groove Quest Promo · Tribal Ink Art &amp; Music Fest · August 7 &amp; 8, 2026 · World Trade Center, Metro Manila</footer>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
