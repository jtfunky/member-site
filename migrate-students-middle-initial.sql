-- Adds the middle_initial column to the existing students table.
-- Run once via Hostinger phpMyAdmin -> SQL tab (or Import). Safe to re-run:
-- if the column already exists, MySQL will error harmlessly — just ignore it.
ALTER TABLE students
  ADD COLUMN middle_initial VARCHAR(10) DEFAULT '' AFTER first_name;
