-- ============================================================
--  Practice-plan session games — members-site
--  Each plan session becomes a playable, AI-generated drum exercise. These
--  columns hold the generated chart + tempo and the student's best accuracy.
--  Completion is EARNED (accuracy >= PLAN_PASS_ACCURACY) — the existing
--  plan_sessions.completed / completed_at are reused as "passed".
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

DROP PROCEDURE IF EXISTS _add_plan_game_cols;
DELIMITER //
CREATE PROCEDURE _add_plan_game_cols()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_sessions' AND COLUMN_NAME = 'chart') THEN
    ALTER TABLE plan_sessions ADD COLUMN chart JSON NULL AFTER drills;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_sessions' AND COLUMN_NAME = 'bpm') THEN
    ALTER TABLE plan_sessions ADD COLUMN bpm INT NOT NULL DEFAULT 90 AFTER chart;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_sessions' AND COLUMN_NAME = 'best_accuracy') THEN
    ALTER TABLE plan_sessions ADD COLUMN best_accuracy DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER bpm;
  END IF;
END //
DELIMITER ;
CALL _add_plan_game_cols();
DROP PROCEDURE _add_plan_game_cols;
