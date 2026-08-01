<?php
// Provider redirects back here with ?code & ?state. Verify state, exchange the
// code for a token, fetch the profile, then either log in / create (login mode)
// or link to the current account (link mode).
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/oauth.php';

sessionStart();

$mode = $_SESSION['oauth_mode'] ?? 'login';

function oauthClear(): void {
    unset(
        $_SESSION['oauth_state'], $_SESSION['oauth_provider'], $_SESSION['oauth_next'],
        $_SESSION['oauth_mode'],  $_SESSION['oauth_link_user']
    );
}

// Abort: link failures return to the profile page, login failures to login.
function oauthFail(string $mode, string $msg): void {
    oauthClear();
    $target = $mode === 'link'
        ? '/profile.php?link_error=' . urlencode($msg)
        : '/login.php?social_error=' . urlencode($msg);
    header('Location: ' . SITE_URL . $target);
    exit;
}

$provider = $_GET['provider'] ?? '';
if (!oauthEnabled($provider)) oauthFail($mode, 'That sign-in option is not available.');

// User declined consent, or the provider returned an error.
if (!empty($_GET['error'])) oauthFail($mode, $mode === 'link' ? 'Connection was cancelled.' : 'Sign-in was cancelled.');

// Anti-CSRF: the state must match the one we stored, for the same provider.
$state     = $_GET['state'] ?? '';
$sessState = $_SESSION['oauth_state'] ?? '';
$sessProv  = $_SESSION['oauth_provider'] ?? '';
if ($state === '' || $sessState === '' || !hash_equals($sessState, $state) || $sessProv !== $provider) {
    oauthFail($mode, 'Security check failed. Please try again.');
}

$code = $_GET['code'] ?? '';
if ($code === '') oauthFail($mode, 'No authorization code was returned.');

$pname = oauthProvider($provider)['name'];

$token = oauthExchangeCode($provider, $code);
if (!$token) oauthFail($mode, "Could not complete sign-in with $pname. Please try again.");

$profile = oauthFetchProfile($provider, $token);
if (!$profile) oauthFail($mode, "Could not read your profile from $pname.");

// ── Link mode: attach this provider to the logged-in account ──
if ($mode === 'link') {
    $uid = (int) ($_SESSION['oauth_link_user'] ?? 0);
    $cur = getCurrentUser();
    if (!$cur || (int) $cur['id'] !== $uid) oauthFail('link', 'Please sign in and try linking again.');

    $res = oauthLinkToCurrentUser($uid, $provider, $profile);
    oauthClear();
    $target = ($res === true)
        ? '/profile.php?linked='     . urlencode($provider)
        : '/profile.php?link_error=' . urlencode($res);
    header('Location: ' . SITE_URL . $target);
    exit;
}

// ── Login / create mode ──
$sessionNext = $_SESSION['oauth_next'] ?? null;
$result      = oauthLoginOrCreate($provider, $profile);
oauthClear();

if (is_string($result)) oauthFail('login', $result);

$next = $sessionNext ?: defaultPostLoginRedirect($result);
header('Location: ' . SITE_URL . safeRedirectPath($next));
exit;
