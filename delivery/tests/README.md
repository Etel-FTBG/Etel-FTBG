# Running everything (recommended)

```bash
# throwaway MariaDB + api/.env (APP_ENV=development, CRON_DISPATCH_KEY=test_cron_key,
# UPDATER_PASSWORD=anything) -- NEVER the live database
FRESH_DB=1 ./tests/ci/run_integration.sh
```
This is the same script GitHub CI runs (`.github/workflows/ci.yml`): migration
checks, reserved-word check, XSS ratchet, security / business-logic /
open-meter / week-1 hardening smoke tests. `tests/load_test.py` is the load
test (staging only, see `STAGING_GUIDE.md`).

---

# ETEL Delivery — Local Test Harness

The project ships with **zero** runtime test dependencies. Everything runs on
the `php -S` built-in server + `curl` + `bash` (and `python` for JSON
extraction on Windows / Git Bash).

## Open Meter / Street-Hail smoke test

End-to-end walk of the new open-trip flow:

```
php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
API=http://127.0.0.1:8765/api/api.php ./tests/open_meter_smoke.sh
```

What it covers:

1. **register** customer + driver (two fresh accounts)
2. **driver_hail_customer** with no km → open trip (`is_open_trip=1`)
3. **driver_start_ride** → status `in_progress`
4. **driver_update_location** x4 along a ~2 km line → `traveled_km` accumulates
5. **driver_arrived** → status `arrived`, `fare_final` computed from GPS
6. **customer_confirm_arrival** with `payment_method=cash` → status `completed`
7. **driver_get_profile** → `total_rides` and `total_earnings` incremented

Expected exit code: `0`. Output ends with `ALL CHECKS PASSED 🎉`.

## Security/validation regression smoke test

Exercises the auth, validation, and Telebirr-webhook fixes made in the
2026-07-12 security review, against a real running instance - this is what
turns those fixes from "verified by reading the code" into "verified by
actually running it":

```
php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
python3 tests/security_regression_smoke.py
```

What it covers:

1. **register** rejects a malformed phone number and a sub-8-character password
2. **register** + **login** round-trip returns a working token
3. **logout** actually revokes the token server-side - the same token is
   rejected on the next authenticated call (this is the regression test for
   token revocation; before that feature, a "logged out" token still worked
   for up to 7 days)
4. Repeated failed **login** attempts eventually get rate-limited (429)
5. The **Telebirr callback** rejects a request with no signature header
   (the single most important check here - this is exactly the gap that
   let anyone forge a "payment completed" event before this session's fix)
6. **driver_cashout** rejects a non-numeric and a negative amount

Expected exit code: `0`. Output ends with `ALL CHECKS PASSED`.

## Business-logic smoke test (order / ride / service-giver)

Closes the coverage gap the other two files leave: order lifecycle,
service-giver catalogue management, the customer-initiated `book_ride` path
(distinct from `open_meter_smoke`'s driver-initiated street hail), and the
password-reset flow restored this session.

```
php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
python3 tests/business_logic_smoke.py
```

What it covers:

1. **Order lifecycle**: admin creates a service giver → service giver adds
   an item (and a negative-price item is rejected) → customer orders it →
   a courier accepts (and a second courier is correctly blocked from
   double-accepting) → courier marks it picked_up then delivered →
   customer rates it (and a second rating attempt is rejected)
2. **Ride lifecycle**: customer `book_ride`s with pickup+dropoff (a second
   simultaneous booking is correctly blocked) → driver `driver_accept_ride`s
   → customer `cancel_ride`s (and cancelling twice is correctly rejected)
3. **Password reset**: `reset_request` → `reset_verify` (wrong OTP
   rejected, correct OTP accepted) → `reset_password` → login with the new
   password → replaying the same OTP is correctly rejected

The order/service-giver section needs the seeded default admin account
(phone `0900000000`, see `api/seeds/admin_demo_accounts.sql`) to create a
service giver; if that seed hasn't been applied to whatever database this
points at, that section **skips** with a clear message instead of failing -
"no admin fixture here" is an environment fact, not a regression. The ride
and password-reset sections need no fixtures and always run. The
password-reset section itself skips only if a real OTP channel
(`TG_BOT_TOKEN`/`AT_API_KEY`/etc.) is configured in `.env`, since in that
case the OTP is actually delivered externally and isn't recoverable by the
script.

Expected exit code: `0`. Output ends with `ALL CHECKS PASSED` (optionally
suffixed `(with skips)`).

## Prerequisites

- PHP 5.6+ with `pdo_mysql` extension
- A reachable MySQL with the schema applied (or use the auto-creating
  `rides_ensure_schema()` on first ride action)
- Bash, curl, python (for JSON path extraction)

## Cleanup

Test rows stay in the DB. To remove them:

```sql
DELETE FROM rides WHERE id IN (SELECT id FROM rides ORDER BY id DESC LIMIT 1);
DELETE FROM ride_drivers WHERE user_id IN (...);
DELETE FROM users WHERE phone LIKE '0911000%' OR phone LIKE '0922000%';
```

## Adding more tests

The pattern in `open_meter_smoke.sh` is:

```bash
RESP=$(call "$TOKEN" "{\"action\":\"...\",\"param\":\"value\"}")
# extract a field
extract "$RESP" .data.field
```

If the test fails, the API response is printed in full. For convenience,
`set -euo pipefail` is enabled — any failed `call` or assertion exits the
script with non-zero status.
