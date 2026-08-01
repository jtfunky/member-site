-- Require self-registered accounts to verify their email before they can log
-- in, so a typo'd/fake address (e.g. the jezjez@gmail.cmom raffle-winner
-- incident) is caught at signup instead of silently swallowing every email
-- we ever try to send that account.
--
-- Existing accounts are grandfathered in as verified — this only gates NEW
-- self-registrations (register.php / student-signup.php) going forward.
-- OAuth signups (Google/Facebook) are auto-verified too since the provider
-- already confirmed the email (see oauthLoginOrCreate()'s existing
-- $profile['verified'] check).
--
-- Run this BEFORE uploading the updated PHP.

ALTER TABLE users
  ADD COLUMN email_verified_at DATETIME NULL AFTER email;

UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

CREATE TABLE email_verifications (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  CONSTRAINT email_verifications_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
