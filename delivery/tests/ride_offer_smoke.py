"""
Offer waves: the nearest drivers see a new ride request first, the rest a little later.

Five drivers are online at 0, 220, 440, 660 and 880 m from the pickup. A customer books. Checks: right after
booking only the nearest three (wave 1) list the request; once the ride is 25 s old the nearest eight (wave 2)
do, so the fourth and fifth now see it; when ride_offer_wave_secs is 0 everyone sees it at once again.

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
SUFFIX = str(int(time.time()))
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    payload = dict(body or {}, action=action)
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(payload).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def register(phone, role, name):
    st, res = call("register", {"name": name, "phone": phone, "password": "test1234", "role": role})
    d = res.get("data") or {}
    return d.get("token"), d.get("user_id")


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("ride offer waves")
tail = SUFFIX[-6:]
drivers = []
for i in range(5):
    tok, uid = register("+25198%d%s" % (i, tail), "courier", "Wave D%d" % i)
    drivers.append((tok, uid))
cust_tok, _ = register("+251989%s" % tail, "customer", "Wave Cust")
if not (cust_tok and all(t for t, _ in drivers)):
    check("test users registered", False, (cust_tok, drivers))
    sys.exit(1)

for tok, uid in drivers:
    call("driver_get_profile", {}, tok)
    if not etel_test_db.onboard_driver(uid):
        print("SKIP: cannot mark the drivers onboarded in the test database")
        sys.exit(0)

# nobody else may take part in the ranking: park every other driver left online by earlier tests
ids = ",".join(str(int(u)) for _, u in drivers)
etel_test_db.run_sql("UPDATE ride_drivers SET online=0 WHERE user_id NOT IN (%s)" % ids)
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 20, 'test') ON DUPLICATE KEY UPDATE rule_value=20")

for i, (tok, uid) in enumerate(drivers):
    call("driver_go_online", {"lat": 7.0500 + i * 0.002, "lng": 38.49}, tok)

st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, cust_tok)
quote = (est.get("data") or {}).get("quote_token")
st, res = call("book_ride", {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52,
                             "payment_method": "cash", "quote_token": quote}, cust_tok)
rid = (res.get("data") or {}).get("request_id")
check("ride booked", bool(rid), res)
if not rid:
    sys.exit(1)


# The API keeps its database session in the server's own time zone, this script's connection does not. Read the
# zone off the ride itself so "n seconds old" means the same thing to both.
ok_q, out, _ = etel_test_db.query_text("SELECT ROUND(TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), created_at)/900)*900 FROM rides WHERE id=%d" % rid)
try:
    off = int(float(out.strip().splitlines()[-1]))
except Exception:
    off = 0
TZ = "%s%02d:%02d" % ("-" if off < 0 else "+", abs(off) // 3600, (abs(off) % 3600) // 60)


def set_age(seconds):
    etel_test_db.run_sql("SET time_zone='%s'; UPDATE rides SET created_at=DATE_SUB(NOW(), INTERVAL %d SECOND) WHERE id=%d" % (TZ, seconds, rid))


def sees(tok):
    st, res = call("driver_get_nearby_requests", {}, tok)
    return rid in [int(r["id"]) for r in (res.get("data") or {}).get("requests", [])]


now = [sees(t) for t, _ in drivers]
check("wave 1: the nearest three see the new request", now[:3] == [True, True, True], now)
check("the fourth and fifth do not see it yet", now[3:] == [False, False], now)

set_age(25)
later = [sees(t) for t, _ in drivers]
check("wave 2: after 25 s all five see it", later == [True] * 5, later)

# the passenger's status poll sends the wider wake-up push once, and remembers it did
call("ride_status", {"id": rid}, cust_tok)
call("ride_status", {"id": rid}, cust_tok)
ok_q, out, _ = etel_test_db.query_text("SELECT COUNT(*) FROM ride_offer_log WHERE ride_id=%d" % rid)
check("the wider push is claimed once for the ride", ok_q and out.strip().splitlines()[-1] == "1", out)

# a driver who declines is not counted, so the next one moves up
set_age(0)
call("driver_decline_ride", {"request_id": rid}, drivers[0][0])
now = [sees(t) for t, _ in drivers]
check("after the nearest declines the fourth driver is in wave 1", now[0] is False and now[3] is True and now[4] is False, now)

# waves off: everyone at once
etel_test_db.run_sql("UPDATE pricing_rules SET rule_value=0 WHERE rule_key='ride_offer_wave_secs'")
set_age(0)
off = [sees(t) for t, _ in drivers]
check("with waves off every driver (who has not declined) sees it", off[1:] == [True] * 4, off)

etel_test_db.run_sql("UPDATE pricing_rules SET rule_value=20 WHERE rule_key='ride_offer_wave_secs'")
call("cancel_ride", {"id": rid}, cust_tok)

print("\nFAILED: %d" % len(fails) if fails else "\nAll ride offer wave checks passed")
sys.exit(1 if fails else 0)
