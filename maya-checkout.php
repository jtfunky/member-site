<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maya.php';

$user = requireLogin();

$requestId = (int) ($_GET['request'] ?? 0);

// Load the request; it must belong to this user and be approved (payable).
$req = null;
if ($requestId) {
    $st = db()->prepare('SELECT * FROM song_requests WHERE id = ? AND user_id = ?');
    $st->execute([$requestId, (int) $user['id']]);
    $req = $st->fetch() ?: null;
}

if (!$req || $req['status'] !== 'approved') {
    header('Location: ' . SITE_URL . '/request-song.php');
    exit;
}

if (!mayaEnabled()) {
    // Keys not set yet — fall back to the manual flow.
    header('Location: ' . SITE_URL . '/request-song.php?nopay=1');
    exit;
}

$redirect = mayaCreateCheckout($req, $user);
if ($redirect) {
    header('Location: ' . $redirect);   // hand off to Maya's hosted checkout
    exit;
}

header('Location: ' . SITE_URL . '/request-song.php?failed=1');
exit;
