---
name: 'etel-delivery-ride-memory'
description: 'Running project memory for the ETEL Delivery & Ride system (rider PWA delivery/ride, driver PWA delivery/driver, courier, customer, service-giver, admin, PHP API delivery/api) and for the ETEL marketing/office/brand-media work done around it (logos, Coming Soon poster/banner/video, office interior design and 3D renders, Higgsfield usage). Use at the start of ANY new chat about Delivery & Ride, ETEL Ride, ETEL Driver, trip PIN, receipts, Bluetooth receipt printer, refresh button, hot areas, scheduled rides, admin 2FA, system health, cron, the Hawassa launch, marketing plan, user/driver guides, office design, 3D views, Higgsfield, Coming Soon posters or videos, or when the user says continue / what did we do / remember. Read together with etel-group-ops (conventions) and etel-audit-fix-workflow (deploy-and-verify loop).'
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

## 4. Office interior (4 x 5 m one-room office, Hawassa)
- Layout assumed: room 4 m x 5 m, door on south wall (right side), window on north wall,
  ceiling 2.8 m. Brand wall west (master logo + six company logos + QR + "Become a driver /
  sign up early"), service desk with SERVICE DESK sign, dispatcher desk with two monitors,
  support desk, parcel shelf NE, TV watch screen, waiting seats, gold LED strips (ceiling
  cove, wall frame, desk base, shelves, skirting). Palette: carbon black + gold + emerald.
- STILL NEEDED: real door/window positions and ceiling height.
- Deliverables: `ETEL_Office_Design_4x5m.pdf`; 3D views (three.js r149 via npm tarball,
  SwiftShader) - latest `ETEL_Office_3D_all_sides_LED_v2.zip` (15 views, no 5% fee, six logos);
  Higgsfield photoreal set (7 views) + versions with real logos overlaid
  (`ETEL_Office_logos_on_desk_v3.zip`, logos in true perspective beside the SERVICE DESK
  sign, 3 left / 3 right); videos `ETEL_Office_Brand_Video_v5.mp4` (Higgsfield views) and
  `ETEL_Office_3D_Video.mp4` (3D views). Older zips still contain the fee - superseded.

## 5. Brand assets (use these exact files, never redraw logos)
- Master logo (Etel Family Technology and Business Group, Hawassa 2026 medallion) -
  `Master logo.jpeg`. Company medallions: Ride (green "ETEL RIDE", traveller + pin - supplied
  by Dagi as a 200x200 image; ask for a larger original), Driver (`logo-driver.webp`),
  Courier (`courier.png`), Delivery (`Delivery.png`), Service (repo `delivery/icons/
  service-icon-512.png` - **not yet confirmed by Dagi**), Pay (`logo.png`, blue/gold
  EtelPay), Admin (`Admin.png`, internal only - keep off customer-facing material).
- Brand colours: gold #f4d56a / #c89a24, carbon #0b0a07 / #14110a, emerald green, LED amber
  #ffb83d. Fonts: Outfit (Latin), Noto Sans Ethiopic (Amharic) - fetch TTFs from
  fonts.googleapis.com via curl and install to ~/.fonts.
- The earlier gold "car coin" `etel-ride-coin.png` is NOT the rider logo. Use the green one.

## 6. Coming Soon pack (done)
`ETEL_ComingSoon_Pack.zip`: poster 1080x1350, banner 1500x500, social 1200x628, video
1080x1920 (12.5 s, no sound). Amharic line "በቅርብ ቀን ይጠብቁን" is MY draft - verify. No launch
date, no phone/link/QR yet (Dagi to supply). CTA reads "Drivers: sign up early".
Built as HTML + Playwright screenshots (video = per-frame screenshots + ffmpeg).

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
2. Marketing plan inputs (section 3). 3. Office door/window/ceiling facts.
4. Confirm Service logo; supply larger Ride logo; native Amharic review.
5. Optional: real-phone screenshots for map screens; Higgsfield paid plan for AI video;
   logos along the desk in the 3D renders; background music / 16:9 / Amharic video versions.
6. Offer to commit this memory into the repo `.claude/skills/` if wanted.

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
