---
name: members-site-students-are-users
description: members-site data model — students are the members; the admin Users page lists administrators only
metadata: 
  node_type: memory
  type: project
  originSessionId: 85c3dfc9-235b-48f9-b60e-1b0b13517323
---

In the `members-site` project (DrumKit / members.zachalcasid.com):

- **Students = the site's members/users** (role `user`). The ~244 imported enrollment
  accounts each have a `users` row (linked from `students.user_id`). The **Students page**
  (`admin/students.php`) is their management view (payment proofs, parent/guardian, program).
- **The admin "Users" page** (`admin/users.php`) manages **administrators only** — it lists
  `WHERE role = "admin"`, heading "Administrators". Do NOT list members/students here.
- **Overview member stats** ("Total Members", "Active Now", "Recent Sign-ups") count
  `role = "user"`, i.e. the members/students — that is correct, leave it.

**Why:** the user clarified "users = administrators" for that admin page, after I first
wrongly tried to filter students out by their student-link and then wrongly showed all
accounts. The right split is by role: admins on the Users page, members on the Students page.
**How to apply:** keep the Users page admin-only (`role="admin"`); never hide members from
member counts; manage members via the Students page.
