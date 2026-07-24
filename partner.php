<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Partners (and admins) only.
$user = requireRoles(['admin', 'partner']);
$db   = db();

$message = '';
$error   = '';

// Find the investor agreement tied to this partner's email, if any (a partner
// account can also be created manually by an admin with no agreement behind
// it — in that case there's simply nothing to show in "Your Investment").
// If more than one agreement matched this email, only the first is shown;
// this tool doesn't yet support a partner tracking multiple investments.
$myAgreementStmt = $db->prepare(
    'SELECT doc_id, state_json FROM groovequest_agreements
     WHERE JSON_UNQUOTE(JSON_EXTRACT(state_json, \'$.leoEmail\')) = ?
     LIMIT 1'
);
$myAgreementStmt->execute([$user['email']]);
$myAgreement = $myAgreementStmt->fetch();
$myDocId     = $myAgreement['doc_id'] ?? null;

function fetchInvestorProof(PDO $db, string $docId): array|false {
    $st = $db->prepare('SELECT * FROM investor_payment_proofs WHERE doc_id = ?');
    $st->execute([$docId]);
    return $st->fetch();
}

$myProof = $myDocId ? fetchInvestorProof($db, $myDocId) : false;

// Until the investor's payment is confirmed, the only thing they see is the
// "Your Investment" upload section — no subscriber/revenue reporting. Admins
// and partner accounts with no tracked investment (e.g. manually created via
// admin/users.php) are unaffected.
$showFullDashboard = $user['role'] === 'admin' || !$myDocId || ($myProof && $myProof['confirmed']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    verifyCsrf();
    if (!$myDocId) {
        $error = 'No agreement found for your account.';
    } elseif ($myProof && $myProof['confirmed']) {
        $error = 'Your payment has already been confirmed.';
    } else {
        $check = validateProofUpload($_FILES['proof'] ?? []);
        if ($check !== true) {
            $error = $check;
        } else {
            if (!is_dir(UPLOAD_INVESTOR_PROOF_DIR)) {
                @mkdir(UPLOAD_INVESTOR_PROOF_DIR, 0755, true);
            }
            $ext      = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            $filename = 'investor_proof_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_INVESTOR_PROOF_DIR . $filename);
            $proofUrl = UPLOAD_INVESTOR_PROOF_URL . $filename;

            $db->prepare(
                'INSERT INTO investor_payment_proofs (doc_id, user_id, proof_url, uploaded_at, confirmed)
                 VALUES (:doc_id, :user_id, :proof_url, NOW(), 0)
                 ON DUPLICATE KEY UPDATE
                   proof_url = VALUES(proof_url), uploaded_at = NOW(), user_id = VALUES(user_id),
                   confirmed = 0, confirmed_at = NULL, confirmed_by = NULL'
            )->execute([':doc_id' => $myDocId, ':user_id' => $user['id'], ':proof_url' => $proofUrl]);

            $message = 'Proof of payment uploaded — Zach will confirm it once verified.';
            $myProof = fetchInvestorProof($db, $myDocId);
        }
    }
}

// "Web subscribers" = users.role='user' rows that are NOT linked to a paid
// coaching/session program in `students` (i.e. no students row, or one whose
// program is blank/'Website access'). This keeps the separate in-person/online
// drum coaching business entirely out of the Partner's view — the revenue
// share agreement only covers the app's Net Sales (Section 1.1).
$subscribers = $db->query(
    "SELECT u.id, u.email, u.first_name, u.last_name, u.subscription_status,
            u.access_expires_at, u.currency, u.created_at
     FROM users u
     WHERE u.role = 'user'
       AND NOT EXISTS (
         SELECT 1 FROM students s
         WHERE (s.user_id = u.id OR s.email = u.email)
           AND s.program IS NOT NULL AND s.program NOT IN ('', 'Website access')
       )
     ORDER BY u.created_at DESC"
)->fetchAll();

$statusCounts = ['trial' => 0, 'active' => 0, 'cancelled' => 0, 'expired' => 0, 'pending' => 0];
foreach ($subscribers as $s) {
    $expired = $s['access_expires_at'] && strtotime($s['access_expires_at']) < time();
    $status  = $expired ? 'expired' : $s['subscription_status'];
    if (!isset($statusCounts[$status])) $statusCounts[$status] = 0;
    $statusCounts[$status]++;
}
$totalSubscribers = count($subscribers);

// `payments` only ever gets rows from app subscriptions and song-request fees —
// coaching enrollments are tracked separately in `students` and never write here —
// so no exclusion join is needed for revenue.
$revenueRows = $db->query(
    "SELECT currency, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
     FROM payments WHERE status = 'success' GROUP BY currency"
)->fetchAll();

$pageTitle = 'Partner Portal — ' . SITE_NAME;
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/includes/header.php';
?>

<main class="container container--wide">
<h1><i class="ti ti-users" aria-hidden="true"></i> Partner Portal</h1>
<p class="text-muted">Welcome, <?= htmlspecialchars($user['first_name'] ?: $user['username']) ?>.
This view covers Groove Quest's web/app paying members only — it does not include the separate
in-person/online drum coaching (session) business.</p>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($myDocId): ?>
<h2>Your Investment</h2>
<div class="mini-form" style="margin-bottom:20px;">
  <?php if ($myProof && $myProof['confirmed']): ?>
    <p>
      <span class="badge badge-active">Confirmed</span>
      Payment confirmed on <?= date('M j, Y', strtotime($myProof['confirmed_at'])) ?>.
      <a href="<?= htmlspecialchars($myProof['proof_url']) ?>" target="_blank" rel="noopener">View your uploaded proof ↗</a>
    </p>
  <?php elseif ($myProof): ?>
    <p>
      <span class="badge badge-pending">Pending review</span>
      Proof uploaded on <?= date('M j, Y', strtotime($myProof['uploaded_at'])) ?> — awaiting confirmation.
      <a href="<?= htmlspecialchars($myProof['proof_url']) ?>" target="_blank" rel="noopener">View your upload ↗</a>
    </p>
    <p class="text-muted" style="font-size:12.5px;">Uploaded the wrong file? Submitting again below replaces it.</p>
    <form method="POST" enctype="multipart/form-data" class="inline-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload_proof">
      <input type="file" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" required>
      <button type="submit" class="btn btn-secondary btn-sm">Re-upload Proof</button>
    </form>
  <?php else: ?>
    <p>Once you've sent your investment via bank transfer, upload your receipt or screenshot here so Zach can confirm it.</p>
    <form method="POST" enctype="multipart/form-data" class="inline-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload_proof">
      <input type="file" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" required>
      <button type="submit" class="btn btn-primary btn-sm">Upload Proof of Payment</button>
    </form>
  <?php endif; ?>
</div>
<?php if (!$showFullDashboard): ?>
  <p class="text-muted" style="font-size:13px;">Once Zach confirms your payment, subscriber and revenue reporting will appear here.</p>
<?php endif; ?>
<?php endif; ?>

<?php if ($showFullDashboard): ?>
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-value"><?= $totalSubscribers ?></div>
    <div class="stat-label">Total Web Subscribers</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $statusCounts['active'] ?></div>
    <div class="stat-label">Active</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $statusCounts['trial'] ?></div>
    <div class="stat-label">Trial</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $statusCounts['pending'] ?></div>
    <div class="stat-label">Pending</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $statusCounts['cancelled'] + $statusCounts['expired'] ?></div>
    <div class="stat-label">Cancelled / Expired</div>
  </div>
</div>

<h2>Revenue (gross, successful payments)</h2>
<p class="text-muted" style="font-size:13px; margin-top:-8px;">
Gross amounts collected before payment-processing fees, taxes, and refunds — see Section 1.1 of your
agreement for how "Net Sales" is actually calculated for your revenue share. This is a reference figure
only; the Company's quarterly statement (Section 4.1) is the authoritative number.
</p>
<div class="stats-grid">
<?php if (!$revenueRows): ?>
  <div class="stat-card">
    <div class="stat-value">—</div>
    <div class="stat-label">No payments recorded yet</div>
  </div>
<?php else: foreach ($revenueRows as $r): ?>
  <div class="stat-card">
    <div class="stat-value"><?= ($r['currency'] === 'PHP' ? '₱' : '$') . number_format((float) $r['total'], 2) ?></div>
    <div class="stat-label"><?= htmlspecialchars($r['currency']) ?> collected (<?= (int) $r['cnt'] ?> payments)</div>
  </div>
<?php endforeach; endif; ?>
</div>

<h2>Web Subscribers</h2>
<div class="table-scroll">
<table class="data-table">
  <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Access Expires</th><th>Joined</th></tr></thead>
  <tbody>
  <?php foreach ($subscribers as $s): ?>
    <?php
      $expired = $s['access_expires_at'] && strtotime($s['access_expires_at']) < time();
      $status  = $expired ? 'expired' : $s['subscription_status'];
      $name    = trim($s['first_name'] . ' ' . $s['last_name']);
    ?>
    <tr>
      <td><?= $name !== '' ? htmlspecialchars($name) : '—' ?></td>
      <td><?= htmlspecialchars($s['email']) ?></td>
      <td><span class="badge badge-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span></td>
      <td><?= $s['access_expires_at'] ? date('M j, Y', strtotime($s['access_expires_at'])) : '—' ?></td>
      <td><?= date('M j, Y', strtotime($s['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$subscribers): ?>
    <tr><td colspan="5" class="text-muted">No web subscribers yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
