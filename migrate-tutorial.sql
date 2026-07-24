-- ============================================================
--  Tutorial-seen flag — members-site
--  Tracks whether a student has been shown the basic kick/snare/hi-hat/crash/
--  tom walkthrough before their first placement test. Defaults to 0 (not
--  seen) for everyone — no backfill UPDATE is needed, since students who've
--  already taken the placement test never reach this gate anyway
--  (placement-test.php redirects them away once hasTakenPlacementTest() is
--  true, same as it always has).
--  Run once via phpMyAdmin -> SQL tab. Re-running just errors harmlessly with
--  "Duplicate column" (same convention as the other students-table migrations).
-- ============================================================

ALTER TABLE students
  ADD COLUMN tutorial_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER placement_required;
