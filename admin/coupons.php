<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = requireAdmin();
$db    = db();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code          = strtoupper(trim($_POST['code'] ?? ''));
        $discountType  = in_array($_POST['discount_type'] ?? '', ['percent', 'fixed'], true) ? $_POST['discount_type'] : 'percent';
        $discountValue = (float) ($_POST['discount_value'] ?? 0);
        $maxUses       = trim($_POST['max_uses'] ?? '');
        $expiresAt     = trim($_POST['expires_at'] ?? '');

        if ($code === '' || $discountValue <= 0) {
            $error = 'Code and a positive discount value are required.';
        } else {
            $dup = $db->prepare('SELECT id FROM coupons WHERE code=?');
            $dup->execute([$code]);
            if ($dup->fetch()) {
                $error = 'That code already exists.';
            } else {
                $db->prepare(
                    'INSERT INTO coupons (code, discount_type, discount_value, max_uses, expires_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $code, $discountType, $discountValue,
                    $maxUses !== '' ? (int) $maxUses : null,
                    $expiresAt !== '' ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null,
                    $admin['id'],
                ]);
                $message = "Coupon \"{$code}\" created.";
            }
        }

    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        $message = 'Coupon status toggled.';
    }
}

$coupons = $db->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll();

$pageTitle = 'Coupons — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Coupons</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<p class="field-hint">Redeemable at checkout (payment.php) for the paid membership. Only wired into the "dummy" test-mode payment path today — if PAYMENT_MODE is Stripe in production, coupons won't yet reduce the actual charge (see the code comment in payment.php).</p>

<details class="collapsible">
  <summary class="btn btn-primary">+ Create Coupon</summary>
  <form method="POST" class="inline-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group"><label>Code *</label><input type="text" name="code" required value="<?= htmlspecialchars($_POST['code'] ?? '') ?>"></div>
      <div class="form-group">
        <label>Type</label>
        <select name="discount_type">
          <option value="percent">Percent off</option>
          <option value="fixed">Fixed amount off</option>
        </select>
      </div>
      <div class="form-group"><label>Value *</label><input type="number" step="0.01" name="discount_value" required></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Max Uses <span class="field-hint">(blank = unlimited)</span></label><input type="number" name="max_uses" min="1"></div>
      <div class="form-group"><label>Expires <span class="field-hint">(blank = never)</span></label><input type="datetime-local" name="expires_at"></div>
    </div>
    <button type="submit" class="btn btn-primary">Create</button>
  </form>
</details>

<div class="table-scroll">
<table class="data-table">
  <thead><tr><th>Code</th><th>Status</th><th>Discount</th><th>Uses</th><th>Expires</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($coupons as $c): ?>
    <?php $expired = $c['expires_at'] && strtotime($c['expires_at']) < time(); ?>
    <tr>
      <td class="font-mono"><?= htmlspecialchars($c['code']) ?></td>
      <td><span class="badge badge-<?= (!$c['is_active'] || $expired) ? 'expired' : 'active' ?>"><?= (!$c['is_active'] || $expired) ? 'inactive' : 'active' ?></span></td>
      <td><?= $c['discount_type'] === 'percent' ? (float) $c['discount_value'] . '%' : '$' . number_format((float) $c['discount_value'], 2) ?> off</td>
      <td><?= (int) $c['uses_count'] ?><?= $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : '' ?></td>
      <td><?= $c['expires_at'] ? date('M j, Y', strtotime($c['expires_at'])) : 'Never' ?></td>
      <td>
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-xs"><?= $c['is_active'] ? 'Pause' : 'Resume' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
