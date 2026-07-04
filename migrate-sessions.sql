-- ============================================================
--  Session scheduling — members-site
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run (IF NOT EXISTS /
--  duplicate-column errors are harmless).
-- ============================================================

-- Per-student session credit balance (how many sessions they can still book).
ALTER TABLE students ADD COLUMN session_credits INT NOT NULL DEFAULT 0 AFTER experience;

-- Admin-defined availability. Each row is one bookable 1-on-1 time slot.
CREATE TABLE IF NOT EXISTS session_slots (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  starts_at    DATETIME     NOT NULL,
  duration_min INT          NOT NULL DEFAULT 60,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  KEY idx_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A student's booking against a slot. One active booking per slot (UNIQUE).
CREATE TABLE IF NOT EXISTS session_bookings (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slot_id     INT NOT NULL,
  student_id  INT NOT NULL,
  user_id     INT NULL,
  status      ENUM('scheduled','completed','no_show') NOT NULL DEFAULT 'scheduled',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slot (slot_id),
  KEY idx_student (student_id),
  FOREIGN KEY (slot_id)    REFERENCES session_slots(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Top-up payment requests: student uploads proof, admin approves to add credits.
CREATE TABLE IF NOT EXISTS session_topups (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  student_id  INT NOT NULL,
  user_id     INT NULL,
  program     VARCHAR(255) DEFAULT '',
  credits     INT NOT NULL DEFAULT 0,
  amount      DECIMAL(10,2) NULL,
  currency    CHAR(3) DEFAULT 'PHP',
  proof_url   VARCHAR(500) DEFAULT '',
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_student (student_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
