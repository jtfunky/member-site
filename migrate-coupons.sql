-- ============================================================
--  Coupons — members-site
--  Admin-managed discount codes (percent or fixed amount off) redeemable at
--  checkout on payment.php for the paid membership subscription.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS coupons (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40)   NOT NULL,
  discount_type  ENUM('percent','fixed') NOT NULL,
  discount_value DECIMAL(10,2) NOT NULL,
  max_uses       INT           NULL,
  uses_count     INT           NOT NULL DEFAULT 0,
  expires_at     DATETIME      NULL,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  created_by     INT           NULL,
  created_at     DATETIME      DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  coupon_id   INT NOT NULL,
  user_id     INT NOT NULL,
  payment_id  INT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (coupon_id)  REFERENCES coupons(id)  ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
