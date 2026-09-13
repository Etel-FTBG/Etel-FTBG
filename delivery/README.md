# ETEL Delivery v3.2.6 — Hawassa Standalone Platform
*Etel Group · ኢትኤል ቡድን · ethelmarket.com*

Multi-role delivery platform for the Ethiopian market (Hawassa): customer
ordering, courier dispatch, service-giver inventory, Telebirr + bank payments,
ride-hailing, PWA offline support, push notifications.

## Stack

- Pure HTML/CSS/JS frontend (no bundler)
- PHP 8 backend (arrow functions used — needs PHP 7.4+)
- MySQL 8 / MariaDB — PDO prepared statements throughout
- cPanel shared hosting target
- Three.js r128 (CDN) for 3D hero
- Zero npm, zero build step

## Layout

```
delivery/
├── *.html                       # PWA pages (index, login, customer, courier, ...)
├── css/                         # design system + per-role styles
├── js/                          # vanilla JS — core, customer, courier, driver, admin, ride
├── api/
│   ├── boot.php                 # main API entry (replaces legacy api/api.php)
│   ├── r1.php  r2.php  r3.php   # ride + OTP + payment routing chain
│   ├── routes_b.php / routes_c.php removed (dead code, June 2026 audit)
│   ├── migrate.php              # schema migration runner (CLI + web)
│   ├── migrations/              # versioned SQL — 001..005
│   ├── seeds/                   # one-time data scripts (NOT auto-applied)
│   ├── updater.php / updater-api.php / upload.php  # admin file tools
│   ├── controllers/             # canonical controllers (Auth/Admin/Order/Ride/ServiceGiver)
│   ├── *.php                    # supporting files (config, push, telegram, telebirr, ...)
│   ├── .htaccess                # API directory hardening
│   └── .env                     # DB + secret config (gitignored)
├── .htaccess                    # HTTPS redirect + headers + clean URLs
├── icons/                       # PWA icons
├── assets/images/               # logos, coin sprites
├── uploads/items/               # service-giver product images
├── tests/
│   ├── test_migrate.php         # migration runner smoke tests (no MySQL needed)
│   ├── audit/                   # one-off Python audit scripts
│   └── open_meter_smoke.*       # ride fare calculator tests
└── patches/                     # applied patch log
```

## Deploy (cPanel, fresh install)

1. Upload everything under `public_html/delivery/`
2. cPanel → MySQL → create DB `etel_delivery` + user with ALL PRIVILEGES
3. Edit `api/.env` (or `api/config.php`) with DB credentials + `TOKEN_SECRET`
4. Run migrations:
   ```bash
   php api/migrate.php --apply
   ```
   Or import `api/seeds/complete_migration.sql` directly via phpMyAdmin
5. Optional: load demo data
   ```bash
   mysql etel_delivery < api/seeds/admin_demo_accounts.sql
   mysql etel_delivery < api/seeds/sample_data.sql
   ```
6. Verify: `https://ethelmarket.com/delivery/api/migrate.php` returns JSON
   showing all 5 migrations applied

## Demo Credentials

| Role     | Phone        | Password   |
|----------|--------------|------------|
| Admin    | 0900000000   | admin1234  |
| Customer | +251911000001| etel1234   |
| Courier  | +251911000002| etel1234   |

## Database Migrations

The migration runner is the canonical way to evolve the schema:

```bash
php api/migrate.php              # status
php api/migrate.php --apply      # apply pending
php api/migrate.php --validate   # integrity check (CI-friendly)
php api/migrate.php --reset      # mark all unapplied (DANGEROUS)
```

Files live in `api/migrations/` as `NNN_name.sql`. See
`api/migrations/README.md` for the full convention and validation rules.

## Patch / Auto-update

1. Host the new `admin.html` (or any patched file) at a public URL
2. Edit `patch.json` — bump `version`, set `patch_url`
3. Admin dashboard shows update banner on next load
4. Admin clicks **Apply Patch** → server downloads + verifies + writes

## Tests

```bash
php tests/test_migrate.php        # 4 smoke tests, no MySQL needed
python tests/audit/*.py           # one-off audit scripts (run from project root)
```

The smoke tests cover discovery, file validation, SQL statement splitting,
and CLI loading. They fail fast if the migration runner is broken.

## Recent audit (June 2026)

Audit ran against `etel-audit-etel-delivery-hawassa-2026-06-25.json`.
Findings and resolutions:

| Audit claim                              | Reality                          | Resolution |
|------------------------------------------|----------------------------------|------------|
| 57 PHP files contain non-ASCII (WAF risk)| All 38 PHP files clean           | Sanitized `.htaccess` (309→0 decorative `─` bytes), trashed orphan `api/controller` |
| Missing HTTPS enforcement                | Already present (HSTS + 301)     | Cancelled — no change needed |
| SQL injection at AuthController.php:45   | Line 45 is `$vehicle = ...`, no SQL | Audited all controllers — no exploitable SQLi, codebase uses prepared statements |
| Schema managed via loose .sql files      | Real issue — 9 files, no runner | Built `migrate.php` runner + 5 versioned migrations + seeds/ folder |
| `_b.php` duplicates (maintenance debt)   | 6 dead duplicates in `api/`      | Trashed (canonical lives in `api/controllers/`) |
| `password_resets` table never created    | Real bug — used by `r3.php`, never defined | Added migration 005 + fixed 6 broken error messages that pointed to non-existent SQL files |