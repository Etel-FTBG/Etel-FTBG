# Phase 5 proof report (17 Nov 2026)

## 1. Chapa online payments (sandbox)
Script: `delivery/tests/chapa_sandbox_e2e.py` (needs the Chapa TEST key; refuses to run on a live key).
Result: 34 checks passed, 0 failed, using Chapa's real test API through our own code:
start payment, verify, settle once only, wrong amount/currency/status refused, replay safe,
webhook signature logic, and the reconcile job for lost webhooks.
Not tested: Chapa's hosted checkout page, and Chapa's real webhook sent to our live server.
Before real money: on the live server set the live key, add the webhook URL in the Chapa dashboard,
make one small real payment, and check the order is paid once.

## 2. EtelMadeya HTTP 500
Most likely cause: `EtelMadeya/.htaccess` had bare `php_value` / `php_flag` lines. On hosting that does
not run PHP as an Apache module, Apache answers 500 for every page.
Reproduced with a real Apache: 500 with "Invalid command 'php_value'". Fix: the lines are now inside
`<IfModule mod_php*.c>` blocks, and the same settings are in a new `.user.ini`. Verified in the reproduction.
Not confirmed on the live server (no access to its error log). To confirm: cPanel > Errors, or
rename the live `.htaccess` for a minute; if the site opens, this was the cause.

## 3. Load test
Script: `delivery/tests/load_test.py` (standard Python only). Local server, local database:
- Browsing, 10/25/50 users: about 2,100 requests/s, 0 failures, median 4/10/21 ms, p95 9/21/43 ms.
- Ride flow (sign up, estimate, book, check, cancel), 10 users: 100 bookings in 31 s, 0 failures.
- Ride flow, 30 users: 300 bookings, booking median 25 ms, 0 failures. The booking rate limit answered 429 as designed.
These numbers are for a fast local machine, NOT for the shared host. The live site was not reachable from
the test sandbox, so no live run was made.
Live run (off-peak, watch cPanel > Resource Usage): `python3 load_test.py --live --users 5 --seconds 20`
Live mode is browse-only, max 20 users. Never run ride booking against the live database.

## 4. Independent penetration test
See `delivery/docs/PENTEST_PREPARATION.md`, section "Open". Items 1 and 3 to 10 still need a person outside the team.
