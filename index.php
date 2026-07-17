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

<!-- Ambient callback to the game's note highway — same 10 lane colors from
     input.js getLaneColors(), drifting subtly behind the page. Hidden behind
     the opaque hero, only visible in the plainer sections below it. -->
<div class="falling-notes" aria-hidden="true">
  <span class="note" style="left:4%;  width:30px; background:#ef4444; box-shadow:0 0 10px #ef4444; animation-duration:14s; animation-delay:-2s;"></span>
  <span class="note" style="left:14%; width:36px; background:#f5f5f5; box-shadow:0 0 10px #f5f5f5; animation-duration:17s; animation-delay:-11s;"></span>
  <span class="note" style="left:24%; width:30px; background:#eab308; box-shadow:0 0 10px #eab308; animation-duration:12s; animation-delay:-5s;"></span>
  <span class="note" style="left:34%; width:42px; background:#60a5fa; box-shadow:0 0 10px #60a5fa; animation-duration:19s; animation-delay:-14s;"></span>
  <span class="note" style="left:46%; width:30px; background:#3b82f6; box-shadow:0 0 10px #3b82f6; animation-duration:13s; animation-delay:-3s;"></span>
  <span class="note" style="left:56%; width:36px; background:#22c55e; box-shadow:0 0 10px #22c55e; animation-duration:16s; animation-delay:-8s;"></span>
  <span class="note" style="left:66%; width:30px; background:#16a34a; box-shadow:0 0 10px #16a34a; animation-duration:11s; animation-delay:-1s;"></span>
  <span class="note" style="left:76%; width:42px; background:#06b6d4; box-shadow:0 0 10px #06b6d4; animation-duration:18s; animation-delay:-12s;"></span>
  <span class="note" style="left:86%; width:30px; background:#0891b2; box-shadow:0 0 10px #0891b2; animation-duration:15s; animation-delay:-6s;"></span>
  <span class="note" style="left:94%; width:36px; background:#f97316; box-shadow:0 0 10px #f97316; animation-duration:20s; animation-delay:-17s;"></span>
</div>

<nav class="top-nav">
  <div class="nav-brand"><i class="ti ti-music drum-icon" aria-hidden="true"></i> <span class="brand-name"><?= SITE_NAME ?></span><span class="brand-abbr">ZADA</span></div>
  <div class="nav-links">
    <a href="/login.php">Log In</a>
    <a href="/register.php" class="btn btn-primary btn-sm">Sign Up Free</a>
  </div>
</nav>

<header class="hero">
  <div class="hero-media">
    <picture>
      <source media="(max-width: 640px)" srcset="/assets/img/hero-drummer-mobile.jpg">
      <img src="/assets/img/hero-drummer.jpg" width="1920" height="1280" alt="Drummer playing an energetic fill on an acoustic kit" loading="eager" fetchpriority="high">
    </picture>
  </div>
  <!-- Same falling-note callback as .falling-notes, but confined to the hero
       and recolored to plain illuminated white so it reads as light drifting
       over the photo rather than competing with its own warm colors. -->
  <div class="hero-notes" aria-hidden="true">
    <span class="note" style="left:8%;  width:28px; animation-duration:13s; animation-delay:-4s;"></span>
    <span class="note" style="left:22%; width:34px; animation-duration:17s; animation-delay:-9s;"></span>
    <span class="note" style="left:38%; width:26px; animation-duration:11s; animation-delay:-1s;"></span>
    <span class="note" style="left:55%; width:32px; animation-duration:16s; animation-delay:-7s;"></span>
    <span class="note" style="left:70%; width:26px; animation-duration:14s; animation-delay:-12s;"></span>
    <span class="note" style="left:83%; width:34px; animation-duration:19s; animation-delay:-3s;"></span>
    <span class="note" style="left:94%; width:26px; animation-duration:12s; animation-delay:-6s;"></span>
  </div>
  <div class="hero-inner">
    <div class="hero-text">
      <h1>Play Drums.<br>Easy and Fun.</h1>
      <p class="hero-sub">A browser-based rhythm game that works with your electronic drum kit or acoustic drums. No download needed.</p>
      <a href="/register.php" class="btn btn-primary btn-lg">Start Free Trial — Signup Now!</a>
    </div>
  </div>
</header>

<section class="features">
  <div class="container">
    <div class="feature-grid">
      <div class="feature-card feature-card--amber">
        <div class="feature-icon"><i class="ti ti-usb" aria-hidden="true"></i></div>
        <h3>E-Drum Support</h3>
        <p>Connect any electronic drum kit via USB MIDI. All 8 pads mapped out of the box.</p>
      </div>
      <div class="feature-card feature-card--red">
        <div class="feature-icon"><i class="ti ti-playlist" aria-hidden="true"></i></div>
        <h3>New Songs Every Week</h3>
        <p>Play along with your favorite songs and exercises.</p>
      </div>
      <div class="feature-card feature-card--gold">
        <div class="feature-icon"><i class="ti ti-star" aria-hidden="true"></i></div>
        <h3>Score &amp; Improve</h3>
        <p>Track your PERFECT / GOOD / MISS accuracy and grade on every song.</p>
      </div>
      <div class="feature-card feature-card--blue">
        <div class="feature-icon"><i class="ti ti-world" aria-hidden="true"></i></div>
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
        <li><i class="ti ti-check" aria-hidden="true"></i> Full drum game access</li>
        <li><i class="ti ti-check" aria-hidden="true"></i> Complete song library</li>
        <li><i class="ti ti-check" aria-hidden="true"></i> Profile customisation</li>
        <li><i class="ti ti-check" aria-hidden="true"></i> Exclusive member pages</li>
        <li><i class="ti ti-check" aria-hidden="true"></i> Cancel anytime</li>
      </ul>
      <a href="/register.php" class="btn btn-primary">Try Free for <?= TRIAL_DAYS_SELF ?> Days</a>
    </div>
  </div>
</section>

<footer class="site-footer">
  <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p>
</footer>

<?php require __DIR__ . '/includes/footer.php'; ?>
