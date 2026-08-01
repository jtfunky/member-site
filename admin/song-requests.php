<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/song_requests.php';

$user = requireRoles(['admin', 'editor']);
$db   = db();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'approve' && $id) {
        approveSongRequest($id);
        $message = 'Request approved — the student can now pay.';
    } elseif ($action === 'decline' && $id) {
        declineSongRequest($id, trim($_POST['reason'] ?? ''));
        $message = 'Request declined.';
    } elseif ($action === 'decline_blacklist' && $id) {
        $reason = trim($_POST['reason'] ?? '');
        declineSongRequest($id, $reason);
        $st = $db->prepare('SELECT yt_id FROM song_requests WHERE id = ?');
        $st->execute([$id]);
        if ($ytId = $st->fetchColumn()) {
            blacklistVideo($ytId, $reason, (int) $user['id']);
        }
        $message = 'Request declined and video blacklisted — it can’t be requested again.';
    } elseif ($action === 'unblacklist' && !empty($_POST['yt_id'])) {
        unblacklistVideo($_POST['yt_id']);
        $message = 'Video removed from the blacklist.';
    } elseif ($action === 'mark_paid' && $id) {
        fulfillPaidRequest($id)
            ? $message = 'Marked paid. If the song is already charted it’s unlocked; otherwise chart it below.'
            : $error   = 'Could not mark that request paid.';
    } elseif ($action === 'make_public' && ($sid = (int) ($_POST['song_id'] ?? 0))) {
        $db->prepare("UPDATE songs SET visibility='public' WHERE id=?")->execute([$sid]);
        $message = 'Song is now public — visible to all members.';
    }
}

// Requests with requester + linked-song info, plus a demand count — how many
// non-declined requests exist for the same video across ALL requesters. Every
// request is still its own full-price paid request (nothing about payment or
// entitlement changes); this is purely a "what do people want charted"
// signal, so pending gets sorted by demand first, then newest.
$requests = [];
try {
    $requests = $db->query(
        "SELECT r.*, u.username, u.email,
                s.title AS song_title, s.visibility AS song_visibility,
                (SELECT COUNT(*) FROM song_requests r2
                  WHERE r2.yt_id = r.yt_id AND r2.status <> 'declined') AS request_count
         FROM song_requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN songs s ON s.id = r.song_id
         ORDER BY (r.status = 'pending') DESC, (r.status = 'paid') DESC,
                  request_count DESC, r.created_at DESC"
    )->fetchAll();
} catch (\Throwable $e) {
    $error = 'The song_requests table is missing — run migrate-song-requests.sql in phpMyAdmin first.';
}

$STATUS = [
    'pending'   => ['Pending review', 'warn'],
    'approved'  => ['Approved (awaiting payment)', 'info'],
    'declined'  => ['Declined', 'err'],
    'paid'      => ['Paid — needs charting', 'info'],
    'fulfilled' => ['Fulfilled', 'ok'],
];

$pageTitle = 'Song Requests — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Song Requests</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (songRequestsAreFree()): ?>
<div class="alert alert-info">Requests are <strong>free</strong> until <?= htmlspecialchars(SONG_REQUEST_FREE_UNTIL) ?> (Asia/Manila) — approving a request skips straight to “paid” so you can chart it right away, no payment step.</div>
<?php endif; ?>

<p class="muted">
  Approve or decline requests, mark them paid (until Maya online payment is live), then
  <strong>Chart it</strong> to build the drum chart — the song becomes exclusive to buyers.
  Use <strong>Make public</strong> to open a song to all members.
  <br><span class="muted" style="font-size:.8rem">Online payment (Maya): set the keys in config, then
  <a href="/admin/maya-register-webhooks.php">register the webhooks</a> once.</span>
</p>

<?php if ($requests): ?>
<div class="table-scroll">
<table class="data-table">
  <thead><tr><th>Song</th><th>Requested by</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($requests as $r):
      $st = $STATUS[$r['status']] ?? [$r['status'], 'info']; ?>
    <tr>
      <td>
        <strong><?= htmlspecialchars($r['title'] ?: '(untitled)') ?></strong>
        <?php if ((int) $r['request_count'] > 1): ?>
          <span class="req-count" title="Total non-declined requests for this video">🔥 <?= (int) $r['request_count'] ?>x requested</span>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($r['youtube_url']) ?>" target="_blank" rel="noopener">↗ YouTube</a>
        <?php if ($r['note']): ?><div class="muted" style="font-size:.8rem">“<?= htmlspecialchars($r['note']) ?>”</div><?php endif; ?>
        <?php if ($r['song_title']): ?><div class="muted" style="font-size:.8rem">Chart: <?= htmlspecialchars($r['song_title']) ?> · <?= htmlspecialchars($r['song_visibility'] ?? 'public') ?></div><?php endif; ?>
      </td>
      <td>
        <?= htmlspecialchars($r['username']) ?><br>
        <span class="muted" style="font-size:.8rem"><?= htmlspecialchars($r['email']) ?></span>
      </td>
      <td><span class="req-status req-<?= $st[1] ?>"><?= htmlspecialchars($st[0]) ?></span>
        <?php if ($r['status'] === 'declined' && $r['decline_reason']): ?>
          <div class="muted" style="font-size:.8rem"><?= htmlspecialchars($r['decline_reason']) ?></div>
        <?php endif; ?>
      </td>
      <td style="text-align:right">
        <div class="req-actions">
          <?php if (in_array($r['status'], ['pending', 'declined'], true)): ?>
            <form method="POST" style="display:inline">
              <?= csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-ghost btn-xs">Approve</button>
            </form>
          <?php endif; ?>

          <?php if (in_array($r['status'], ['approved'], true)): ?>
            <form method="POST" style="display:inline">
              <?= csrfField() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-primary btn-xs" title="Confirm the student paid (manual until Maya)">Mark paid</button>
            </form>
          <?php endif; ?>

          <?php if ($r['status'] === 'paid'): ?>
            <a class="btn btn-primary btn-xs" href="/admin/song-editor.php?request=<?= (int) $r['id'] ?>"><i class="ti ti-music" aria-hidden="true"></i> Chart it</a>
          <?php endif; ?>

          <?php if ($r['status'] === 'fulfilled' && $r['song_id']): ?>
            <a class="btn btn-ghost btn-xs" href="/admin/song-editor.php?song=<?= (int) $r['song_id'] ?>">Edit chart</a>
            <?php if (($r['song_visibility'] ?? 'public') === 'exclusive'): ?>
              <form method="POST" style="display:inline">
                <?= csrfField() ?><input type="hidden" name="action" value="make_public"><input type="hidden" name="song_id" value="<?= (int) $r['song_id'] ?>">
                <button class="btn btn-ghost btn-xs" title="Open this song to all members">Make public</button>
              </form>
            <?php else: ?>
              <span class="muted" style="font-size:.8rem">Public ✓</span>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (!in_array($r['status'], ['declined', 'fulfilled'], true)): ?>
            <form method="POST" style="display:inline-flex;gap:.25rem;align-items:center">
              <?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="text" name="reason" placeholder="reason" maxlength="255" style="width:110px;font-size:.75rem">
              <button type="submit" name="action" value="decline" class="btn btn-danger btn-xs">Decline</button>
              <button type="submit" name="action" value="decline_blacklist" class="btn btn-danger btn-xs"
                      title="Decline this request and block this exact video from being requested again"
                      onclick="return confirm('Decline and blacklist this video? No one will be able to request it again.');">
                Decline &amp; blacklist
              </button>
            </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php elseif (!$error): ?>
  <p class="muted">No song requests yet.</p>
<?php endif; ?>

<h2 style="margin-top:2.5rem">Blacklisted videos</h2>
<?php
$blacklist = [];
try {
    $blacklist = $db->query(
        "SELECT b.*, u.username AS blacklisted_by_name
         FROM song_request_blacklist b
         LEFT JOIN users u ON u.id = b.blacklisted_by
         ORDER BY b.created_at DESC"
    )->fetchAll();
} catch (\Throwable $e) { /* table not migrated yet */ }
?>
<?php if ($blacklist): ?>
<div class="table-scroll">
<table class="data-table">
  <thead><tr><th>Video</th><th>Reason</th><th>Blacklisted by</th><th style="text-align:right">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($blacklist as $b): ?>
    <tr>
      <td><a href="https://www.youtube.com/watch?v=<?= htmlspecialchars($b['yt_id']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($b['yt_id']) ?> ↗</a></td>
      <td><?= htmlspecialchars($b['reason'] ?: '—') ?></td>
      <td class="muted" style="font-size:.85rem"><?= htmlspecialchars($b['blacklisted_by_name'] ?? '—') ?> · <?= htmlspecialchars(formatManilaDate($b['created_at'])) ?></td>
      <td style="text-align:right">
        <form method="POST" style="display:inline">
          <?= csrfField() ?><input type="hidden" name="action" value="unblacklist"><input type="hidden" name="yt_id" value="<?= htmlspecialchars($b['yt_id']) ?>">
          <button class="btn btn-ghost btn-xs">Remove from blacklist</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
  <p class="muted">No videos blacklisted.</p>
<?php endif; ?>
</main>

<style>
  .req-actions { display:flex; gap:.35rem; justify-content:flex-end; align-items:center; flex-wrap:wrap; }
  .req-status { display:inline-block; font-size:.76rem; font-weight:600; padding:.15rem .55rem; border-radius:999px; }
  .req-ok   { background:rgba(34,197,94,.15);  color:#4ade80; }
  .req-info { background:rgba(59,130,246,.15); color:#93c5fd; }
  .req-warn { background:rgba(245,158,11,.15); color:#fcd34d; }
  .req-err  { background:rgba(239,68,68,.15);  color:#f87171; }
  .req-count { display:inline-block; font-size:.72rem; font-weight:600; margin-left:.4rem;
               padding:.1rem .5rem; border-radius:999px; background:rgba(249,115,22,.15); color:#fb923c; }
</style>
<?php require __DIR__ . '/../includes/footer.php'; ?>
