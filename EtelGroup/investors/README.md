# Investor Relations — Etel Delivery & Ride

A standalone investor-relations dashboard for Etel Delivery & Ride,
living entirely inside **EtelGroup's own codebase and database**. It
never adds, modifies, or deploys anything into the Delivery & Ride
codebase or database — the only connection to Delivery & Ride anywhere
in this module is a **read-only** MySQL SELECT against its `rides`
table, through a dedicated, minimally-privileged MySQL user you create
once (see below).

## What you need to upload

Everything under `EtelGroup/investors/` is new. Upload the whole
folder to your existing `EtelGroup/` directory on the server:

```
EtelGroup/investors/
├── admin.html                          ← admin dashboard + setup wizard
├── portal.html                         ← investor-facing login/portal
├── README.md                           ← this file
├── .htaccess                           ← blocks direct access to migrations/lib/controllers
├── css/
│   └── investors.css                   ← module-specific styles (reuses ../css/admin.css)
├── js/
│   ├── admin.js                        ← admin dashboard logic
│   └── portal.js                       ← investor portal logic
└── api/
    ├── boot.php                        ← API router (NOT named api.php, kept small - see note below)
    ├── lib/
    │   ├── Crypto.php                  ← encrypts/decrypts the read-only DB password at rest
    │   ├── Settings.php                ← ir_settings key/value store + all table self-heal
    │   ├── DeliveryReadonly.php        ← the ONLY code that talks to Delivery & Ride's DB
    │   ├── DistributionEngine.php      ← pure calculation engine (no I/O)
    │   └── InvestorAuth.php            ← investor login token scheme
    ├── controllers/
    │   ├── SetupController.php         ← setup_* actions
    │   ├── AdminController.php         ← admin_* actions
    │   └── PortalController.php        ← investor_* actions
    └── migrations/
        └── 001_investor_relations_schema.sql
```

Nothing here touches `EtelGroup/api/`, `EtelGroup/php/`, or any
existing EtelGroup table — this module only *reuses* two things from
EtelGroup's existing codebase (never modifies them): `eg_db()` from
`EtelGroup/php/config.php`, and `eg_require_admin()` /
`eg_current_actor()` from `EtelGroup/api/auth.php`. That's also why the
admin side of this module uses **the same EtelGroup admin login** you
already have — there's no second admin password to manage.

## 1. Run the migration

Run `EtelGroup/investors/api/migrations/001_investor_relations_schema.sql`
once against **EtelGroup's own database** (the same one
`EtelGroup/.env`'s `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` already point
to — via phpMyAdmin in cPanel, or any MySQL client). It only adds new
tables, every one prefixed `ir_` so nothing can collide with EtelGroup's
existing tables:

`ir_settings`, `ir_tranches`, `ir_cost_categories`,
`ir_cost_category_history`, `ir_investors`, `ir_share_purchases`,
`ir_daily_trip_cache`, `ir_distribution_periods`,
`ir_distribution_investor_lines`, `ir_activity_log`.

(Every table also self-heals via `CREATE TABLE IF NOT EXISTS` the first
time the API runs, same as EtelGroup's own `companies` table — running
the migration first is still the documented, reviewable way to do it.)

## 2. Add two lines to `EtelGroup/.env`

```
INV_CRYPTO_KEY=<a long random string>
INV_TOKEN_SECRET=<a different long random string>
```

Generate each with, e.g.:
```
php -r "echo bin2hex(random_bytes(32));"
```

- `INV_CRYPTO_KEY` encrypts the read-only Delivery & Ride DB password
  at rest in `ir_settings` — without it, that password is stored as
  useless ciphertext.
- `INV_TOKEN_SECRET` signs investor login tokens (separate from
  EtelGroup's own `ADMIN_TOKEN_SECRET`, so an investor session and an
  EtelGroup admin session are never interchangeable).

`.env` is already gitignored in this repo — never commit real values.

## 3. Create the read-only MySQL user (the one and only touch on Delivery & Ride)

In cPanel's MySQL Databases tool (or phpMyAdmin), create a **new MySQL
user** — do not reuse Delivery & Ride's own application user — and
grant it **SELECT only** on Delivery & Ride's database, scoped to just
the `rides` table:

```sql
CREATE USER 'ir_readonly'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT ON delivery_db_name.rides TO 'ir_readonly'@'localhost';
FLUSH PRIVILEGES;
```

Replace `delivery_db_name` with Delivery & Ride's actual database name,
and pick your own username/password. This user has **no**
INSERT/UPDATE/DELETE/DROP/ALTER privileges on anything, and no access
to any table beyond `rides` — even if this module were somehow
compromised, it could not write to or damage Delivery & Ride's data.

cPanel's "Add User to Database" UI may only offer whole-database
privilege presets — if so, run the `GRANT` statement above directly via
phpMyAdmin's SQL tab instead (target Delivery & Ride's database, not
EtelGroup's).

## 4. Open the Get Started wizard

Visit `EtelGroup/investors/admin.html`, sign in with your existing
EtelGroup admin/EtelID login, and the setup wizard opens automatically
since nothing is configured yet:

1. **Delivery Connection** — enter the read-only user's host, database
   name, username, and password from step 3. Use "Test Connection"
   before saving.
2. **Commission Rate** — defaults to `0.05` (5%), editable anytime
   later from the dashboard.
3. **Cost Categories** — starts pre-filled with Staff/Payroll, Office
   Rent, Payment Gateway Fees, Other/Contingency; add, rename, or
   remove any of these before saving — nothing about the count is
   hardcoded.
4. **Done** — optionally seed demo data to preview the full system
   before entering real investors.

## 5. Give investors their portal link

Investors sign in separately at `EtelGroup/investors/portal.html` with
the login identifier + password you set for them in the admin
dashboard's Investors panel — completely separate from your own admin
login and from Delivery & Ride's own user accounts.

## Demo mode

The "Demo Mode" toggle in the admin dashboard header seeds three sample
investors, their purchases, and one sample locked distribution period —
every row clearly tagged `DEMO` in the UI, and completely excluded from
real distribution math (a real distribution run only ever counts
non-demo investors and non-demo periods). Turning demo mode off cleanly
deletes every demo row.

## A note on the API router's filename and size

Per the hosting constraint you gave: this host's WAF has silently
deleted PHP files named exactly `api.php` or over ~30KB on sibling
systems. The router here is named `boot.php` (same convention as
`delivery/api/boot.php` and `EtelGroup/api/boot.php`), and every PHP
file in this module is well under 20KB — the largest,
`AdminController.php`, is about 17KB. Actions are grouped into three
controllers (`SetupController`, `AdminController`, `PortalController`)
specifically to keep each file small rather than one large monolith.

## Investor Deal Sheet (digital, English and Amharic)

`deal.html` is the one-page Investor Deal Sheet as a web form: the investor reads how the profit share works, picks how many Profit Shares to buy
(amount and share of the pool are worked out live), and signs by drawing a signature and typing their name. No login is needed.
Share `.../EtelGroup/investors/deal.html` (English) or `.../deal.html?lang=am` (Amharic).

- Standard terms come from `DistributionEngine.php` (2,000 Profit Shares in the pool, ETB 1,000 each, 20 % pool, 2.5x boost) and the units still open
  come from the unlocked tranches minus real purchases, so the form cannot oversell.
- Each signed sheet is stored in `ir_deal_sheets` (self-healing; also `api/migrations/002_deal_sheets.sql`) with a reference code (`EG-INV-26-12345`),
  the signature image, a SHA-256 fingerprint of the sheet, and a hashed connection id (limit: 5 sheets per hour per connection, plus a honeypot field).
- Admin dashboard > **Deal Sheets**: open a sheet, print or save it as a PDF, **Accept** or **Reject** it (a note is required to reject), then **Enroll as investor**.
  Enrolling creates the portal login (a one-time temporary password is shown to the admin) and, when "money received" is ticked, records the purchase.
- Blank printable forms are in `forms/` (`ETEL_Investor_Deal_Sheet_EN.pdf`, `ETEL_Investor_Deal_Sheet_AM.pdf`).
- The Amharic text is a first draft in `js/deal-sheet.js`: have a native speaker review it.
- A drawn signature on a screen is evidence, not a replacement for the signed paper Agreement: keep signing the full Agreement in ink.
- Manual smoke test: `tests/deal_sheet_smoke.py` (needs a throwaway MySQL database; see its header).


## Staged raise, investor pitch and demo mode (update)

**Three stages.** The 2,000-share pool is sold in stages, each with its own price per share and a trips-per-day target
(Admin > Dashboard > Staged raise). Defaults: Stage 1 = 400 shares at ETB 1,000 (open now); Stage 2 = 600 shares at ETB 1,500 (target 400 trips/day);
Stage 3 = 1,000 shares at ETB 2,000 (target 1,000 trips/day). Unlocking a stage is a manual decision with a written note, and Stage 3 needs Stage 2 first.
The deal sheet always charges the price of the first open stage that still has shares. A stage with real sales keeps its price and cannot shrink below what was sold.
Older installs are upgraded automatically (price and target columns added, Stage 2 moved to ETB 1,500, Stage 3 added).

**Growth planner scenarios.** The planner now takes "Other income / month" (delivery fees etc.) and a cost plan (lean 50 %, growing 75 %, full 100 % of the listed costs)
and shows the break-even trips per day.

**Demo / real switcher.** The existing Demo Mode switch now also drives the public pages: in demo mode the deal sheet and the pitch show a yellow
"DEMO" banner, the deal sheet can be filled in and signed but nothing is saved (reference `DEMO-PREVIEW`), and the pitch shows labelled sample traction.
Turning demo mode off removes all demo data and the banners.

**Investor pitch** (`pitch.html`, English and Amharic, light and dark, "Save as PDF" prints one slide per A4 landscape page).
Traction: demo = labelled sample; real = hidden until you tick "Show real traction numbers on the pitch" in the dashboard. The scenario slide uses assumed costs
(ETB 75,000 lean / 150,000 full a month) and an assumed average fare of ETB 120 with the commission rate from setup: review those numbers before showing it.
The Amharic text is a first draft and needs a native reviewer.

Tests: `tests/deal_sheet_smoke.py` now also covers the stages, demo preview, pitch data and planner scenarios.
