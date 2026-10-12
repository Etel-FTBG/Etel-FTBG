# Staging copy of Etel Delivery and Etel Ride

A staging copy is a private second install on your own hosting. Every change is tried there first;
only after it works do you put the same files on the live site. It protects real customers, drivers
and money from a mistake.

Time: about 45 minutes the first time. Cost: free (same cPanel account).

## 1. Rules that keep the live system safe

1. Staging uses its **own database**. Never point it at the live database.
2. Staging has **no real customer data**. Copy the table structure and settings only, then add test accounts.
3. Staging has **no real money, push, SMS or Telegram keys**. Leave those blank so nothing can reach real people.
4. Staging is **password-protected** and hidden from search engines.
5. Staging uses a **different TOKEN_SECRET**, so a sign-in token from the live site never works on staging (and the reverse).

## 2. Create the subdomain (cPanel)

1. cPanel, **Domains** (or Subdomains), Create A Domain.
2. Name: `staging.ethelmarket.com`. Document root: `public_html/staging` (leave the suggested folder).
3. Wait for the HTTPS certificate: cPanel, **SSL/TLS Status**, run AutoSSL for the new name.

## 3. Create the staging database

1. cPanel, **MySQL Databases**: create a database `ACCOUNT_stg`, a user `ACCOUNT_stgu` with a long random password, and add the user to the database with All Privileges.
2. phpMyAdmin, select the **live** database, **Export**, method **Custom**:
   - Format SQL, and for every table choose **Structure only**, except these tables where you also tick **Data**: `pricing_rules`, `ride_fare_rules`, `zones`, `role_permissions`, `delivery_tiers`, `migrations` (or the migrations table your system uses).
   - Do not export the data of any table that holds users, orders, rides, payments, wallets or messages.
3. phpMyAdmin, select the **staging** database, **Import**, choose the exported file.
4. Create test accounts on staging (register them in the staging apps), and promote one to admin through the same Employees step you used for the live test accounts.

## 4. Copy the files

1. On your computer, build the same folder you upload to live (`delivery/`).
2. cPanel File Manager, open `public_html/staging`, create a folder `delivery`, upload and extract the zip there.
   The apps then live at `https://staging.ethelmarket.com/delivery/`.
3. Do **not** copy the live `api/.env`. Create a new `api/.env` on staging from `api/.env.example`.

## 5. The staging `.env` (what to fill and what to leave blank)

| Setting | Staging value |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | The staging database from step 3 |
| `APP_ENV` | `development` |
| `TOKEN_SECRET` | A new long random value (not the live one) |
| `CRON_DISPATCH_KEY`, `UPDATER_PASSWORD`, `PURGE_CACHE_SECRET`, `ETEL_AUDIT_SECRET` | New random values |
| `ETEL_BASE_URL` | `https://staging.ethelmarket.com/delivery` |
| `GOOGLE_MAPS_API_KEY` | Same key is fine; add `staging.ethelmarket.com/*` to its allowed websites in Google Cloud |
| `ETELID_SERVICE_URL`, `ETELID_SERVICE_KEY`, `ETELID_LEGACY_KEY` | **Blank** (staging signs in with its own test accounts, not real EtelID members) |
| `ETEL_PAY_SECRET` | **Blank** (no real wallet movements) |
| `TELEBIRR_*`, `CBE_*`, `ABYSINIA_*`, `CHAPA_SECRET_KEY` | **Blank** |
| `TG_BOT_TOKEN`, `TG_WEBHOOK_SECRET` | **Blank** (no Telegram alerts to real staff) |
| `FCM_SERVER_KEY`, `VAPID_PRIVATE_KEY_PEM`, `VAPID_PUBLIC_KEY` | **Blank** (no push to real phones) |
| `AT_API_KEY`, `AT_USERNAME`, `WA_*` | **Blank** (no SMS or WhatsApp to real numbers) |

If a feature needs one of the blank services, it will say it is not configured. That is the intended behaviour on staging.

## 6. Lock it down

1. cPanel, **Directory Privacy**: choose `public_html/staging`, enable password protection, create one user. Everyone who tests must know it.
2. Create `public_html/staging/robots.txt` containing:
   ```
   User-agent: *
   Disallow: /
   ```
3. Do **not** add the cron jobs on staging unless you are testing them. If you do, use the staging path and the staging `CRON_DISPATCH_KEY`.

## 7. The routine for every change

1. Upload the changed files to staging and test them (sign in with the staging test accounts).
2. Run the checks: open `https://staging.ethelmarket.com/delivery/api/health.php` (needs the directory password), then walk through the screens you changed in light and dark mode.
3. Only when staging is clean, extract the same zip into the live `public_html/delivery/`.
4. Open the live health page and the screen you changed.

## 8. Keeping staging useful

- Each month, repeat step 3.2 to 3.3 so the staging structure matches live after database changes.
- If you add a new migration file, run it on staging first (admin, Settings, Database Migrations), then on live.
- Never copy a staging database over the live one.
