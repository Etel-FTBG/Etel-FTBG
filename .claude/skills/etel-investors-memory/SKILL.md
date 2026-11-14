---
name: etel-investors-memory
description: Project memory for the Etel investor-relations work (EtelGroup/investors) and the Delivery & Ride launch plan (commission holiday, bonuses, referral rewards), built Nov 2026, plus an overview of how all Etel systems are built (EtelID root, shared cPanel hosting, repo layout) and the Etel branding (gold/carbon tokens, navy-gold-blue group logo palette, fonts, rules). Use at the start of ANY chat about investors, the pitch deck or PowerPoint (English or Amharic), the deal sheet, profit shares, stages, the launch period or launch budget, the unsold-share rule, the agreement clause, commission plan, driver incentives, demo data, investor Q&A, the Amharic glossary, Etel branding or logos, how the Etel systems fit together, or when the user says continue / what did we build / remember. Read together with etel-group-ops (hosting and ASCII rules) and etel-delivery-ride-memory (Delivery & Ride background). Also covers the EtelPay and EtelID light/dark theme, desktop web app layout, line icons and admin console (PRs #776-#782): use it for any EtelPay look, theme, desktop or icon work.
---

# Etel investors, launch plan and EtelPay look: project memory (as of 2026-11-14)

Written from a long working session. It records what was built, what was decided, and what is still open, so a new chat does not have to ask again. Check `git log` on main for anything newer.

## 0. Who and how we work
- Owner: Dagi (use they/them). Etel Family Technology and Business Group, Hawassa. Trial phase, no real customer money yet.
- Delivery: ONE draft PR per change from branch `claude/admiring-fermi-8pcqlx` (create it from main, push, open a DRAFT PR, subscribe to PR activity). Dagi marks it ready and merges.
- After each merge: `git fetch origin main && git checkout -B claude/admiring-fermi-8pcqlx origin/main && git push -f -u origin claude/admiring-fermi-8pcqlx`, then zip ONLY the changed runtime files (keep folder paths like `EtelGroup/investors/...` and `delivery/...`) plus a short `TEST.txt` into the scratchpad as `ETEL_mergeNNN.zip`, send it with SendUserFile (display attach), and give a short note: which folders to upload, old files to delete, what to check. Never include `includes/config.php` or `api/.env`.
- Hosting is shared cPanel, no SSH: Dagi uploads zips by hand. PHP is written ASCII-only (the host WAF corrupted non-ASCII before) and each file stays under about 30KB.
- Dagi prefers short answers and finished files; says "yes" to proposals. Never put personal details (email) in outside requests or committed files.

## 1. What was built (merged PRs)
| PR | What |
|---|---|
| #763-#766 | Investor module (`EtelGroup/investors`): staged raise (3 stages), deal sheet (EN/AM, signature, print), pitch page, demo/real switcher, launch period + launch budget, order income, planner scenarios |
| #767 | Delivery commission plan: new drivers 0% for 60 days, 2% for 30 days, then 5% (pricing_rules `commission_plan`); rate stored per ride (`rides.commission_rate`, `commission_amt`) |
| #768 | Delivery launch incentives (`ride_incentives.php`): monthly budget cap, daily trip bonus tiers, driver referral reward, anti-farming rules, Void; auto-counted in the investor launch budget |
| #769 | Demo data also fills sample cost lines, an active launch period and ETB 92,500 of sample launch spending (`api/lib/DemoLaunch.php`); Clear demo removes them |
| #770 | One launch-end rule read everywhere (admin, pitch, deal sheet, portal, demo): ends at most 90 days after start, or earlier at a 4-week average of 150 trips a day |
| #771 | The unsold-share rule is explained on the deal sheet, the declaration and the pitch; draft agreement clause written |
| #772-#775 | English and Amharic PowerPoint, presenter guide, this memory skill |
| #776 | EtelPay light/dark theme + 3 text sizes (`css/pay-theme.css`, `js/pay-theme.js`) on every wallet page |
| #777 | EtelID NFC profile page and sign-in page: shared light/dark (`etelid-theme.css/js`) |
| #778-#779 | EtelPay admin desktop console (`admin-desktop.css/js`), desktop shell fixes (ETB ETB, balance overflow) |
| #780 | EtelPay rebuilt as a real desktop web app at 1024px+ (sidebar, dashboard grid, split sign-in): `pay-desktop.css/js` |
| #781 | Line icons instead of emoji (`pay-icons.js`), focus rings, reduced motion, contrast fix (`pay-polish.css`) |
| #782 | Admin big numbers fit (1T shown compact, full figure in tooltip), ETEL AI card readable in light theme, updater width |

Everything else (the presentations, presenter guide) is under `EtelGroup/investors/docs/` (see section 5).

## 2. Key decisions (do not re-litigate)
- Investors share ONLY Delivery & Ride for now; other Etel companies are separate and not part of the deal. Delivery & Ride first.
- Staged raise (profit-share rights, NOT company ownership): Stage 1 400 shares at ETB 1,000 = ETB 400,000 (open now); Stage 2 600 at ETB 1,500 = ETB 900,000 (opens at 400 trips a day); Stage 3 1,000 at ETB 2,000 = ETB 2,000,000 (opens at 1,000 trips a day). Pool = 2,000 shares = 20% of net profit. Keep the 400k Stage 1.
- Payout rule: pool = 20% of net profit (zero if no profit; investors never absorb losses). Each investor gets shares/2,000 of the pool, times 2.5 until they have received back what they paid, then times 1.
- UNSOLD-SHARE BONUS (kept on purpose): shares not yet sold would earn the boosted rate; half of that amount is shared among existing holders pro rata, half stays with the Company; it ends at 2,000 sold. With only Stage 1 sold, a 100-share holder earns ETB 7,500 per ETB 100,000 profit instead of 2,500. Draft clause: `EtelGroup/investors/docs/AGREEMENT_CLAUSE_UNSOLD_SHARES.md`. The calculator is unchanged (`DistributionEngine.php`).
- Launch plan: commission-free period for new drivers, driver bonuses, referral rewards, rider discounts. NO investor payouts during the launch (locking a distribution is refused while the launch is on). Growth spending is paid from the raise (the launch budget), NOT deducted from the profit that is shared.
- Launch budget split of the Stage 1 raise: Reserve 26%, Marketing 25%, Promotion 15%, Bonuses 12%, Referral 10%, Materials 12% (proposed, to be confirmed).
- Demo mode: demo data is clearly labelled; the deal form returns a DEMO preview and stores nothing. Dagi will remove the demo once real operation starts.
- Do not show the 5% commission in marketing or office material (investors may see it).

## 3. Money facts (Dagi's real costs; assumptions marked)
- Real: office rent ETB 6,000 a month; customer service about ETB 10,000 a month; ETB 20,000 a year (treated as a yearly fixed cost). "Today's costs" = 17,667 a month.
- Assumed (replace with real): average fare ETB 120 (Etel earns 5% = ETB 6 a trip); full team ETB 150,000 a month. Break-even about 99 trips a day on today's costs, about 834 with a full team. Illustration: 100 shares earn about ETB 3,000 a month at 1,500 trips a day with a full team, roughly 33 months to pay back before the unsold-share bonus. Always present these as illustrations, never a promise or a payback date.
- Demo cost lines also include assumed hosting/maps/SMS, gateway fees, licence and taxes, and 10% contingency.

## 4. Where things live
- Investor module: `EtelGroup/investors/` (prefix router in `api/boot.php`: `deal_`/`admin_deal_` to DealController; `admin_launch*`, `investor_launch` to LaunchController; `setup_` to SetupController; `admin_` to AdminController; `investor_` to PortalController). Settings in `ir_settings` via `ir_get_setting/ir_set_setting`. Tables: `ir_tranches`, `ir_launch_budget`, `ir_launch_spend`. Launch settings keys: `launch_active`, `launch_started_on`, `launch_max_days` (90), `launch_trips_goal` (150), `launch_until`, `launch_note`, `launch_auto_from`, `include_orders`, `order_commission_rate`, `demo_mode`.
- Key files: `api/lib/Launch.php` (rule, state, budget summary), `api/lib/DeliveryReadonly.php` (read-only Delivery data incl. incentive totals), `api/lib/DemoLaunch.php`, `js/deal-sheet.js` (EN/AM text), `js/pitch-r3.js` (pitch slides, EN/AM), `README.md`.
- Delivery: `delivery/api/lib/ride_commission.php` (plan, rate per driver and day), `ride_incentives.php`, `ride_fare.php` (hooks), `AdminPricingController.php` (commission and incentive plan actions), `js/admin-pricing-studio.js` (Commission and Incentives tabs), `docs/COMMISSION_PLAN.md`.
- Investor read-only database user needs SELECT on Delivery `orders` if order income is switched on.

## 5. Documents and decks (all under `EtelGroup/investors/docs/`)
- `ETEL_Investor_Presenter_Guide.pdf`: 5-page guide (story, payout math, honest numbers, talk track, Q&A). Written before the unsold-share decision text was final; the logic is still right.
- `AGREEMENT_CLAUSE_UNSOLD_SHARES.md`: draft clause for the lawyer.
- `presentation/`: English and Amharic PowerPoint decks (14 slides, speaker notes) and the build sources; see its README. Amharic deck needs the free Noto Sans Ethiopic font.

## 6. Testing
- Investor smoke tests (need a fresh DB each run): from `EtelGroup`, set `DB_HOST=localhost DB_NAME=etelgroup_test DB_USER=egt DB_PASS=egtpass ADMIN_TOKEN_SECRET=testsecret INV_TOKEN_SECRET=x INV_CRYPTO_KEY=x`, run `php -S 127.0.0.1:8781 -t .`, then `python3 investors/tests/launch_smoke.py` and `deal_sheet_smoke.py` (recreate `etelgroup_test` before each). Local MariaDB stops between turns: `mysqld_safe --user=root --bind-address=127.0.0.1`.
- Delivery: `delivery/tests/ci/run_integration.sh` (fresh migrated DB) and the lint scripts `tests/ci/check_*.py`; registration is rate-limited per IP so smokes use the seeded demo accounts. New tables must be added to the reset wipe list (`SystemResetController.php`).
- Not verifiable in the sandbox: real EtelID sign-in, live Delivery data, Telegram or email delivery, real traction numbers.

## 7. Open items (checklist)
1. Lawyer reviews the Profit-Share Investment Agreement, the unsold-share clause, and the launch clause ("no payouts during launch; at most 90 days or 150 trips a day").
2. Native Amharic review of new Amharic text (deal sheet and pitch additions, the Amharic PowerPoint).
3. Replace assumed numbers with real ones: average fare, full-team cost, traction; enter real costs in Admin > Costs and stages (rent, customer service, yearly 20,000/12, hosting/maps/SMS, gateway fees, licence/taxes, insurance, about 10% contingency).
4. Set the investor commission rate to the real rate while the 5% is off for new drivers; give the read-only DB user SELECT on `orders` if order income is used.
5. Regenerate the blank PDFs in `EtelGroup/investors/forms` (still show the old declaration and no unsold-share point); signed sheets already stored keep their old text.
6. Announce driver bonuses in writing when the incentive plan is switched on (the app does not notify); start with a small monthly budget.
7. Add contact details (phone) to slide 14 of both decks.
8. Madeya items still open from earlier sessions: Telegram tokens and chat ids, station region/lat/lng, tank sizes.
9. Confirm assumptions: ETB 20,000 is a yearly fixed cost; "material" means branding/uniforms/stickers/printed items; "reference" means referral rewards.

## 8. Feature details (exact rules and settings)
**Commission plan** (Delivery, `pricing_rules` key `commission_plan`, Admin > Pricing > Commission). Fields: `standard_rate` (default 0.05, capped at 0.30), `holiday_on`, `starts_on`, `enroll_until`, `phase1_days` 60 at `phase1_rate` 0.0, `phase2_days` 30 at `phase2_rate` 0.02. A driver's start day = account creation day, or the plan's start day if later. Drivers who start after `enroll_until` pay the standard rate. A holiday rate is never above the standard rate. The rate is stored on each completed ride (`rides.commission_rate`, `rides.commission_amt`); use `rides_commission_sql()` for sums (older rides fall back to 5%). Plan off = everyone pays the standard rate, as before.

**Incentive plan** (`pricing_rules` key `incentive_plan`, Admin > Pricing > Incentives). Defaults: everything OFF, `monthly_cap` 0 (nothing is paid until the plan is on AND the cap is above 0), `driver_month_max` 0 (no limit), `daily_on` 1, `daily_tiers` empty (up to 8 tiers, trips 1-50 to bonus up to ETB 5,000), `min_fare` 30, `min_km` 0.5, `max_same_rider` 2 (trips with the same rider per day that count), `referral_on` 0, `referral_trips` 10, `referral_reward` 100, `referral_max_per_month` 5. Referral rules: the referrer is the driver whose code is in `couriers.referred_by`; paid once when the referred driver completes the required qualifying trips, from at least 3 different riders, at most 3 trips per rider counted, the referrer's own rides excluded, a suspended referrer is not paid. Credits go to `ride_drivers.etel_money_balance`; each award is a row in `ride_incentive_awards` (unique per kind, driver, ref key) and is stored on the ride (`rides.incentive_bonus`, `rides.incentive_referral`). Void takes the credit back. Incentives never change a trip's own payout and never throw. The old `driver_goal_tiers.bonus_etb` column is NOT used.

**Launch period and budget** (investors Admin > Launch period). One rule for all screens: `launch_started_on`, `launch_max_days` (90), `launch_trips_goal` (150, 0 = none). The end date is start + max days (an entered end date may not be later); the goal check shows the 4-week average from Delivery and tells the admin to end the launch (not automatic, because ending it starts payouts). Locking a payout is refused while the launch is on. Launch budget lines: reserve, marketing, promotion, bonus, referral, material (default 26/25/15/12/10/12% of Stage 1). Promotion, bonus and referral spending is counted automatically from Delivery (`rides.promo_discount`, `incentive_bonus`, `incentive_referral`) from the "count from" date; hand-entered spending adds on top. An investor never sees a technical error.

**Deal sheet and pitch.** Deal sheet at `deal.html` (EN/AM, drawn or typed signature, printable one page, document fingerprint); pitch at `pitch.html` (12 web slides, EN/AM, light/dark, prints one slide per A4 landscape page). In demo mode the deal form returns a DEMO preview and stores nothing, and the pitch shows sample traction. Real traction shows only if the admin switches it on.

**Presentations.** 14-slide PowerPoint in English and Amharic, navy/gold/blue, Cambria + Calibri (Amharic: Noto Sans Ethiopic), native charts, speaker notes; built by `docs/presentation/build.js`.

## 9. Lessons and gotchas (do not rediscover)
- **DDL commits a transaction.** `CREATE TABLE` inside a transaction ends it silently. Call `ir_launch_ensure()` / `ir_demo_launch_seed()` BEFORE `beginTransaction()`.
- **Guard columns that may not exist.** `ride_drivers.suspended` is absent on a fresh database; the first referral code silently paid nothing. Use `SHOW COLUMNS` guards in Delivery code that reads optional columns.
- **Registration is rate-limited per IP** in Delivery CI, so smoke tests must log in with the seeded demo accounts (see `delivery/tests/ci/` for the demo admin, customer and courier logins) instead of registering.
- **Reproduce CI failures locally** with `FRESH_DB=1 delivery/tests/ci/run_integration.sh` before fixing. Lint scripts `tests/ci/check_*.py` encode rules (for example a driver statement must use the stored commission rate, not a literal 5%; new tables must be in the reset wipe list).
- **Investor smoke tests need a fresh database each run**; a second run on a dirty DB fails unrelated checks.
- **File size and ASCII.** Keep each PHP file under about 30KB (host WAF) and ASCII-only; new investor logic goes in `lib/Launch.php`, `LaunchController.php`, `DemoLaunch.php`, not into `AdminController.php`.
- **Cache versions.** Bump `?v=N` on changed `<script>` tags (service worker caches by URL).
- **Seeding the demo twice fails** (duplicate demo investors); Clear demo first.
- **The unsold-share bonus is real money.** The pitch table and "what you would get" figures do not include it; the footnote says so. Changing the calculator means changing the agreement.
- **Do not copy a slide file by hand** when editing a .pptx; use the pptx skill scripts. Charts in pptxgenjs are native; run the validator after building.
- **Sandbox limits:** PIL is broken (use Playwright or ImageMagick `montage`); LibreOffice previews substitute fonts, so Amharic widths are approximate (leave slack); real EtelID, live Delivery data and Telegram cannot be tested here.

## 10. Investor Q&A and talk track (short)
Pitch in about 20 minutes, one message per slide: problem, product (live demo), how Etel earns, 2.5x boost, unsold-share bonus, stages, break-even illustration, launch plan, use of funds, capped growth spending, protections, risks, next steps. Say "profit share", "illustration", "assumed"; never "guaranteed", "safe", "double your money", or a payback date.
- *When do I get my money back?* No promised date; depends on trips a day and costs; boost 2.5x until paid back.
- *No profit?* No payout, nothing owed back; you may receive less than you paid.
- *Do I own part of Etel?* No: a profit-share right in Delivery & Ride; the pool is 20% of net profit.
- *Why higher price later?* Risk falls as trips grow; early money pays least.
- *How do I know the numbers are true?* Read-only data link from the platform, a statement each period, your own portal login.
- *Where does my money go?* Mostly growth plus a six-month reserve; the portal shows each line against budget.
- *Why no profit during launch?* Volume first; the launch is capped (90 days or 150 trips a day) and written in the agreement.
- *What stops bonus cheating?* Minimum fare and distance, per-rider daily limit, monthly and per-driver caps, 3 different riders for referrals, admin Void.
- *Why 5%?* Low for drivers, helps recruiting; be ready to say what happens if the rate must rise.
- *Other Etel companies?* Not part of this deal.
Full version: `docs/ETEL_Investor_Presenter_Guide.pdf`.

## 11. Amharic glossary (draft, needs native review)
Profit Share / share = ድርሻ (የትርፍ ድርሻ); investor pool = የባለሀብቶች ድርሻ; net profit = የተጣራ ትርፍ; running costs = የሥራ ማስኬጃ ወጪ; boost = ጭማሪ (2.5×); payback = መመለስ; stage = ደረጃ; launch = ማስጀመር; launch period = የማስጀመሪያ ወቅት; bonus = ቦነስ; referral reward = የሪፈራል ሽልማት; reserve = ተጠባባቂ; marketing = ማስታወቂያ; promotion = ማስተዋወቂያ; materials = ቁሳቁስ; deal sheet = የስምምነት ሉህ; agreement = ስምምነት; unsold-share extra = ያልተሸጠ ድርሻ ተጨማሪ ክፍያ; riders = ተሳፋሪዎች; drivers = አሽከርካሪዎች; couriers = ተላላኪዎች; trips a day = በቀን ጉዞ. Keep numbers and "ETB" in Latin characters. Existing Amharic pitch wording (`js/pitch-r3.js`, `js/deal-sheet.js`) is the reference; new wording must match it.

## 12. The Etel systems: how they are built
- **EtelID is the identity root.** Every company links through it (SSO and the Service API, `ETELID_SERVICE_KEY`); sibling systems keep local user tables that only mirror EtelID identity. New access or role features go through EtelID first, never as local per-system logic. If a service key was ever rotated, downstream copies do not update themselves (compare character by character). Send the key as header `X-Etel-Service-Key` AND a `?key=` fallback (some hosts strip X- headers).
- **Hosting.** All on one shared cPanel account (live host ethelmarket.com), no SSH: Dagi uploads zips or files by hand, or uses each system's in-app Updater (EtelID plugin Updater, EtelPay `updater.html`, Delivery admin > Updater: prefer the Upload tab with an explicit target path). Never edit from a pristine copy of a file already modified earlier; confirm the current deployed state first.
- **Systems and repo folders.** `EtelId` (WordPress plugin `etel-id-v3`, table prefix `Etelid_`, PHP 5.6 compatible, ASCII-only PHP); `EtelGroup` (static site + PHP admin API, also `EtelGroup/investors`); `Etel.Pay` (standalone PHP/MySQL wallet, ASCII-only, PHP 5.6 compatible, Chapa and bank rails); `delivery` (Delivery & Ride PHP/MySQL, PHP 8.1, PWAs for rider, driver, customer, courier, service-giver and admin, dispatcher `api/boot.php`, `pricing_rules` key/value settings); `Etel-Driver-App` (native Android driver app, React Native/Expo, talks to `delivery/api/boot.php`); `EtelTaxi` (taxi dispatch, separate admin/officer/driver tables); `EtelGebeya` (B2C and B2B marketplace, PHP 8, Telebirr/CBE/Chapa, EN+AM); `EtelAi`; `EtelMadeya` (fuel-station gas queue system, Telegram); `EtelERP` (lean PHP ERP on the shared host, sign in with EtelID, 15% VAT invoices) and `EtelERP-Frappe` (ERPNext edition for a VPS only, never cPanel); `mcp-server` (read-only SELECT tools across the systems plus EtelID role checks and `system_health`); `design-system` (shared tokens); `docs` (INSA audit packages, business model canvas, launch playbook, deploy checklists).
- **How we build.** Plain PHP + MySQL (PDO) written PHP5-style and ASCII-only where the host requires it; phone-first web apps (PWAs with a service worker); bilingual English/Amharic; one dispatcher file plus controllers; settings in the database with safe defaults; additive scripts and `?v=` cache bumps instead of rebuilding bundles; every change tested (smoke tests, CI integration on a fresh migrated database, lint checks) and shipped as one draft PR plus an upload zip. Single-page admin dashboards do not reload JS between tabs: close and reopen the tab after an upload. Watch for duplicate file names at different paths. Security: audited per system (INSA packages in `docs`); admin 2FA is optional but enforceable; trip PIN, receipts and similar features are additive.

## 13. Etel branding
- **Two layers, both valid.** (1) The long-standing **gold/carbon** system for apps: tokens in `design-system/etel-tokens.css` (gold `#D4AF37`, light gold `#F5E6A3`, dim gold `#8B6914`, deep gold `#C8922A`, carbon `#1A0E00`; near-black grounds `#030205`/`#0d0d0d`; fonts Syne for headings, Cormorant Garamond for serif accents, JetBrains Mono for labels, Noto Sans Ethiopic for Amharic). Apps should look like siblings, not separate products. (2) The newer **navy/gold/blue** look from the new group logo (Oct 2026), used in marketing, the investor materials and the decks: navy `#061230` / `#0a1d4d` / `#12337a`, gold `#f6c22b` (also `#f8c01a`, `#d99a06`), blue `#1368e8` / `#1f7bff`, ice text `#c9d8ff` / `#9fb3e6`, white. Tagline: "Trust · Heritage · Modern Execution". Fonts for marketing: Outfit (Latin) + Noto Sans Ethiopic; in PowerPoint use the safe fonts Cambria (headings) and Calibri (body), Amharic Noto Sans Ethiopic.
- **Logos (use the real files, never redraw).** Group logo: blue Ethiopic "ኢ" + gold "E" with a rising arrow, wordmark "ETEL | ኢትኤል" (supplied with a white background: make it transparent with alpha = clamp((1-min(r,g,b))/0.24) and un-premultiply against white; plain "transparent white" leaves a halo). Etel Delivery & Ride logo: round navy and gold coin with a car (`EtelGroup/investors/img/logo-etel.webp`, also `docs/presentation/logo-etel.png`). Master seal: green and gold coin "Etel Family Technology and Business Group, Hawassa 2026" (`img/logo-master.webp`; EtelID keeps the master at `EtelId/.../etel-master-logo.webp`, see `EtelId/BRANDING.md`). Six company medallions (green and gold) stay as supplied: Ride (green "ETEL RIDE"), Driver, Courier, Delivery, Service (not confirmed), Pay (blue and gold). The Admin medallion is internal, keep it off customer-facing material. The gold car coin `etel-ride-coin.png` is NOT the rider logo.
- **Rules.** Show the master coin or the monogram once per screen. Keep English and Amharic wording on public surfaces. Do not show the 5% commission in marketing or office material (fine for investors). EtelPay is the shared wallet under every service; its login is the gold/carbon reference ("ETEL Driver" login is the aspirational premium look). Light backgrounds white, not cream. Every Amharic line written by Claude is a draft for native review. Dark title and closing slides, light content slides, one motif (circular rings echoing the logo coin and icons in circles) across a deck.
- **Marketing and brand work already done** (details in `etel-delivery-ride-memory`): Coming Soon and teaser packs, driver and rider flyers and videos, 4-week content calendar, countdown posters, launch-day video, office interior designs (navy look), all built as HTML + Playwright + ffmpeg with blanks left for dates, phone, QR and Telegram.

## 14. EtelPay and EtelID look (Nov 2026, PRs #776-#782)
- **Goal from Dagi:** EtelPay, the NFC opening page and every page should look advanced, premium, highly controlling, with Etel branding like the Delivery admin, with a light/dark toggle. Delivered in phases; Dagi checks on the live site with screenshots and we fix what shows.
- **Theme:** `html[data-theme="light|dark"]`, `html[data-textsize="md|lg|xl"]`. EtelPay keys `etelpay_theme` / `etelpay_textsize`; EtelID pages use the shared `theme` / `etel_textsize` (same as the EtelID portal, Delivery admin, Group admins). One 44px display button plus a popover. Load order on each page: pay-theme (early) -> pay-desktop -> pay-polish/pay-icons. `etel-design-v4.css` uses heavy `!important`, so overrides need `!important` or `style.setProperty(..., 'important')`.
- **Desktop (>=1024px):** `js/desktop-shell.js` builds `#ep-desktop-wrap > #ep-sidebar | #ep-desktop-main(#app) | #ep-desktop-info`. `pay-desktop.js` sets `html[data-page]` and `html.ep-desk`, swaps sidebar/action emoji for SVG, builds the split-screen sign-in. `admin.html` has its own console (`admin-desktop.*`), not pay-desktop. Phones keep the original layout.
- **Icons:** `pay-icons.js` swaps an emoji that stands alone or starts an element for a line icon, re-runs on DOM changes; skips `#ep-sidebar`. Not applied to admin/updater/login/analytics.
- **Big numbers:** `fitStats()` shrinks, then compacts (1.5B, 1T) with the full value in `title`; `fullText()` keeps the average-per-transaction maths on the real value.
- **Service worker:** `etelpay-vNN` cache bumped on every change (now v13) and new files added to PRECACHE; users may need Ctrl+F5 or close/reopen the tab. If a live page looks old, the file was probably not uploaded or the cache is stale.
- **EtelID:** `nfc-profile.php` (body.eid-nfc, default dark) and `auth-page.php` (default light) load `etelid-theme.css/js`. CSS vars `--at2/--at3` resolve at :root, so override inside the page scope. `auth-page.php` has pre-existing non-ASCII; our edits are ASCII.
- **Live data seen:** 110 transactions, volume about 1 trillion ETB, average 9.09B: test data. Offered a "test data" filter on the admin dashboard (not built).
- **Testing recipe:** `php -S 127.0.0.1:8790 -t <repo>` (restart if "chrome-error"), Playwright with `NODE_PATH=/opt/node22/lib/node_modules`, route mocks keyed on `ep_action` (POST to `api/v2_verify.php`), screenshots at 1366x768, 1024x700, 390x844 in both themes; a script audits text contrast and leftover emoji.
- **Lessons:** I wrongly claimed the EtelID portal had no light mode (it did). Phase 3 covered only admin, so Dagi's screenshots showed a "phone in a window"; always check every page at desktop size before claiming done. Dagi sends live screenshots after each merge: read them carefully (cut-off numbers, old files).
- **Open:** icons on admin/updater/login/analytics pages; "test data" filter; KYC, Disputes, Analytics, Rates, Settings admin panels not individually checked on live data.
