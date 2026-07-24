-- ============================================================
--  Scoped staff roles — members-site
--  Adds two admin-assigned roles alongside user/admin:
--    editor  — Song Tags, Song Requests, Chart Editor only
--    partner — Partner page only
--  Additive ENUM change; existing user/admin rows are unaffected. Safe to re-run.
--  Run once via phpMyAdmin -> SQL tab.
-- ============================================================

ALTER TABLE users
  MODIFY role ENUM('user','admin','editor','partner') NOT NULL DEFAULT 'user';
