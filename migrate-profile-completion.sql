-- ============================================================
--  Profile-completion gate — members-site
--  Self-signups (social login / direct registration) only have a name + email,
--  so they must complete their profile before accessing content. This flag
--  defaults to 1 (complete) so existing members and full enrollment-form
--  signups are never gated; only self-signup/backfill accounts start at 0.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run.
-- ============================================================

ALTER TABLE students
  ADD COLUMN profile_completed TINYINT(1) NOT NULL DEFAULT 1 AFTER source_file;

-- Accounts created without the enrollment form must complete their profile.
UPDATE students SET profile_completed = 0
  WHERE source_file IN ('self-signup', 'backfill');
