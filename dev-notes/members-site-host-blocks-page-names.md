---
name: members-site-host-blocks-page-names
description: members-site host (Hostinger) returns 500 for certain page URLs by name — avoid enroll.php / web-access.php
metadata: 
  node_type: memory
  type: project
  originSessionId: bcfd8d63-cce8-4360-9818-5d07d45dc427
---

In members-site (members.zachalcasid.com on Hostinger/LiteSpeed), a direct HTTP
request to `enroll.php` and `web-access.php` returned **HTTP 500 with no PHP
error and an empty log**, while the identical code ran fine when `include`d by
another script, and a byte-for-byte copy under a different name
(`signuptest.php`) loaded normally. Cause: a **host WAF / malware-scanner rule
blocks those URL names at the request layer** (not PHP, not .htaccess, not file
permissions — perms were 0644).

Fix applied (2026-07-01): renamed the public pages and rewired all references —
`enroll.php` → `student-signup.php`, `web-access.php` → `membership-signup.php`,
`my-enrollment.php` → `my-membership.php`.

**How to apply:** when adding new public pages here, avoid names like
"enroll"/"web-access"/"access". If a new page 500s with no PHP error, test a
renamed copy before deep debugging — it's likely this URL block. See
[[members-site-students-are-users]].
