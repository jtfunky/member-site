# Deployment Checklist

> ✅ **DEPLOYED — all steps below completed on 2026-07-01.** (Level / Country /
> enrollment / web-access / session scheduling + email notifications are all live.)
> Kept for reference and for re-running on a fresh environment.

Steps to deploy the Level / Country / enrollment / web-access work. Do them in
order.

## Step 1 — Run database SQL (phpMyAdmin → SQL tab)

Run these. Each is safe to re-run — a "Duplicate column name" error just means
it was already applied; ignore and continue.

```sql
ALTER TABLE users    ADD COLUMN country        VARCHAR(60) DEFAULT '' AFTER currency;
ALTER TABLE students ADD COLUMN middle_initial VARCHAR(10) DEFAULT '' AFTER first_name;
ALTER TABLE students ADD COLUMN date_of_birth  DATE        NULL       AFTER age;
```

The two `students` columns were added to the schema after the live DB was first
set up; without them the signup INSERT and the admin edit/save forms 500 with
"Unknown column". The `pending`/`trial` statuses already exist in the `users`
ENUM.

- [ ] `users.country` column added
- [ ] `students.middle_initial` column added
- [ ] `students.date_of_birth` column added

## Step 2 — Create the proof-upload folder

On the server (Hostinger File Manager or FTP), under the site root:

- [ ] Create folder `uploads/proofs/`
- [ ] Upload `uploads/proofs/.htaccess` into it
- [ ] Make it writable (permissions `755`)

## Step 3 — Upload files

New files:

- [ ] `student-signup.php`  (was enroll.php — renamed; host blocked the old URL)
- [ ] `my-membership.php`    (was my-enrollment.php)
- [ ] `membership-signup.php` (was web-access.php)
- [ ] `assets/js/program-amount.js`
- [ ] `includes/programs.php`
- [ ] `uploads/proofs/.htaccess`

Changed files:

- [ ] `admin/students.php`
- [ ] `admin/users.php`
- [ ] `includes/auth.php`
- [ ] `includes/geo.php`
- [ ] `includes/access.php`
- [ ] `includes/security.php`
- [ ] `includes/config.php`
- [ ] `includes/countries.php`
- [ ] `includes/nav.php`
- [ ] `dashboard.php`
- [ ] `login.php`
- [ ] `install.php` (optional — only matters for a fresh install)

## Step 3b — Delete old / temporary files from the server

The public pages were renamed because the host blocks the old URLs. Remove the
old copies and the diagnostics so nothing stale is served:

- [ ] `enroll.php` (old blocked name → replaced by student-signup.php)
- [ ] `web-access.php` (old blocked name → replaced by membership-signup.php)
- [ ] `my-enrollment.php` (old name → replaced by my-membership.php)
- [ ] `signuptest.php` (the rename test copy)
- [ ] `_diag.php`, `_min.php`, `_php_error.log` (diagnostic probes)

## Step 4 — Verify each flow

### A. Course enrollment (proof + approval)

- [ ] Open `/student-signup.php`, fill it, upload an image/PDF proof, submit
- [ ] Land on `/my-membership.php` showing "Awaiting payment confirmation"; `/game.php` redirects back there
- [ ] Admin → Students → open that student → click **✓ Confirm Payment & Grant Access**
- [ ] Log back in as that user → full access; enrollment page shows "active"

### B. Website access (trial + card)

- [ ] Open `/membership-signup.php`, fill it, click **Start 15-Day Free Trial**
- [ ] Taken to dashboard with 15 days access; student shows in admin with program **Website access**, status **Trial**

### C. Admin student management

- [ ] **+ Add Student** → create a record (Level + Country + Program dropdowns, amount auto-fills)
- [ ] Test the **Level** and **Program** filters on the list

### D. Session scheduling

- [ ] Admin → **Sessions** → add an available slot
- [ ] As a student, open `/my-sessions.php` → book that slot (credit count drops by 1)
- [ ] Reschedule it (>24h away) → moves to another slot; within 24h it's locked
- [ ] Drain credits to 0 → book is blocked → `/session-payment.php` → upload proof
- [ ] Admin → Sessions → **Approve** the pending payment → student's credits go up
- [ ] Confirm enrollment approval auto-grants credits (F2F 9k=6, Online 2k=1, Online 9k=6)

## Session scheduling — extra deploy items

Run the whole `migrate-sessions.sql` (adds `students.session_credits` + the
`session_slots`, `session_bookings`, `session_topups` tables).

New files: `includes/sessions.php`, `includes/mail.php`, `my-sessions.php`,
`session-payment.php`, `admin/sessions.php`, `assets/css/calendar.css`.
Changed: `includes/bootstrap.php` (loads mail.php), `includes/programs.php`,
`includes/nav.php`, `admin/students.php`, `admin/index.php`, `admin/users.php`,
`admin/songs.php`, `student-signup.php` (admin notify on enrollment).

- [ ] `migrate-sessions.sql` run (session_credits column + 3 tables)
- [ ] session files uploaded (incl. `calendar.css` and `mail.php`)
- [ ] payment details already set in `session-payment.php` (PayPal / GCash / BPI)
- [ ] test email delivery (book a session → admin should get a mail)

Email notifications use PHP `mail()` (same transport as password reset). Recipients
for staff alerts are all active `role='admin'` accounts — nothing to configure.
Booking/reschedule notify admins + the student; session/enrollment payments notify
admins; approvals notify the student.

## Notes

- Card entry on `/membership-signup.php` is simulated (site is in `PAYMENT_MODE = 'dummy'`).
  No real charge happens until Stripe is configured.
- If anything errors, the most likely cause is the `uploads/proofs/` folder not
  existing or not being writable (Step 2).

## Reference: SQL migration files in the repo

- `migrate-users-country.sql` — the ALTER in Step 1
- `migrate-students-experience-to-level.sql` + `-part2.sql` — already run (Level mapping)
