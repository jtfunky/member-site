# Deploy checklist — landing page redesign (2026-07-17)

Scope: only the landing page work from 2026-07-17 (photo hero, photo-sampled
palette, self-hosted Righteous/Poppins, falling-notes accents, copy edits).
Does NOT include the older uncommitted backlog (staff roles, Maya payments,
song requests, admin devices, plan games, etc.) — that's a separate, larger
batch with its own SQL migrations, not covered here.

No database changes required for this batch.

## Step 0 — Pricing update (separate from the landing-page redesign)

`includes/config.php` changed too: `PRICE_PHP` 299.00 → 499.00, `PRICE_USD`
6.99 → 20.00.

⚠️ **This file is not the same risk level as the rest of the batch.** It's
gitignored and holds your *entire* live config — DB credentials, OAuth
secrets, the Anthropic API key, `SITE_URL`, everything — not just pricing.
Overwriting the server's copy wholesale will also overwrite any of those
values if they've drifted from this local copy (e.g. a secret rotated
directly on the server since this file was last pulled down). Before
uploading:

- [ ] Diff this local `includes/config.php` against the live server's copy
      (download it first, don't just overwrite blind) — confirm the only
      difference is the two `PRICE_*` lines
- [ ] If anything else differs, stop and reconcile before uploading
- [ ] Upload `includes/config.php`

## Step 1 — Upload files (overwrite existing)

### root
- [ ] `index.php`

### assets/css/
- [ ] `landing.css`
- [ ] `main.css`
- [ ] `icons.css`  (new file — wasn't on the server before)

### assets/js/
- [ ] `landing.js`  (new file — scroll-linked content reveal, ~1 KB)

### assets/fonts/ (all new — create the folder if it doesn't exist on the server)
- [ ] `poppins-400.woff2`   (~8 KB)
- [ ] `poppins-500.woff2`   (~8 KB)
- [ ] `poppins-600.woff2`   (~8 KB)
- [ ] `poppins-700.woff2`   (~8 KB)
- [ ] `righteous-400.woff2` (~13 KB)
- [ ] `tabler-icons.woff2`         (~810 KB — wasn't on the server before either)
- [ ] `tabler-icons-filled.woff2`  (~118 KB)

### assets/img/ (new)
- [ ] `hero-drummer.jpg`        (~230 KB — desktop hero photo)
- [ ] `hero-drummer-mobile.jpg` (~92 KB — portrait crop for mobile)

## Step 2 — Verify CSP allows the new fonts

Your `.htaccess` currently shows as **deleted** in the local working tree (a
pre-existing issue, unrelated to this batch — see if that was intentional
before deploying). If the live server's `.htaccess` still has the original
CSP (`style-src 'self' 'unsafe-inline'`, no `font-src` override), self-hosted
fonts under `assets/fonts/` need no extra CSP change — they fall under
`default-src 'self'`, same as the Tabler icon font already did.

- [ ] Confirm live `.htaccess` still has its CSP header (check before/after
      this deploy — if it's already missing on the server too, that's a
      separate issue worth fixing regardless of this deploy)

## Step 3 — Smoke test

- [ ] Open the live homepage — hero shows the photo (not the old gradient-only
      version), headline reads "Play Drums. Easy and Fun." in the rounded
      Righteous font
- [ ] Body text (feature cards, pricing) renders in Poppins, not the old
      system font
- [ ] "Log In" / "Sign Up Free" sit top-right of the nav
- [ ] CTA button reads "Start Free Trial — Signup Now!"
- [ ] Feature card reads "New Songs Every Week" / "Play along with your
      favorite songs and exercises."
- [ ] No "No credit card required for trial." line under the CTA (removed)
- [ ] Open browser dev tools → Network tab → confirm all `.woff2` and the two
      `.jpg` files return `200`, not `404`
- [ ] Check on a phone-width viewport — hero photo shows the portrait crop
      (face + both drumsticks in frame), not a squeezed landscape image
- [ ] The "Simple Pricing" section is **intentionally hidden** (`.pricing`
      has `display: none` in landing.css, per request 2026-07-17) — if you
      still want the price change from Step 0 live and just not shown yet,
      that's expected. Don't "fix" this by re-adding it unless asked.
- [ ] Colored "falling notes" softly drift down behind the feature cards and
      pricing area (10 small bars in the game's lane colors, very low
      opacity) — confirms they're active, not accidentally hidden too
- [ ] Nav and footer stay fixed in place (top/bottom) while scrolling, with a
      barely-there frosted-glass tint (very low opacity + blur) — not scrolling
      away with the rest of the page
- [ ] The hero photo itself stays pinned as a fixed background behind
      everything while scrolling, instead of scrolling away after the first
      screen
- [ ] Hero text and feature cards fade/scale in as they scroll toward the
      center of the screen, and fade back out past it — check that pausing
      mid-scroll freezes the effect rather than it finishing on its own
      (confirms `assets/js/landing.js` loaded — check DevTools Console for
      any errors if this doesn't work)
