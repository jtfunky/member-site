-- ============================================================
--  Investor payment-proof tracking
--  Kept as its own table (not stored in groovequest_agreements.state_json)
--  because that JSON blob is overwritten wholesale by the e-sign tool's
--  autosave — writing payment-proof fields into it risked a race where a
--  stale in-memory copy of the agreement state clobbers the proof fields.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS investor_payment_proofs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  doc_id       VARCHAR(64)  NOT NULL,
  user_id      INT          NULL,
  proof_url    VARCHAR(255) NOT NULL,
  uploaded_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed    TINYINT(1)   NOT NULL DEFAULT 0,
  confirmed_at DATETIME     NULL,
  confirmed_by INT          NULL,
  UNIQUE KEY uniq_doc_id (doc_id),
  FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
