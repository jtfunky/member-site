# Deploy checklist — game/songs + in-admin Chart Editor (2026-07-05)

No database changes. Reuses the existing `songs` table. Upload the files below
(overwrite existing), then smoke-test. Everything is static PHP/JS/CSS.

## Step 1 — Upload NEW files
- `admin/song-editor.php`             — in-admin Chart Editor page (create + edit songs)
- `assets/js/chart-editor.js`         — editor logic + Save-to-Library + edit loader
- `assets/css/chart-editor.css`       — editor styles
- `assets/js/game/songs/rock-basics.js` — 2nd built-in practice song

## Step 2 — Upload CHANGED files (overwrite)
- `game.php`                          — main.js cache-bust → v=20260705
- `api/song-save.php`                 — validates notes (lane 0–9, time≥0) + sorts
- `admin/songs.php`                   — Chart Editor nav pill + row "🥁 Editor" link, updated hints
- `assets/js/admin.js`                — removed FFT auto-detect; slim duration/title autofill + live note summary/validation
- `assets/js/game/main.js`            — imports Rock Basics; placement test pinned to demo; demo.js cache-bust
- `assets/js/game/songs/demo.js`      — lane remap: crash 6→7, ride 7→9 (were firing floor-tom / 16" crash)

## Step 3 — Smoke test
1. **`/admin/song-editor.php` loads (no 500).** The page was named `song-editor.php`
   (not `chart-editor.php`) pre-emptively to avoid the host's page-name blocking
   (enroll.php/web-access.php class of issue). If it *still* 500s, try another neutral
   name and update the links in `admin/songs.php`.
2. **Create:** Chart Editor → 📂 Load Audio → tap pads / play e-drums to record → 💾 Save to
   Library → song appears in Admin → Songs and is playable in `/game.php`.
3. **Edit:** Admin → Songs → **🥁 Editor** on a song → notes + audio load; tweak a hit → Save →
   change persists. Loading a new file shows "New: … replaces the saved track on save".
4. **Game:** Demo Beat's crashes/rides now hit the correct pads (rightmost lanes light up);
   **Rock Basics** shows in the library; the placement test (`/game.php?test=1`) still lists
   only the Demo exercise.
5. **Validation:** in the manual Songs form, paste `[{"time":0,"lane":12}]` → save is rejected
   with a clear error; the live summary shows per-lane counts.

## Notes
- Browsers cache ES modules — the `?v=20260705` bumps cover `main.js`, `demo.js`, `admin.js`,
  and the new editor assets, so a normal reload is enough.
- Any song an admin saves is visible to ALL premium members (the `songs` table has no owner
  column) — by design, but keep in mind for test/junk songs.
