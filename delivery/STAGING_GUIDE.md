# Staging (test copy) guide — Delivery & Ride

Goal: a second copy of the site on your same cPanel, with its **own empty
database**, where you can try every change and run the load test before it
touches real customers. Nothing here touches the live site or live data.

## One-time setup (about 20 minutes)

1. **cPanel → Domains/Subdomains → Create** `staging.<your-domain>`
   (document root e.g. `public_html/staging`). Turn on AutoSSL for it.
2. **cPanel → MySQL Databases**: create `youracct_delivery_stg`, a new user
   (`youracct_stg`) with a strong password, and give that user ALL privileges
   on that database only. Never reuse the live DB user.
3. **Upload the code**: File Manager → upload the same zip you upload for live
   into `public_html/staging` and extract it.
4. **Create `api/.env` for staging** with *test-only* values:
   ```
   DB_HOST=localhost
   DB_NAME=youracct_delivery_stg
   DB_USER=youracct_stg
   DB_PASS=<the new password>
   TOKEN_SECRET=<a NEW random 64-char string, not the live one>
   APP_ENV=development        # makes OTP codes appear on screen, no real SMS
   CRON_DISPATCH_KEY=<random>
   UPDATER_PASSWORD=<random>
   ```
   Leave out the live Chapa / Telebirr / SMS / Maps secrets. For Chapa use
   your Chapa **test** key (`CHASECK_TEST-...`) so no real money moves.
5. **Build the empty database**: phpMyAdmin → select the staging DB → Import
   `api/seeds/complete_migration.sql`, then open
   `https://staging.<your-domain>/api/migrate.php` in the admin tool (or run
   the Migrations screen) and apply all, then import
   `api/seeds/admin_demo_accounts.sql` for a demo admin/customer/courier.
6. **Make staging obvious and private**: add `Header set X-Robots-Tag "noindex"`
   and HTTP basic auth (cPanel → Directory Privacy on the staging folder) so
   search engines and strangers can't reach it.
7. **Do NOT copy `api/.schema_done`** from live. It's a marker that says "the
   ride tables were already upgraded"; with it present, a new database never
   gets its upgrade.

## Every release

1. Upload the new zip to staging → click through the changed screens.
2. Run the load test from your laptop (needs Python 3):
   `python3 delivery/tests/load_test.py --base https://staging.<domain> --workers 20 --seconds 30`
   Healthy = **0 errors and p95 under ~800 ms** at the worker count that
   matches your busiest hour. Shared hosting usually tops out well before a
   VPS does — the point is to learn the number, not to hit a target.
3. Only then upload the same zip to live.

## Refreshing staging with realistic data (optional)

Export the live DB from phpMyAdmin, import it into the *staging* DB, then
immediately: change every user's phone to a fake one
(`UPDATE users SET phone = CONCAT('+2519000', LPAD(id,5,'0'))`), and clear
`password_resets`, `push_subscriptions`, saved bank/Telebirr numbers. Never
leave real phone numbers on staging.
