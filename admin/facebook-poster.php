<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/facebook.php';
require_once __DIR__ . '/../includes/comment_sentiment.php';

$admin = requireAdmin();
$db    = db();

$message = '';
$error   = '';
$sweepResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'run_engagement_sweep') {
        if (!FB_PAGE_ACCESS_TOKEN) {
            $error = 'FB_PAGE_ACCESS_TOKEN is not configured.';
        } else {
            $sweepResult = fbRunEngagementSweep(60);
            if ($sweepResult['ok']) {
                $liked = count(array_filter($sweepResult['processed'], fn($p) => $p['liked']));
                $message = count($sweepResult['processed']) . ' new comment(s) classified, ' . $liked . ' liked.';
            } else {
                $error = 'Engagement sweep failed: ' . htmlspecialchars($sweepResult['error']);
            }
        }

    } elseif ($action === 'repost') {
        $videoId  = trim($_POST['video_id'] ?? '');
        $permalink = trim($_POST['permalink_url'] ?? '');
        $caption  = trim($_POST['caption'] ?? '');
        if ($videoId === '' || $permalink === '') {
            $error = 'Missing video to repost.';
        } elseif (!FB_PAGE_ACCESS_TOKEN) {
            $error = 'FB_PAGE_ACCESS_TOKEN is not configured.';
        } else {
            $res = fbRepostVideo($videoId, $permalink, $caption);
            if ($res['ok']) {
                $message = 'Reposted to the Page feed. Post ID: ' . htmlspecialchars($res['data']['id'] ?? '');
            } else {
                $error = 'Repost failed: ' . htmlspecialchars($res['error'] ?? 'unknown error');
            }
        }
    }
}

$videos     = [];
$fetchError = '';
if (!FB_PAGE_ACCESS_TOKEN) {
    $fetchError = 'FB_PAGE_ACCESS_TOKEN is not configured — set it in config.php to load videos.';
} else {
    $result = fbFetchRecentVideoPosts(10);
    if ($result['ok']) {
        $videos = $result['videos'];
    } else {
        $fetchError = $result['error'];
    }
}

$recentLikes = $db->query(
    'SELECT comment_id, post_id, sentiment, liked, processed_at FROM fb_comment_likes ORDER BY processed_at DESC LIMIT 20'
)->fetchAll();

$pageTitle = 'Facebook Poster — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="container container--wide">
<div class="admin-header">
  <h1>Facebook Poster</h1>
  <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
</div>

<p class="field-hint">Recent videos from the Page, ranked by engagement (likes + comments×2 + shares×3). Reposting creates a new Page feed post linking back to the video — Facebook's API has no native "reshare" call.</p>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
<?php if ($fetchError): ?><div class="alert alert-error">Couldn't load videos: <?= htmlspecialchars($fetchError) ?></div><?php endif; ?>

<?php if ($videos): ?>
<div class="table-scroll">
<table class="data-table">
  <thead><tr><th>Video</th><th>Posted</th><th>Likes</th><th>Comments</th><th>Shares</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($videos as $i => $v): ?>
    <tr>
      <td>
        <a href="<?= htmlspecialchars($v['permalink_url'] ?? '#') ?>" target="_blank" rel="noopener">
          <?= htmlspecialchars($v['title'] ?? $v['description'] ?? $v['id']) ?>
        </a>
        <?php if ($i === 0 && !isset($v['engagement_error'])): ?><span class="badge badge-active">Top pick</span><?php endif; ?>
      </td>
      <td><?= date('M j, Y', strtotime($v['created_time'])) ?></td>
      <?php if (isset($v['engagement_error'])): ?>
        <td colspan="3"><span class="field-hint">Engagement data unavailable: <?= htmlspecialchars($v['engagement_error']) ?></span></td>
      <?php else: ?>
        <td><?= (int) $v['likes'] ?></td>
        <td><?= (int) $v['comments'] ?></td>
        <td><?= (int) $v['shares'] ?></td>
      <?php endif; ?>
      <td>
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="repost">
          <input type="hidden" name="video_id" value="<?= htmlspecialchars($v['id']) ?>">
          <input type="hidden" name="permalink_url" value="<?= htmlspecialchars($v['permalink_url'] ?? '') ?>">
          <input type="hidden" name="caption" value="<?= htmlspecialchars($v['title'] ?? $v['description'] ?? '') ?>">
          <button type="submit" class="btn btn-primary btn-xs" onclick="return confirm('Repost this video to the live Page feed now?');">Repost to Facebook</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php elseif (!$fetchError): ?>
<p>No videos found on the Page.</p>
<?php endif; ?>

<h2 style="margin-top:2rem;">Comment Auto-Like</h2>
<p class="field-hint">Every 5-10 minutes, a cron job checks comments on posts from the last hour, runs each new comment through an AI sentiment check, and likes it if positive. No auto-reply, no hiding/deleting — likes only.</p>

<form method="POST" style="margin-bottom:1rem;">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="run_engagement_sweep">
  <button type="submit" class="btn btn-secondary btn-sm">Run engagement sweep now</button>
</form>

<?php if ($sweepResult && $sweepResult['ok'] && $sweepResult['processed']): ?>
<div class="table-scroll">
<table class="data-table data-table--sm">
  <thead><tr><th>Comment ID</th><th>Sentiment</th><th>Liked?</th></tr></thead>
  <tbody>
  <?php foreach ($sweepResult['processed'] as $p): ?>
    <tr>
      <td class="font-mono"><?= htmlspecialchars($p['comment_id']) ?></td>
      <td><?= htmlspecialchars($p['sentiment']) ?></td>
      <td><?= $p['liked'] ? '👍 Liked' : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h3 style="margin-top:1.5rem;">Recent history</h3>
<?php if ($recentLikes): ?>
<div class="table-scroll">
<table class="data-table data-table--sm">
  <thead><tr><th>Comment ID</th><th>Post ID</th><th>Sentiment</th><th>Liked?</th><th>Processed</th></tr></thead>
  <tbody>
  <?php foreach ($recentLikes as $r): ?>
    <tr>
      <td class="font-mono"><?= htmlspecialchars($r['comment_id']) ?></td>
      <td class="font-mono"><?= htmlspecialchars($r['post_id']) ?></td>
      <td><?= htmlspecialchars($r['sentiment']) ?></td>
      <td><?= $r['liked'] ? '👍 Liked' : '—' ?></td>
      <td><?= date('M j, g:ia', strtotime($r['processed_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<p class="field-hint">No comments processed yet.</p>
<?php endif; ?>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
