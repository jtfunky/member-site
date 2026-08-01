-- Add a `last_seen_at` column to the users table so the admin dashboard can
-- show who's actually online right now, instead of "Active Now" which was
-- really just counting users with an unexpired subscription.
-- Updated (throttled to ~once/minute per user) on every authenticated page
-- load — see getCurrentUser() in includes/auth.php.
--
-- Run this BEFORE uploading the updated PHP; the code tolerates the column
-- being absent (falls back to the old subscription-based count) either way.

ALTER TABLE users
  ADD COLUMN last_seen_at DATETIME NULL AFTER access_expires_at,
  ADD INDEX idx_users_last_seen_at (last_seen_at);
