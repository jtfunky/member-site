-- ============================================================
--  Payment reminder tracking — members-site
--  Records when a pending-payment enrollee was last emailed a reminder, so the
--  admin can see it and the bulk action doesn't double-send the same day.
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

DROP PROCEDURE IF EXISTS _add_payment_reminded;
DELIMITER //
CREATE PROCEDURE _add_payment_reminded()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'payment_reminded_at') THEN
    ALTER TABLE students ADD COLUMN payment_reminded_at DATETIME NULL AFTER payment_status;
  END IF;
END //
DELIMITER ;
CALL _add_payment_reminded();
DROP PROCEDURE _add_payment_reminded;
