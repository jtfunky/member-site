-- 5-star experience ratings: page-level ("request-song", "my-plan") and
-- per-song (context='song', ref_id=song_id). One rating per user per
-- context+ref_id — resubmitting updates it rather than adding a new row.
CREATE TABLE IF NOT EXISTS experience_ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  context VARCHAR(20) NOT NULL,
  ref_id VARCHAR(20) NOT NULL DEFAULT '',
  rating TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rating (user_id, context, ref_id),
  KEY idx_context (context, ref_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
