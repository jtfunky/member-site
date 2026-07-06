-- ============================================================
--  AI practice plans — members-site
--  After a student takes the placement test, an AI-generated 10-session plan
--  (one month) is created for them, shown on my-plan.php. One plan per user.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS practice_plans (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  drum_test_id INT NULL,
  intro        TEXT,
  generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plan_sessions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  plan_id      INT          NOT NULL,
  session_no   INT          NOT NULL,
  title        VARCHAR(200) NOT NULL DEFAULT '',
  focus        VARCHAR(255) NOT NULL DEFAULT '',
  drills       TEXT,                       -- JSON array of drill strings
  completed    TINYINT(1)   NOT NULL DEFAULT 0,
  completed_at DATETIME     NULL,
  UNIQUE KEY uniq_plan_session (plan_id, session_no),
  INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
