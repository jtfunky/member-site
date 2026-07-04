-- ============================================================
--  Placement test as a mandatory onboarding step — members-site
--  New signups must take the AI drum placement test before accessing content.
--  The column defaults to 1 (required) so every NEW student row is gated; the
--  UPDATE clears it for all EXISTING students so current members aren't
--  interrupted. The gate passes once the student has a drum_tests row.
--  ⚠️ RUN ONCE ONLY. Do NOT re-run the UPDATE afterwards — it has no date guard,
--  so a second run would un-gate every new signup made since. (The ALTER errors
--  harmlessly with "Duplicate column" if repeated.)
-- ============================================================

ALTER TABLE students
  ADD COLUMN placement_required TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_completed;

-- Existing students are exempt; only accounts created after this migration are required.
UPDATE students SET placement_required = 0;
