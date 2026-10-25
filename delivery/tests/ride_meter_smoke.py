"""
Trip meter and waiting clock (driver_update_location, driver_toggle_wait): a parked phone that drifts a few tens of metres must not add
kilometres or stop the waiting clock, a GPS speed of ~0 settles small drift, a real drive adds its distance and stops the clock, and the
waiting charge (ETB) is part of every answer so the driver app can show it while the clock runs.

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


def register(phone, role, name):
    st, res = call("register", {"name": name, "phone": phone, "password": "test1234", "role": role})
    d = data(res)
    return d.get("token"), d.get("user_id")


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("trip meter and waiting clock")
rider, rider_id = register("+251993%s" % TAIL, "customer", "Meter Rider")
drv, drv_id = register("+251994%s" % TAIL, "courier", "Meter Driver")
if not (rider and drv):
    check("test users registered", False, (rider, drv))
    sys.exit(1)
call("driver_get_profile", {}, drv)
if not etel_test_db.onboard_driver(drv_id):
    print("SKIP: cannot mark the driver onboarded in the test database")
    sys.exit(0)
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")
call("driver_go_online", {"lat": 7.05, "lng": 38.49}, drv)

st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, rider)
st, res = call("book_ride", {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash",
                              "quote_token": data(est).get("quote_token")}, rider)
rid = data(res).get("request_id")
check("a ride is booked", bool(rid), res)
if not rid:
    sys.exit(1)
call("driver_accept_ride", {"request_id": rid}, drv)
st, res = call("driver_start_ride", {"ride_id": rid}, drv)
check("the trip is started", res.get("success") is True, res)


def ping(lat, lng, acc, speed=None, back=0):
    """One position report; back = pretend the previous accepted point is that many seconds older."""
    if back:
        etel_test_db.run_sql("UPDATE rides SET last_location_at = DATE_SUB(last_location_at, INTERVAL %d SECOND) WHERE id=%d" % (back, rid))
    body = {"lat": lat, "lng": lng, "accuracy": acc, "ride_id": rid}
    if speed is not None:
        body["speed"] = speed
    st, res = call("driver_update_location", body, drv)
    return data(res).get("meter") or {}


BASE_LAT, BASE_LNG = 7.0500, 38.4900
M = 1.0 / 111000.0           # one metre in degrees of latitude

m = ping(BASE_LAT, BASE_LNG, 10)
check("the first fix sets the starting point at 0 km", m.get("traveled_km") == 0, m)

st, res = call("driver_toggle_wait", {"ride_id": rid}, drv)
w = data(res)
check("the waiting clock starts", w.get("wait_active") is True, res)
check("the answer carries the waiting rate per minute", float(w.get("wait_rate_per_min") or 0) > 0, w)
check("and the most minutes that are billed", float(w.get("wait_cap_minutes", -1)) >= 0, w)

m = ping(BASE_LAT + 30 * M, BASE_LNG, 25)
check("a parked phone drifting 30 m with 25 m accuracy adds no distance", m.get("traveled_km") == 0, m)
check("and does not stop the waiting clock", m.get("wait_active") is True and not m.get("wait_auto_stopped"), m)

m = ping(BASE_LAT + 40 * M, BASE_LNG, 10, speed=0.0)
check("40 m of drift while the GPS speed is 0 adds no distance", m.get("traveled_km") == 0, m)
check("and does not stop the waiting clock", m.get("wait_active") is True and not m.get("wait_auto_stopped"), m)

# two minutes of waiting so far: the waiting charge must be in the answer
# (the server stamps wait_started_at with PHP's clock, which runs on Africa/Addis_Ababa time, not the database's)
try:
    from zoneinfo import ZoneInfo
    now_php = datetime.datetime.now(ZoneInfo("Africa/Addis_Ababa")).strftime("%Y-%m-%d %H:%M:%S")
except Exception:
    now_php = (datetime.datetime.utcnow() + datetime.timedelta(hours=3)).strftime("%Y-%m-%d %H:%M:%S")
etel_test_db.run_sql("UPDATE rides SET wait_seconds_total=120, wait_started_at='%s' WHERE id=%d" % (now_php, rid))
m = ping(BASE_LAT + 10 * M, BASE_LNG, 10, speed=0.0)
check("the waiting charge (ETB) is part of the meter answer", float(m.get("wait_etb") or 0) > 0 and float(m.get("wait_rate_per_min") or 0) > 0, m)
check("the meter answer also carries the billing cap", float(m.get("wait_cap_minutes", -1)) >= 0, m)
check("it equals minutes x rate", abs(float(m["wait_etb"]) - round(float(m["wait_minutes"]) * float(m["wait_rate_per_min"]), 2)) < 0.06, m)

m = ping(BASE_LAT + 400 * M, BASE_LNG, 10, speed=9.0, back=60)
check("a real drive of 400 m at 9 m/s adds its distance", 0.35 <= float(m.get("traveled_km") or 0) <= 0.45, m)
check("and stops the waiting clock", m.get("wait_auto_stopped") is True and m.get("wait_active") is False, m)
check("the waiting charge stays in the bill", float(m.get("wait_etb") or 0) > 0, m)

km0 = float(m["traveled_km"])
m = ping(BASE_LAT + 430 * M, BASE_LNG, 10, speed=0.0, back=5)
check("30 m of drift at a standstill afterwards adds nothing", float(m.get("traveled_km")) == km0, m)
m = ping(BASE_LAT + 470 * M, BASE_LNG, 10, back=5)
check("a 70 m hop with no speed information is still credited", float(m.get("traveled_km")) > km0, m)

print("\n%s" % ("ALL PASS" if not fails else "FAILED: " + ", ".join(fails)))
sys.exit(1 if fails else 0)
