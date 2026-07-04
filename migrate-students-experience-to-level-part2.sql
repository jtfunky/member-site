-- Follow-up mapping for the remaining freeform `experience` values that
-- the first migration did not catch. Uses LIKE prefixes because the admin
-- list truncates long values (the stored text may be longer than shown).
--
-- Run AFTER migrate-students-experience-to-level.sql.

-- Advanced: many years of playing.
UPDATE students
SET experience = 'Advanced'
WHERE experience LIKE 'Started 2014%'      -- ~10 yrs (2014, minus a 2-yr break)
   OR experience LIKE 'More than 20%'
   OR experience IN ('22', '15', '24');

-- Intermediate: real prior playing experience, just informal / interrupted.
UPDATE students
SET experience = 'Intermediate'
WHERE experience LIKE 'Had a big break%'
   OR experience LIKE 'Self-taught for church%';

-- Beginner: under a year, casual, or self-directed only.
UPDATE students
SET experience = 'Beginner'
WHERE experience LIKE '%month%'                 -- 8 months, 1 month, 4 months, 6 months, 2months
   OR experience LIKE 'Started playing by ear%'
   OR experience LIKE 'Just a passion%'
   OR experience LIKE 'Youtube tutorials%';

-- Re-check what (if anything) is still unmapped:
--   SELECT DISTINCT experience FROM students
--   WHERE experience NOT IN ('Beginner','Intermediate','Advanced');
