-- ============================================================
--  Facebook auto-like tracking — members-site
--  Records every comment the auto-engagement cron has already
--  classified/acted on, so re-running the cron never reclassifies
--  (extra Claude cost) or re-likes/unlikes a comment the Page admin
--  may have manually toggled since. Idempotent (information_schema
--  guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

CREATE TABLE IF NOT EXISTS fb_comment_likes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  comment_id VARCHAR(64) NOT NULL,
  post_id VARCHAR(64) NOT NULL,
  sentiment VARCHAR(10) NOT NULL,   -- 'positive' | 'neutral' | 'negative'
  liked TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_comment (comment_id),
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
