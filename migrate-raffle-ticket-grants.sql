-- ============================================================
--  Raffle ticket fulfillment tracking — members-site
--  Permanent record of which specific physical ticket PDF was
--  granted to which winner, so a ticket can never be re-sent.
--  The UNIQUE KEY is the actual safety net: the DB itself refuses
--  a second grant of the same (day, file) pair.
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

CREATE TABLE IF NOT EXISTS raffle_ticket_grants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  user_id INT NOT NULL,
  ticket_day VARCHAR(10) NOT NULL,     -- 'day1' | 'day2'
  ticket_file VARCHAR(100) NOT NULL,   -- e.g. GA-day-1-51.pdf
  awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ticket (ticket_day, ticket_file),
  KEY idx_campaign (campaign_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
