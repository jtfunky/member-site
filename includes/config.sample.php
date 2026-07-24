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
define('SITE_NAME', 'Zach Alcasid Drum Academy');
define('MAIL_FROM', 'noreply@example.com');

// ── AI feedback (Claude) ──────────────────────────────────
define('ANTHROPIC_API_KEY',  '');            // sk-ant-... (empty = feature off)
define('AI_FEEDBACK_MODEL',  'claude-haiku-4-5');

// ── Pricing ───────────────────────────────────────────────
define('PRICE_PHP',  499.00);
define('PRICE_USD',   20.00);

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
define('UPLOAD_INVESTOR_PROOF_DIR', __DIR__ . '/../uploads/investor-proofs/');
define('UPLOAD_INVESTOR_PROOF_URL', SITE_URL . '/uploads/investor-proofs/');

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

// ── Notifications ─────────────────────────────────────────
// Extra email(s) — comma-separated — that also receive NEW-SIGNUP notifications
// (new registrations + enrollment submissions) in addition to admin accounts.
// Example: define('NOTIFY_CC', 'partner@example.com, assistant@example.com');
define('NOTIFY_CC', '');

// ── Admin device lock ─────────────────────────────────────
// When true, full admins can only reach the admin area from a device they've
// registered via an emailed one-time code. Leave false until you've confirmed
// email works — otherwise you could lock yourself out. Editors/Partners/students
// are unaffected. Escape hatch: set false here, or delete the admin_devices row.
define('ADMIN_DEVICE_LOCK', false);

// ── Payment ───────────────────────────────────────────────
define('PAYMENT_MODE', 'dummy');   // 'stripe' / 'maya' once keys are set

// Paid song requests (see includes/song_requests.php). Fixed price for any
// student-requested song; charged via Maya once its keys are set.
define('SONG_REQUEST_PRICE',    149);     // amount in SONG_REQUEST_CURRENCY
define('SONG_REQUEST_CURRENCY', 'PHP');

// Practice-plan session games: minimum accuracy (%) to pass a session and unlock
// the next one (see includes/practice_plan.php).
define('PLAN_PASS_ACCURACY', 80);
// Maya keys (fill when credentials arrive; the request flow works manually until then).
// Set PAYMENT_MODE to 'maya' once these are live to switch on online payment.
define('MAYA_ENV',         'sandbox');   // 'sandbox' | 'production'
define('MAYA_PUBLIC_KEY',  '');          // pk-...  (used to create checkouts)
define('MAYA_SECRET_KEY',  '');          // sk-...  (used to verify payments + register webhooks)
define('STRIPE_PUBLIC_KEY', '');
define('STRIPE_SECRET_KEY', '');
define('STRIPE_WEBHOOK_SECRET', '');
define('STRIPE_PRICE_ID_PHP', '');
define('STRIPE_PRICE_ID_USD', '');
