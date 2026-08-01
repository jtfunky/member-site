<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/song_requests.php';
require_once __DIR__ . '/includes/maya.php';
require_once __DIR__ . '/includes/ratings.php';

$user = requireLogin();
requireProfileComplete($user);   // self-signups finish their profile first

$error   = '';
$message = '';

// Return flags from the Maya hosted checkout.
if (isset($_GET['paid']))      $message = 'Payment received — your song will be ready to play shortly.';
if (isset($_GET['cancelled'])) $error   = 'Payment cancelled.';
if (isset($_GET['failed']))    $error   = 'Payment didn’t go through. Please try again.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    [$ok, $err, $id] = createSongRequest(
        (int) $user['id'],
        $_POST['youtube_url'] ?? '',
        $_POST['title'] ?? '',
        $_POST['note']  ?? ''
    );
    if ($ok) $message = 'Request submitted! We’ll review it and get back to you.';
    else     $error   = $err;
}

// This member's requests, newest first.
$requests = [];
try {
    $st = db()->prepare('SELECT * FROM song_requests WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([(int) $user['id']]);
    $requests = $st->fetchAll();
} catch (\Throwable $e) {
    $error = 'Song requests aren’t set up yet — run migrate-song-requests.sql.';
}

$STATUS = [
    'pending'   => ['label' => 'Pending review',        'cls' => 'warn'],
    'approved'  => ['label' => 'Approved — pay to unlock','cls' => 'info'],
    'declined'  => ['label' => 'Declined',              'cls' => 'err'],
    'paid'      => ['label' => 'Paid — charting soon',  'cls' => 'info'],
    'fulfilled' => ['label' => 'Ready to play',         'cls' => 'ok'],
];
$pageTitle = 'Request a Song — ' . SITE_NAME;
$pageCss   = ['main', 'dashboard'];
$showNav   = true;
$pageHead  = '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken()) . '">';
require __DIR__ . '/includes/header.php';
?>

<main class="container">
<h1>Request a Song</h1>
<p class="text-muted">
  Paste a YouTube link to the song you want to drum to. We’ll chart it for you.
  We may decline songs that can’t be charted well for the app.
  <?php if (songRequestsAreFree()): ?>
    <br><strong>Free during the beta</strong> — no payment needed until September.
  <?php endif; ?>
</p>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST" action="/request-song.php" class="card" style="max-width:640px;margin-bottom:2rem">
  <?= csrfField() ?>
  <div class="form-group">
    <label for="youtube_url">YouTube link *</label>
    <input type="url" id="youtube_url" name="youtube_url" required
           placeholder="https://www.youtube.com/watch?v=…">
  </div>
  <div class="form-group">
    <label for="title">Song title (optional)</label>
    <input type="text" id="title" name="title" maxlength="200" placeholder="Song — Artist">
    <span id="title-autofill-hint" class="text-muted" style="font-size:.78rem; display:none">Filled in from the video — edit if it’s not quite right.</span>
  </div>
  <div class="form-group">
    <label for="note">Note to admin (optional)</label>
    <textarea id="note" name="note" maxlength="500" rows="2" placeholder="Anything we should know?"></textarea>
  </div>
  <button type="submit" class="btn btn-primary">Submit request</button>
</form>

<h2>My Requests</h2>
<?php if (!$requests): ?>
  <p class="text-muted">You haven’t requested any songs yet.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Song</th><th>Requested</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r):
        $s = $STATUS[$r['status']] ?? ['label' => $r['status'], 'cls' => 'info']; ?>
      <tr>
        <td>
          <?= htmlspecialchars($r['title'] ?: $r['youtube_url']) ?>
          <a href="<?= htmlspecialchars($r['youtube_url']) ?>" target="_blank" rel="noopener" class="text-muted" style="font-size:.8rem">↗</a>
          <?php if ($r['status'] === 'declined' && $r['decline_reason']): ?>
            <div class="text-muted" style="font-size:.8rem">Reason: <?= htmlspecialchars($r['decline_reason']) ?></div>
          <?php endif; ?>
        </td>
        <td class="text-muted" style="font-size:.85rem"><?= htmlspecialchars(formatManilaDate($r['created_at'])) ?></td>
        <td>
          <span class="req-status req-<?= $s['cls'] ?>"><?= htmlspecialchars($s['label']) ?></span>
          <?php if ($r['status'] === 'approved'): ?>
            <?php if (mayaEnabled()): ?>
              <div style="margin-top:.3rem">
                <a class="btn btn-primary btn-sm" href="/maya-checkout.php?request=<?= (int) $r['id'] ?>">
                  Pay <?= htmlspecialchars($r['currency'] . ' ' . number_format((float) $r['price'], 2)) ?>
                </a>
              </div>
            <?php else: ?>
              <div class="text-muted" style="font-size:.8rem">
                Pay <strong><?= htmlspecialchars($r['currency'] . ' ' . number_format((float) $r['price'], 2)) ?></strong> to unlock —
                online payment is coming soon; message the admin to complete it.
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<div style="margin-top:1.5rem"><?= ratingWidget((int) $user['id'], 'request-song', '', 'Rate this page') ?></div>
</main>

<style>
  .req-status { display:inline-block; font-size:.78rem; font-weight:600; padding:.15rem .55rem; border-radius:999px; }
  .req-ok   { background:rgba(34,197,94,.15);  color:#4ade80; }
  .req-info { background:rgba(59,130,246,.15); color:#93c5fd; }
  .req-warn { background:rgba(245,158,11,.15); color:#fcd34d; }
  .req-err  { background:rgba(239,68,68,.15);  color:#f87171; }
</style>
<script src="/assets/js/request-song.js?v=<?= @filemtime(__DIR__ . '/assets/js/request-song.js') ?: 1 ?>"></script>
<script src="/assets/js/rating-widget.js?v=<?= @filemtime(__DIR__ . '/assets/js/rating-widget.js') ?: 1 ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
