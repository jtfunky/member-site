<?php
/**
 * Social-login buttons partial. Include after setting (optional):
 *   $socialNext  string  — same-site path to return to after login (default dashboard)
 * Renders nothing if no provider is configured in config.php.
 */
require_once __DIR__ . '/oauth.php';

$enabledProviders = oauthEnabledProviders();
if ($enabledProviders):
    $socialNext = $socialNext ?? '/dashboard.php';
    $nextQ      = '&next=' . urlencode($socialNext);

    // Inline brand marks (no external requests → CSP-safe).
    $icons = [
        'google' => '<svg viewBox="0 0 18 18" width="18" height="18" aria-hidden="true">'
            . '<path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>'
            . '<path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>'
            . '<path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>'
            . '<path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.47.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>'
            . '</svg>',
        'facebook' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">'
            . '<path fill="#1877F2" d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.69.24 2.69.24v2.96h-1.52c-1.49 0-1.96.93-1.96 1.88V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"/>'
            . '</svg>',
        'discord' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">'
            . '<path fill="#5865F2" d="M20.32 4.37A19.8 19.8 0 0 0 15.4 2.8a.07.07 0 0 0-.08.04c-.21.38-.45.87-.62 1.25a18.3 18.3 0 0 0-5.4 0c-.17-.39-.41-.87-.63-1.25a.08.08 0 0 0-.08-.04A19.7 19.7 0 0 0 3.68 4.37a.07.07 0 0 0-.03.03C.53 9.05-.32 13.58.1 18.06a.08.08 0 0 0 .03.06 19.9 19.9 0 0 0 6 3.03.08.08 0 0 0 .09-.03c.46-.63.87-1.3 1.23-2a.08.08 0 0 0-.04-.11c-.65-.25-1.27-.55-1.87-.89a.08.08 0 0 1-.01-.13c.13-.1.25-.2.37-.3a.07.07 0 0 1 .08-.01c3.93 1.79 8.18 1.79 12.06 0a.07.07 0 0 1 .08.01c.12.1.24.2.37.3a.08.08 0 0 1-.01.13c-.6.35-1.22.64-1.87.89a.08.08 0 0 0-.04.11c.36.7.78 1.36 1.23 2a.08.08 0 0 0 .09.03 19.8 19.8 0 0 0 6.01-3.03.08.08 0 0 0 .03-.06c.5-5.18-.84-9.67-3.55-13.66a.06.06 0 0 0-.03-.03zM8.02 15.33c-1.18 0-2.16-1.09-2.16-2.42 0-1.34.96-2.42 2.16-2.42 1.21 0 2.18 1.09 2.16 2.42 0 1.33-.96 2.42-2.16 2.42zm7.97 0c-1.18 0-2.16-1.09-2.16-2.42 0-1.34.96-2.42 2.16-2.42 1.21 0 2.18 1.09 2.16 2.42 0 1.33-.95 2.42-2.16 2.42z"/>'
            . '</svg>',
    ];
?>
<div class="social-login">
  <div class="social-divider"><span>or continue with</span></div>
  <?php foreach ($enabledProviders as $p): $meta = oauthProvider($p); ?>
  <a class="btn-social btn-social--<?= $p ?>"
     href="/oauth-start.php?provider=<?= urlencode($p) ?><?= $nextQ ?>">
    <?= $icons[$p] ?? '' ?>
    <span>Continue with <?= htmlspecialchars($meta['name']) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
