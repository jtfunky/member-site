-- Add a `country` column to the users table so each account stores the
-- country detected from the registrant's IP at sign-up time.
-- Stores the country name (e.g. "Philippines"), matching students.address.
--
-- Run this BEFORE uploading the updated PHP, though the code is written to
-- tolerate the column being absent (sign-up won't break either way).

ALTER TABLE users
  ADD COLUMN country VARCHAR(60) DEFAULT '' AFTER currency;
