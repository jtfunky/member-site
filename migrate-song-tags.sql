-- ============================================================
--  Song tags — members-site
--  Admin-managed, multi-tag categorization for songs, grouped by type:
--    genre  (Rock, Jazz, Funk…), skill (Grooves, Fills, Rudiments…),
--    folder (free-form buckets: a course module, a setlist…).
--  A song can carry many tags (song_tag_map). Separate from `songs.category`,
--  which is the INPUT type (kit vs practice pad) and is unrelated.
--  Idempotent (CREATE TABLE IF NOT EXISTS). Run once via phpMyAdmin -> SQL.
-- ============================================================

CREATE TABLE IF NOT EXISTS song_tags (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL,
  tag_group  VARCHAR(20)  NOT NULL DEFAULT 'genre',   -- genre | skill | folder
  sort_order INT          NOT NULL DEFAULT 0,
  created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_group_name (tag_group, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS song_tag_map (
  song_id INT NOT NULL,
  tag_id  INT NOT NULL,
  PRIMARY KEY (song_id, tag_id),
  INDEX idx_tag (tag_id),
  FOREIGN KEY (song_id) REFERENCES songs(id)     ON DELETE CASCADE,
  FOREIGN KEY (tag_id)  REFERENCES song_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
