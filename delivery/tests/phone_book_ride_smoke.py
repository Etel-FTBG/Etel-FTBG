"""
Admin books a ride for a caller who phoned in (admin_phone_book_ride): finds or creates the caller by phone, creates a 'searching' ride with the
right vehicle, fixed-distance or open-trip fare, and refuses bad input, a second active ride, a driver's number and non-admins.

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
TAIL = str(int(time.time()))[-5:]
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


def sql(q):
    ok, out, err = etel_test_db.query_text(q)
    return out.strip() if ok else ""


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin")
    sys.exit(0)

phone = "09144%s" % TAIL
st, res = call("admin_phone_book_ride", {"phone": phone, "name": "Caller Test", "vehicle_type": "bajaj", "km": 4, "open": 0, "pickup_note": "Near the stadium"}, admin)
check("action exists and books a fixed-distance ride", res.get("success") is True and data(res).get("ride_id"), (st, res))
rid = data(res).get("ride_id")
check("the caller account was created", data(res).get("new_account") == 1, res)
row = sql("SELECT status, vehicle_type, open_trip, distance_km, fare_final > 0, pickup_address FROM rides WHERE id=%d" % int(rid or 0))
check("the ride is searching, bajaj, fixed, 4 km, with a fare and the note", row.startswith("searching\tbajaj\t0\t4.00\t1\t") and "stadium" in row, row)
st, res = call("admin_phone_book_ride", {"phone": phone, "vehicle_type": "bajaj", "open": 1}, admin)
check("a caller with an active ride is refused", res.get("success") is False and st == 409, (st, res))
sql("UPDATE rides SET status='cancelled' WHERE id=%d" % int(rid or 0))
st, res = call("admin_phone_book_ride", {"phone": phone, "vehicle_type": "car", "open": 1}, admin)
check("an existing caller books an open trip", res.get("success") is True and data(res).get("new_account") == 0, (st, res))
rid2 = data(res).get("ride_id")
row = sql("SELECT open_trip, vehicle_type FROM rides WHERE id=%d" % int(rid2 or 0))
check("the open trip is flagged and has the car type", row.startswith("1\t"), row)
sql("UPDATE rides SET status='cancelled' WHERE id=%d" % int(rid2 or 0))
n = sql("SELECT COUNT(*) FROM users WHERE phone LIKE '%%%s'" % phone[-9:])
check("no duplicate account was made", n == "1", n)

st, res = call("admin_phone_book_ride", {"phone": "12345", "open": 1}, admin)
check("a bad phone is refused", res.get("success") is False and st == 400, (st, res))
st, res = call("admin_phone_book_ride", {"phone": "09155%s" % TAIL, "open": 0, "km": 0}, admin)
check("a fixed trip needs a distance", res.get("success") is False and st == 400, (st, res))
call("admin_register_person", {"role": "driver", "name": "Phone Drv", "phone": "09166%s" % TAIL, "password": "test1234", "vehicle_type": "bajaj", "vehicle_plate": "PB%s" % TAIL}, admin)
st, res = call("admin_phone_book_ride", {"phone": "09166%s" % TAIL, "open": 1}, admin)
check("a driver's number is refused", res.get("success") is False and st == 409, (st, res))
call("admin_register_person", {"role": "customer", "name": "Plain Cust", "phone": "09177%s" % TAIL, "password": "test1234"}, admin)
st, res = call("login", {"phone": "09177%s" % TAIL, "password": "test1234"})
cust = data(res).get("token")
st, res = call("admin_phone_book_ride", {"phone": "09188%s" % TAIL, "open": 1}, cust)
check("a customer cannot use it", res.get("success") is False and st in (401, 403), (st, res))

print("\n%s" % ("ALL PASS" if not fails else "%d FAILED: %s" % (len(fails), ", ".join(fails))))
sys.exit(1 if fails else 0)
