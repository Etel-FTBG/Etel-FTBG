"""
ETEL Delivery -- commission plan over the real API (Admin > Pricing > Commission and the driver's own note).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/ride_commission_smoke.py

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). Saves a plan and puts the default back: throwaway database only.
"""
import json
import sys
import time
import urllib.error
import urllib.request

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
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
    n = int(SUFFIX) % 1000000
    s, r = call("register", {"name": "Comm Cust %s" % SUFFIX, "phone": "+251971%06d" % n, "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token")
    s, r = call("register", {"name": "Comm Drv %s" % SUFFIX, "phone": "+251972%06d" % n, "password": "testpass123", "role": "courier"})
    drv = (r.get("data") or {}).get("token")

    print("1. Who may read and save the plan")
    for act in ("admin_get_commission_plan", "admin_save_commission_plan"):
        s1, _ = call(act, {})
        s2, _ = call(act, {}, cust)
        s3, _ = call(act, {}, drv)
        check(act + ": no login refused", s1 in (401, 403), s1)
        check(act + ": a customer and a driver are refused", s2 in (401, 403) and s3 in (401, 403), (s2, s3))
    s, r = call("admin_get_commission_plan", {}, admin)
    d = (r.get("data") or {})
    check("admin reads the plan: default is off with 5 percent", s == 200 and d.get("plan", {}).get("holiday_on") == 0 and abs(d["plan"]["standard_rate"] - 0.05) < 1e-9, r)

    print("2. Saving")
    s, _ = call("admin_save_commission_plan", {"standard_rate": 0.05, "holiday_on": 1, "phase1_rate": 0.08}, admin)
    check("a launch rate above the standard rate is refused", s == 400, s)
    s, _ = call("admin_save_commission_plan", {"standard_rate": 0.5}, admin)
    check("a standard rate above 30 percent is refused", s == 400, s)
    s, _ = call("admin_save_commission_plan", {"standard_rate": 0.05, "starts_on": "soon"}, admin)
    check("a bad date is refused", s == 400, s)
    s, r = call("admin_save_commission_plan", {"standard_rate": 0.05, "holiday_on": 1, "phase1_days": 60, "phase1_rate": 0, "phase2_days": 30, "phase2_rate": 0.02}, admin)
    p = (r.get("data") or {}).get("plan", {})
    check("a valid plan is saved and starts today when no start day is given", s == 200 and p.get("holiday_on") == 1 and p.get("starts_on") != "", r)

    print("3. The driver's own view")
    s, r = call("driver_get_commission", {}, drv)
    d = r.get("data") or {}
    check("a new driver sees 0 percent in phase 1 with an end day", s == 200 and d.get("rate") == 0 and d.get("phase") == "holiday" and d.get("ends_on"), r)
    s, _ = call("driver_get_commission", {})
    check("driver_get_commission needs a login", s in (401, 403), s)

    print("4. Switching off")
    s, r = call("admin_save_commission_plan", {"standard_rate": 0.05, "holiday_on": 0}, admin)
    s, r = call("driver_get_commission", {}, drv)
    d = r.get("data") or {}
    check("with the plan off the driver pays the standard rate", s == 200 and abs(d.get("rate", 0) - 0.05) < 1e-9 and d.get("phase") == "standard", r)

    print("\n%d checks passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
