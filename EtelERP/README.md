# EtelERP (lean edition) - `ethelmarket.com/EtelERP`

Sales, invoices, stock, customers, expenses and VAT for small businesses, built like the other Etel
apps: plain PHP on the shared host, phone-first web app, sign in with **EtelID**. The heavier ERPNext
edition for the VPS lives in `../EtelERP-Frappe`.

## What is in this first slice
Sign in with EtelID (password checked by EtelID) - create a business - team with roles (owner, manager,
cashier) - customers - items and stock - one-tap **Sell** screen (cash, Telebirr, CBE, Chapa, bank, or
pay later) - tax invoices with 15% VAT, numbered per business - part payments - cancel invoices and
payments - expenses - dashboard (today, this month, who owes you, low stock, best sellers) - sales and
VAT report - printable invoice - audit trail - plan limits (free / standard / business).

Not yet: selling while offline, Amharic screens, billing the plan through Chapa, purchases and
suppliers, PDF download (printing to PDF works), SMS/Telegram receipts. These come in the next slices.

## Rules that are built in and tested
* **Money is exact**: whole cents and thousandths, half-up rounding, VAT per line; the server always
  recomputes totals (the phone's numbers are only a preview).
* **Never oversold, never double-charged**: stock and invoice numbers are locked inside a database
  transaction; every sale and payment carries a `client_ref`, so tapping twice on a slow connection
  gives one invoice and one payment. Tested with 10 sales racing for 3 items and 8 payments racing for
  one invoice (real parallel processes).
* **Businesses are walled off**: every query filters on the business; tested that one business cannot
  read, edit, issue, pay, cancel or adjust another's data.
* **Roles are enforced on the server**: cashiers sell at list price only (no free-text lines or price
  changes), cannot cancel invoices or payments, see no expenses or reports; only the owner manages the team.
* Sessions are random 256-bit tokens stored only as a SHA-256 hash, in an HttpOnly SameSite=Strict
  cookie; every POST needs the `X-Requested-With` header; sign-in is rate limited; wrong password and
  unknown account give the same message; EtelID outages show a safe message.
* An invoice, once issued, cannot be edited; it can be cancelled (reason required, stock returns) only
  after its payments are cancelled. Nothing is deleted except discarded drafts.

## Deploy on the shared host
1. cPanel: create a MySQL database and user (give the user all privileges on it).
2. Upload the folder so the files sit at `public_html/EtelERP/` (the `index.html` is directly inside).
3. Copy `.env.example` to `.env` and fill in `DB_*`, `ETELID_SERVICE_KEY` and a random `INSTALL_KEY`.
4. On the EtelID host, add a key for this app to `ETELID_SERVICE_KEYS` in `wp-config.php`
   (scopes `authenticate` and `resolve`, 40+ random characters - the same value as in step 3).
5. Open `https://ethelmarket.com/EtelERP/install.php?key=<INSTALL_KEY>` once. It says how many
   statements ran. Then **delete `install.php`** and remove `INSTALL_KEY` from `.env`.
6. Open `https://ethelmarket.com/EtelERP/` and sign in with an EtelID.
7. `storage/` must stay writable only during the install; `.htaccess` blocks `app/`, `db/`, `tests/`,
   `storage/` and `.env` from the web.

Every PHP and JS file is under 30 KB (this host's firewall has deleted larger ones) and the PHP is pure ASCII.

## Plans
`app/Plans.php` holds the limits (free: 2 users, 30 invoices a month, 100 items; standard: 5 users;
business: 25 users; both unlimited invoices and items). A business is on `free` until its `plan` column
is changed; billing through Chapa is the next slice. A paid plan past `plan_expires_at` counts as free.

## Tests
```bash
# needs a THROWAWAY MariaDB/MySQL database - the tables are dropped and recreated
TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=etelerp_test TEST_DB_USER=etelerp TEST_DB_PASS=testpass php EtelERP/tests/run.php
TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=etelerp_test TEST_DB_USER=etelerp TEST_DB_PASS=testpass php EtelERP/tests/concurrency.php
```
EtelID is faked in the tests, so nothing leaves the machine. The screens were also exercised end to end in a real
browser (sell, pay, part-pay, cancel, stock, expenses, report, sign out) against a real database.
