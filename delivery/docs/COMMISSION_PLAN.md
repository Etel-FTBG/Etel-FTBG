# Commission plan (launch schedule)

What Etel takes from a driver on each completed ride, per driver and per day. One setting, edited in
**Admin > Pricing > Commission** (`admin_get_commission_plan`, `admin_save_commission_plan`, stored as JSON in
`pricing_rules.commission_plan`). With the plan off (the default) every driver pays the standard rate, 5 %, exactly as before.

## Rules
- Plan ON: each driver pays **phase 1** rate (default 0 %) for `phase1_days` (default 60) from their start day,
  then the **phase 2** rate (default 2 %) for `phase2_days` (default 30), then the standard rate.
- Start day = the day the driver's account was created, or the plan's **starts on** day if that is later
  (so drivers who joined before the plan also get their free days, from the plan start).
- **Only drivers who start by** (optional): a driver whose start day is after it pays the standard rate.
- A launch rate is never above the standard rate; the standard rate is capped at 30 %; days 0 to 365.
- A completed ride stores the rate and the amount it was charged (`rides.commission_rate`, `rides.commission_amt`).
  Changing or switching off the plan never changes completed rides. Older rides (no stored amount) count as 5 % of fare plus promo.

## Where it is used
- `rides_complete_and_pay()` (api/lib/ride_fare.php): the commission added to `commission_owed` and taken from the Etel money balance.
- Driver statement and trip history (api/lib/ride_statement.php): each trip shows the commission it really paid.
- Platform economics, driver KPI and growth totals: sum of the commission really charged (`rides_commission_sql()`).
- Driver app: `driver_get_commission` and a small note (js/driver-commission-note.js) while the driver pays less than standard.
- Investor module (EtelGroup/investors): the distribution counts `rides.commission_amt` as charged, and older rides at the rate set there.

## Tests
`php tests/ride_commission_test.php` (pure rules plus a database part) and `python3 tests/ride_commission_smoke.py` (API). Both are in `tests/ci/run_integration.sh`.

---

# Launch incentives (daily trip bonuses and driver referral rewards)

Edited in **Admin > Pricing > Incentives** (`admin_get_incentive_plan`, `admin_save_incentive_plan`, `admin_void_incentive`; JSON in
`pricing_rules.incentive_plan`; code in `api/lib/ride_incentives.php`). **Nothing is paid** unless the plan is ON **and** has a monthly budget above 0.

- **Monthly budget** is a hard ceiling for all incentives in a calendar month; an award that does not fit in what is left is skipped.
  Optional per-driver monthly maximum.
- **Daily trip bonus**: tiers `trips = bonus` in the plan (up to 8, e.g. 5 trips = ETB 30, 8 trips = ETB 60). Paid once per tier per day when the
  driver has that many qualifying trips that day. The old `driver_goal_tiers.bonus_etb` column is not used (it holds legacy numbers).
- **Referral reward**: the driver whose invite code (`couriers.referred_by`) brought in another driver is paid once when the new driver has the
  required number of qualifying trips from at least 3 different riders (at most 3 trips per rider count; the referrer's own rides do not count).
  Limit per referrer per month; a suspended referrer is not paid.
- **Qualifying trip**: completed, fare plus promo at least the minimum fare, at least the minimum distance, rider is not the driver, and for the daily
  bonus at most N trips per rider per day.
- Money is added to the driver's Etel money balance. Each award is one row in `ride_incentive_awards` (unique per kind, driver and reference, so never
  paid twice) and the amount is stored on the triggering ride (`rides.incentive_bonus`, `rides.incentive_referral`).
- **Void** an award in the admin: the credit is taken back and it leaves the totals.
- **Investor launch budget**: the investor module counts promo discounts (`rides.promo_discount`), bonuses and referral rewards automatically from
  the rides table, from the "count from" date set in Admin > Launch period. No extra database access is needed.

Tests: `php tests/ride_incentives_test.php` and `python3 tests/ride_incentives_smoke.py` (both in `tests/ci/run_integration.sh`).
