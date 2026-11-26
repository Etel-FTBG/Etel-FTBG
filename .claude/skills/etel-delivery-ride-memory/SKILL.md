---
name: 'etel-delivery-ride-memory'
description: 'Running project memory for the ETEL Delivery & Ride system (rider PWA delivery/ride, driver PWA delivery/driver, courier, customer, service-giver, admin, PHP API delivery/api) and for the ETEL marketing/office/brand-media work done around it (logos, Coming Soon poster/banner/video, the navy/gold/blue ETEL brand look and new group logo, office interior design and 3D renders, Pinterest/Windsor notes, Higgsfield usage). Use at the start of ANY new chat about Delivery & Ride, ETEL Ride, ETEL Driver, trip PIN, receipts, Bluetooth receipt printer, refresh button, hot areas, scheduled rides, admin 2FA, system health, cron, Etel Shield, idle sign-out, stream tickets, Ethiopia-only access, cron keys, System health card, courier live map and navigation, the Hawassa launch, marketing plan, user/driver guides, office design, 3D views, Higgsfield, Coming Soon posters or videos, or when the user says continue / what did we do / remember. Read together with etel-group-ops (conventions) and etel-audit-fix-workflow (deploy-and-verify loop).'
---

# ETEL Delivery & Ride - project memory (as of 2026-11-25; sections 0-10 are from Oct 2026, section 11 is the Nov 2026 launch stretch, section 12 is courier/customer polish plus security and cron work, PRs #899-#938)

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
