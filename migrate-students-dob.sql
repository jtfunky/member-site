-- Adds the date_of_birth column to the existing students table.
-- Run once via Hostinger phpMyAdmin -> SQL tab (or Import). Safe to re-run:
-- if the column already exists, MySQL errors harmlessly — just ignore it.
ALTER TABLE students
  ADD COLUMN date_of_birth DATE NULL AFTER age;
