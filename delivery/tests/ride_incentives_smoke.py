"""
ETEL Delivery -- launch incentives over the real API (Admin > Pricing > Incentives).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/ride_incentives_smoke.py

Needs the seeded demo accounts (api/seeds/admin_demo_accounts.sql). Saves a plan and puts it OFF again: throwaway database only.
"""
import json
import sys
import urllib.error
import urllib.request

API_URL = "http://127.0.0.1:8765/api/boot.php"
PASS, FAIL = [], []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as f:
            return f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8"))
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON error body)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:300]))


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("  SKIP no seeded demo admin")
        return 0
    s, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
    cust = (r.get("data") or {}).get("token")
    s, r = call("login", {"phone": "+251911000002", "password": "etel1234"})
    drv = (r.get("data") or {}).get("token")
    if not (cust and drv):
        print("  SKIP no seeded demo customer or driver")
        return 0

    print("1. Who may read, save and void")
    for act in ("admin_get_incentive_plan", "admin_save_incentive_plan", "admin_void_incentive"):
        s1, _ = call(act, {"id": 1})
        s2, _ = call(act, {"id": 1}, cust)
        s3, _ = call(act, {"id": 1}, drv)
        check(act + ": no login refused", s1 in (401, 403), s1)
        check(act + ": a customer and a driver are refused", s2 in (401, 403) and s3 in (401, 403), (s2, s3))
    s, r = call("admin_get_incentive_plan", {}, admin)
    d = r.get("data") or {}
    check("admin reads the plan: OFF, budget 0, no tiers", s == 200 and d["plan"]["on"] == 0 and d["plan"]["monthly_cap"] == 0 and d["plan"]["daily_tiers"] == [], r)
    check("the month summary is there", s == 200 and d["month"]["spent"] == 0 and "by_kind" in d["month"], r)

    print("2. Saving")
    s, _ = call("admin_save_incentive_plan", {"on": 1, "monthly_cap": 0}, admin)
    check("switching on without a budget is refused", s == 400, s)
    s, _ = call("admin_save_incentive_plan", {"on": 1, "monthly_cap": -5}, admin)
    check("a negative budget is refused", s == 400, s)
    s, _ = call("admin_save_incentive_plan", {"on": 1, "monthly_cap": 1000, "daily_tiers": [{"trips": 5, "bonus": 99999}]}, admin)
    check("a bonus above 5,000 is refused", s == 400, s)
    s, _ = call("admin_save_incentive_plan", {"on": 1, "monthly_cap": 1000, "daily_tiers": [{"trips": 0, "bonus": 10}]}, admin)
    check("a tier with 0 trips is refused", s == 400, s)
    s, r = call("admin_save_incentive_plan", {"on": 1, "monthly_cap": 5000, "driver_month_max": 600, "daily_on": 1, "daily_tiers": [{"trips": 8, "bonus": 60}, {"trips": 5, "bonus": 30}],
                                              "min_fare": 40, "min_km": 1, "max_same_rider": 2, "referral_on": 1, "referral_trips": 10, "referral_reward": 100, "referral_max_per_month": 5}, admin)
    p = (r.get("data") or {}).get("plan", {})
    check("a valid plan is saved (tiers sorted)", s == 200 and p.get("on") == 1 and p.get("monthly_cap") == 5000 and [t["trips"] for t in p.get("daily_tiers", [])] == [5, 8], r)
    s, r = call("admin_get_incentive_plan", {}, admin)
    check("it reads back the same", s == 200 and r["data"]["plan"]["referral_reward"] == 100 and r["data"]["plan"]["driver_month_max"] == 600, r)

    print("3. Voiding")
    s, _ = call("admin_void_incentive", {"id": 0}, admin)
    check("a missing id is refused", s == 400, s)
    s, _ = call("admin_void_incentive", {"id": 987654}, admin)
    check("an unknown award is refused", s == 409, s)

    print("4. Switching off")
    s, r = call("admin_save_incentive_plan", {"on": 0}, admin)
    s, r = call("admin_get_incentive_plan", {}, admin)
    check("the plan is OFF again", s == 200 and r["data"]["plan"]["on"] == 0, r)

    print("\n%d checks passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
