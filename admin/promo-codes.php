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
        $code      = strtoupper(trim($_POST['code'] ?? ''));
        // Preset durations only (see the <select> below) — 100 years stands in
        // for "unlimited" so it flows through the same day-based extendAccess()
        // math as everything else, no special-casing needed anywhere else.
        $durations = ['1m' => 30, '3m' => 90, '6m' => 180, '12m' => 365, 'unlimited' => 36500];
        $bonusDays = $durations[$_POST['duration'] ?? ''] ?? 30;
        $maxUses   = trim($_POST['max_uses'] ?? '');
        $expiresAt = trim($_POST['expires_at'] ?? '');

        if ($code === '') {
            $error = 'Code is required.';
        } else {
            $dup = $db->prepare('SELECT id FROM promo_codes WHERE code=?');
            $dup->execute([$code]);
            if ($dup->fetch()) {
                $error = 'That code already exists.';
            } else {
                $db->prepare(
                    'INSERT INTO promo_codes (code, bonus_days, max_uses, expires_at, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $code, $bonusDays,
                    $maxUses !== '' ? (int) $maxUses : null,
                    $expiresAt !== '' ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null,
                    $admin['id'],
                ]);
                $message = "Promo code \"{$code}\" created.";
            }
        }

    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare('UPDATE promo_codes SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        $message = 'Promo code status toggled.';
    }
}

$codes = $db->query('SELECT * FROM promo_codes ORDER BY created_at DESC')->fetchAll();

$pageTitle = 'Promo Codes — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Promo Codes</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<p class="field-hint">Redeemable on the normal sign-up page (register.php) or by existing members on their profile page — separate from the raffle, which uses its own dedicated landing page.</p>

<details class="collapsible">
  <summary class="btn btn-primary">+ Create Promo Code</summary>
  <form method="POST" class="inline-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group"><label>Code *</label><input type="text" name="code" required value="<?= htmlspecialchars($_POST['code'] ?? '') ?>"></div>
      <div class="form-group"><label>Duration</label>
        <select name="duration">
          <option value="1m">1 month</option>
          <option value="3m">3 months</option>
          <option value="6m">6 months</option>
          <option value="12m" selected>12 months</option>
          <option value="unlimited">Unlimited</option>
        </select>
      </div>
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
  <thead><tr><th>Code</th><th>Status</th><th>Bonus</th><th>Uses</th><th>Expires</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($codes as $c): ?>
    <?php $expired = $c['expires_at'] && strtotime($c['expires_at']) < time(); ?>
    <tr>
      <td class="font-mono"><?= htmlspecialchars($c['code']) ?></td>
      <td><span class="badge badge-<?= (!$c['is_active'] || $expired) ? 'expired' : 'active' ?>"><?= (!$c['is_active'] || $expired) ? 'inactive' : 'active' ?></span></td>
      <td><?= (int) $c['bonus_days'] >= 36500 ? 'Unlimited' : (int) $c['bonus_days'] . ' days' ?></td>
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
