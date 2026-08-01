<?php
// Start a flow:
//   • logged-out (GET)        → sign-in / sign-up with a provider
//   • logged-in (POST + CSRF) → link a provider to the current account
// An anti-CSRF `state` is stored for the callback to verify.
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/oauth.php';

sessionStart();

$provider = $_GET['provider'] ?? $_POST['provider'] ?? '';
if (!oauthEnabled($provider)) {
    header('Location: ' . SITE_URL . '/login.php?social_error=' . urlencode('That sign-in option is not available.'));
    exit;
}

$isLink = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['oauth_action'] ?? '') === 'link';

if ($isLink) {
    // State-changing action on the current account → require a CSRF token.
    verifyCsrf();
    $current = getCurrentUser();
    if (!$current) { header('Location: ' . SITE_URL . '/login.php'); exit; }
    $_SESSION['oauth_mode']      = 'link';
    $_SESSION['oauth_link_user'] = (int) $current['id'];
    $_SESSION['oauth_next']      = '/profile.php';
} else {
    if (isLoggedIn()) { header('Location: ' . SITE_URL . defaultPostLoginRedirect(getCurrentUser())); exit; }
    if (tooManyRequests('oauth', 20, 15)) {
        header('Location: ' . SITE_URL . '/login.php?social_error=' . urlencode('Too many attempts. Please wait a few minutes and try again.'));
        exit;
    }
    $_SESSION['oauth_mode'] = 'login';
    // Only carry an explicit deep-link through; an absent one is resolved to
    // the right default once oauth-callback.php knows who logged in.
    $_SESSION['oauth_next'] = (isset($_GET['next']) && $_GET['next'] !== '') ? safeRedirectPath($_GET['next']) : null;
}

$state = bin2hex(random_bytes(32));
$_SESSION['oauth_state']    = $state;
$_SESSION['oauth_provider'] = $provider;

header('Location: ' . oauthAuthUrl($provider, $state));
exit;
