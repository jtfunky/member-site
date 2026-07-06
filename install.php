<?php
/**
 * Run this ONCE to create tables and the admin account.
 * DELETE this file immediately after running it.
 */
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// ── Tables ────────────────────────────────────────────────

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  username            VARCHAR(50)  NOT NULL UNIQUE,
  email               VARCHAR(100) NOT NULL UNIQUE,
  password_hash       VARCHAR(255) NOT NULL,
  first_name          VARCHAR(50)  DEFAULT '',
  last_name           VARCHAR(50)  DEFAULT '',
  avatar              VARCHAR(255) DEFAULT '',
  bio                 TEXT         DEFAULT '',
  role                ENUM('user','admin') DEFAULT 'user',
  registration_type   ENUM('self','admin') DEFAULT 'self',
  subscription_status ENUM('trial','active','cancelled','expired','pending') DEFAULT 'trial',
  access_expires_at   DATETIME     NULL,
  currency            CHAR(3)      DEFAULT 'USD',
  country             VARCHAR(60)  DEFAULT '',
  stripe_customer_id  VARCHAR(100) DEFAULT '',
  stripe_sub_id       VARCHAR(100) DEFAULT '',
  is_active           TINYINT(1)   DEFAULT 1,
  created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS access_grants (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  granted_by    INT NOT NULL,
  duration_days INT NOT NULL,
  note          VARCHAR(255) DEFAULT '',
  granted_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS payments (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT           NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  currency       CHAR(3)       DEFAULT 'USD',
  payment_method VARCHAR(50)   DEFAULT 'dummy',
  transaction_id VARCHAR(255)  DEFAULT '',
  status         ENUM('pending','success','failed','refunded') DEFAULT 'pending',
  period_start   DATETIME,
  period_end     DATETIME,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS songs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(200) NOT NULL,
  artist          VARCHAR(200) DEFAULT '',
  bpm             DECIMAL(5,2) DEFAULT 120,
  duration_ms     INT          DEFAULT 0,
  notes           JSON,
  audio_filename  VARCHAR(255) DEFAULT '',
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS song_plays (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  song_id      VARCHAR(64)  NOT NULL,
  song_title   VARCHAR(200) NOT NULL DEFAULT '',
  score        INT          NOT NULL DEFAULT 0,
  accuracy     DECIMAL(5,2) NOT NULL DEFAULT 0,
  grade        VARCHAR(2)   NOT NULL DEFAULT '',
  max_combo    INT          NOT NULL DEFAULT 0,
  perfect      INT          NOT NULL DEFAULT 0,
  good         INT          NOT NULL DEFAULT 0,
  miss         INT          NOT NULL DEFAULT 0,
  total_notes  INT          NOT NULL DEFAULT 0,
  input_method VARCHAR(20)  NOT NULL DEFAULT '',
  played_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_song   (user_id, song_id),
  INDEX idx_user_played (user_id, played_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS practice_plans (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  drum_test_id INT NULL,
  intro        TEXT,
  generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS plan_sessions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  plan_id      INT          NOT NULL,
  session_no   INT          NOT NULL,
  title        VARCHAR(200) NOT NULL DEFAULT '',
  focus        VARCHAR(255) NOT NULL DEFAULT '',
  drills       TEXT,
  completed    TINYINT(1)   NOT NULL DEFAULT 0,
  completed_at DATETIME     NULL,
  UNIQUE KEY uniq_plan_session (plan_id, session_no),
  INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45)  NOT NULL,
  identifier   VARCHAR(100) NOT NULL,
  attempts     INT          DEFAULT 1,
  last_attempt DATETIME     DEFAULT CURRENT_TIMESTAMP,
  locked_until DATETIME     NULL,
  UNIQUE KEY unique_ip_id (ip, identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME     NOT NULL,
  used       TINYINT(1)   DEFAULT 0,
  created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── Admin user ────────────────────────────────────────────

$adminPassword = 'Admin@1234!';   // ← change after first login
$hash = password_hash($adminPassword, PASSWORD_BCRYPT);

$st = $pdo->prepare(
    'INSERT IGNORE INTO users
     (username, email, password_hash, first_name, role, registration_type, subscription_status)
     VALUES (?, ?, ?, ?, "admin", "admin", "active")'
);
$st->execute(['admin', 'bongalcasid@gmail.com', $hash, 'Admin']);

echo '<pre>';
echo "✅ Tables created.\n";
echo "✅ Admin account created.\n\n";
echo "  Username : admin\n";
echo "  Email    : bongalcasid@gmail.com\n";
echo "  Password : {$adminPassword}\n\n";
echo "⚠️  CHANGE YOUR PASSWORD AFTER LOGGING IN.\n";
echo "🗑️  DELETE THIS FILE (install.php) NOW.\n";
echo '</pre>';
