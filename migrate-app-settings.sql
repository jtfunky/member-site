-- ============================================================
--  Site-wide admin-toggleable settings — members-site
--  Generic key/value store so future on/off switches don't each
--  need their own migration + column. Starts with the leaderboard
--  toggle, defaulted OFF (not enough players yet for rankings to
--  mean anything — admin turns it on once there's real data).
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key   VARCHAR(64) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL DEFAULT '',
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('leaderboard_enabled', '0');
