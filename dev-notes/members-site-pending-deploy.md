---
name: members-site-pending-deploy
description: "members-site — what's built but NOT yet deployed/configured, as of 2026-07-02 (crash-recovery checklist)"
metadata: 
  node_type: memory
  type: project
  originSessionId: 4c742988-3be4-4c73-9237-a7c7b37088e9
---

**STATUS: ✅ ALL DEPLOYED 2026-07-02** — all 4 SQL migrations run, all files uploaded,
`_opcache-check.php` deleted. Only remaining task is smoke-testing (esp. the AI drum
placement test, which Zach had not yet tested). Items 1-7 below kept as a record of
what shipped / for re-running on a fresh environment.

Crash-recovery snapshot of members-site work as of 2026-07-02. All code below is
written and lint-clean (PHP 8.3) in `D:\claude projects\members-site`.

**1. AI drum placement-test feedback** — see [[members-site-ai-drum-feedback]]. TO GO LIVE:
   - Run `migrate-drum-tests.sql` in phpMyAdmin.
   - Set `ANTHROPIC_API_KEY` in `includes/config.php` (from console.anthropic.com →
     Settings → Billing add credit → API Keys → Create Key, `sk-ant-...`). Model already
     set to `claude-haiku-4-5`. Empty key = feature off (button shows "not configured").
   - Upload: assets/js/game/{scoring,chart,main}.js, includes/{config,ai_feedback,nav}.php,
     api/{save-test,ai-feedback}.php, placement-test.php, game.php, and admin/{index,users,
     students,sessions,songs,placement-tests}.php.
   - Test: /placement-test.php → Start → play → coaching; then Admin → Placement Tests.

**2. Perf/geo fix** — see [[members-site-hosting-capacity]]. Upload `includes/oauth.php`
   (OAuth timeout cut) and `includes/geo.php` (Cloudflare header + bounded ip-api fallback)
   — geo.php already uploaded per user. Delete `_opcache-check.php` from server if still there.

**3. Middle-initial single-letter** — upload admin/students.php, membership-signup.php,
   student-signup.php; run `migrate-middle-initial-single-letter.sql`.

**4. MAIL_FROM centralization** — upload includes/config.php, includes/mail.php,
   forgot-password.php (domain now lives only in config.php SITE_URL + MAIL_FROM).

**5. Web-access-only student view (2026-07-02)** — no DB change. `programs.php` got
   `studentHasSessions(?array)` + `WEB_ACCESS_PROGRAM` const. nav.php hides "My Sessions"
   for web-access-only students; dashboard.php adds a "My Sessions" tile only for session
   students (shows credits left); admin/students.php filter now accepts "Website access"
   (program='Website access'). Session-eligible = session_credits>0 OR program grants
   credits; null student (admin/legacy) keeps sessions visible. Upload: includes/programs.php,
   includes/nav.php, dashboard.php, admin/students.php.

**6. Auto-enroll self-signups as Website-access students (2026-07-02)** — root cause of
   "4 users but 2 students": register.php + social login create a `users` row but NO
   `students` row (only membership-signup/student-signup/admin do). Fix: `ensureWebAccessStudent(int)`
   helper in programs.php creates a 'Website access' student (Trial, source_file 'self-signup')
   for a user if none exists (by user_id OR email); called in register.php (after registerUser)
   and oauth.php oauthCreateUser (between captureUserCountry and captureStudentCountry).
   Backfill existing orphans: run `migrate-backfill-web-access-students.sql`. Upload:
   includes/programs.php, register.php, includes/oauth.php. Also placement test opened to all
   enrolled (game.php skips requirePremium on ?test=1) — re-upload game.php.

**7. Profile-completion gate (2026-07-02)** — self-signups must complete their profile
   before content. New `students.profile_completed TINYINT DEFAULT 1` (run
   `migrate-profile-completion.sql`; defaults 1 so existing/enrollment-form students are
   NOT gated; self-signup/backfill flipped to 0). `ensureWebAccessStudent` inserts flag=0
   (with pre-migration fallback). `requireProfileComplete($user)` in access.php redirects
   incomplete students to `complete-profile.php` (new focused form: name, DOB, phone, level,
   country, guardian-if-<18; sets flag=1). Gate wired into dashboard.php, game.php (both
   modes), exclusive/index.php. Admins + no-student accounts never gated; inert until
   migration runs. Upload: includes/programs.php, includes/access.php, complete-profile.php,
   dashboard.php, game.php, exclusive/index.php.

**Deferred (deliberate):** Cloudflare (wait for new domain — see [[members-site-hosting-capacity]]);
social login on enrollment pages (wait for payment integration — see [[members-site-social-login-status]]).
