"""
Ride Payments console (admin_ride_payments): the shape the admin screen draws from, the 7/30/90/365 window (anything else falls back to 30),
a pending top-up arrives with its receipt photo, a rejected one needs the admin's decision and then moves to the decision history,
and a customer cannot read any of it.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
TAIL = str(int(time.time()))[-6:]
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def data(res):
    return res.get("data") or {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("ride payments console")
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)
st, res = call("register", {"name": "RP Rider", "phone": "+251991%s" % TAIL, "password": "test1234", "role": "customer"})
cust, cust_id = data(res).get("token"), data(res).get("user_id")
st, res = call("register", {"name": "RP Driver", "phone": "+251992%s" % TAIL, "password": "test1234", "role": "courier"})
drv, drv_id = data(res).get("token"), data(res).get("user_id")
call("driver_get_profile", {}, drv)
etel_test_db.onboard_driver(drv_id)

st, res = call("admin_ride_payments", {}, admin)
d = data(res)
check("the console answers", res.get("success") is True, res)
for key in ("by_method", "totals", "period", "trend", "drivers", "payouts", "topups", "topup_history"):
    check("it carries " + key, key in d, list(d))
check("the default window is 30 days", d.get("days") == 30, d.get("days"))
for key in ("today", "week", "month", "total", "earnings", "paid_out", "commission_owed", "etel_money"):
    check("totals has " + key, key in (d.get("totals") or {}), d.get("totals"))
check("the period has trips, amount and average fare", all(k in (d.get("period") or {}) for k in ("trips", "amount", "avg_fare")), d.get("period"))
check("the new driver is listed with a balance", any(str(r.get("id")) == str(drv_id) and "etel_money_balance" in r for r in d.get("drivers", [])), d.get("drivers", [])[:2])

for want, sent in ((7, 7), (90, 90), (365, 365), (30, 5), (30, "x"), (30, -1)):
    st, res = call("admin_ride_payments", {"days": sent}, admin)
    check("days=%r gives a %d-day window" % (sent, want), data(res).get("days") == want, data(res).get("days"))

call("admin_get_pending_etel_topups", {}, admin)   # makes sure the table and its receipt column exist
etel_test_db.run_sql("INSERT INTO driver_etel_money_topups (user_id, amount, tx_ref, source, status, receipt_image_url, note) VALUES (%d, 250, 'RPT%s', 'manual_receipt', 'pending', '/uploads/rp-test.jpg', 'CBE transfer')" % (drv_id, TAIL))
st, res = call("admin_ride_payments", {}, admin)
mine = [t for t in data(res).get("topups", []) if t.get("tx_ref") == "RPT" + TAIL]
check("a pending top-up is listed with its receipt photo and note", len(mine) == 1 and mine[0].get("receipt_image_url") == "/uploads/rp-test.jpg" and mine[0].get("note") == "CBE transfer", mine)
if mine:
    st, res = call("admin_reject_etel_topup", {"id": mine[0]["id"], "reason": "Blurry photo"}, admin)
    check("it can be rejected", res.get("success") is True, res)
    st, res = call("admin_ride_payments", {}, admin)
    d = data(res)
    check("it leaves the pending list", all(t.get("tx_ref") != "RPT" + TAIL for t in d.get("topups", [])), d.get("topups"))
    hist = [t for t in d.get("topup_history", []) if t.get("tx_ref") == "RPT" + TAIL]
    check("and appears in the decision history as rejected with the reason", len(hist) == 1 and hist[0].get("status") == "rejected" and hist[0].get("note") == "Blurry photo", hist)
    st, res = call("admin_reject_etel_topup", {"id": mine[0]["id"], "reason": "again"}, admin)
    check("deciding the same top-up twice is refused", res.get("success") is not True, res)

st, res = call("admin_ride_payments", {}, cust)
check("a customer cannot read the console", res.get("success") is not True and st in (401, 403), (st, res))
st, res = call("admin_ride_payments", {})
check("nor can anyone without a login", res.get("success") is not True, (st, res))

print("\n%s" % ("ALL PASS" if not fails else "FAILED: " + ", ".join(fails)))
sys.exit(1 if fails else 0)
