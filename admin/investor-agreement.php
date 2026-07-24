<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = requireAdmin();
$db    = db();

const DEFAULT_INVESTOR_DOC_ID = 'groovequest-codezero-leo-sison-revshare-v1';

// "New agreement" just needs a fresh, unused doc_id — the row itself gets
// created automatically the first time someone opens the signing page for it.
if (!empty($_GET['new'])) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $rand  = '';
    for ($i = 0; $i < 8; $i++) $rand .= $chars[random_int(0, strlen($chars) - 1)];
    $newDocId = 'groovequest-' . strtolower($rand);
    header('Location: ' . SITE_URL . '/admin/investor-agreement.php?doc=' . urlencode($newDocId));
    exit;
}

$docId = trim((string) ($_GET['doc'] ?? '')) ?: DEFAULT_INVESTOR_DOC_ID;

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {
    verifyCsrf();
    $postedDocId = trim((string) ($_POST['doc_id'] ?? ''));
    if ($postedDocId !== '') {
        $db->prepare(
            'UPDATE investor_payment_proofs SET confirmed = 1, confirmed_at = NOW(), confirmed_by = :admin_id
             WHERE doc_id = :doc_id'
        )->execute([':admin_id' => $admin['id'], ':doc_id' => $postedDocId]);
        $message = 'Payment marked as confirmed.';
    }
}

// The Groove Quest revenue-share agreements live in their own table (created by
// the standalone e-sign tool at /investor/), one row per doc_id. This page is
// read-only — it surfaces status/terms for the admin and links out to the real
// editable/signable page (still token-protected, since an investor has no
// login until they sign). All documents share that same access token, so
// treat the doc_id like a capability — anyone with a link can reach that one
// agreement, same as before this page existed.
$row = $db->prepare('SELECT state_json, updated_at FROM groovequest_agreements WHERE doc_id = ?');
$row->execute([$docId]);
$row = $row->fetch();

$state = $row ? json_decode($row['state_json'], true) : null;

$ownerLink    = 'https://members.zachalcasid.com/investor/GrooveQuest_Agreement_DB.html?doc=' . urlencode($docId);
$investorLink = $ownerLink . '&investor=1';

// All known agreements, for the picker below.
$allRows = $db->query('SELECT doc_id, updated_at, state_json FROM groovequest_agreements ORDER BY updated_at DESC')->fetchAll();

$proofStmt = $db->prepare('SELECT * FROM investor_payment_proofs WHERE doc_id = ?');
$proofStmt->execute([$docId]);
$proof = $proofStmt->fetch();

$pageTitle = 'Investor Agreement — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Investor Agreement</h1>
  <div class="admin-nav-pills">
    <a href="/admin/">Overview</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/students.php">Students</a>
    <a href="/admin/sessions.php">Sessions</a>
    <a href="/admin/songs.php">Songs</a>
    <a href="/admin/placement-tests.php">Placement Tests</a>
    <a href="/admin/investor-agreement.php" class="active">Investor Agreement</a>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (count($allRows) > 1 || $docId !== DEFAULT_INVESTOR_DOC_ID): ?>
<div class="mini-form" style="margin-bottom:20px;">
  <h3>All Agreements</h3>
  <div class="table-scroll">
  <table class="data-table data-table--sm">
    <thead><tr><th>Investor</th><th>Reference</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($allRows as $r): ?>
      <?php $rs = json_decode($r['state_json'], true) ?: []; ?>
      <tr<?= $r['doc_id'] === $docId ? ' style="background:var(--bg2)"' : '' ?>>
        <td><?= !empty($rs['investorLegalName']) ? htmlspecialchars($rs['investorLegalName']) : '(unnamed)' ?></td>
        <td><?= htmlspecialchars($rs['refId'] ?? '—') ?></td>
        <td><?= date('M j, Y', strtotime($r['updated_at'])) ?></td>
        <td><a href="/admin/investor-agreement.php?doc=<?= urlencode($r['doc_id']) ?>" class="btn btn-ghost btn-xs">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="footer-actions" style="margin-bottom:20px;">
  <a href="/admin/investor-agreement.php?new=1" class="btn btn-primary">+ New Agreement</a>
</div>

<?php if (!$state): ?>
  <div class="alert alert-error">
    No agreement record found yet for this document — open the
    <a href="<?= htmlspecialchars($ownerLink) ?>" target="_blank" rel="noopener">edit page</a>
    once to initialize it, then this page will show its status.
  </div>
<?php else: ?>
  <?php
    $zachAt = $state['zachAt'] ?? null;
    $leoAt  = $state['leoAt'] ?? null;
    if ($zachAt && $leoAt) {
        $statusLabel = 'Fully executed';
        $statusClass = 'active';
    } elseif ($zachAt || $leoAt) {
        $statusLabel = $zachAt ? "Awaiting Investor's signature" : "Awaiting Owner's signature";
        $statusClass = 'trial';
    } else {
        $statusLabel = 'Awaiting signatures';
        $statusClass = 'pending';
    }
  ?>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-value"><span class="badge badge-<?= $statusClass ?>" style="font-size:1rem;"><?= htmlspecialchars($statusLabel) ?></span></div>
      <div class="stat-label">Status</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= htmlspecialchars($state['refId'] ?? '—') ?></div>
      <div class="stat-label">Reference</div>
    </div>
    <div class="stat-card">
      <div class="stat-value" style="font-size:1.2rem;"><?= $row['updated_at'] ? date('M j, Y g:i A', strtotime($row['updated_at'])) : '—' ?></div>
      <div class="stat-label">Last Updated</div>
    </div>
  </div>

  <h2>Signatures</h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Party</th><th>Name Typed</th><th>Signed At</th><th>Email / Portal Account</th></tr></thead>
    <tbody>
      <tr>
        <td>Owner (Zach)</td>
        <td><?= !empty($state['zachName']) ? htmlspecialchars($state['zachName']) : '—' ?></td>
        <td><?= $zachAt ? date('M j, Y g:i A', strtotime($zachAt)) : 'Not yet signed' ?></td>
        <td>—</td>
      </tr>
      <tr>
        <td>Investor<?= !empty($state['investorLegalName']) ? ' (' . htmlspecialchars($state['investorLegalName']) . ')' : '' ?></td>
        <td><?= !empty($state['leoName']) ? htmlspecialchars($state['leoName']) : '—' ?></td>
        <td><?= $leoAt ? date('M j, Y g:i A', strtotime($leoAt)) : 'Not yet signed' ?></td>
        <td>
          <?= !empty($state['leoEmail']) ? htmlspecialchars($state['leoEmail']) : '—' ?>
          <?php if (!empty($state['leoAccountUsername'])): ?>
            <br><span class="text-muted" style="font-size:12px;">
              Portal login: <?= htmlspecialchars($state['leoAccountUsername']) ?>
              (<?= htmlspecialchars($state['leoAccountStatus'] ?? '') ?>)
            </span>
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
  </div>

  <h2>Payment Proof</h2>
  <div class="mini-form" style="margin-bottom:20px;">
    <?php if (!$proof): ?>
      <p class="text-muted">No proof of payment uploaded yet — the investor can upload one from their Partner Portal after signing.</p>
    <?php elseif ($proof['confirmed']): ?>
      <p>
        <span class="badge badge-active">Confirmed</span>
        Confirmed on <?= date('M j, Y g:i A', strtotime($proof['confirmed_at'])) ?>.
        <a href="<?= htmlspecialchars($proof['proof_url']) ?>" target="_blank" rel="noopener">View proof ↗</a>
      </p>
    <?php else: ?>
      <p>
        <span class="badge badge-pending">Pending review</span>
        Uploaded <?= date('M j, Y g:i A', strtotime($proof['uploaded_at'])) ?>.
        <a href="<?= htmlspecialchars($proof['proof_url']) ?>" target="_blank" rel="noopener">View proof ↗</a>
      </p>
      <form method="POST" class="inline-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="confirm_payment">
        <input type="hidden" name="doc_id" value="<?= htmlspecialchars($docId) ?>">
        <button type="submit" class="btn btn-primary btn-sm">Confirm Payment Received</button>
      </form>
    <?php endif; ?>
  </div>

  <h2>Deal Terms</h2>
  <div class="table-scroll">
  <table class="data-table data-table--sm">
    <tbody>
      <tr><td>Effective Date</td><td><?= !empty($state['effectiveDate']) ? htmlspecialchars($state['effectiveDate']) : '—' ?></td></tr>
      <tr><td>Investment Amount</td><td>₱<?= number_format((float) ($state['investmentAmount'] ?? 0)) ?></td></tr>
      <tr><td>Investor Legal Name</td><td><?= !empty($state['investorLegalName']) ? htmlspecialchars($state['investorLegalName']) : '—' ?></td></tr>
      <tr><td>Investor Address</td><td><?= !empty($state['investorAddress']) ? htmlspecialchars($state['investorAddress']) : '—' ?></td></tr>
      <tr><td>Company DTI Reg. No.</td><td><?= !empty($state['dtiRegNo']) ? htmlspecialchars($state['dtiRegNo']) : '—' ?></td></tr>
      <tr><td>Payment Due Date</td><td><?= !empty($state['paymentDueDate']) ? htmlspecialchars($state['paymentDueDate']) : '—' ?></td></tr>
      <tr><td>Payment Method</td><td><?= ($state['paymentMethod'] ?? 'lump') === 'lump' ? 'Single lump sum' : ('Installments — ' . htmlspecialchars($state['installmentDetails'] ?? '')) ?></td></tr>
      <tr><td>Term</td><td><?= ($state['termType'] ?? 'perpetual') === 'perpetual' ? 'Perpetual' : ((int) ($state['termYears'] ?? 0) . ' year(s)') ?></td></tr>
      <tr><td>Dispute Venue</td><td><?= !empty($state['disputeVenue']) ? htmlspecialchars($state['disputeVenue']) : '—' ?></td></tr>
      <tr><td>Buy-Back Option</td><td><?= !empty($state['buybackEnabled']) ? 'Enabled' : 'Not enabled' ?></td></tr>
      <?php if (!empty($state['buybackEnabled'])): ?>
      <tr><td>Buy-Back Terms</td><td>
        Lock-in <?= (int) ($state['buybackLockInMonths'] ?? 0) ?> mo ·
        Notice <?= (int) ($state['buybackNoticeDays'] ?? 0) ?> days ·
        <?= htmlspecialchars((string) ($state['buybackInvestMultiplier'] ?? 0)) ?>× investment or
        <?= htmlspecialchars((string) ($state['buybackRevenueMultiplier'] ?? 0)) ?>× trailing revenue ·
        Cure <?= (int) ($state['buybackCureDays'] ?? 0) ?> days
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<h2>Links for this document</h2>
<div class="mini-form">
  <p><strong>Investor signing link</strong> (share with the investor — their portal account is created automatically when they sign):<br>
    <code id="investorLinkText"><?= htmlspecialchars($investorLink) ?></code>
    <button type="button" class="btn btn-ghost btn-xs" onclick="navigator.clipboard.writeText(document.getElementById('investorLinkText').textContent); this.textContent='Copied!';">Copy</button>
  </p>
  <p><strong>Full edit / owner-sign page:</strong><br>
    <a href="<?= htmlspecialchars($ownerLink) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($ownerLink) ?></a>
  </p>
</div>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
