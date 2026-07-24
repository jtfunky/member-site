-- ============================================================
--  Song category — members-site
--  Scopes each song to an input: 'kit' (e-drums / acoustic) or 'pad' (practice
--  pad). The game shows only the matching category for the chosen input.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run (ignore "Duplicate column").
-- ============================================================

ALTER TABLE songs
  ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'kit' AFTER artist;
