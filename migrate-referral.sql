-- ============================================================
--  Referral program — members-site
--  Every account gets a unique referral_code (generated in registerUser()).
--  When a new signup includes a valid ?ref=<code>, the REFERRER (not the new
--  member) gets REFERRAL_REWARD_DAYS of free access via the existing
--  grantAdminAccess(). One reward per referred user, tracked here.
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

DROP PROCEDURE IF EXISTS _add_referral_code;
DELIMITER //
CREATE PROCEDURE _add_referral_code()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'referral_code') THEN
    ALTER TABLE users ADD COLUMN referral_code VARCHAR(12) NULL UNIQUE AFTER username;
  END IF;
END //
DELIMITER ;
CALL _add_referral_code();
DROP PROCEDURE _add_referral_code;

CREATE TABLE IF NOT EXISTS referrals (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  referrer_user_id INT NOT NULL,
  referred_user_id INT NOT NULL,
  reward_days      INT NOT NULL,
  rewarded_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_referred (referred_user_id),
  FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
