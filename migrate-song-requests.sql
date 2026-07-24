-- ============================================================
--  Paid song requests + per-song entitlements — members-site
--  Students request a song (YouTube link) for a fixed price; admin approves,
--  charts it, and it becomes EXCLUSIVE to paying users until made public.
--    song_requests     — the requests + their lifecycle status
--    song_entitlements — which user may play which exclusive song
--    songs.visibility  — 'public' (default) | 'exclusive'
--  Idempotent (CREATE IF NOT EXISTS + information_schema guard). Run once via
--  phpMyAdmin -> SQL tab.
-- ============================================================

CREATE TABLE IF NOT EXISTS song_requests (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  youtube_url    VARCHAR(255) NOT NULL,
  yt_id          VARCHAR(20)  NOT NULL,
  title          VARCHAR(200) DEFAULT '',
  note           VARCHAR(500) DEFAULT '',
  status         VARCHAR(20)  NOT NULL DEFAULT 'pending',   -- pending|approved|declined|paid|fulfilled
  decline_reason VARCHAR(255) DEFAULT '',
  price          DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency       CHAR(3)      NOT NULL DEFAULT 'PHP',
  song_id        INT NULL,
  paid_at        DATETIME NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user   (user_id),
  INDEX idx_ytid   (yt_id),
  INDEX idx_status (status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS song_entitlements (
  user_id    INT NOT NULL,
  song_id    INT NOT NULL,
  source     VARCHAR(20) NOT NULL DEFAULT 'request',        -- request | grant
  granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, song_id),
  INDEX idx_song (song_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- songs.visibility (guarded so re-running is safe)
DROP PROCEDURE IF EXISTS _add_song_visibility;
DELIMITER //
CREATE PROCEDURE _add_song_visibility()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'visibility'
  ) THEN
    ALTER TABLE songs ADD COLUMN visibility VARCHAR(12) NOT NULL DEFAULT 'public' AFTER youtube_url;
  END IF;
END //
DELIMITER ;
CALL _add_song_visibility();
DROP PROCEDURE _add_song_visibility;
