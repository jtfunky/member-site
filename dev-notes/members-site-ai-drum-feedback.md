---
name: members-site-ai-drum-feedback
description: "members-site AI drum placement-test coaching feature — built 2026-07-02, needs Anthropic API key to go live"
metadata: 
  node_type: memory
  type: project
  originSessionId: 4c742988-3be4-4c73-9237-a7c7b37088e9
---

members-site has an **AI drum coaching** feature (built 2026-07-02): a student plays
a standardized placement exercise in the rhythm game, and Claude generates
personalized "what/how to improve" feedback from their timing + accuracy metrics.

**Decisions:** enhanced the game engine for rich data (signed timing = rush/drag
bias + per-pad accuracy); a **dedicated placement test** (uses the built-in `demo`
song as the standardized exercise); model **claude-haiku-4-5** (budget choice).

**STATUS: LIVE + WORKING end-to-end (verified 2026-07-02).**

**CSP GOTCHA (fixed 2026-07-02):** the site CSP blocks inline `<script>` (all JS is
external). game.php originally passed DRUM_TEST_MODE + CSRF via an inline script →
blocked in production → test never saved, no coaching, gate stuck. Fixed by passing
config via `<div id="drum-test-config" data-csrf="...">` (game.php) that main.js reads
(`document.getElementById('drum-test-config')`). NEVER use inline scripts on this site.
Inline `style`/`<style>` are OK (style-src allows unsafe-inline), but inline SCRIPT is not:
that includes inline event handlers like `onsubmit="return confirm(...)"` /
`onclick=` — CSP `script-src 'self' https://js.stripe.com` (in .htaccess) blocks them
silently (confirm never fires). Use a `data-confirm="..."` attribute + an external JS
handler instead (see assets/js/students-admin.js and sessions-admin.js). Note: a console
"CSP blocks the use of eval" warning on a page with no eval in our code is almost always
a browser EXTENSION, not the site — don't add 'unsafe-eval'.

**CACHE GOTCHA (fixed 2026-07-02):** Hostinger serves JS with long cache headers, so
updated game JS silently kept running the OLD cached file (even after Ctrl+F5) —
including cached ES-module imports. Fix = version query strings: game.php loads
`main.js?v=YYYYMMDD`, and main.js imports the changed modules as `./scoring.js?v=...`,
`./chart.js?v=...`. When you change a game JS file, BUMP the `?v=` on its entry AND its
import so browsers refetch. Diagnose cache issues via Network tab (is `?v` present?) +
search the loaded file's Response for expected new code.

**How it works:** `game.php?test=1` sets `window.DRUM_TEST_MODE`; on the results
screen `main.js` POSTs metrics to `api/save-test.php` (stored in new `drum_tests`
table), then GETs `api/ai-feedback.php?id=` which calls Claude Haiku via raw cURL
(`includes/ai_feedback.php`, same transport as oauth.php — no Composer) and caches
the markdown on the row so re-views don't re-bill. `placement-test.php` is the
intro + past-results page (nav link added). Owner/admin-only, CSRF via X-CSRF-Token
header, rate-limited. Admin view: `admin/placement-tests.php` (all students' results +
feedback; "Placement Tests" pill added to ALL admin pages). "Retake" is covered by
the game's existing Retry button in test mode.

**Engine capture (JS):** scoring.js `createScoreState` gained `offsets[]` (signed ms)
and `lanes{}` (per-pad perfect/good/miss + offset); `judgeHit(state,diff,signedDiff,lane)`
and `judgeMiss(state,lane)`. chart.js `expireMissed` now returns the missed lanes
array (was a count). Also fixed a pre-existing results-screen bug: main.js read
`scoreState.perfect/.good/.miss` (undefined) and called `calcGrade(acc)` — corrected
to `scoreState.counts.*` and `calcGrade(scoreState)`.

**Access policy (2026-07-02):** placement test is open to ANY logged-in enrolled
student regardless of membership status — game.php skips requirePremium when
`?test=1` (uses only the free built-in exercise); the full game/song library stays
members-only. placement-test.php is requireLogin only.

**Mandatory onboarding gate (2026-07-02):** NEW signups must take the placement test
before content. New col `students.placement_required TINYINT DEFAULT 1` (run
`migrate-placement-required.sql`; the UPDATE clears it to 0 for all EXISTING students
so current members aren't interrupted — RUN ONCE, don't re-run the UPDATE).
`requirePlacementTest($user)` in access.php gates dashboard.php, game.php (self-allows
?test=1), exclusive/index.php — redirects to placement-test.php until the student has a
drum_tests row. Order on content pages: requireProfileComplete → requirePlacementTest.
Admins/non-students/existing members never gated; inert until migration runs. Upload:
includes/access.php, dashboard.php, game.php, exclusive/index.php.

**TO GO LIVE (remaining):** 1) run `migrate-drum-tests.sql` in phpMyAdmin; 2) set
`ANTHROPIC_API_KEY` in config.php (from console.anthropic.com) — empty = feature off,
button shows a "not configured" message; `AI_FEEDBACK_MODEL` already = claude-haiku-4-5;
3) upload the changed files; 4) test /placement-test.php. See [[members-site-hosting-capacity]]
(note: AI call holds a PHP worker ~up to 30s — fine at low volume, watch under bursts).
