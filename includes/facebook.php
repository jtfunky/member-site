<?php
// Graph API helper for the Page-posting automation (admin/facebook-poster.php).
// Standalone cURL, deliberately not routed through oauth.php's require chain
// since that pulls in db/auth/access/programs for an unrelated login flow.

define('FB_GRAPH_VERSION', 'v25.0');

function fbGraphRequest(string $method, string $path, array $params = []): array {
    $params['access_token'] = FB_PAGE_ACCESS_TOKEN;
    $url = 'https://graph.facebook.com/' . FB_GRAPH_VERSION . '/' . ltrim($path, '/');

    $ch = curl_init();
    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'data' => [], 'error' => $err];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true) ?? [];
    if ($status >= 200 && $status < 300 && !isset($data['error'])) {
        return ['ok' => true, 'data' => $data];
    }
    return ['ok' => false, 'data' => $data, 'error' => $data['error']['message'] ?? "HTTP {$status}"];
}

// Recent video posts on the configured Page, with engagement counts where
// Meta's current access tier allows it. Missing-permission errors on the
// engagement fields are surfaced per-post via 'engagement_error' rather than
// failing the whole call, since basic post data (id/message/created_time)
// works today and the engagement fields are the part still pending App Review.
function fbFetchRecentVideoPosts(int $limit = 10): array {
    $res = fbGraphRequest('GET', FB_PAGE_ID . '/videos', [
        'fields' => 'id,title,description,permalink_url,created_time',
        'limit'  => $limit,
    ]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'videos' => []];
    }

    $videos = $res['data']['data'] ?? [];
    foreach ($videos as &$video) {
        // 'shares' is fetched separately and defaulted to 0 on error — Graph API
        // omits the field entirely (and errors if you ask for it directly) on
        // posts with zero shares, which would otherwise sink the whole read.
        $eng = fbGraphRequest('GET', $video['id'], [
            'fields' => 'likes.summary(true),comments.summary(true)',
        ]);
        if ($eng['ok']) {
            $sharesRes = fbGraphRequest('GET', $video['id'], ['fields' => 'shares']);
            $video['likes']    = $eng['data']['likes']['summary']['total_count']    ?? 0;
            $video['comments'] = $eng['data']['comments']['summary']['total_count'] ?? 0;
            $video['shares']   = $sharesRes['ok'] ? ($sharesRes['data']['shares']['count'] ?? 0) : 0;
            $video['score']    = $video['likes'] + $video['comments'] * 2 + $video['shares'] * 3;
        } else {
            $video['engagement_error'] = $eng['error'];
            $video['score'] = -1;
        }
    }
    unset($video);

    usort($videos, fn($a, $b) => $b['score'] <=> $a['score']);
    return ['ok' => true, 'videos' => $videos];
}

// Reposts a video already on the Page as a new feed post linking back to it
// (the Graph API has no native "reshare" call for a Page's own video — this
// creates a fresh post whose message links to the existing video permalink).
function fbRepostVideo(string $videoId, string $permalinkUrl, string $caption): array {
    return fbGraphRequest('POST', FB_PAGE_ID . '/feed', [
        'message' => $caption,
        'link'    => $permalinkUrl,
    ]);
}

// Page posts created within the last $withinMinutes — the window the
// auto-engagement cron operates in (comments are only auto-liked during a
// post's first hour, per the feature's actual design).
function fbFetchRecentPosts(int $withinMinutes = 60): array {
    $res = fbGraphRequest('GET', FB_PAGE_ID . '/posts', [
        'fields' => 'id,created_time',
        'limit'  => 25,
    ]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'posts' => []];
    }
    $cutoff = time() - $withinMinutes * 60;
    $posts = array_values(array_filter($res['data']['data'] ?? [], function ($p) use ($cutoff) {
        return strtotime($p['created_time']) >= $cutoff;
    }));
    return ['ok' => true, 'posts' => $posts];
}

function fbFetchPostComments(string $postId): array {
    $res = fbGraphRequest('GET', $postId . '/comments', [
        'fields' => 'id,message,created_time,user_likes',
        'limit'  => 50,
    ]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'comments' => []];
    }
    return ['ok' => true, 'comments' => $res['data']['data'] ?? []];
}

function fbLikeComment(string $commentId): array {
    return fbGraphRequest('POST', $commentId . '/likes');
}

// Adds a comment to a Page post — used to put a call-to-action link in the
// first comment instead of the post body. Facebook's feed truncates posts
// into "See more" much more aggressively when a link is in the message text
// itself; keeping the link out of the post and posting it as a follow-up
// comment avoids that entirely.
function fbCommentOnPost(string $postId, string $message): array {
    return fbGraphRequest('POST', $postId . '/comments', ['message' => $message]);
}

// Core auto-like sweep: classifies and likes new positive comments on posts
// from the last $withinMinutes. Shared by cron-facebook-engage.php (scheduled,
// every 5-10 min) and admin/facebook-poster.php's "Run now" button (on-demand,
// for demoing/testing). Requires includes/comment_sentiment.php to already be
// loaded by the caller. Returns per-comment results for display.
function fbRunEngagementSweep(int $withinMinutes = 60): array {
    $db = db();
    $postsRes = fbFetchRecentPosts($withinMinutes);
    if (!$postsRes['ok']) {
        return ['ok' => false, 'error' => $postsRes['error'], 'processed' => []];
    }

    $processed = [];
    foreach ($postsRes['posts'] as $post) {
        $commentsRes = fbFetchPostComments($post['id']);
        if (!$commentsRes['ok']) {
            continue;
        }

        foreach ($commentsRes['comments'] as $comment) {
            $already = $db->prepare('SELECT id FROM fb_comment_likes WHERE comment_id = ?');
            $already->execute([$comment['id']]);
            if ($already->fetch()) {
                continue; // already classified/acted on in a previous run
            }

            $sentiment = classifyCommentSentiment($comment['message'] ?? '');
            $didLike   = false;

            if ($sentiment === 'positive' && empty($comment['user_likes'])) {
                $likeRes = fbLikeComment($comment['id']);
                $didLike = $likeRes['ok'];
            }

            $db->prepare('INSERT INTO fb_comment_likes (comment_id, post_id, sentiment, liked) VALUES (?, ?, ?, ?)')
               ->execute([$comment['id'], $post['id'], $sentiment, $didLike ? 1 : 0]);

            $processed[] = [
                'comment_id' => $comment['id'],
                'post_id'    => $post['id'],
                'sentiment'  => $sentiment,
                'liked'      => $didLike,
            ];
        }
    }

    return ['ok' => true, 'processed' => $processed, 'posts_checked' => count($postsRes['posts'])];
}
