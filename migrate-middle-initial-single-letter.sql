-- Normalize existing middle initials to a single uppercase letter.
-- The signup/admin forms now cap middle_initial at one letter; this cleans up
-- any rows saved before that (older records allowed up to 10 chars).
-- Safe to re-run. Strips to the first alphabetic character and uppercases it;
-- rows whose middle initial has no letter (blank, punctuation) are set to ''.

UPDATE students
SET middle_initial = UPPER(LEFT(REGEXP_REPLACE(middle_initial, '[^A-Za-z]', ''), 1))
WHERE middle_initial <> UPPER(LEFT(REGEXP_REPLACE(middle_initial, '[^A-Za-z]', ''), 1));
