-- ============================================================
--  Promo codes — members-site
--  Admin-managed codes (e.g. "SUMMER2026") a new registrant can enter on the
--  normal register.php flow for bonus trial days. Separate from the raffle
--  promo (which is its own dedicated landing page).
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS promo_codes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40)   NOT NULL,
  bonus_days  INT           NOT NULL,
  max_uses    INT           NULL,       -- NULL = unlimited
  uses_count  INT           NOT NULL DEFAULT 0,
  expires_at  DATETIME      NULL,       -- NULL = no expiry
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_by  INT           NULL,
  created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promo_code_redemptions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  promo_code_id  INT NOT NULL,
  user_id        INT NOT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code_user (promo_code_id, user_id),
  FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)       REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
