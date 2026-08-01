-- First-time "how to play Groove Quest" walkthrough shown once on a new
-- user's first visit to game.php — separate from the existing placement-test
-- drum-lane tutorial (students.tutorial_completed), which teaches identifying
-- physical drums, not how to use the app itself.
--
-- Existing users are grandfathered in as already-seen (same pattern as
-- migrate-email-verification.sql) — this only shows for new signups going
-- forward.
--
-- Run this BEFORE uploading the updated PHP; the code tolerates the column
-- being absent (falls back to never showing the tour) either way.

ALTER TABLE users
  ADD COLUMN game_intro_seen_at DATETIME NULL AFTER last_seen_at;

UPDATE users SET game_intro_seen_at = NOW() WHERE game_intro_seen_at IS NULL;
