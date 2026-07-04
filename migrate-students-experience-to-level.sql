-- Map legacy free-text `experience` values onto the new Level dropdown
-- options (Beginner / Intermediate / Advanced).
--
-- Mapping rule (by years of experience):
--   None / blank / 0-1 yr  -> Beginner
--   1-5 yrs                 -> Intermediate
--   6+ yrs                  -> Advanced
--
-- Run order matters: once a row is set to a Level word it no longer
-- matches the year patterns below, so it is only mapped once.

-- 1) Beginner: no experience or under a year.
UPDATE students
SET experience = 'Beginner'
WHERE experience IS NULL
   OR TRIM(experience) = ''
   OR experience = 'None'
   OR experience LIKE '0-1%'
   OR experience LIKE 'less than%';

-- 2) Intermediate: roughly 1-5 years (incl. the freeform "4-6 ... no formal training").
UPDATE students
SET experience = 'Intermediate'
WHERE experience = '1-5 years'
   OR experience = '3 years'
   OR experience = '4-6 years but no formal training yet'
   OR experience LIKE '1-5%'
   OR experience LIKE '2-%'
   OR experience LIKE '3 %'
   OR experience LIKE '4-%'
   OR experience LIKE '5 %';

-- 3) Advanced: 6+ years.
UPDATE students
SET experience = 'Advanced'
WHERE experience = '6-10 years'
   OR experience = '11 or more years'
   OR experience LIKE '6-10%'
   OR experience LIKE '10 years%'
   OR experience LIKE '11%'
   OR experience LIKE '%or more%';

-- After running, spot-check anything left unmapped:
--   SELECT DISTINCT experience FROM students
--   WHERE experience NOT IN ('Beginner','Intermediate','Advanced');
