-- ============================================================
--  Practice-plan mode (kit | pad) — members-site
--  A plan generated from a PRACTICE-PAD placement test produces single-pad
--  exercises instead of full-kit sessions. This column records which kind of
--  plan it is (set at generation from the placement test's input_method).
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

DROP PROCEDURE IF EXISTS _add_plan_mode;
DELIMITER //
CREATE PROCEDURE _add_plan_mode()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'practice_plans' AND COLUMN_NAME = 'mode') THEN
    ALTER TABLE practice_plans ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'kit' AFTER user_id;
  END IF;
END //
DELIMITER ;
CALL _add_plan_mode();
DROP PROCEDURE _add_plan_mode;
