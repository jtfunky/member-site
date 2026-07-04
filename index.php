<?php
require_once __DIR__ . '/includes/bootstrap.php';

sessionStart();
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$currency = getUserCurrency();
$price    = formatPrice(getPrice($currency), $currency);

$pageTitle = SITE_NAME . ' — Play Drums Online';
$pageCss   = ['main', 'landing'];
$bodyClass = 'landing';
require __DIR__ . '/includes/header.php';
?>

<nav class="top-nav">
  <div class="nav-brand"><span class="drum-icon">🥁</span> <?= SITE_NAME ?></div>
  <div class="nav-links">
    <a href="/login.php">Log In</a>
    <a href="/register.php" class="btn btn-primary btn-sm">Sign Up Free</a>
  </div>
</nav>

<header class="hero">
  <div class="hero-inner">
    <h1>Play Drums.<br>Master the Beat.</h1>
    <p class="hero-sub">A browser-based rhythm game that works with your electronic drum kit or keyboard. No download needed.</p>
    <a href="/register.php" class="btn btn-primary btn-lg">Start Free Trial — <?= TRIAL_DAYS_SELF ?> Days Free</a>
    <p class="hero-note">No credit card required for trial.</p>
  </div>
</header>

<section class="features">
  <div class="container">
    <div class="feature-grid">
      <div class="feature-card">
        <div class="feature-icon">🥁</div>
        <h3>E-Drum Support</h3>
        <p>Connect any electronic drum kit via USB MIDI. All 8 pads mapped out of the box.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🎵</div>
        <h3>Growing Song Library</h3>
        <p>Play along to a curated library of songs with detailed note charts.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">⭐</div>
        <h3>Score &amp; Improve</h3>
        <p>Track your PERFECT / GOOD / MISS accuracy and grade on every song.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🌐</div>
        <h3>Play Anywhere</h3>
        <p>Works in Chrome and Edge on any device. No install, no plugins.</p>
      </div>
    </div>
  </div>
</section>

<section class="pricing">
  <div class="container">
    <h2>Simple Pricing</h2>
    <div class="price-card">
      <div class="price-amount"><?= $price ?><span>/month</span></div>
      <ul class="price-features">
        <li>✓ Full drum game access</li>
        <li>✓ Complete song library</li>
        <li>✓ Profile customisation</li>
        <li>✓ Exclusive member pages</li>
        <li>✓ Cancel anytime</li>
      </ul>
      <a href="/register.php" class="btn btn-primary">Try Free for <?= TRIAL_DAYS_SELF ?> Days</a>
    </div>
  </div>
</section>

<footer class="site-footer">
  <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p>
</footer>

<?php require __DIR__ . '/includes/footer.php'; ?>
