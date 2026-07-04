---
name: members-site-hosting-capacity
description: "members-site hosting plan, expected scale, and the performance mitigations applied"
metadata: 
  node_type: memory
  type: project
  originSessionId: 4c742988-3be4-4c73-9237-a7c7b37088e9
---

members-site runs on **Hostinger Premium shared hosting** (~20 concurrent PHP
workers, shared CPU/IO, capped MySQL max_connections; rated ~25k visits/mo).
Owner expects a **1,000–5,000 member base** (total accounts, not concurrent).

**Capacity read:** Premium is comfortable for ~100–300 concurrent browsing users;
strains toward 300–600. That covers a 1,000–2,500 base easily. Toward 5,000 with
synchronized login spikes (a scheduled class starting), plan to move to Hostinger
Cloud Startup / VPS. Biggest real risk is a login rush saturating the ~20 workers.

**Performance mitigations done (2026-07-02):**
- OAuth cURL timeout cut 15s → CONNECTTIMEOUT 4 / TIMEOUT 8 in `includes/oauth.php`
  (a slow Google/FB response no longer pins a scarce worker for 15s — the #1 risk).
- OPcache confirmed ENABLED + healthy (128 MB pool, only ~9 MB used, revalidate 2s).
- DB indexes already optimal (users username/email UNIQUE, students idx_email/idx_user,
  session_slots idx_starts, bookings/topups/oauth all indexed) — nothing to add.
- Load-test script at `loadtest/k6-load.js` (k6, read-only GETs public pages). Run
  off-peak, raise PEAK until p95 / error rate spike = real concurrent ceiling.

**Load test (2026-07-02, PEAK=50):** 0% errors / fully stable, but p95=3.28s and
max=31.67s. Root cause = synchronous external geo-IP call to ip-api.com on public
page renders (incl. homepage index.php via getUserCurrency, + both signup pages).
ip-api free tier ~45 req/min per SERVER outbound IP → a spike of new visitors gets
throttled and workers stall up to the timeout. Cached per session ($_SESSION), so
one call per visitor.

**Fix applied (includes/geo.php):** prefer Cloudflare `HTTP_CF_IPCOUNTRY` header
(instant, no external call) when present; hard-bounded the ip-api fallback with cURL
CONNECTTIMEOUT 2 / TIMEOUT 3 (kills the 31s hang); result cached per session either
way. Recommended follow-up: put site behind Cloudflare (free, hPanel one-click) so
the header is always set AND its CDN offloads static assets from the ~20 PHP workers.
Self-hosted alternative: local MaxMind GeoLite2 DB (no external calls). Re-run k6
after deploying geo.php — expect p95 to drop from ~3.3s toward sub-second.

**Domain switch (planned):** site will move to a new domain before public launch;
holding off Cloudflare until then (would be per-domain rework, and geo.php falls back
fine without it). Domain now lives in ONE file: `includes/config.php` — `SITE_URL` +
`MAIL_FROM` (centralized 2026-07-02; mail.php/forgot-password.php no longer hardcode
it). On switch: edit those 2 constants, then update OAuth redirect URIs in Google +
Facebook consoles (fail silently on mismatch), new DNS/SSL, set up Cloudflare on the
new domain. Current live domain: members.zachalcasid.com.

**Deliberately NOT done:** page/query caching of the calendar/session-availability
views — caching those risks two students seeing the same slot open and double-booking.
OPcache (bytecode, zero staleness) is the safe win instead. See [[members-site-social-login-status]].
