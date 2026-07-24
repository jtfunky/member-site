<?php
/**
 * One-time (admin) utility to register the Maya webhook URLs so Maya notifies
 * maya-webhook.php on payment events. Run after setting the Maya keys + PAYMENT_MODE.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/maya.php';

$user = requireAdmin();

$results = [];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!mayaEnabled()) {
        $error = 'Set MAYA_PUBLIC_KEY, MAYA_SECRET_KEY, and PAYMENT_MODE="maya" in config.php first.';
    } else {
        $callback = SITE_URL . '/maya-webhook.php';
        foreach (['PAYMENT_SUCCESS', 'CHECKOUT_SUCCESS'] as $event) {
            [$status, $resp] = mayaRequest('POST', '/checkout/v1/webhooks', MAYA_SECRET_KEY, [
                'name'        => $event,
                'callbackUrl' => $callback,
            ]);
            $results[$event] = ['status' => $status, 'resp' => $resp];
        }
    }
}

$pageTitle = 'Maya Webhooks — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>
<main class="container">
<h1>Maya Webhooks</h1>
<p class="muted">
  Registers <code><?= htmlspecialchars(SITE_URL) ?>/maya-webhook.php</code> with Maya for
  <code>PAYMENT_SUCCESS</code> and <code>CHECKOUT_SUCCESS</code>. Run once after your Maya keys
  are set. Environment: <strong><?= htmlspecialchars(MAYA_ENV) ?></strong> ·
  Maya enabled: <strong><?= mayaEnabled() ? 'yes' : 'no' ?></strong>.
</p>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($results): ?>
  <h2>Result</h2>
  <ul>
    <?php foreach ($results as $event => $r): ?>
      <li><strong><?= htmlspecialchars($event) ?></strong>:
        HTTP <?= (int) $r['status'] ?> —
        <?= $r['status'] >= 200 && $r['status'] < 300 ? 'registered <i class="ti ti-check" style="color:var(--success)"></i>' : 'failed <i class="ti ti-x" style="color:var(--danger)"></i>' ?>
        <pre style="white-space:pre-wrap;font-size:.78rem"><?= htmlspecialchars(json_encode($r['resp'], JSON_PRETTY_PRINT)) ?></pre>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<form method="POST">
  <?= csrfField() ?>
  <button type="submit" class="btn btn-primary">Register webhooks</button>
</form>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
