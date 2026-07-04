<?php
// ─────────────────────────────────────────────────────────────
//  SAMPLE CONFIG — copy this file to `config.php` and fill in the
//  real values. config.php is gitignored so secrets never go online.
// ─────────────────────────────────────────────────────────────

// ── Database ──────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// ── Site ──────────────────────────────────────────────────
define('SITE_URL', 'https://members.example.com');
define('SITE_NAME', 'DrumKit');
define('MAIL_FROM', 'noreply@example.com');

// ── AI feedback (Claude) ──────────────────────────────────
define('ANTHROPIC_API_KEY',  '');            // sk-ant-... (empty = feature off)
define('AI_FEEDBACK_MODEL',  'claude-haiku-4-5');

// ── Pricing ───────────────────────────────────────────────
define('PRICE_PHP',  299.00);
define('PRICE_USD',    6.99);

// ── Access periods ────────────────────────────────────────
define('TRIAL_DAYS_SELF',   15);
define('SUBSCRIPTION_DAYS', 30);
define('CANCEL_WINDOW_DAYS', 7);

// ── Uploads ───────────────────────────────────────────────
define('UPLOAD_AUDIO_DIR',  __DIR__ . '/../uploads/audio/');
define('UPLOAD_AVATAR_DIR', __DIR__ . '/../uploads/avatars/');
define('UPLOAD_AUDIO_URL',  SITE_URL . '/uploads/audio/');
define('UPLOAD_AVATAR_URL', SITE_URL . '/uploads/avatars/');
define('UPLOAD_PROOF_DIR',  __DIR__ . '/../uploads/proofs/');
define('UPLOAD_PROOF_URL',  SITE_URL . '/uploads/proofs/');

// ── Security ──────────────────────────────────────────────
define('SESSION_TIMEOUT_SECONDS', 30 * 60);
define('MAX_LOGIN_ATTEMPTS',      5);
define('LOGIN_LOCKOUT_MINUTES',   15);
define('LOGIN_LOCKOUT_RESET_MINUTES', 15);
define('MAX_AUDIO_SIZE_MB',       50);
define('ADMIN_ALLOWED_IPS',       []);

// ── Social login (OAuth2) ─────────────────────────────────
// Register the callback URL with each provider:
//   {SITE_URL}/oauth-callback.php?provider=google|facebook|discord
define('OAUTH_GOOGLE_CLIENT_ID',       '');
define('OAUTH_GOOGLE_CLIENT_SECRET',   '');
define('OAUTH_FACEBOOK_CLIENT_ID',     '');
define('OAUTH_FACEBOOK_CLIENT_SECRET', '');
define('OAUTH_DISCORD_CLIENT_ID',      '');
define('OAUTH_DISCORD_CLIENT_SECRET',  '');

// ── Payment ───────────────────────────────────────────────
define('PAYMENT_MODE', 'dummy');   // 'stripe' / 'maya' once keys are set
define('STRIPE_PUBLIC_KEY', '');
define('STRIPE_SECRET_KEY', '');
define('STRIPE_WEBHOOK_SECRET', '');
define('STRIPE_PRICE_ID_PHP', '');
define('STRIPE_PRICE_ID_USD', '');
