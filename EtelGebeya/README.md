# Etel Gebeya — EtelGebeya.com

Ethiopia's B2C + B2B marketplace. PHP 8 + MySQL, built for the Ethiopian market with Telebirr, CBE Birr, and Chapa payment integrations. Bilingual: English + Amharic.

---

## Quick Start

### Local Development (SQLite — no setup needed)

```bash
cd etel-gebeya
php dev-setup.php          # one-time: creates SQLite DB, runs schema + seed
php -S localhost:8080 -t public public/index.php
# Open http://localhost:8080
```

That's it — no MySQL, no XAMPP required for local dev.

### Production (cPanel + MySQL)

Upload the `etel-gebeya/` folder to your cPanel `public_html/EtelGebeya/` directory, then:

1. Open `https://yourdomain.com/EtelGebeya/install`
2. Enter your cPanel MySQL credentials (database name, user, password)
3. Click Install — schema runs, admin account created
4. Done!

---

## Project Structure

```
etel-gebeya/
├── app/
│   ├── Controllers/        # Request handlers (InstallController done, others scaffolded)
│   ├── Core/               # Router, Database, Auth, I18n, View, Upload, Str, Request
│   ├── Models/             # User, Product, Category
│   ├── Payments/           # Telebirr, CBE, Chapa adapters
│   └── Views/              # PHP templates
│       ├── _layouts/        # Public layout (header, footer)
│       ├── storefront/      # Homepage, search, product, category pages
│       ├── install/         # Install wizard UI
│       ├── errors/         # 404, 500 pages
│       └── i18n/           # en.json, am.json translations
├── config/
│   └── app.php             # All config (DB, payments, security, currency)
├── database/
│   ├── schema.sql          # Full MySQL schema (23 tables)
│   └── seed.sql            # Categories + default admin
├── public/
│   ├── assets/css/         # app.css, install.css
│   ├── assets/js/          # (your JS files)
│   └── index.php           # Front controller — all routes defined here
├── storage/                 # uploads/, logs/, cache/, .installed marker
├── bootstrap.php            # Initializes session, autoloader, globals
├── dev-setup.php           # SQLite setup for local dev
└── .htaccess               # Apache rewrite rules
```

---

## What Exists vs What's Next

### ✅ Done (Step 1 — Foundation)
- Custom PHP MVC framework (Router, DB, Auth, I18n, View)
- Full MySQL schema (users, vendors, products, orders, payments, reviews, settings)
- Telebirr + CBE Birr + Chapa payment config
- Bilingual i18n (EN + AM)
- Admin install wizard → creates DB, runs schema, sets admin password
- 12 sample products seeded in SQLite
- **Public layout + homepage** ← just built
- **Product card + category card** components

### 🔜 Build Order (Page by Page)
| # | Page | Route | Notes |
|---|------|-------|-------|
| 1 | ✅ Homepage + layout | `/` | just done |
| 2 | Product detail | `/product/{slug}` | needs model + view |
| 3 | Category listing | `/category/{slug}` | needs view |
| 4 | Login + Register | `/login`, `/register` | AuthController needed |
| 5 | Cart | `/cart` | session-persisted |
| 6 | Checkout | `/checkout` | needs address + payment |
| 7 | Customer dashboard | `/account/*` | orders, addresses |
| 8 | Vendor dashboard | `/vendor-panel/*` | product CRUD |
| 9 | Admin dashboard | `/admin/*` | user + order management |
| 10 | Courier dashboard | `/courier/*` | delivery tracking |

---

## Tech Details

- **PHP 8.0+**, no Composer (PSR-4 autoloader)
- **MySQL 5.7+** (cPanel) or **SQLite 3** (local dev)
- **Driver auto-detection**: set `APP_DB=sqlite` env var for local SQLite; defaults to MySQL config
- **Auth**: bcrypt passwords, rate-limiting, session-based
- **Payments**: Telebirr (Ethio Telecom), CBE Birr API, Chapa (cards)
- **i18n**: JSON catalogs, Amharic + English, `t('key')` helper
- **CSRF**: token-protected POST forms
- **File uploads**: vendor logos, product images → `storage/uploads/`

---

## Configuration

Edit `config/app.php` or set environment variables:

```bash
# MySQL (cPanel)
DB_HOST=127.0.0.1
DB_NAME=etel_gebeya
DB_USER=your_cpanel_user
DB_PASS=your_password

# Payments (live keys replace sandbox defaults)
EG_TELEBIRR_ENABLED=true
EG_TELEBIRR_APP_ID=your-app-id
EG_TELEBIRR_APP_KEY=your-app-key
EG_TELEBIRR_MERCHANT_ID=your-merchant-id

EG_CBE_ENABLED=true
EG_CBE_API_KEY=your-cbe-key

EG_CHAPA_ENABLED=true
EG_CHAPA_SECRET=chsk_live_xxxxx
```

---

## Deployment Checklist (cPanel)

1. Upload all files to `public_html/EtelGebeya/`
2. `storage/` and `config/` must be **writable** by PHP (chmod 755)
3. Point domain or subdomain to the `EtelGebeya/` folder
4. Run `/install` with your cPanel MySQL credentials
5. Delete `storage/.installed` to reset installation
6. Set `APP_DEBUG=false` in `config/app.php` for production
