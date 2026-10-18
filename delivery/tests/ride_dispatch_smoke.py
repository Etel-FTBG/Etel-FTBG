"""
Driver request list: nearest pickup first, and Decline reaches the server.

Two onboarded drivers are online; two customers book rides, the NEARER pickup first (so it has the
lower id). Checks: the list puts the nearer pickup first; a driver who declines a request stops seeing it
(and cannot accept it) while the other driver still sees and can accept it; declining a ride that is not
searching records nothing.

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

print("ride dispatch: nearest first + decline")
tail = SUFFIX[-6:]
drvA_tok, drvA = register("+251971%s" % tail, "courier", "Disp A")
drvB_tok, drvB = register("+251972%s" % tail, "courier", "Disp B")
cust1_tok, _ = register("+251973%s" % tail, "customer", "Disp Cust Near")
cust2_tok, _ = register("+251974%s" % tail, "customer", "Disp Cust Far")
if not (drvA_tok and drvB_tok and cust1_tok and cust2_tok):
    check("test users registered", False, (drvA_tok, drvB_tok, cust1_tok, cust2_tok))
    sys.exit(1)

for tok, uid in ((drvA_tok, drvA), (drvB_tok, drvB)):
    call("driver_get_profile", {}, tok)  # creates the ride_drivers row
    if not etel_test_db.onboard_driver(uid):
        print("SKIP: cannot mark the drivers onboarded in the test database")
        sys.exit(0)
    call("driver_go_online", {"lat": 7.05, "lng": 38.49}, tok)


def book(tok, lat):
    st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, tok)
    quote = (est.get("data") or {}).get("quote_token")
    st, res = call("book_ride", {"pickup_lat": lat, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52,
                                 "payment_method": "cash", "quote_token": quote}, tok)
    return (res.get("data") or {}).get("request_id"), res


near_id, r1 = book(cust1_tok, 7.0505)   # about 55 m from the drivers, booked FIRST (lower id)
far_id, r2 = book(cust2_tok, 7.0700)    # about 2.2 km away, booked second (higher id)
check("both rides booked", bool(near_id) and bool(far_id), (r1, r2))
if not (near_id and far_id):
    sys.exit(1)


def listed(tok):
    st, res = call("driver_get_nearby_requests", {}, tok)
    return [int(r["id"]) for r in (res.get("data") or {}).get("requests", [])]


a = listed(drvA_tok)
check("driver A sees both requests", near_id in a and far_id in a, a)
check("nearer pickup is listed before the farther one", near_id in a and far_id in a and a.index(near_id) < a.index(far_id), a)

st, res = call("driver_decline_ride", {"request_id": far_id}, drvA_tok)
check("driver_decline_ride succeeds", res.get("success") is True, res)
a = listed(drvA_tok)
check("A no longer sees the declined request", far_id not in a and near_id in a, a)
b = listed(drvB_tok)
check("B still sees the request A declined", far_id in b, b)

st, res = call("driver_accept_ride", {"request_id": far_id}, drvA_tok)
check("A cannot accept a request they declined", res.get("success") is False, res)
st, res = call("driver_accept_ride", {"request_id": far_id}, drvB_tok)
check("B can accept it", res.get("success") is True, res)

st, res = call("driver_decline_ride", {"request_id": far_id}, drvA_tok)
check("declining a ride that is no longer searching is harmless", res.get("success") is True, res)
ok_q, out, _ = etel_test_db.query_text("SELECT COUNT(*) FROM ride_driver_exclusions WHERE ride_id=%d AND driver_id=%d" % (far_id, int(drvB)))
check("accepting driver B has no exclusion row", ok_q and out.strip() == "0", out)

call("cancel_ride", {"id": near_id}, cust1_tok)
call("cancel_ride", {"id": far_id}, cust2_tok)

print("\nFAILED: %d" % len(fails) if fails else "\nAll ride dispatch checks passed")
sys.exit(1 if fails else 0)
