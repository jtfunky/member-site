-- ============================================================
--  Groove Quest raffle promo — members-site
--  New-registrant giveaway: entrants sign up via a dedicated promo landing
--  page during a campaign's date range; an admin later draws N random
--  winners, who each get `prize_days` of free access (via the existing
--  grantAdminAccess()) plus a "you won" email.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS raffle_campaigns (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160)  NOT NULL,
  slug        VARCHAR(80)   NOT NULL,
  start_at    DATETIME      NOT NULL,
  end_at      DATETIME      NOT NULL,
  prize_days  INT           NOT NULL DEFAULT 30,
  prize_ticket_count INT    NOT NULL DEFAULT 0,
  prize_ticket_desc  VARCHAR(160) NULL,
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_by  INT           NULL,
  created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slug (slug),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS raffle_entries (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id  INT        NOT NULL,
  user_id      INT        NOT NULL,
  is_winner    TINYINT(1) NOT NULL DEFAULT 0,
  won_at       DATETIME   NULL,
  grant_days   INT        NULL,       -- snapshot of prize_days at draw time (audit trail)
  created_at   DATETIME   DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_campaign_user (campaign_id, user_id),
  INDEX idx_campaign_winner (campaign_id, is_winner),
  FOREIGN KEY (campaign_id) REFERENCES raffle_campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)     REFERENCES users(id)             ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
