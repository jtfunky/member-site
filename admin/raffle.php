<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = requireAdmin();
$db    = db();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_campaign') {
        $name        = trim($_POST['name'] ?? '');
        $slug        = trim($_POST['slug'] ?? '');
        $startAt     = trim($_POST['start_at'] ?? '');
        $endAt       = trim($_POST['end_at'] ?? '');
        $prizeDays   = max(1, (int) ($_POST['prize_days'] ?? 30));
        $ticketCount = max(0, (int) ($_POST['prize_ticket_count'] ?? 0));
        $ticketDesc  = trim($_POST['prize_ticket_desc'] ?? '');

        if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            $error = 'Name is required and slug must be lowercase letters/numbers/hyphens only.';
        } elseif (!$startAt || !$endAt || strtotime($endAt) <= strtotime($startAt)) {
            $error = 'End date must be after the start date.';
        } else {
            $dup = $db->prepare('SELECT id FROM raffle_campaigns WHERE slug=?');
            $dup->execute([$slug]);
            if ($dup->fetch()) {
                $error = 'That slug is already in use by another campaign.';
            } else {
                $db->prepare(
                    'INSERT INTO raffle_campaigns (name, slug, start_at, end_at, prize_days, prize_ticket_count, prize_ticket_desc, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $name, $slug, date('Y-m-d H:i:s', strtotime($startAt)), date('Y-m-d H:i:s', strtotime($endAt)),
                    $prizeDays, $ticketCount, $ticketDesc !== '' ? $ticketDesc : null, $admin['id'],
                ]);
                $message = "Campaign \"{$name}\" created. Share: " . SITE_URL . '/raffle-signup.php?c=' . urlencode($slug);
            }
        }

    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        $db->prepare('UPDATE raffle_campaigns SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        $message = 'Campaign status toggled.';

    } elseif ($action === 'delete_campaign') {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        // raffle_entries has ON DELETE CASCADE on campaign_id, so its entrant/
        // winner rows for this campaign are removed automatically — nothing
        // else references raffle_campaigns.
        $name = $db->prepare('SELECT name FROM raffle_campaigns WHERE id=?');
        $name->execute([$id]);
        $name = $name->fetchColumn();
        $db->prepare('DELETE FROM raffle_campaigns WHERE id=?')->execute([$id]);
        $message = $name ? "Campaign \"{$name}\" deleted." : 'Campaign deleted.';

    } elseif ($action === 'edit_campaign') {
        $id          = (int) ($_POST['campaign_id'] ?? 0);
        $endAt       = trim($_POST['end_at'] ?? '');
        $prizeDays   = max(1, (int) ($_POST['prize_days'] ?? 30));
        $ticketCount = max(0, (int) ($_POST['prize_ticket_count'] ?? 0));
        $ticketDesc  = trim($_POST['prize_ticket_desc'] ?? '');
        if ($endAt) {
            $db->prepare('UPDATE raffle_campaigns SET end_at=?, prize_days=?, prize_ticket_count=?, prize_ticket_desc=? WHERE id=?')
               ->execute([date('Y-m-d H:i:s', strtotime($endAt)), $prizeDays, $ticketCount, $ticketDesc !== '' ? $ticketDesc : null, $id]);
            $message = 'Campaign updated.';
        }

    } elseif ($action === 'draw') {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        $n  = max(1, (int) ($_POST['n'] ?? 1));
        $eligible = entryCount($id) - winnerCount($id);
        if ($eligible <= 0) {
            $error = 'No eligible entrants remain for this campaign.';
        } else {
            $drawn = drawWinners($id, $n, (int) $admin['id']);
            $names = array_map(fn($w) => $w['first_name'] . ' (' . $w['email'] . ')', $drawn);
            if ($n > $eligible) {
                $message = "Only {$eligible} eligible entrant(s) remained — drew all of them: " . implode(', ', $names);
            } else {
                $message = 'Drew ' . count($drawn) . ' winner(s): ' . implode(', ', $names);
            }
        }
    }
}

$campaignId = (int) ($_GET['campaign'] ?? 0);
$campaign   = null;
$entrants   = [];
if ($campaignId) {
    $c = $db->prepare('SELECT * FROM raffle_campaigns WHERE id=?');
    $c->execute([$campaignId]);
    $campaign = $c->fetch();
    if ($campaign) $entrants = listEntrants($campaignId);
}

$campaigns = $db->query(
    'SELECT rc.*,
     (SELECT COUNT(*) FROM raffle_entries re WHERE re.campaign_id = rc.id) AS entrant_count,
     (SELECT COUNT(*) FROM raffle_entries re WHERE re.campaign_id = rc.id AND re.is_winner = 1) AS winner_count
     FROM raffle_campaigns rc ORDER BY rc.created_at DESC'
)->fetchAll();

$pageTitle = 'Raffle — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Raffle</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$campaign): ?>

<!-- Create Campaign -->
<details class="collapsible">
  <summary class="btn btn-primary">+ Create Campaign</summary>
  <form method="POST" class="inline-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_campaign">
    <div class="form-row">
      <div class="form-group"><label>Name *</label><input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"></div>
      <div class="form-group"><label>Slug * <span class="field-hint">(lowercase, hyphens only — used in the signup URL)</span></label><input type="text" name="slug" pattern="^[a-z0-9-]+$" required value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Starts *</label><input type="datetime-local" name="start_at" required></div>
      <div class="form-group"><label>Ends *</label><input type="datetime-local" name="end_at" required></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Prize — days of free access</label><input type="number" name="prize_days" value="30" min="1"></div>
      <div class="form-group"><label>Prize — ticket count <span class="field-hint">(0 = no tickets)</span></label><input type="number" name="prize_ticket_count" value="0" min="0"></div>
      <div class="form-group"><label>Ticket description</label><input type="text" name="prize_ticket_desc" placeholder="e.g. 2 tickets to the Summer Concert"></div>
    </div>
    <button type="submit" class="btn btn-primary">Create Campaign</button>
  </form>
</details>

<h2>Campaigns</h2>
<div class="table-scroll">
<table class="data-table">
  <thead><tr><th>Name</th><th>Status</th><th>Window</th><th>Prize</th><th>Entrants</th><th>Winners</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($campaigns as $c): $st = campaignStatus($c); ?>
    <tr>
      <td><?= htmlspecialchars($c['name']) ?><?= $c['is_active'] ? '' : ' <span class="badge">paused</span>' ?></td>
      <td><span class="badge badge-<?= $st === 'open' ? 'active' : ($st === 'ended' ? 'expired' : 'trial') ?>"><?= $st ?></span></td>
      <td><?= date('M j', strtotime($c['start_at'])) ?> – <?= date('M j, Y', strtotime($c['end_at'])) ?></td>
      <td><?= (int) $c['prize_days'] ?> days<?= (int) $c['prize_ticket_count'] > 0 ? ' + ' . (int) $c['prize_ticket_count'] . ' ticket' . ((int) $c['prize_ticket_count'] === 1 ? '' : 's') : '' ?></td>
      <td><?= (int) $c['entrant_count'] ?></td>
      <td><?= (int) $c['winner_count'] ?></td>
      <td>
        <a href="/admin/raffle.php?campaign=<?= $c['id'] ?>" class="btn btn-ghost btn-xs">Manage</a>
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-xs"><?= $c['is_active'] ? 'Pause' : 'Resume' ?></button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete &quot;<?= htmlspecialchars(addslashes($c['name'])) ?>&quot;? This also deletes its <?= (int) $c['entrant_count'] ?> entrant record(s) and cannot be undone.');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_campaign">
          <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--danger,#f45a5a)">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php else: ?>

<div class="user-detail-card">
  <div class="user-detail-header">
    <h2><?= htmlspecialchars($campaign['name']) ?></h2>
    <span class="badge"><?= campaignStatus($campaign) ?></span>
    <a href="/admin/raffle.php" class="btn btn-ghost btn-sm">← Back</a>
  </div>

  <p><strong>Signup link:</strong> <span class="font-mono"><?= SITE_URL ?>/raffle-signup.php?c=<?= htmlspecialchars($campaign['slug']) ?></span></p>
  <p><strong>Window:</strong> <?= date('M j, Y g:ia', strtotime($campaign['start_at'])) ?> – <?= date('M j, Y g:ia', strtotime($campaign['end_at'])) ?></p>
  <p><strong>Prize:</strong> <?= (int) $campaign['prize_days'] ?> days free access<?php if ((int) $campaign['prize_ticket_count'] > 0): ?> + <?= (int) $campaign['prize_ticket_count'] ?> ticket<?= (int) $campaign['prize_ticket_count'] === 1 ? '' : 's' ?><?= $campaign['prize_ticket_desc'] ? ' (' . htmlspecialchars($campaign['prize_ticket_desc']) . ')' : '' ?><?php endif; ?></p>
  <p><strong>Entrants:</strong> <?= entryCount($campaign['id']) ?> · <strong>Winners so far:</strong> <?= winnerCount($campaign['id']) ?></p>

  <div class="detail-grid">
    <!-- Edit dates/prize -->
    <form method="POST" class="mini-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_campaign">
      <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
      <h3>Edit End Date / Prize</h3>
      <input type="datetime-local" name="end_at" value="<?= date('Y-m-d\TH:i', strtotime($campaign['end_at'])) ?>">
      <input type="number" name="prize_days" value="<?= (int) $campaign['prize_days'] ?>" min="1" placeholder="Days free access">
      <input type="number" name="prize_ticket_count" value="<?= (int) $campaign['prize_ticket_count'] ?>" min="0" placeholder="Ticket count">
      <input type="text" name="prize_ticket_desc" value="<?= htmlspecialchars($campaign['prize_ticket_desc'] ?? '') ?>" placeholder="Ticket description">
      <button type="submit" class="btn btn-secondary btn-sm">Save</button>
    </form>

    <!-- Draw winners -->
    <form method="POST" class="mini-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="draw">
      <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
      <h3>Draw Winner(s)</h3>
      <input type="number" name="n" value="1" min="1">
      <button type="submit" class="btn btn-primary btn-sm">Draw</button>
    </form>

    <!-- Delete campaign -->
    <form method="POST" class="mini-form" onsubmit="return confirm('Delete &quot;<?= htmlspecialchars(addslashes($campaign['name'])) ?>&quot;? This also deletes its <?= entryCount($campaign['id']) ?> entrant record(s) and cannot be undone.');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete_campaign">
      <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
      <h3>Delete Campaign</h3>
      <button type="submit" class="btn btn-sm" style="color:var(--danger,#f45a5a);border-color:var(--danger,#f45a5a)">Delete</button>
    </form>
  </div>

  <h3>Entrants</h3>
  <div class="table-scroll">
  <table class="data-table data-table--sm">
    <thead><tr><th>Name</th><th>Email</th><th>Entered</th><th>Winner?</th></tr></thead>
    <tbody>
    <?php foreach ($entrants as $e): ?>
      <tr class="<?= $e['is_winner'] ? 'row-inactive' : '' ?>">
        <td><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?></td>
        <td><?= htmlspecialchars($e['email']) ?></td>
        <td><?= formatManilaDate($e['created_at']) ?></td>
        <td><?= $e['is_winner'] ? '<span class="badge badge-active">Winner 🏆</span>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
