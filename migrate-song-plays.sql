-- ============================================================
--  Song play history — members-site
--  Records every completed song play (not the placement test, which lives in
--  drum_tests). Powers per-song best scores + a recent-plays list in the game.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS song_plays (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  song_id      VARCHAR(64)  NOT NULL,               -- 'demo' / 'rock-basics' / numeric DB id
  song_title   VARCHAR(200) NOT NULL DEFAULT '',
  score        INT          NOT NULL DEFAULT 0,
  accuracy     DECIMAL(5,2) NOT NULL DEFAULT 0,
  grade        VARCHAR(2)   NOT NULL DEFAULT '',
  max_combo    INT          NOT NULL DEFAULT 0,
  perfect      INT          NOT NULL DEFAULT 0,
  good         INT          NOT NULL DEFAULT 0,
  miss         INT          NOT NULL DEFAULT 0,
  total_notes  INT          NOT NULL DEFAULT 0,
  input_method VARCHAR(20)  NOT NULL DEFAULT '',
  played_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_song   (user_id, song_id),
  INDEX idx_user_played (user_id, played_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
