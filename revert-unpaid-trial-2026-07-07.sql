-- ============================================================
--  One-off: revert free-trial access for self-enrolled students who signed
--  up (student-signup.php) on/after 2026-07-07 and still haven't paid, so
--  they fall under the new "proof required, no access until confirmed"
--  policy (see includes/auth.php createEnrolleeAccount()). Their students
--  row (payment_status, proof_url if any) is untouched — only the users
--  row's access is reset. They'll see the same "Awaiting payment
--  confirmation" + upload-proof screen on my-membership.php that new
--  signups get.
--
--  Run the SELECT first and review the list. Only run the UPDATE once
--  you're sure it's the right set of people — this immediately revokes
--  their current site access, including anyone logged in right now.
-- ============================================================

-- 1) PREVIEW — who would be affected. Run this first.
SELECT u.id AS user_id, u.email, u.subscription_status, u.access_expires_at,
       s.enrolled_at, s.program, s.payment_status, s.proof_url
FROM users u
JOIN students s ON s.user_id = u.id
WHERE u.registration_type = 'self'
  AND u.subscription_status = 'trial'
  AND s.source_file = 'enroll'
  AND s.enrolled_at >= '2026-07-07'
  AND s.payment_status NOT IN ('Yes','yes','Paid','paid','Done','done')
ORDER BY s.enrolled_at;

-- 2) APPLY — only after confirming the list above looks right.
-- UPDATE users u
-- JOIN students s ON s.user_id = u.id
-- SET u.subscription_status = 'pending',
--     u.access_expires_at   = NULL
-- WHERE u.registration_type = 'self'
--   AND u.subscription_status = 'trial'
--   AND s.source_file = 'enroll'
--   AND s.enrolled_at >= '2026-07-07'
--   AND s.payment_status NOT IN ('Yes','yes','Paid','paid','Done','done');
