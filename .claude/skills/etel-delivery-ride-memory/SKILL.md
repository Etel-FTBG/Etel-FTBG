---
name: 'etel-delivery-ride-memory'
description: 'Running project memory for the ETEL Delivery & Ride system (rider PWA delivery/ride, driver PWA delivery/driver, courier, customer, service-giver, admin, PHP API delivery/api) and for the ETEL marketing/office/brand-media work done around it (logos, Coming Soon poster/banner/video, the navy/gold/blue ETEL brand look and new group logo, office interior design and 3D renders, Pinterest/Windsor notes, Higgsfield usage). Use at the start of ANY new chat about Delivery & Ride, ETEL Ride, ETEL Driver, trip PIN, receipts, Bluetooth receipt printer, refresh button, hot areas, scheduled rides, admin 2FA, system health, cron, the Hawassa launch, marketing plan, user/driver guides, office design, 3D views, Higgsfield, Coming Soon posters or videos, or when the user says continue / what did we do / remember. Read together with etel-group-ops (conventions) and etel-audit-fix-workflow (deploy-and-verify loop).'
---

# ETEL Delivery & Ride - project memory (as of 2026-10-05)

This file is the memory of a very long working session. It does NOT replace `etel-group-ops`
(hosting/deploy conventions, EtelID root, ASCII rules) - read that too. This file records
*what has been built, decided, and left open*, so a new chat can continue without re-asking.

## 0. Who and how
- Owner: Dagi (dagmawidikasso16@gmail.com). Trial/demo phase, **no real customer money**.
- Style: wants things done, says "Continue to the next" / "Go" / "Check". Prefers short status
  and finished files. Hosting is shared cPanel, **no SSH**: Dagi uploads zips by hand.
- Git: develop on `claude/nice-sagan-zxlfhs`, commit, push, open a DRAFT PR, subscribe to PR
  activity. Dagi marks ready and merges. If the remote branch already has unmerged commits,
  stack on it (cherry-pick) instead of making a new branch. Never destructive git.
- Deliverable pattern: every code change ships as a zip with a top-level `delivery/` folder to
  extract into `public_html`. Never overwrite EtelGroup root files (root `admin.html`,
  `EtelGroup/api/boot.php`). Tell Dagi which zip to upload.
- Pronouns: use they/them for people unless stated.

## 1. Code conventions (Delivery & Ride)
- PHP is written PHP5-style and **ASCII-only** (PDO/MySQL). `boot.php` is the action dispatcher:
  every new API action must be added to its `case` lists. Settings live in `pricing_rules`
  key/value rows. `etel_heartbeat` records cron health. `rides_add_missing_cols` adds columns.
  Compare times with SQL `NOW()`.
- Prefer **additive scripts** (Shadow DOM, delegated `data-*` handlers, fetch wrappers) so no
  bundle release is needed. Only touch `js/driver.js` / `js/ride.js` core (then rebuild bundles
  and cut a release) when unavoidable. Script tags carry `?v=N` cache versions - bump on change.
- Mock-API + Playwright (headless Chromium, `/opt/pw-browsers/chromium`) is how UI was
  verified in the sandbox; Google Maps cannot load there (blank map areas).

## 2. Features shipped in this segment (all merged to main)
| PR | What | Key files |
|---|---|---|
| #405/#406 | Colourful profile icons (runtime emoji replacer fought it; fixed with `data-no-emoji-icons`) | driver/ride index.html, etel-ui.js |
| #407 | SOS button overlap + hide map note on profile | driver/ride index.html |
| #408 | Optional admin 2FA requirement (`admin_set_2fa_required`, `etel_admin_2fa_enforce()` in `require_auth`) | RideController.php, boot.php, admin-rides.js |
| #409 | Optional trip-start PIN (`ride_pin_get`, `admin_set_ride_pin_required`, PIN gate in `driver_start_ride`) | api/lib/ride_pin.php, js/ride-pin.js |
| #410/#411 | Rider receipts: button on completed trips (observer on `#ride-detail-overlay`, subtree) + premium A4 print/PDF with logo, route timeline, fare table, PAID stamp | js/ride-receipt.js (v4) |
| #412 | Rider Bluetooth thermal receipt printer (58 mm P58E, Web Bluetooth ESC/POS, `getDevices()` reconnect, Floyd-Steinberg logo) | js/ride-printer.js |
| #413 | Refresh button for ALL roles (tap = SW update + reload; hold 0.7 s = hard refresh keeping login), auto hot-area circles, cancelled trips show ETB 0 (`not_charged:true`) | js/etel-refresh.js, js/driver-demand.js |
| #414 | Driver app receipt printer + trip receipts | driver index.html |
| #415 | Scheduled-ride reminder push 30 min before (`reminder_sent_at`) | api/cron_dispatch_scheduled.php, lib/ride_reminders.php |
| #416 | System-health rows: Admin 2FA rule, Trip start PIN, Scheduled-ride cron | api/lib/system_health.php |
Earlier work (map restyle, SVG icon set, chat alerts, backups, etc.) is in `etel-group-ops`.

Receipt API: `ride_receipt` (passenger or driver of that ride; `ETEL-R000123`; lines from
`fare_breakdown_json`; no phone numbers). `fail($msg,$code,$extra)` is the error helper.

## 3. Marketing, guides, planning (delivered, awaiting Dagi's inputs)
- Delivered `ETEL-screens-guides-plan.zip`: 10 marketing cards, 16 app screens, plan PDF,
  rider + driver guides in English and Amharic. Screens use DEMO data and blank map areas;
  real-phone captures still needed. **Amharic is a draft - needs native review.**
- Marketing numbers mostly reflect Dagi's own testing - say so when quoting.
- STILL NEEDED from Dagi for the plan: launch date, monthly marketing budget (ETB), how many
  drivers can be signed up in the first 2 weeks, 3 launch zones.
- **Decision: do NOT show the "5% fee" in any marketing/office material.** Removed everywhere
  (poster, video, office renders). Do not reintroduce.

## 4. Office interior (Hawassa) - latest facts and designs
- **Real room (from Dagi's panorama photo, taken at the entrance door, 2026-10-09):** old
  off-white walls, patterned floor tile, a big burnt live-edge wooden slab table in the
  foreground, clothes/bags/suitcase to clear out. Left wall: tall narrow window with a metal
  grille and cream curtain near the far corner. Far (back) wall: blank, two small black
  sockets/switches low down. Right wall: a DOOR (confirmed) near the far corner. The entrance
  door is behind the camera. Size: Dagi writes "4*5" (once "square feet"); I treat it as
  **4 m x 5 m**; ceiling height unknown (3D uses 2.8 m). Floor may or may not be covered.
- **Pinterest board "Modern reception"** (Dagi's account `dagmawidikasso`, 14 pins). Seen via
  screenshots: round backlit logo above a curved ribbed reception counter with gold underglow,
  wood slat panels with thin LED lines, dark walls with warm amber light, backlit floating
  shelves, "Our Culture" black wall with gold icons, live-edge shelf on black wall, LED decor
  guide (warm ~2700K). Pinterest pages are blocked in the sandbox; Windsor.ai (pinterest_organic)
  lists boards but not pins. **Dagi uploads screenshots.**
- **Current design (decision 2026-10-09, "option 2"): navy + gold + blue look** with the new
  ETEL group logo on a gold-framed panel on the back wall between navy slat panels with gold LED
  lines; curved navy counter, pale stone top, gold edge/underglow, SERVICE DESK sign with the
  six company medallions (3 left, 3 right, following the curve); dispatcher desk under the
  window; amber-backlit parcel shelves; blue sofa + table + plant; dark glossy floor; gold LED
  cove. Layout coordinates (x west->east 0..4, z north->south 0..5): window on west wall
  z 0.35-1.15; door on east wall z 0.3-1.2; entrance door south wall x 2.9-3.8; counter
  x 0.5-3.5, back edge z 1.7; shelves west wall z 3.0-4.6; sofa east wall z 2.9-4.5.
- **Latest files (2026-10-09):** `ETEL_Office_3D_navy.zip` (15 views, navy look),
  `ETEL_Office_Navy_Video.mp4` (40 s). Earlier black/gold sets (`ETEL_Office_3D_modern_reception.zip`,
  `ETEL_Office_3D_all_sides_LED_v2.zip`, Higgsfield sets, `ETEL_Office_Brand_Video_v5.mp4`,
  `ETEL_Office_3D_Video.mp4`) are the OLD palette - superseded. Higgsfield photoreal images are
  AI images in the old black/gold style and were not regenerated.
- Built with `office3d_v4.py` (three.js scene, 15 camera views, PNGs via Playwright). The
  script lived in /tmp and is gone after the session; rebuild from the recipes in references/.

## 5. Brand assets (use these exact files, never redraw logos)
- **NEW group logo (supplied by Dagi 2026-10-09):** blue Ethiopic "ኢ" + gold "E" with a rising
  arrow, wordmark "ETEL | ኢትኤል"; also a symbol-only version and a navy-background version with
  the tagline **"Trust · Heritage · Modern Execution"**. The originals have a WHITE background:
  make them transparent by alpha = clamp((1-min(r,g,b))/0.24) and un-premultiply against white
  (plain `-transparent white` leaves a halo on dark backgrounds).
- **Decision 2026-10-09: whole look switched to the logo's colours** - deep navy
  (#061230 / #0a1d4d / #12337a), gold (#f6c22b, #f8c01a, #d99a06), blue (#1368e8, #1f7bff),
  white/ice text (#c9d8ff, #9fb3e6). Gold LED lines stay. Fonts: Outfit (Latin), Noto Sans
  Ethiopic (Amharic); fetch TTFs from fonts.googleapis.com via curl and install to ~/.fonts.
- Old master medallion (Etel Family Technology and Business Group, Hawassa 2026; `Master logo.jpeg`)
  is no longer the main mark in new material; the old black/gold/emerald palette is superseded.
- **Six company medallions stay as they are (green + gold)** and sit in a row: Ride (green
  "ETEL RIDE", traveller + pin; Dagi supplied only a 200x200 image - ask for a larger original),
  Driver (`logo-driver.webp`), Courier (`courier.png`), Delivery (`Delivery.png`), Service (repo
  `delivery/icons/service-icon-512.png` - **not yet confirmed by Dagi**), Pay (`logo.png`,
  blue/gold EtelPay). Admin (`Admin.png`) is internal - keep off customer-facing material.
  Dagi may supply blue versions to match the new look - ask before recolouring.
- The gold "car coin" `etel-ride-coin.png` is NOT the rider logo. Use the green one.

## 6. Coming Soon pack (done, navy version is current)
`ETEL_ComingSoon_Navy_Pack.zip`: poster 1080x1350, banner 1500x500, social 1200x628, video
1080x1920 (12.5 s, no sound), navy/gold/blue with the new logo and the tagline line. The earlier
black/gold pack (`ETEL_ComingSoon_Pack.zip`) is superseded. Amharic line "በቅርብ ቀን ይጠብቁን" is MY
draft - verify. No launch date, no phone/link/QR yet (Dagi to supply). CTA reads "Drivers: sign
up early". Built as HTML + Playwright screenshots (video = per-frame screenshots + ffmpeg).

## 7. Higgsfield facts
- Free plan, ~4.85-5.35 credits left. Premium models (`gpt_image_2_5`, `recraft_v4_1`,
  Kling, Grok video, Seedance cost 35) are blocked or unaffordable -> no AI video made.
- Cheapest useful: `gpt_image_2` quality `low` = 0.5 credit/image; with a prior generation
  passed as a reference (`medias[].value` = job id, role `image`) rooms stay consistent.
  Free plan allows **one concurrent job** - submit sequentially.
- The sandbox proxy BLOCKS `upload.higgsfield.ai` and the Higgsfield CloudFront image host,
  so logos cannot be uploaded as references and results cannot be downloaded by me. Dagi
  downloads images and uploads them to the chat; then I overlay real logos with ImageMagick.
- Higgsfield MCP tools are deferred and sometimes disconnect - use ToolSearch to reload.
- No Canva connector exists. Logo placement was done with a homography from the SERVICE DESK
  sign quad (pure-python solver + ImageMagick `-distort Perspective`).

## 8. Security / safety notes
- Dagi's CRON_DISPATCH_KEY was visible in earlier screenshots - advised rotation; never repeat
  or store it. Confirm the cron job for `cron_dispatch_scheduled.php` exists on cPanel.
- Never overwrite EtelGroup root files; never use destructive git; no secrets in commits.

## 9. Open items (checklist for the next chat)
1. Dagi to upload zips of merged PRs not yet deployed; confirm cron for scheduled rides.
2. Marketing plan inputs (section 3).
3. Office facts still missing: ceiling height, whether the floor can be covered, real
   measured wall lengths / window and door sizes. Dagi to upload Pinterest screenshots.
4. Confirm Service logo; supply larger Ride logo; native Amharic review; possibly blue company
   medallions.
5. Optional: real-phone screenshots for map screens; Higgsfield paid plan for AI video;
   regenerate Higgsfield office renders in the navy look; background music / 16:9 / Amharic
   video versions.

## 10. Work by other sessions already on main (seen 2026-10-05, not done in this chat)
- Chapa online payments were added in separate sessions: EtelGebeya checkout (#417), EtelPay
  wallet top-ups (#418, `CHAPA_SECRET_KEY` in `Etel.Pay/wallet/api/.env`), EtelTaxi dues +
  Delivery & Ride (#419: `api/chapa_bridge.php`, `controllers/ChapaController.php` with
  `chapa_init`/`chapa_status`, `chapa-callback.php`; EtelTaxi needs migration `006_chapa.sql`),
  and "Pay with Chapa" buttons in the ride and customer apps (#420, `js/chapa-pay.js`).
  Money counts as received only after Chapa's verify API confirms status, ETB and amount.
  Until `CHAPA_SECRET_KEY` is set the API answers "not configured". Check main (`git log`)
  for newer changes before assuming this file is complete.

See `references/changelog-and-pipeline.md` for the file/script recipes.
