<?php
/**
 * Maya (PayMaya) Checkout integration — SKELETON.
 *
 * Wired to the paid-song-request flow: maya-checkout.php creates a checkout and
 * redirects the buyer to Maya; on success Maya calls maya-webhook.php, which
 * verifies the payment and calls fulfillPaidRequest() (the same seam the manual
 * "Mark paid" admin button uses).
 *
 * ⚠️ Untested against a live Maya account. Before going live: create SANDBOX keys,
 * set MAYA_ENV='sandbox', run maya-register-webhooks.php once, and test end-to-end.
 * Verify field names/endpoints against current Maya docs (https://developers.maya.ph).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

if (!defined('MAYA_ENV'))        define('MAYA_ENV', 'sandbox');
if (!defined('MAYA_PUBLIC_KEY')) define('MAYA_PUBLIC_KEY', '');
if (!defined('MAYA_SECRET_KEY')) define('MAYA_SECRET_KEY', '');

function mayaEnabled(): bool {
    return (defined('PAYMENT_MODE') && PAYMENT_MODE === 'maya')
        && MAYA_PUBLIC_KEY !== '' && MAYA_SECRET_KEY !== '';
}

function mayaBaseUrl(): string {
    return MAYA_ENV === 'production'
        ? 'https://pg.paymaya.com'
        : 'https://pg-sandbox.paymaya.com';
}

// Basic auth header value from a Maya key (key as username, empty password).
function mayaAuth(string $key): string {
    return 'Basic ' . base64_encode($key . ':');
}

/**
 * Low-level JSON request. Returns [httpStatus(int), body(array|null)].
 */
function mayaRequest(string $method, string $path, string $key, ?array $body = null): array {
    $ch = curl_init(mayaBaseUrl() . $path);
    $headers = ['Authorization: ' . mayaAuth($key), 'Content-Type: application/json', 'Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $raw ? (json_decode($raw, true) ?: null) : null];
}

/**
 * Create a Checkout for a song request. Returns the redirect URL, or null on error.
 * requestReferenceNumber encodes the request id so the webhook can match it back.
 */
function mayaCreateCheckout(array $request, array $user): ?string {
    $amount = number_format((float) $request['price'], 2, '.', '');
    $body = [
        'totalAmount' => ['value' => $amount, 'currency' => $request['currency'] ?: 'PHP'],
        'buyer' => [
            'firstName' => $user['first_name'] ?: ($user['username'] ?? 'Member'),
            'contact'   => ['email' => $user['email'] ?? ''],
        ],
        'items' => [[
            'name'        => 'Song request: ' . ($request['title'] ?: $request['youtube_url']),
            'quantity'    => 1,
            'totalAmount' => ['value' => $amount, 'currency' => $request['currency'] ?: 'PHP'],
        ]],
        'redirectUrl' => [
            'success' => SITE_URL . '/request-song.php?paid=1',
            'failure' => SITE_URL . '/request-song.php?failed=1',
            'cancel'  => SITE_URL . '/request-song.php?cancelled=1',
        ],
        'requestReferenceNumber' => 'req-' . (int) $request['id'],
    ];
    [$status, $resp] = mayaRequest('POST', '/checkout/v1/checkouts', MAYA_PUBLIC_KEY, $body);
    if ($status >= 200 && $status < 300 && !empty($resp['redirectUrl'])) return $resp['redirectUrl'];
    error_log('Maya checkout failed (' . $status . '): ' . json_encode($resp));
    return null;
}

/** Fetch a payment by id (secret key) to VERIFY status server-side. Null on error. */
function mayaGetPayment(string $paymentId): ?array {
    [$status, $resp] = mayaRequest('GET', '/payments/v1/payments/' . rawurlencode($paymentId), MAYA_SECRET_KEY);
    return ($status >= 200 && $status < 300) ? $resp : null;
}
