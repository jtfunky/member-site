# Memory Index

- [Students are the site's users](members-site-students-are-users.md) — in members-site, imported students ARE the members; don't filter them out of Users/stats
- [Host blocks some page names](members-site-host-blocks-page-names.md) — Hostinger 500s direct URLs enroll.php/web-access.php by name; renamed to student-signup/membership-signup
- [PHP 8.3 in production](members-site-php-version.md) — members-site runs PHP 8.3; lint with php8.3.28 not 8.0 (8.0 falsely errors on `string|true`)
- [Social login status](members-site-social-login-status.md) — OAuth LIVE (Google + Facebook); left off the enrollment signup pages pending payment integration
- [Hosting capacity](members-site-hosting-capacity.md) — Hostinger Premium; 1000-5000 member base; OPcache on, OAuth timeout cut, indexes optimal; page-cache deliberately skipped
- [AI drum feedback](members-site-ai-drum-feedback.md) — placement-test AI coaching (Claude Haiku); built 2026-07-02, needs ANTHROPIC_API_KEY + drum_tests table to go live
- [Pending deploy](members-site-pending-deploy.md) — crash-recovery: what's built but not yet migrated/configured/uploaded as of 2026-07-02
