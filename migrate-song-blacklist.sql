-- ============================================================
--  Song request blacklist — members-site
--  Videos an admin has explicitly rejected (spam, copyright issues, low
--  quality, etc). Requesting a blacklisted video is blocked at submission.
--  Idempotent (CREATE IF NOT EXISTS). Run once via phpMyAdmin -> SQL tab.
-- ============================================================

CREATE TABLE IF NOT EXISTS song_request_blacklist (
  yt_id         VARCHAR(20) PRIMARY KEY,
  reason        VARCHAR(255) DEFAULT '',
  blacklisted_by INT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (blacklisted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
