# Deploy checklist — pending batch (as of 2026-07-02)

> ✅ **DEPLOYED 2026-07-02** — Steps 1 (SQL), 2 (uploads), and 3 (deleted probe) are
> DONE. Only Step 4 (smoke test) remains. Kept for reference / re-running on a fresh env.

Everything below is written and lint-clean locally. Do it in this order.

## Step 1 — Run SQL (phpMyAdmin → SQL tab), in this order
Each is safe to re-run.

1. `migrate-drum-tests.sql`  — AI placement-test table. **(already run per Zach — skip if done)**
2. `migrate-middle-initial-single-letter.sql`  — normalize existing middle initials
3. `migrate-backfill-web-access-students.sql`  — give existing accounts a Website-access student record
4. `migrate-profile-completion.sql`  — add profile_completed flag (**must run AFTER the backfill above**)

## Step 2 — Upload files (overwrite existing)

### includes/
- config.php   ← contains the ANTHROPIC_API_KEY + MAIL_FROM
- mail.php
- oauth.php
- geo.php
- programs.php
- nav.php
- access.php
- ai_feedback.php   (new)

### root pages
- forgot-password.php
- membership-signup.php
- student-signup.php
- register.php
- dashboard.php
- game.php
- placement-test.php   (new)
- complete-profile.php   (new)

### api/
- save-test.php   (new)
- ai-feedback.php   (new)

### admin/
- index.php
- users.php
- students.php
- sessions.php
- songs.php
- placement-tests.php   (new)

### exclusive/
- index.php

### assets/js/game/
- scoring.js
- chart.js
- main.js

## Step 3 — Delete from server
- `_opcache-check.php`  (leftover diagnostic probe)

## Step 4 — Smoke test
- Sign up a NEW account via Google/Facebook → should land on **Complete Your Profile** → fill it → dashboard.
- Existing member logs in → NOT asked to complete profile; sees content normally.
- `/placement-test.php` → Start → play (keys A S D F G H J K L ;) → AI coaching appears.
- Admin → Students → filter **Website access** → all self-signups now listed.
- Admin → Placement Tests → the test you played is listed.
- Web-access student: no "My Sessions" in nav / no sessions tile. One-on-one student: both present.

## Notes
- The profile gate and auto-enroll are **inert until their migrations run** (missing columns are caught), so uploading code before the SQL won't lock anyone out.
- After testing, consider rotating the Anthropic API key (it was pasted in plaintext) and setting a monthly spend cap in the Anthropic console.
