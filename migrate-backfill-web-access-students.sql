-- ============================================================
--  Backfill: give every login account without a student record a
--  "Website access" student enrollment (Trial). Self-signups via direct
--  registration or social login create a users row but no students row; this
--  reconciles the existing accounts so they appear in the Students admin.
--  Run once via phpMyAdmin -> SQL tab. Safe to re-run (the NOT EXISTS guard
--  skips anyone already enrolled).
-- ============================================================

INSERT INTO students
  (user_id, enrolled_at, email, first_name, last_name, full_name,
   program, currency, payment_status, source_file)
SELECT
  u.id,
  COALESCE(u.created_at, NOW()),
  u.email,
  COALESCE(u.first_name, ''),
  COALESCE(u.last_name, ''),
  TRIM(CONCAT(
    COALESCE(u.last_name, ''),
    IF(COALESCE(u.first_name, '') <> '', CONCAT(', ', u.first_name), '')
  )),
  'Website access',
  COALESCE(NULLIF(u.currency, ''), 'PHP'),
  'Trial',
  'backfill'
FROM users u
WHERE u.role = 'user'
  AND NOT EXISTS (
    SELECT 1 FROM students s
    WHERE s.user_id = u.id
       OR (s.email <> '' AND s.email = u.email)
  );
