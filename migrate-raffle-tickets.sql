-- ============================================================
--  Raffle ticket prize fields — members-site
--  Adds a configurable ticket prize (count + description) per campaign,
--  alongside the existing prize_days. E.g. "2 tickets to the Summer
--  Concert". Mentioned in the winner email only — no fulfillment
--  tracking, same treatment as the free-access days.
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

DROP PROCEDURE IF EXISTS _add_raffle_ticket_fields;
DELIMITER //
CREATE PROCEDURE _add_raffle_ticket_fields()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raffle_campaigns' AND COLUMN_NAME = 'prize_ticket_count') THEN
    ALTER TABLE raffle_campaigns ADD COLUMN prize_ticket_count INT NOT NULL DEFAULT 0 AFTER prize_days;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raffle_campaigns' AND COLUMN_NAME = 'prize_ticket_desc') THEN
    ALTER TABLE raffle_campaigns ADD COLUMN prize_ticket_desc VARCHAR(160) NULL AFTER prize_ticket_count;
  END IF;
END //
DELIMITER ;
CALL _add_raffle_ticket_fields();
DROP PROCEDURE _add_raffle_ticket_fields;
