-- ============================================================
--  Drum placement test + AI coaching — members-site
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

-- One row per completed placement test. Rich per-play metrics live in the
-- `metrics` JSON column (per-pad breakdown); the flat columns are for listing
-- and quick stats. `ai_feedback` caches the generated coaching so re-viewing a
-- test never re-bills the Anthropic API.
CREATE TABLE IF NOT EXISTS drum_tests (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  user_id               INT NOT NULL,
  student_id            INT NULL,
  song                  VARCHAR(255)  NOT NULL DEFAULT '',
  input_method          VARCHAR(20)   NOT NULL DEFAULT '',
  score                 INT           NOT NULL DEFAULT 0,
  accuracy              DECIMAL(5,2)  NOT NULL DEFAULT 0,
  grade                 CHAR(1)       NOT NULL DEFAULT '',
  perfect               INT           NOT NULL DEFAULT 0,
  good                  INT           NOT NULL DEFAULT 0,
  miss                  INT           NOT NULL DEFAULT 0,
  max_combo             INT           NOT NULL DEFAULT 0,
  total_notes           INT           NOT NULL DEFAULT 0,
  avg_offset_ms         INT           NOT NULL DEFAULT 0,  -- <0 = rushes, >0 = drags
  timing_consistency_ms INT           NOT NULL DEFAULT 0,  -- stdev of hit timing
  metrics               JSON          NULL,                -- { pads: [...] }
  ai_feedback           MEDIUMTEXT    NULL,                -- cached markdown coaching
  ai_model              VARCHAR(40)   NOT NULL DEFAULT '',
  created_at            DATETIME      DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  KEY idx_student (student_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
