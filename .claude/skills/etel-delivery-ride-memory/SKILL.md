---
name: 'etel-delivery-ride-memory'
description: 'Running project memory for the ETEL Delivery & Ride system (rider PWA delivery/ride, driver PWA delivery/driver, courier, customer, service-giver, admin, PHP API delivery/api) and for the ETEL marketing/office/brand-media work done around it (logos, Coming Soon poster/banner/video, the navy/gold/blue ETEL brand look and new group logo, office interior design and 3D renders, Pinterest/Windsor notes, Higgsfield usage). Use at the start of ANY new chat about Delivery & Ride, ETEL Ride, ETEL Driver, trip PIN, receipts, Bluetooth receipt printer, refresh button, hot areas, scheduled rides, admin 2FA, system health, cron, Etel Shield, idle sign-out, stream tickets, Ethiopia-only access, cron keys, System health card, courier live map and navigation, the Hawassa launch, marketing plan, user/driver guides, office design, 3D views, Higgsfield, Coming Soon posters or videos, or when the user says continue / what did we do / remember. Read together with etel-group-ops (conventions) and etel-audit-fix-workflow (deploy-and-verify loop).'
---

# ETEL Delivery & Ride - project memory (as of 2026-11-27; sections 0-10 are from Oct 2026, section 11 is the Nov 2026 launch stretch, section 12 is courier/customer polish plus security and cron work, PRs #899-#938, section 13 is #939-#963: courier choice, light default, premium Send a package, photo proof, address book, Express first)

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

## 6b. Later campaign packs (all navy/gold, built as HTML + Playwright + ffmpeg, no sound)
- Teaser: "Who is ኢE? Who is ETEL? Who is ኢትኤል?" with "?" for the six companies (poster, banner,
  social, 14 s video). `ETEL_Teaser_Pack.zip`.
- Driver recruitment: budget advice + 3-page plan PDF, A5 flyer (PNG/PDF), 30 s vertical video.
- Rider: A5 flyer + 24 s video. `ETEL_Rider_Pack.zip`.
- 4-week content calendar (28 days, EN captions, AM draft; PDF + CSV).
- Countdown set 1080x1350: 7 days, 3 days, Tomorrow, Driver Meetup invite (date/time/place/phone
  left blank). `ETEL_Countdown_Meetup_Posters.zip`.
- Launch-day video 11 s 1080x1920 "ETEL IS HERE / ኢትኤል መጥቷል!" + six medallions + blank
  QR/Telegram/phone box. `ETEL_Launch_Day_Video_1080x1920.mp4`.
- Decision: company medallions stay as supplied (green/gold); blue hue-shift test was
  inconsistent, so blue needs official artwork. Gold = branding, green = nature accent.
- Every Amharic line is my draft and needs native review. QR / Telegram / phone / dates are blank.

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
- Dagi's CRON_DISPATCH_KEY was visible in earlier screenshots, again on 25 Nov in cPanel screenshots - advised rotation (long random value in `api/.env` AND in the cron commands); never repeat
  or store it. The three cron jobs now exist on cPanel (see section 12).
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

## 11. Launch stretch, Nov 2026 (PRs #821-#898; Dagi marks ready and merges, Claude sends a zip after each merge)
- **Phase plan done:** proof before opening (#821), owner-controlled ETEL app (apps visible, editor, labels, banner, maintenance mode, minimum version, notifications, Google Play readiness, delete-account queue: #826-#843), Live operations and dispatch speed (#839, #845), SOS by push (#840), Driver trust: document reminders, expired list, fake-GPS checks (#841-#842), weekly statements for driver and courier (#849-#850), signed-in devices (#851), low-data mode (#852), crash reports to Admin (#853), Ethiopian calendar in the phone app (#854), golden light only while online (#848, #860).
- **Paying with Etel Pay (#844, #855-#857, #859, #864-#866):** the ride wallet gate follows the Admin EtelPay switch and per-member overrides (`rides_wallet_enabled($pdo, $userId)`). A rider pays with the wallet PIN only; the EtelID password is asked once per phone and the phone is remembered (`ep_login_device`, `etelpay-session.js`). The Profile "Payment security" switch chooses PIN, password or 6-digit code (`ep_pay_auth_mode`, `ep_pay_prefs`). Etel Pay files live in `Etel.Pay/wallet/api/` (`ep_r5.php` is the newest route part).
- **Trip fare views (#868):** the driver sees the fare details during a trip; the rider sees only km and total until the trip ends.
- **Shops (#869-#872):** a shop owner who is also admin can use ETEL Pro for their own shop; shop owners set the shop position (Settings, Shop location; shops without a pin are hidden from the city filter); extras have a price and a count (`item_extras`, `extras_qty`); a real cause of "Server fatal error" is kept in `client_errors` (`lib/server_errors.php`) and readable in Admin. The "Place Order" fatal error was a MISSING server file (`lib/own_shop.php`): partial uploads again.
- **ETEL app pages (#873-#878, #880-#882):** the ETEL app WebView must set `scalesPageToFit` true or Android ignores the viewport tag and draws everything too big. Profile has a Page size choice (default "Like the TWA"). The city chip sits inside the Courier top bar and its colours are forced (`js/etel-area.js`, v=7 and up) because Courier paints text with `.header *`, `body.etel-light ...` and `-webkit-text-fill-color: transparent`.
- **Vehicles (#883-#892): one list of real vehicle types** in `delivery/api/lib/vehicle_catalog.php`: courier vehicle names (motorcycle, bicycle, on_foot, car, ev_car, minibus, bajaj, qute) map to package vehicle classes (bike, moto, car, van and any type staff add in Admin, Package prices). Bajaj and Qute are one added type "Bajaj / Qute" created once by `etel_vc_seed_three_wheelers` (flag `seeded_threewheel` in `package_settings`).
  - Admin, Couriers: premium drawer (`js/admin-courier-drawer.js`): readiness ring and checklist, performance, 14-day chart, vehicle card, grouped controls, vehicle chosen from REAL PICTURES (`EtelVehiclePic`), clear save messages. `admin-couriers-pro.js` loads the drawer itself if the page did not.
  - Admin, Package prices (`js/admin-packages.js`): four tabs (Overview price table with courier supply, Vehicles, Distance and weight, Rules), a warning for courier vehicles that match no package vehicle with a one-click add.
  - Customer cart: the delivery picker lists the real vehicles with couriers online (`get_delivery_tiers` also returns `vehicles`). The pick is saved as `orders.vehicle_class`; the fee is the zone fee times the vehicle's delivery tier (bike like moto, van like express_cargo, added types by size); dispatch, offers, the job list and accept only use couriers of that vehicle (`etel_vehicle_sql`).
  - **Driver extra job (#887-#889):** a verified ride driver taps "Ask for the courier job" in the Driver profile (`js/driver-extra-job.js`, actions `driver_courier_status` and `driver_courier_apply`, `lib/driver_courier.php`). It creates a pending courier row; an admin approves in Couriers (existing approve button); approval turns the account into a courier account that keeps its ride profile. Admins see the card with an Open Courier button.
  - **Live bug found by the new save message (#892):** on the live install `couriers.vehicle_type` was an ENUM, so qute saved as an EMPTY value while Admin said "saved". `lib/courier_schema.php` widens it to VARCHAR(30) (migration 118 is optional). Lesson: after saving, read it back.
- **Admin workspaces (#894):** the Admin menu has a switch Delivery / Ride / Company (`js/admin-workspaces.js`, panel `#panel-wshome`, `AdminWS.show/apply`). Each workspace has a Home of tiles grouped in sections (`WS[].sections`) and a DESC line per page; a page can sit in two workspaces. New admin pages must be added to `admin-nav.js` (group + EXTRA_CAPS) AND to a workspace section, or they are hidden from the Home. Admin has a Ride-only staff mode (`ETEL_RIDE_ONLY`).
- **Courier no-vehicle bar (#895):** `js/admin-courier-vehicles.js` lists couriers saved with no vehicle and lets admin choose one (read-back check, bulk apply).
- **Driver Pull tab and Special offers (#896, #897):** drivers farther than the set distance (default 2 km, max 25) see rides the nearest drivers did not take, after a wait (default 20 s), in a Pull tab (`js/driver-pull.js`, action `driver_get_pull_requests`, `api/lib/ride_pull.php`). High fare (VIP, from `ride_vip_min_fare`) and trips from admin-drawn special areas (table `ride_special_areas`, kinds sweet or vip) show at once as offers with tags and the area note. Admin, Ride, Special offers (`js/admin-ride-offers.js`, `RideOffersAdminController.php`: `admin_ride_offers_get/save`, `admin_ride_area_save/delete`) edits the rules and areas. Taking a trip still uses `driver_accept_ride`.
- **Admin Ride Drivers page (#898):** `js/admin-ride-drivers.js`: live map (admin_get_live_drivers, 20 s), counts and filters (online, waiting, ID mismatch, suspended), search, suspend with reason, let drive again, Add driver (`EtelDriverRegister.open()`).
- **Lessons from this stretch:** (1) A fresh CI database does not have lazily created columns such as `rides.rider_offer` (made by `rides_offer_price_ensure`), so a new query must call the ensure first, and must not swallow errors silently (log them). Test by dropping the column locally and deleting `api/.price_offer_ok`. (2) Ride ages: the app syncs the DB session zone only when `ETEL_DB_TZ_SYNC`; compare PHP-written `created_at` with PHP `time()`, not with `NOW()`. (3) Every new table must be in `tests/ci/check_reset_coverage.py` (KEEP for admin setup, otherwise the reset wipe list). (4) Fresh-DB drivers have a different default vehicle: smokes set `ride_drivers.vehicle_type` explicitly. (5) `core.js` redefines `loadGoogleMaps` after page load and calls the callback even when Google failed to load: always check `window.google.maps` in the callback. (6) Zips: tell Dagi to extract inside `public_html/delivery`; once a zip was extracted in the root and overwrote the Etel Group root files `admin.html` and `api/boot.php` (restored from `EtelGroup/`). Never put a root-level path in a delivery zip that also exists in `EtelGroup/`. (7) The Etel Group admin is the root `admin.html` (ethelmarket.com/admin.html); the Delivery admin is ethelmarket.com/delivery/admin.html.
- **Open from this stretch:** couriers saved earlier with an empty vehicle must be chosen again; nothing here was tested on a real phone except what Dagi screenshots; Amharic strings (extra job card, page size, others) are drafts; the owner items in `etel-working-method` section 8 still apply.

## 12. Courier/customer polish and the security + cron stretch (PRs #899-#938, 24-25 Nov 2026)
Same loop as before (one draft PR per change, Dagi merges, Claude sends a zip of only the changed runtime files + TEST.txt; extract INSIDE `public_html/delivery`). Zips were numbered `ETEL_merge<PR>.zip`.

**Admin and shops (#899-#908):** Ride prices / Delivery prices pages follow the workspace (#900); delivery fees by distance per vehicle, 60/40 courier split, free service charge (#901); order refusals show the real reason (#902); shops are open/closed by opening hours, not the online switch (#904, test flaked at xx:59 and was fixed in #922); early error report with call-stack lines (#905); courier earnings hide button, QR libraries from Etel (#906); customer tracking follows the shop's steps (#907); shop app order details, no delivery fee for the shop, customer details hidden (#908, #909).

**Courier and customer (#910-#919):**
- Courier sees pickup and dropoff km with Navigate buttons (#910: `js/courier-job-nav.js`, `api/lib/courier_job_geo.php`, `CourierJobGeoController.php`); full-screen navigation starts only after a Navigate tap (#912, `courier-nav.js` wantNav gate).
- Customer live order map (#911 `js/customer-live-map.js`) needs the courier's position, which the courier app now sends (#915 `js/courier-location-report.js`). "Who is coming": full courier card with photo, vehicle and plate (#914 `js/customer-courier-card.js`, `api/lib/courier_card.php`); real vehicle pin on the courier map (`js/courier-vehicle-marker.js`).
- ETEL-branded confirmation dialog replaces `window.confirm` in every app (#913 `js/etel-dialog.js`, shows no URL).
- Courier page scrolls, one-button map hide/show, footer (#918 `courier-map-toggle.js`, `courier-footer.js`, an inline `html` overflow fix); bottom buttons not hidden (#916); dark-mode colours (#919; the vehicle picture uses the exact inverse of the map's `invert(90%) hue-rotate(180deg)` filter).
- Admin "Diagnostic error" was the WRONG `admin.js` on the server (#917: red bar names the stale file, `js/admin-filecheck.js`, version bump).
- Courier app code is baked into versioned bundles (`js/releases/courier/vN`): fix it with a separate additive script or an inline `<style>`; `check_releases.py` guards source/bundle match.

**Security (#920-#934).** Dagi asked for: sign out when unused, Ethiopia-only access, "a Cloudflare, not a bot checker", protection of private data. Built as:
- **Idle sign-out (#920, loop fixed in #933):** `js/etel-idle-logout.js` in every signed-in app + `api/lib/idle_session.php`, `auth_sessions.last_active` (migration 119), action `session_ping`. Limits per role, edited in Admin > Etel Shield: admin 15, customer/courier/driver 30, service giver 240, agent 60, other 30 minutes (0 = never, min 5). Working couriers/drivers are exempt; an app that never reported activity is never expired. Emergency: `IDLE_TIMEOUT_OFF=1` in `api/.env`. #933: the saved last-use time (`etel_last_active`) belongs to one sign-in (`etel_last_active_tok`) and is cleared on sign-out and on the login pages (before, an old time threw people out again at once).
- **Etel Shield (#921):** `api/lib/shield.php` (+ `shield_store.php`, `shield_waf.php`, `shield_geo.php`, `shield_geo_data.php`); guard called in `boot.php` right after `$action`; settings in `pricing_rules.shield_config` (cached 60 s in the temp folder), counters in temp files (no DB per request), tables `shield_events`/`shield_bans` (migration 120); fails open; CGNAT-aware (signed-in people are never blocked by address; only a clear flood counts); modes off/monitor/block for flood limits, hacking-tool User-Agents, attack patterns (WAF stays on monitor), over-size bodies; `allow_ips`/`deny_ips`; `proxy_mode` none|cloudflare|any_xff (tests only). Emergency: `SHIELD_OFF=1` in `api/.env` (Dagi had it set once: the page shows a red note). Controller `ShieldController.php`, actions `admin_shield_*`.
- **Ethiopia-only (#923):** `geo_mode` (the connection, address list) and `geo_device_mode` (the position the app sends): off/monitor/enforce. It STARTS and STAYS in monitor until Dagi has read the log (only Googlebot and one unknown foreign PC so far). Payment servers are never turned away by country.
- **Admin page (#925, tile in Company > System #926, floating buttons hidden there #932):** `admin-shield.html`: Ethiopia-only, protection modes and limits, allowed addresses, idle minutes per role, blocks, address check, security log with filter.
- **Other entry files (#928):** `api/lib/shield_entry.php` `etel_shield_entry('upload'|'public'|'callback'|'sink')` at the top of upload.php, documents_*.php, private_file.php, trip_share.php, chapa-return/callback, telebirr-callback, telegram_webhook, csp-report, client-error. Callbacks: only the owner's block list and a high flood limit, never by country, never auto-blocked. Setting `entry_mode`.
- **File protection (#927):** `delivery/.htaccess` never serves archives, keys, old copies, hidden files and folders (`.well-known` stays open); CSP gets `base-uri` and `object-src`. Found a public 9 MB `icons/master-logo-icons.zip` (now blocked; Dagi may delete it).
- **Tokens out of web addresses (#930):** action `stream_ticket` (`api/lib/stream_ticket.php`): a sealed (AES-256-CBC + HMAC) ticket, 2 minutes (beacon 10), bound to one stream action and id; `js/etel-stream-ticket.js` (loaded FIRST in driver, ride, customer, admin) wraps `EventSource` and `sendBeacon`, so the baked bundles are untouched; falls back to the old address. Setting `url_token_mode` allow|monitor|refuse (log rule `token_in_url`). Admin investors CSV is fetched with the header.
- **Keys out of web addresses (#934):** `api/lib/cron_key.php`: header `X-Cron-Key` (cron files) or `X-Etel-Key` (diagnose, etel-os); `url_key_mode` allow|monitor|refuse (log rule `keyurl_<file>`); guide `docs/CRON_KEYS.md`. Left alone on purpose: the EtelID service-key fallback, the legacy `backfill_open_trip_fares.php` tool (delete it after use), the investor link token.
- **Two two-step sign-ins (#931):** EtelID's (the code box at the login page, table `etelid_2fa` in EtelID) and Delivery's "Admin two-step" (table `admin_2fa`). A sign-in through EtelID whose code was checked marks its session (`auth_sessions.mfa_ok`, migration 121) and counts as the admin's second step (asked once); a password-only sign-in does not. Rule `require_admin_2fa` is ON; Dagi's admin (user 1) has two-step on. Turning off the admin one locks every `admin_*` screen with 403 `2fa_setup_required`.
- **Live settings on 25 Nov:** Ethiopia-only = monitor; `url_token_mode` and `url_key_mode` = monitor (to set to Refuse once the log filters stay empty for a day); `entry_mode` = block.

**Cron and health (#924, #935-#938):**
- The Android preview APK builds automatically again when `Etel-Driver-App/**` changes on main (#924, `.eas/workflows/build-android-preview.yml`).
- Admin > Ride > Rides had NO "System health" and "Live trip watch" cards since 24 Oct: a comment inside their box made `el.firstChild` true (#935; use `children.length`).
- `cron_ride_monitor.php` stopped on 18 Nov. #936 made it visible (steps wrapped, heartbeat always written, the reason in `etel_heartbeat.ride_monitor_error`, shown on the Cron job row); that showed the cause (#937): `webpush.php` called boot-only `etel_env()` from cron, a fatal on any push, since the Phase 3 backup alert pushes every run while the backup is stale. Now `etel_wp_env()`.
- #938: the backup job wrote its heartbeat in the DB server's zone (3 h early, a false red hour before each run); only the heartbeat connection is synced.
- **Live cron (cPanel) as of 25 Nov: all by `wget` with the header, NOT `php` CLI (php CLI cron jobs did not run on this host).** `*/2` cron_dispatch_scheduled, `*/5` cron_ride_monitor, `30 2` cron_backup_db (first backup test ran fine). Shape: `sleep $((RANDOM \% 60)) && wget -q -O /dev/null --header="X-Cron-Key: KEY" "https://ethelmarket.com/delivery/api/<file>.php"`. The server clock is UTC = Hawassa minus 3 hours, so cron Hour fields are UTC. Not set up yet: cron_chapa_reconcile (when Chapa goes live), equb_cron, cron_field_agents.

**Lessons from this stretch:**
1. A test that runs a real script must not leave state for other tests: the backup smoke left a backup that broke `backup_watch_test` (use a temp `BACKUP_DIR`); tests that sign in many times clear `login_attempts`; the shield log keeps one row per minute per address+rule (clear the `l.*` throttle files in the temp folder).
2. `error_reporting(0)` + output to `/dev/null` + a heartbeat written only at the end = a crash with no trace. Wrap steps, record the reason, show it on System health.
3. Code that works in `boot.php` can fatal in a cron or standalone file: `etel_env()`, `db()`, `fail()` do not exist there.
4. Database clocks: PHP sets the session zone to Addis; a new connection (cron files, the mysql client, the read-only tools) uses UTC. Compare with `DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR)` or sync the session.
5. A false "no changes" can hide in DOM checks: a comment node is a child (`firstChild`), so use `children.length`.
6. Read the live heartbeats and logs with `delivery_query` (read only) before guessing: it solved the 2FA lock-out, the silent cron and the false health row.
7. Never repeat a secret that appears in a screenshot (cron key); advise rotation.
8. Check `git status` before `git add -A` after running tests: upload tests wrote four pictures into `delivery/uploads/` and #928 committed them (removed in #929).

**Open from this stretch:** read the Ethiopia-only log and decide on Enforce (diaspora users abroad?); move the two Watch settings to Refuse after a quiet day; rotate the cron key; Chapa/equb/field-agent cron jobs; the owner items in `etel-working-method` section 8; nothing here was tested on a real phone except what Dagi screenshots.

See `references/changelog-and-pipeline.md` for the file/script recipes.

## 13. Courier choice, light default and the premium Send a package (PRs #939-#955, 26-27 Nov 2026)
Same loop (one draft PR per change, `gh api ... -F draft=true`, subscribe with the number, Dagi marks ready and merges, Claude sends `etel-pr<N>.zip` of only the changed runtime files + TEST.txt, paths relative to `delivery/`). The stop hook says "1 unpushed commit, no remote branch" after every push: it reads a stale local tracking ref; `git ls-remote origin <branch>` shows the push is there. The first push after a merge sometimes fails once and works on retry.

**Courier and cart (#941-#946):**
- #941 Incentive planner (EtelGroup/investors `js/admin-incentives-fit.js`): "Fit my budget" box and an optional discount cap (own budget 100k-200k ETB, 2 months, 50 drivers, fare 200-350 ETB).
- #942 Shop orders are OFFERED to every eligible courier (`etel_package_broadcast`), NOT auto-assigned. Back on with `pricing_rules.courier_auto_assign = 1` (`etel_dispatch_auto_assign_on`). A smoke that needs auto-assign sets that rule itself.
- #943 Courier job cards show km and the real address with a plus code (`js/courier-job-nav.js`, `window.__etelJobNavPlus`). #946 the cart delivery address is "name, 2FQM+RHF, Hawassa, Ethiopia" (`js/etel-plus-code.js`: `EtelPlusCode.withCode/label/same/key`, maths copied from `ride-address-fix.js`; Google Places writes its own text into the input before `place_changed`, so `renderCartDrawer` must be given `input.value = _deliveryAddr` first; saved addresses are compared with `_addrSame`).
- #944 Bajaj and Qute are two vehicles with their own picture and price (`etel_vc_seed_three_wheelers`, `etel_vc_split_three_wheelers`, per-class `dfee_<class>_*` rules, `_vehPicName`). #945 Navigate stays inside the app (`window.drawRouteOnMap`, `__courierNavAsk`).

**Light is the default (#947-#950):** every system starts light when nobody chose a theme, on a light OR dark phone, and keeps a saved "dark" choice. Delivery apps (#947: `window.ETEL_THEME_DEFAULT='light'`), sign-in and plain pages (#948: `css/login-light.css`), EtelPay/Taxi/Gebeya/Ai/Madeya (#949), EtelID/Group/investors/equb (#950: `investor-light.css`, `equb-light.css`). One shared key `theme` on ethelmarket.com is used by Delivery, EtelID and Group; each system also has its own (`etelpay_theme`, `etaxi_theme`, `egebya_theme`, `etelai_theme`, `etel_ride_theme`, `etel_driver_theme`). Gotcha: `etel-design-v4.css` forces `color: inherit !important` on p/span/div/li/td/th/label and dark input rules, so a light card needs strong selectors (`body:not(.dark-theme) .x input.y`) and an explicit dark `color` on the card. Left dark: EtelAi main chat page (index.html), EtelERP (no switch). Tests: `ui_light_default*.js`, `ui_light_login_pages.js`.

**Send a package, premium (#951-#955)** (`js/customer-package.js` is the wizard; extra pieces are additive files loaded before it; actions used in markup must be listed in `js/customer-actions.js`, and a test must TAP the real button, not call the function):
- #951 Step 3 lists EVERY vehicle type (smallest first, real pictures via `EtelVehiclePic`; the ones that cannot take the package are greyed with the reason). The quote returns `unavailable` next to the unchanged `options`. Step 4 has six payments: Cash, Telebirr (phone, then `initiate_telebirr_payment`, tells the customer to dial *150#), Etel Pay wallet ("Coming soon" until `etelPayLive`), Chapa, Abyssinia and CBE (account from `get_bank_accounts`, a reference, then `confirm_bank_payment`; a bank without an account is greyed).
- #952 `js/customer-package-waybill.js`: the confirmation is a navy/gold waybill (tracking number, QR from `js/qrcode.min.js`, route, vehicle picture, 5-step timeline, Share / Copy number / Receipt PDF). #953 fixed its buttons (they were not on the actions list and did nothing when tapped).
- #953 Express: `api/lib/package_speed.php`; settings `express_on`, `express_pct` (30), `express_min` (20), `booking_on` (pause all package booking) in `package_settings`, edited in Admin > Packages > Rules (`js/admin-packages.js`, form `data-speed`); the order stores `orders.pkg_speed`; the courier note starts "EXPRESS delivery - go straight to the recipient."; price breakdown on the pay step.
- #954 Public tracking link: `api/lib/package_public.php`, `orders.track_token` (12 hex), action `public_track_package` (no sign-in, code AND token, same 404 for wrong code or token, 40 asks / 10 min), `track.html?c=CODE&t=TOKEN`. The public view has NO phone, address, PIN, price or position. Packages booked before #954 have no link. Report a problem on the waybill uses `submit_order_issue` (admin sees it in the issue list).
- #955 `api/lib/package_history.php`, action `customer_package_recent` (own packages only, last 40), `js/customer-package-history.js`: "Send again" strip on step 1 (3 different recipients) and "My packages" with All / On the way / Delivered / Cancelled filters; `window.pkgApply` fills the wizard (the prohibited-items box stays unticked, the price is fresh).
- File sizes: `api/lib/package_orders.php` is about 47 KB (over the 30 KB rule); new logic goes into new small files.

**Test recipe that worked:** Playwright with `serviceWorkers: 'block'`, a static server on the repo root, `boot.php` mocked (the mock must return `{items: []}` for `customer_get_items`); click the "Not Now" onboarding button first; prove a new test fails on the old file by swapping `git show HEAD:path` and putting the new file back. MariaDB is not running in a new container: `service mariadb start` before `FRESH_DB=1 MYSQL_ADMIN_ARGS="-uroot" ./tests/ci/run_integration.sh`.

**Later in the same stretch (#956-#963, 27 Nov 2026):**
- #957 Express strip on the courier job card (`api/lib/courier_job_geo.php` adds `express`, `js/courier-job-nav.js` draws the gold strip). #962 `etel_package_express_first` (in `api/lib/package_speed.php`) puts Express packages first in `courier_get_available_orders` and adds `express` to each job. The courier's PAY is unchanged: the Express extra goes into the normal fare; Dagi has not given a courier share rule yet.
- #958 Photo proof of delivery: `api/lib/package_proof.php`, actions `courier_package_proof` (courier, own order) and `customer_package_proof` (customer, own order), table `package_proofs` (photo kept as a small data URL in the database, NOT in a public folder; in the reset wipe list), `orders`' tracking answer has `has_proof` (never the photo itself, never in the public link). Package: only after the PIN delivery; 2 hours to add or change. Additive courier script `js/courier-package-proof.js` (finds the order by watching the `courier_package_deliver` fetch; adds the button on the green Delivered screen). #960 the same for SHOP orders: the button is on the Active-tab card while the order is picked up / in transit (and for 2 hours after delivery by API); customer side `js/customer-package-proof.js` shows "Photo from the courier" for any own order with `has_proof`.
- #959 **Stuck pay card:** `admin_update_order` (Admin Orders board) set an order to delivered but left the courier's `order_confirmations` row pending, so the customer kept "Your order has been delivered! Pay ..." and every tap said "not out for delivery". `api/lib/order_confirmations.php`: the request now follows the status; `customer_pending_confirmations` lists only picked_up / in_transit orders and sweeps stale rows. A cash order that staff set to Delivered stays unpaid (Admin > Cash > unpaid delivered).
- #961 Address book: `api/lib/package_contacts.php`, table `package_contacts` (own contacts, 30 at most, same phone + address updates), `js/customer-package-contacts.js` (Address book / Save to address book on both sides of step 1; Delete needs a second tap).
- #963 Share tracking link from the package tracking screen: `track_order` gives the SENDER `track_token` (an older package gets its token the first time its sender opens it; nobody else ever gets it); card in `js/customer-package-waybill.js` (`linkHtml`, `pkgShareLink`).
- Branch note: while a PR is open, a second PR is started on a NEW branch (`claude/admiring-fermi-8pcqlx-<name>`) from `origin/main`, or STACKED on the open PR's branch when they touch the same files (say so in the PR; Dagi merges in order). When an earlier PR merges first and the later one shows a conflict, merge `origin/main` into the later branch, keep both lines of lists such as `tests/ci/run_integration.sh`, re-run everything, push.

**Open after #963:** the courier's share of the Express extra (needs Dagi's rule); a named address book is done, a SHOP-order public tracking link is not (only packages have `track_token`); the courier app is still a baked bundle (new courier features go in additive scripts: `courier-job-nav.js`, `courier-package-proof.js`); Amharic texts of all new screens are drafts needing a native reader; EtelAi main page dark and EtelERP has no theme switch. Owner items unchanged (see section 12 and the working-method file).

## 14. The new logo for Delivery, Ride and Driver (27 Nov 2026, PR #969)
- All three apps use ONE logo (Dagi's choice): `icon-*`, `ride-icon-*`, `driver-icon-*` (every size, maskable, apple-touch), `splash-*` and `ride-splash-*`, and the transparent logo-box pictures `assets/images/etel-{ride,driver,delivery}-coin.{png,webp}` (pages ask for them with `?v=2`). `sw.js` CACHE_NAME is `etel-v299` or later. Source picture `icons/source/etel-transport-logo.jpg`; rebuild with `python3 tools/brand/make_transport_icons.py`. Guards: `delivery/tests/ci/check_transport_brand.py` (in CI) and `delivery/tests/ui_transport_brand.js` (browser, real pixels). Courier, Service, Admin and Agent keep their own icons. The login page shows the shared group logo, not a role coin. The APK files carry their own native icons (rebuild them). Full story: `etel-investors-memory` section 19.

## 15. Rider map looks like the driver map (28 Nov 2026)
- Dagi sent two phone screenshots (ETEL Ride map washed out, no place names; ETEL Driver map colourful with names, Map/Hybrid switch, + zoom) and asked for the rider map to match "in detail". Done in `delivery/js/ride-theme.js` (`applyMap`, `?v=3`, sw cache `etel-v303`), NOT in the bundles: light theme = Google's own colourful style (`styles: []`), dark theme = carbon style kept; both get the Map/Hybrid switch (top right), + - zoom (right centre), `clickableIcons:false` (a tap on a shop icon must still set the pickup point), `gestureHandling:'greedy'`. Test `delivery/tests/ui_ride_map_like_driver.js` (fake Google Maps, 18 checks, fails on old code).
- Not verified on a real phone or the real Google map (this container cannot load Google Maps). The driver map source (`driver.js`) says carbon style and no Map/Hybrid switch, yet Dagi's screenshot shows both: the driver map on the phone may come from a different release; check if asked. Ideas not built: traffic layer button, nearby landmarks chips, "Hot areas"-style chip for riders.

## 16. Own iOS start screens for Courier, Service, Admin, Agent (28 Nov 2026)
- Before: those four pages got Delivery's `splash-*.png` (transport logo) from core.js. Now `delivery/js/etel-start-screens.js?v=1` runs BEFORE core.js on `courier/courier-index.html`, `servicegiver.html`, `admin.html`, `agent/index.html`, picks the role from the page path and adds 13 `apple-touch-startup-image` links to `icons/<role>-splash-WxH.jpg` (core.js skips a size that already has a link, so no bundle or release change). Pictures: badge on the navy glow, `python3 tools/brand/make_system_icons.py startscreens` (52 JPEG, about 4.8 MB). Guards: `delivery/tests/ci/check_start_screens.py` (in ci.yml) and browser test `delivery/tests/ui_start_screens.js` (24 checks). sw cache `etel-v304`.
- iOS reads start screens only when the app is added to the home screen: testers must remove and add again. Not tested on a real iPhone. Next in Dagi's list of three: (2) per-app splash slots in Admin > Branding, (3) rider map traffic button and landmark chips.

## 17. Admin > Branding: one opening-splash slot per app family (28 Nov 2026)
- The old "Splash logo (all apps)" slot wrote `assets/images/master-logo-splash.png`, which no page loads any more (since #975). Now `api/admin-branding.php` has `splash_transport` (Ride, Driver, Customer), `splash_courier`, `splash_service`, `splash_admin` (each writes its own `assets/images/splash-<name>.webp`, 320 px WebP) and `splash` = all four at once. The screen (`js/admin-branding-pro.js?v=2`, old `admin-branding.js?v=1.1`) shows the six slots with a preview of the file each one replaces. A server without WebP in GD gets a clear error instead of a silent no-op.
- Test: `python3 delivery/tests/branding_splash_slots_smoke.py` runs the REAL slot table and image code of the PHP file in php (php with GD+WebP is in this container) and checks what each slot writes (37 checks, fails on the old file). Not in ci.yml (CI php may lack WebP). The endpoint was not run end to end with a real login and upload.
- This PR is on branch `claude/admiring-fermi-8pcqlx-splash-slots` because the start-screens PR #982 was still open on the usual branch.

## 18. Rider map Traffic button (28 Nov 2026)
- `delivery/js/ride-map-traffic.js?v=1` (loaded `defer` in `ride/index.html` after ride-quickzones-toggle): a 40 px white square Google map control in the RIGHT_CENTER stack under the + / - zoom buttons, shows Google's live traffic layer (`google.maps.TrafficLayer`), gold outline when on, remembered in `localStorage etel_ride_traffic`, Amharic/English title. Waits for `RIDE.map` (polls like ride-theme.js), does nothing without Google Maps. Tests: `delivery/tests/ui_ride_map_traffic.js` (fake Google Maps, 37 checks) and CI `check_ride_map_traffic.py`.
- "Landmark chips": NOT built, because the Quick zones box already is that (admin-fed places per city, `ride-city-zones.js`). Idea if asked: show the quick-zone places as small pins on the map. Google traffic data in Hawassa may be thin; not checked on a real phone. No sw cache bump in this PR (another PR touches that line); `ride/index.html` is not precached.

## 19. One-time fare backfill tool removed (28 Nov 2026)
- Dagi deleted `api/backfill_open_trip_fares.php` on the server; it is now removed from the repo too, with the admin button "Open Fare Backfill Tool" (Updater tab, "One-Time Admin Tools"), its line in `tests/audit/find_orphans.py`, and the stream-ticket guard now allows 1 token-in-address build in `admin.html` (was 2). Guard: `tests/ci/check_backfill_removed.py`.

## 20. Quick-zone places as pins on the rider map (28 Nov 2026)
- `delivery/js/ride-map-zone-pins.js?v=1` (defer, after ride-map-traffic.js in `ride/index.html`): the admin's quick-zone places for the rider's city (same `get_area_places` API and `EtelArea` city as `ride-city-zones.js`) as small gold round pins on the map. A tap opens a card with the place name and "Pickup here" / "Drop-off here" (call `setPickup` / `setDropoff` like the chips). A round map-pin button under the traffic button shows or hides them (`localStorage etel_ride_zone_pins`, default shown). Names go in with `textContent`. Redraws on the `etel-area-change` event. Tests: `delivery/tests/ui_ride_map_zone_pins.js` (fake Google Maps, 34 checks) and CI `check_ride_map_zone_pins.py`. Amharic button words (እዚህ ይውሰዱኝ, እዚህ ያውርዱኝ, ቦታዎችን ደብቅ/አሳይ) are drafts for a native reader. Not tested on a real phone or with the real Google map or the real places API.

## 21. Role marks on the login role buttons (28 Nov 2026)
- `delivery/login.html`: Customer, Ride and Driver share the transport logo, so each of those three buttons now has a small gold round mark at the corner of its logo (`.del-rtab-mark`, 17 px, white ring): Customer = shopping bag, Ride = person (passenger), Driver = steering wheel. Courier and Service keep their own logos and have no mark. Marks are inline SVG, `aria-hidden` (the button text still says the role). Tests: `delivery/tests/ui_login_role_marks.js` (real login page at 390 and 320 px, 26 checks) and CI `check_login_role_marks.py`. Not tested on a real phone.

## 22. Driver map: hexagon heat in gold + traffic button (28 Nov 2026)
- Dagi asked (28 Nov 2026) for traffic on the driver and courier maps and hot/surge areas as hexagons like Uber, "highest places bright golden, brightness by level and ratings". I read "level and ratings" as the demand heat (score = (requests + 2 x waiting) / (free drivers + 1)); if Dagi meant driver star ratings, ask.
- Server `api/controllers/RideController.php` `driver_get_demand` now also returns `hexes` (up to 40 busy cells with axial `q`,`r`, `heat` 0..1 = min(1, score/6), at least 0.85 when a rider waits and no driver is free), `size_m` (700), `surge_now` (the active peak-window multiplier from `rides_active_peak_multiplier`, or null). `areas` (top 8, the panel list) is unchanged, so old phones keep working.
- `delivery/js/driver-demand.js?v=3`: hexagon Polygons (same flat-top axial math and Hawassa origin as `api/lib/hexgrid.php`), gold from dim bronze (#9A7A1E) to bright (#FFE27A) by heat, more solid fill and thicker edge when busier, brightest on top, a fare multiplier pill (for example 1.3x) on up to 3 hexes with heat 0.6 or more when a peak window is active; panel gets a peak-time note, a Quiet-to-Busiest legend and a heat bar per area. An old server without `hexes` still gets circles.
- `delivery/js/etel-map-traffic.js?v=1` (`data-app="driver"` or `"courier"`): traffic button in the right-centre map control stack, remembered per app (`etel_traffic_<app>`). The driver page loads it; the courier page needs the courier map exposed as `window.__etelCourierMap` (courier.js keeps it in a closure; see the courier PR).
- Tests: `delivery/tests/ui_driver_hex_heat.js` (20 checks, fake Google Maps, hexagon geometry and brightness order), `delivery/tests/ui_map_traffic_driver_courier.js` (57 checks), CI `check_driver_hex_heat.py`. Not tested on a real phone, with real Google Maps, or with the real database (the PHP was only linted).


## 23. Courier hot areas as gold hexagons + traffic (28 Nov 2026; stacked on the driver PR)
- Server: `api courier_get_demand` (OrderController.php, routed in boot.php): open delivery jobs (no courier yet, not cancelled / delivered, placed in the last 3 hours, online-payment jobs only when paid; pickup = `orders.pickup_lat/lng` or the shop's `service_givers.lat/lng`) counted per 700 m hexagon cell, "waiting" = open 10 minutes or more, free couriers = `couriers.status='online'` seen in the last 5 minutes within 600 m. Same score, level and heat rules and the same reply shape as `driver_get_demand` (`areas` top 8, `hexes` up to 40 with q, r, heat, `size_m` 700, `surge_now` null: no multiplier pill for couriers). Counts only, never an order, customer, address or phone.
- Client: `delivery/js/courier-demand.js?v=1` (both courier pages, `defer`): a card on the map (Google control, top left) with On/Off, a Quiet-to-Busiest bar, the busiest area (jobs, km) and "Go there"; gold hexagons like the driver map; only while online (`COURIER_IS_ONLINE`), refresh 60 s, choice remembered (`etel_courier_hot_areas`). The courier map is the courier bundle's SCRIPT-LEVEL `let map` (not a window property): other classic scripts read it as a bare `map` (same trick as `courierLat`), so no courier release bundle was needed. `js/etel-map-traffic.js` (`data-app="courier"`) uses the same lookup. Amharic card words are drafts.
- Guards: CI `check_courier_hot_areas.py` (also keeps the hexagon origin, gold range and formulas equal to `driver-demand.js`), browser test `tests/ui_courier_hot_areas.js` (31), and two REAL-API smokes against MariaDB (added to `tests/ci/run_integration.sh`): `tests/courier_demand_smoke.py` (17) and `tests/ride_demand_heat_smoke.py` (11, the driver endpoint). Lesson from writing them: the app runs its DB session in Ethiopian time (`SET time_zone = '+03:00'` in boot.php), so smoke SQL that writes times must do the same or NOW() is 3 hours off; a ride driver in tests is a courier account plus a `ride_drivers` row; this container can run MariaDB (`mariadbd --datadir=/tmp/... --port=3306`, then the steps of `run_integration.sh`) and php -S for the real API.

## 24. Language switch: English, Amharic, Oromo, Tigrinya, Somali, Afar (28 Nov 2026)
- Dagi asked for ALL Ethiopian languages in the language switch of every system; chose (via questions) Oromo + Tigrinya + Somali + Afar, Delivery apps first, drafts written by Claude and marked BETA. Other systems (Pay, ID, Ai, ERP, Gebeya, Madeya, Taxi, master page) are NOT done yet.
- Shared file `delivery/js/etel-langs.js` (loaded `defer` by customer, login, servicegiver, ride, driver, courier x2): a picker (button + bottom sheet, `#etel-lang-btn`, native names, BETA / COMING SOON tags) that REPLACES the old EN/Amharic buttons (hidden with `data-etel-oldlang`: `#ride-lang-btn`, `#drv-lang-btn`, `#cl8-lang`, `.sg-lang`, `.del-lang-switch`, `[data-act=toggleCustomerLanguage]`) and sits in their place (floating top-right when a page has none). The choice is the shared key `etel_lang` = two-letter code en/am/om/ti/so/aa; picking saves it, posts `set_user_language` when signed in, and RELOADS the page (every old engine starts clean). English and Amharic stay with the apps' own engines (they treat any other code as English). The new languages are translated here: a DOM walker looks every visible English text up in `delivery/i18n/<code>.js` (English text -> translation; also placeholder / title / aria-label; emoji and trailing dots handled; MutationObserver for text drawn later; never typed values, textarea, `data-no-i18n`). Text with a changing number or name does not match yet (stays English).
- Translations: ONE table `tools/i18n/strings.tsv` (English, om, ti, so, aa; 1009 texts: first the short ones from the Ride / Driver / Courier Amharic dictionaries (417), then Customer, package booking, Service Giver and login (592, 28 Nov 2026: 766 of the 863 English texts registered with `ETEL_I18N` are covered, about 89 percent)) -> `python3 tools/i18n/build_langs.py` writes the four `delivery/i18n/*.js` (CI `check_languages.py` fails if they differ). `tools/i18n/README.md` tells a native reader how to fix a cell. NOT covered yet: long sentences, texts with numbers or names (like "About {km} km"), a few Service Giver settings strings, text built from parts. Claude's drafts need a native reader (Tigrinya, Oromo, Somali). **Afar is empty on purpose**: Claude could not write Afar with confidence, so `aa` is listed as COMING SOON (`soon: 1` in `LANGS`, cannot be picked); when a native translator fills the `aa` column, build and delete `soon: 1`.
- Server: `set_user_language` (boot.php) accepts the six codes (before: en, am). The `users.lang` column is optional on installs (501 when missing).
- Tests: `delivery/tests/ui_languages.js` (63, stub page + real login page in Oromo), `delivery/tests/language_choice_smoke.py` (16, real API, in `run_integration.sh`), CI `check_languages.py`. sw cache `etel-v305`. Not tested on a real phone; Ge'ez text in Tigrinya uses the page's fonts.

## 25. Language picker redesign + owner control + flags (28 Nov 2026)
- Dagi sent two phone screenshots: in the light theme the picker names were dark on dark (the ride page forces its own button and text colours), and asked for a premium, ETEL-branded picker with "their own flag" and "higher level controlling system".
- `delivery/js/etel-langs.js?v=2`: sheet now self-contained (every colour `!important`, buttons `all:unset`), navy and gold, grab bar, gold globe badge, one card per language with its flag, native name, English name, tag (BETA / COMING SOON; live has no tag) and a gold tick; focus ring, arrow keys, Escape, close button, reduced motion. The button is a navy pill with a gold ring, flag, code and caret, readable on light and dark pages. Flags are drawn inline (offline safe): UK, Ethiopia, Somalia are certain; Oromo (red/white/black + Odaa tree), Tigray (red + yellow star) and Afar (blue/white/green + red triangle) are best effort and contested: only their `FLAGS` entry changes if a community sends its flag. Dagi must look at them.
- Owner control: Admin > Branding > Languages (`delivery/js/admin-languages.js?v=1`, `admin.html` sets `ETEL_LANGS_NO_PICKER` and loads etel-langs only for the flags). Each language is Live / Beta / Coming soon / Hidden, plus the language new visitors start in; English is always live; shows how many people chose each. API: `get_language_config` (public), `admin_get_languages`, `admin_save_languages` (settings.manage); `api/controllers/LangController.php`, `api/lib/lang_config.php`. pricing_rules.rule_value is DECIMAL, so settings are numbers: `lang_state_<code>` 0 hidden, 1 soon, 2 beta, 3 live, and `lang_default` = position in en, am, om, ti, so, aa. The picker reads the config in the background (kept 6 hours in `etel_lang_cfg`); a visitor whose language is hidden goes back to English; a first visit starts in the default. Afar stays Coming soon until the `aa` column is filled, then set it to Beta in Admin.
- Contrast sweep (browser, 7 pages x light/dark, picker open): no other text below WCAG AA found; the only hit is the amber moon emoji button (an emoji, left). Only the signed-out shells were swept.
- Tests: `tests/ui_languages.js` (78), `tests/ui_admin_languages.js` (9), `tests/language_choice_smoke.py` (22), CI `check_languages.py`. sw cache etel-v306.

## 26. Login texts that stayed English (28 Nov 2026)
- Dagi's live screenshots of login.html in Somali, Tigrinya, Oromo and Amharic: the new picker works; but "One sign-in for every ETEL app..." and "Need a specific app? Choose app" stayed English, and Amharic also kept "Welcome back". Fix: login.html has the keys `welcome_back_auto`, `signin_auto_sub`, `need_app_q`, `choose_app` in its en and am tables (the two texts are wrapped in data-i18n elements); three more rows in `tools/i18n/strings.tsv` (om, ti, so drafts). etel-langs.js `?v=3` and dictionaries `?v=2` (cache bump), sw cache etel-v307. Other login texts may still be English: tell Claude what you see.

## 27. Branding page empty when opened from the Company / Delivery home (28 Nov 2026)
- Dagi opened Admin > Company > Branding and saw only the two logos at the bottom: the cards (and the new Languages section) were missing. Cause: the home cards (`js/admin-workspaces.js` `openPage`) called `switchPage()` only, but some menu items also start their own loader in their inline onclick (`loadBrandingPanel`, `ridePaymentsLoad`, `loadMenuCatalog`, `loadPromos`, `loadOrderChats`), so those pages stayed empty from the home cards (they worked from the sidebar). Fix: `openPage` clicks the real menu item for those five (`LOADERS`). admin-workspaces.js `?v=8`, sw etel-v308. Test `tests/ui_admin_branding_from_home.js` (real admin.html, mocked API; fails on the old code: 0 cards).
- If another page is ever empty from the home cards, check its nav item in admin.html for an extra call after switchPage and add its key to LOADERS.
