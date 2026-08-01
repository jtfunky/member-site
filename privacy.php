<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Privacy Policy — ' . SITE_NAME;
$pageCss   = ['main', 'auth'];
require __DIR__ . '/includes/header.php';
?>

<main class="container" style="max-width:760px;padding:40px 20px;">
<h1>Privacy Policy</h1>
<p class="field-hint">Last updated: July 29, 2026</p>

<p><?= SITE_NAME ?> ("we", "us") operates <?= SITE_URL ?> and its subdomains
(collectively, the "Site"). This page explains what information we collect from
students and visitors, how we use it, and who it's shared with.</p>

<h2>Information we collect</h2>
<ul>
  <li><strong>Account information</strong> — name, email address, and a hashed
      password when you register directly, or your name and email as provided by
      Google/Facebook/Discord when you sign in with one of those providers.</li>
  <li><strong>Payment information</strong> — when you enroll in a paid program, we
      record payment status and proof-of-payment submissions. Card and payment
      details themselves are handled by our payment processor (Maya), not stored on
      our servers.</li>
  <li><strong>Practice and progress data</strong> — placement test results, practice
      plan progress, and drum game scores, used to personalize your lessons.</li>
  <li><strong>Uploaded content</strong> — audio recordings and avatar images you
      choose to upload as part of lessons or your profile.</li>
  <li><strong>Raffle/promo entries</strong> — name and email submitted when entering
      a promotional raffle, used only to contact winners and never sold or shared.</li>
</ul>

<h2>How we use it</h2>
<p>We use this information to operate your account, deliver lessons and practice
tools, process payments, send service-related emails (enrollment confirmations,
raffle results, session reminders), and — only if you opt in — occasional updates
about the Academy.</p>

<h2>Social login (Google, Facebook, Discord)</h2>
<p>If you sign in using a third-party provider, we receive your name and email
address from that provider to create or match your account. We do not post to your
social accounts or access your contacts, friends list, or other social data.</p>

<h2>Sharing</h2>
<p>We don't sell your personal information. We share it only with the service
providers needed to run the Site — our hosting provider, our payment processor
(Maya), and email delivery — each solely to perform that function on our behalf.</p>

<h2>Data retention &amp; deletion</h2>
<p>We retain account data for as long as your account is active. To request deletion
of your account and associated data, email us at the address below.</p>

<h2>Contact</h2>
<p>Questions about this policy or your data: <a href="mailto:<?= htmlspecialchars(MAIL_FROM) ?>"><?= htmlspecialchars(MAIL_FROM) ?></a></p>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
