---
name: members-site-social-login-status
description: members-site social login (OAuth) is LIVE — Google + Facebook working in production as of 2026-07-01
metadata: 
  node_type: memory
  type: project
  originSessionId: 4c742988-3be4-4c73-9237-a7c7b37088e9
---

members-site social login (Google, Facebook, Discord) is **code-complete and
lint-clean** — `includes/oauth.php`, `oauth-start.php`, `oauth-callback.php`
(secure `state` CSRF via random_bytes + hash_equals), `includes/social-buttons.php`
(wired into login.php + register.php), `profile.php` (connect/disconnect),
`migrate-oauth.php` (creates `oauth_accounts` table). A provider with an empty
Client ID in config.php is auto-hidden, so they can be enabled one at a time.

Launch target: **Google + Facebook** (Discord left disabled for now).

**Remaining work is external setup only (no coding):**
1. Register app at each provider console; set redirect URI exactly
   `https://members.zachalcasid.com/oauth-callback.php?provider=google` (and `...=facebook`).
2. Paste Client ID + Secret into `includes/config.php` lines 45-48.
3. Visit `/migrate-oauth.php` once on the server to create the table, then delete it.
4. Test each button + profile.php connect/disconnect.

**Status as of 2026-07-01: LIVE and working in production.** Google + Facebook
both deployed, tested end-to-end, working. Discord left empty (button hidden).

**Decision (2026-07-01):** deliberately NOT adding social login to
`student-signup.php` or `membership-signup.php`. Those pages create a `students`
enrollment record (program, DOB, proof-of-payment upload) in the same submit, which
OAuth can't capture. Real online payment is coming to those pages soon, so social
sign-up there would just be reworked. Social login stays on login.php + register.php
only. Revisit after the payment integration lands.

**Only remaining item is for PUBLIC launch (not urgent):** Facebook app is still
in Development mode — only admins/testers can log in. To open Facebook login to all
students, complete Business Verification + App Review and add the four missing
items (App icon 1024x1024, Privacy Policy URL, User data deletion URL, Category),
then flip the app to Live. Google consent screen: if still in "Testing," publish it
or add students as test users.

**Gotcha:** a provider's button shows as soon as its Client ID is non-empty —
the secret is NOT checked. Don't deploy config to production with an ID but no
secret, or the button will fail mid-flow. See [[members-site-host-blocks-page-names]].
