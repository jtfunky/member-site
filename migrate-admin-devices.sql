-- ============================================================
--  Admin trusted devices — members-site
--  When ADMIN_DEVICE_LOCK is on (config.php), a full admin can only reach the
--  admin area from a registered device. A new device is trusted after the admin
--  confirms a one-time code emailed to the account; the device token is stored
--  here (as a sha256 hash) and remembered via a long-lived cookie.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_devices (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  label        VARCHAR(160) DEFAULT '',
  ip           VARCHAR(45)  DEFAULT '',
  created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME     NULL,
  UNIQUE KEY uniq_token (token_hash),
  INDEX idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
