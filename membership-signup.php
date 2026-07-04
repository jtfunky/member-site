<?php
/**
 * Merged into register.php — register.php is now the single self-serve signup
 * (name + email + password + social login; the rest of the profile is collected
 * by the mandatory complete-profile step). Kept as a redirect so existing links
 * and bookmarks to this URL still work.
 */
require_once __DIR__ . '/includes/config.php';
header('Location: ' . SITE_URL . '/register.php', true, 302);
exit;
