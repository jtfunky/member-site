<?php
/**
 * Maya payment webhook. Maya POSTs here on payment events (register the URL once
 * via maya-register-webhooks.php). We NEVER trust the body alone — we re-fetch the
 * payment with the secret key to confirm success + amount, then fulfill.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/song_requests.php';
require_once __DIR__ . '/includes/maya.php';

http_response_code(200);   // always ack so Maya doesn't retry-storm; work is guarded below

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];

// Pull the payment id + our reference out of whatever shape Maya sent.
$paymentId = $payload['id'] ?? ($payload['paymentId'] ?? '');
$ref       = $payload['requestReferenceNumber'] ?? ($payload['metadata']['requestReferenceNumber'] ?? '');
if (!$paymentId || !preg_match('/^req-(\d+)$/', (string) $ref, $m)) {
    error_log('Maya webhook: unrecognized payload: ' . $raw);
    exit;
}
$requestId = (int) $m[1];

// Only act on a request that's still awaiting payment (idempotent — ignores retries).
$st = db()->prepare('SELECT * FROM song_requests WHERE id = ?');
$st->execute([$requestId]);
$req = $st->fetch();
if (!$req || $req['status'] !== 'approved') exit;

// Verify server-side with the secret key.
$payment = mayaGetPayment((string) $paymentId);
$status  = strtoupper((string) ($payment['status'] ?? $payment['paymentStatus'] ?? ''));
$paidVal = (float) ($payment['amount'] ?? $payment['totalAmount']['value'] ?? 0);

if (!$payment || strpos($status, 'SUCCESS') === false) {
    error_log('Maya webhook: payment not successful for req ' . $requestId . ' (' . $status . ')');
    exit;
}
if ($paidVal + 0.01 < (float) $req['price']) {   // underpaid — don't grant
    error_log('Maya webhook: amount mismatch for req ' . $requestId . ' (' . $paidVal . ' < ' . $req['price'] . ')');
    exit;
}

fulfillPaidRequest($requestId, (string) $paymentId);
