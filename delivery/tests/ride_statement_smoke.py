"""
Driver weekly statement (driver_get_statement) and per-trip money in driver_get_ride_history: Etel's 5% is shown on
every trip, a promo discount counts as part of the fare Etel funded, totals add up, another driver sees nothing of it,
and the date rules hold (up to 12 weeks back, never the future).

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import datetime
import json
import os
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
SUFFIX = str(int(time.time()))
TAIL = SUFFIX[-6:]
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


def register(phone, role, name):
    st, res = call("register", {"name": name, "phone": phone, "password": "test1234", "role": role})
    d = data(res)
    return d.get("token"), d.get("user_id")


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("driver weekly statement")
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)
rider, rider_id = register("+251951%s" % TAIL, "customer", "Stmt Rider")
drv, drv_id = register("+251952%s" % TAIL, "courier", "Stmt Driver")
other, other_id = register("+251953%s" % TAIL, "courier", "Stmt Other")
if not (rider and drv and other):
    check("test users registered", False, (rider, drv, other))
    sys.exit(1)
call("driver_get_profile", {}, drv)
if not etel_test_db.onboard_driver(drv_id):
    print("SKIP: cannot mark the driver onboarded in the test database")
    sys.exit(0)
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")
call("driver_go_online", {"lat": 7.05, "lng": 38.49}, drv)

st, res = call("driver_get_statement", {}, drv)
check("an empty week answers with zero trips", res.get("success") is True and data(res).get("totals", {}).get("trips") == 0, res)
check("an empty week has no hourly rate", data(res).get("per_hour") is None, res)

CODE = "STMT%s" % TAIL
st, res = call("admin_ride_promo_save", {"code": CODE, "discount_type": "fixed", "value": 20}, admin)
check("a promo code exists for the trip", res.get("success") is True, res)


def trip(promo):
    st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, rider)
    body = {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash",
            "quote_token": data(est).get("quote_token")}
    if promo:
        body["promo_code"] = CODE
    st, res = call("book_ride", body, rider)
    rid = data(res).get("request_id")
    if not rid:
        return None, None
    call("driver_accept_ride", {"request_id": rid}, drv)
    call("driver_start_ride", {"ride_id": rid}, drv)
    call("driver_arrived", {"ride_id": rid}, drv)
    st, res = call("customer_confirm_arrival", {"ride_id": rid, "payment_method": "cash"}, rider)
    return rid, data(res)


rid1, paid1 = trip(False)
check("a plain trip completes", bool(rid1) and bool(paid1), (rid1, paid1))
rid2, paid2 = trip(True)
check("a promo trip completes", bool(rid2) and bool(paid2), (rid2, paid2))
if not (rid1 and rid2):
    sys.exit(1)

st, res = call("driver_get_ride_history", {}, drv)
rides = {r["id"]: r for r in data(res).get("rides", [])}
m1 = (rides.get(rid1) or {}).get("money") or {}
m2 = (rides.get(rid2) or {}).get("money") or {}
check("history shows Etel's 5% on a plain trip", m1 and abs(m1["commission"] - round(m1["gross"] * 0.05, 2)) < 0.011 and m1["promo"] == 0, m1)
check("and what the driver keeps", m1 and abs(m1["you_keep"] - (m1["gross"] - m1["commission"])) < 0.011, m1)
check("a promo trip: gross is the rider's fare plus the discount", m2 and m2["promo"] > 0 and abs(m2["gross"] - (m2["rider_paid"] + m2["promo"])) < 0.011, m2)
check("and the 5% is on the full fare", m2 and abs(m2["commission"] - round(m2["gross"] * 0.05, 2)) < 0.011, m2)
check("history does not leak the payment reference", "wallet_tx_ref" not in (rides.get(rid1) or {}), rides.get(rid1))

st, res = call("driver_get_statement", {}, drv)
s = data(res)
t = s.get("totals", {})
check("the week has both trips", res.get("success") is True and t.get("trips") == 2, res)
check("totals add up: gross = both trips", abs(t.get("gross", 0) - (m1["gross"] + m2["gross"])) < 0.02, (t, m1, m2))
check("commission and what you keep add up", abs(t.get("commission", 0) + t.get("you_keep", 0) - t.get("gross", 0)) < 0.02, t)
check("the promo Etel funded is counted", abs(t.get("promo", 0) - m2["promo"]) < 0.011, t)
check("both were paid in cash", abs(t.get("cash_collected", 0) - (m1["rider_paid"] + m2["rider_paid"])) < 0.02 and t.get("etel_collected") == 0, t)
check("the week is Monday to Sunday", datetime.date.fromisoformat(s["week_start"]).weekday() == 0 and s["week_end"] == str(datetime.date.fromisoformat(s["week_start"]) + datetime.timedelta(days=6)), s)
check("seven days are listed and today carries the trips", len(s.get("days", [])) == 7 and sum(d["trips"] for d in s["days"]) == 2, s.get("days"))
check("the trip list has both trips", {x["id"] for x in s.get("trips", [])} == {rid1, rid2}, s.get("trips"))
check("there is no next week from this week", s.get("next_week") is None and bool(s.get("prev_week")), s)

st, res = call("driver_get_statement", {"week_start": s["prev_week"]}, drv)
check("last week is empty", res.get("success") is True and data(res).get("totals", {}).get("trips") == 0 and bool(data(res).get("next_week")), res)
future = str(datetime.date.today() + datetime.timedelta(days=30))
st, res = call("driver_get_statement", {"week_start": future}, drv)
check("a future date shows this week", res.get("success") is True and data(res).get("week_start") == s["week_start"], res)
old = str(datetime.date.today() - datetime.timedelta(days=200))
st, res = call("driver_get_statement", {"week_start": old}, drv)
check("older than 12 weeks is refused", res.get("success") is False and st == 400, (st, res))
st, res = call("driver_get_statement", {"week_start": "tomorrow"}, drv)
check("a bad date is refused", res.get("success") is False and st == 400, (st, res))

st, res = call("driver_get_statement", {}, other)
check("another driver sees none of it", data(res).get("totals", {}).get("trips") == 0 and not data(res).get("trips"), res)

if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All statement checks passed")
