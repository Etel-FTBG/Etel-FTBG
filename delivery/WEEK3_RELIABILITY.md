# Week 3 — Reliability

## 1. Automatic checks on every change (`.github/workflows/ci.yml`)

On every pull request and every push to `main`, GitHub now:

* syntax-checks every PHP and JavaScript file,
* builds a **fresh MariaDB 10.11** database from the repo only
  (`complete_migration.sql` + all migrations + demo accounts),
* starts the site and runs the regression tests: security (13), business
  logic (29: orders, rides, couriers, ratings), open-meter ride flow, and the
  Week-1 hardening tests (password-reset lockout, Equb cron key, updater
  lockout/CORS, retired scripts, Chapa webhook signature),
* guards against three classes of bug (below).

Run exactly the same thing locally: `FRESH_DB=1 delivery/tests/ci/run_integration.sh`
(throwaway database only).

| Guard | What it stops |
|---|---|
| `check_migrations.py` | two migrations sharing a number (the live `040` clash), gaps/typos |
| `check_reserved_columns.py` | an unquoted column that MariaDB treats as a keyword (the `portion` bug that made *add menu item* fail) |
| `check_xss_sinks.py` | new unescaped `innerHTML` writes; the count may only go down (`--update` after cleaning some) |

## 2. Real bugs the new tests found (all fixed here)

* **Adding a menu item failed on live MariaDB** — `portion` is a reserved word; now backquoted in 8 places.
* **A new database could not be built from the repo** — migrations assumed tables/columns that only existed after the app created them at runtime. Added `000_runtime_schema_baseline.sql`, a guarded `est_minutes` add in `007`, `IF NOT EXISTS` in `051`, and made the migration runner read result rows properly (`008`).
* **`040_rides_cancel_reason` never ran on live** — two files used number 040, so the runner skipped one. It is now `072_rides_cancel_reason` (**apply it from the admin Migrations screen after deploying**; until then ride cancel reasons aren't stored).
* **`api/.schema_done` was committed to git** — any fresh install skipped its ride-table upgrade (missing `onboarded`, etc.). It is now untracked/ignored. Your live copy is unaffected; *don't* copy it to staging.
* The old tests predated the driver-onboarding gate and the GPS anti-glitch rule, so they were failing/skipping silently; they now exercise those rules properly.

## 3. Staging and load testing

See `STAGING_GUIDE.md` (second copy of the site + empty database) and
`tests/load_test.py` (concurrent users, p50/p95/p99 and error rate).
On a laptop against `php -S`, 8 workers did ~1,000 req/s with p95 ≈ 15 ms —
useful only for before/after comparisons. Real numbers come from staging.

## 4. Data retention (suggestion — not automatic)

`ride_path_points` stores a GPS breadcrumb every few seconds per active ride
and is the table most likely to bloat a shared-host database. Once rides are
older than the dispute window you keep (say 90 days), run from phpMyAdmin
(or a monthly cPanel cron):

```sql
DELETE FROM ride_path_points WHERE recorded_at < NOW() - INTERVAL 90 DAY LIMIT 50000;
```
Repeat until 0 rows are affected. Take a backup first; it is irreversible.

## 5. Notes

* `api/seeds/sample_data.sql` inserts fixed user IDs 1–3 — use it only on an empty test database, never on live.
* A deploy zip can add/replace files but cannot delete them: the renamed migration means `040_rides_cancel_reason.sql` may remain on the server. That's harmless — the runner now ignores it because `040_equb_system` owns version 40 — but you can delete it by hand in File Manager.
