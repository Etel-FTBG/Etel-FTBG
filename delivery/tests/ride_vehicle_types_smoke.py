"""
Vehicle list data for the Book screen: per-type rates, pickup-to-driver ETA, and admin-added types.

Checks: ride_get_vehicle_types returns rates for every listed type; with a pickup point and an online driver it
returns an ETA for that driver's type; a type added in the Pricing panel (a ride_fare_rules row) is quoted and
booked as itself instead of silently becoming a bajaj.

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


def call(action, body=None, token=None, get=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if get is not None:
        url = API_URL + "?action=" + action + "".join("&%s=%s" % (k, v) for k, v in get.items())
        req = urllib.request.Request(url, headers=headers, method="GET")
    else:
        req = urllib.request.Request(API_URL, data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
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

print("ride vehicle types: rates, ETA, admin-added type")
tail = SUFFIX[-6:]
drv_tok, drv_id = register("+251976%s" % tail, "courier", "VT Driver")
cust_tok, _ = register("+251975%s" % tail, "customer", "VT Cust")
if not (drv_tok and cust_tok):
    check("test users registered", False)
    sys.exit(1)
call("driver_get_profile", {}, drv_tok)
if not etel_test_db.onboard_driver(drv_id):
    print("SKIP: cannot mark the driver onboarded in the test database")
    sys.exit(0)
call("driver_go_online", {"lat": 7.05, "lng": 38.49}, drv_tok)

st, res = call("ride_get_vehicle_types", token=cust_tok, get={"pickup_lat": "7.0505", "pickup_lng": "38.49"})
d = res.get("data") or {}
types = d.get("vehicle_types") or []
check("vehicle types listed", len(types) > 0, res)
rates = d.get("rates") or {}
check("every listed type has rates", all(t in rates and float(rates[t]["base"]) >= 0 and float(rates[t]["per_km"]) > 0 for t in types), rates)
eta = d.get("eta_min") or {}
vt = None
st2, prof = call("driver_get_profile", {}, drv_tok)
vt = ((prof.get("data") or {}).get("driver") or {}).get("vehicle_type") or (prof.get("data") or {}).get("vehicle_type")
vt = (vt or "bajaj").lower()
check("an online driver nearby gives an ETA for that type", vt in eta and int(eta[vt]) >= 1, (vt, eta))

st, res = call("ride_get_vehicle_types", token=cust_tok)
check("without a pickup point the list still works and has no ETA", (res.get("data") or {}).get("eta_min") == [] or (res.get("data") or {}).get("eta_min") == {}, res)

# a type added in the Pricing panel keeps its own name
etel_test_db.run_sql("INSERT INTO ride_fare_rules (vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active) "
                     "VALUES ('ev_car', 90, 31, 31, 3, 90, 90, 1) ON DUPLICATE KEY UPDATE active=1, base_fare=90")
st, res = call("ride_estimate_fare", token=cust_tok, get={"distance_km": "2", "vehicle_type": "ev_car"})
check("an admin-added type is quoted as itself", (res.get("data") or {}).get("vehicle_type") == "ev_car", res)
st, res = call("ride_estimate_fare", token=cust_tok, get={"distance_km": "2", "vehicle_type": "not_a_type"})
check("an unknown type still falls back to bajaj", (res.get("data") or {}).get("vehicle_type") == "bajaj", res)
etel_test_db.run_sql("DELETE FROM ride_fare_rules WHERE vehicle_type='ev_car' AND base_fare=90")

print("\nFAILED: %d" % len(fails) if fails else "\nAll vehicle type checks passed")
sys.exit(1 if fails else 0)
