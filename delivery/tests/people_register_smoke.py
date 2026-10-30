"""
Admin registers a person (admin_register_person): a customer, a courier (approved straight away, with vehicle and zone) or a driver (vehicle on the
profile, not yet onboarded). Checks the sign-in works, the profile rows are right, bad input and duplicate phones are refused, and only an admin
with users.manage may do it.

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
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)
# A customer token without the public sign-up (its IP rate limit is shared by the whole suite): register through the admin panel, then sign in.
call("admin_register_person", {"role": "customer", "name": "Reg Cust", "phone": "09199%s" % TAIL[-5:], "password": "test1234"}, admin)
st, res = call("login", {"phone": "09199%s" % TAIL[-5:], "password": "test1234"})
cust = data(res).get("token")

print("customer")
cphone = "09110%s" % TAIL[-5:]
st, res = call("admin_register_person", {"role": "customer", "name": "Almaz Tesfaye", "phone": cphone, "password": "custpass123"}, admin)
check("admin registers a customer", res.get("success") is True and data(res).get("user_id"), (st, res))
st, res = call("login", {"phone": cphone, "password": "custpass123"})
check("the customer signs in with the password admin typed", res.get("success") is True and data(res).get("token"), (st, res))
st, res = call("admin_register_person", {"role": "customer", "name": "Dup", "phone": cphone, "password": "custpass123"}, admin)
check("the same phone cannot be used twice", res.get("success") is False and st == 409, (st, res))

print("courier")
kphone = "09120%s" % TAIL[-5:]
st, res = call("admin_register_person", {"role": "courier", "name": "Dawit Bekele", "phone": kphone, "password": "courpass123", "vehicle_type": "bicycle", "zone": "Tabor"}, admin)
d = data(res)
check("admin registers a courier", res.get("success") is True and d.get("code", "").startswith("C-"), (st, res))
row = sql("SELECT CONCAT(c.vehicle_type, '|', IFNULL(c.home_zone, ''), '|', c.approval_status, '|', c.status) FROM couriers c JOIN users u ON u.id = c.user_id WHERE u.phone LIKE '%%%s'" % kphone[-9:])
check("the courier profile has vehicle and zone and is approved", row.startswith("bicycle|Tabor|approved|"), row)
st, res = call("admin_register_person", {"role": "courier", "name": "Bad", "phone": "09130%s" % TAIL[-5:], "password": "courpass123", "vehicle_type": "rocket"}, admin)
check("an unknown vehicle is refused", res.get("success") is False, res)

print("driver")
dphone = "09140%s" % TAIL[-5:]
st, res = call("admin_register_person", {"role": "driver", "name": "Kebede Alemu", "phone": dphone, "password": "drivpass123", "vehicle_type": "bajaj",
                                         "vehicle_make": "TVS", "vehicle_color": "Blue", "vehicle_year": 2021, "vehicle_plate": " et 12345 "}, admin)
check("admin registers a driver", res.get("success") is True, (st, res))
row = sql("SELECT CONCAT(r.vehicle_type, '|', r.vehicle_make, '|', r.vehicle_color, '|', r.vehicle_year, '|', r.vehicle_plate, '|', r.onboarded) FROM ride_drivers r JOIN users u ON u.id = r.user_id WHERE u.phone LIKE '%%%s'" % dphone[-9:])
check("the vehicle is saved (plate tidied) and the driver is not onboarded yet", row == "bajaj|TVS|Blue|2021|ET 12345|0", row)
st, res = call("admin_register_person", {"role": "driver", "name": "No Plate", "phone": "09150%s" % TAIL[-5:], "password": "drivpass123", "vehicle_type": "car"}, admin)
check("a driver needs a plate", res.get("success") is False, res)
st, res = call("admin_register_person", {"role": "driver", "name": "Old Car", "phone": "09160%s" % TAIL[-5:], "password": "drivpass123", "vehicle_type": "car", "vehicle_plate": "AA1", "vehicle_year": 1950}, admin)
check("an impossible vehicle year is refused", res.get("success") is False, res)

print("input and who may")
st, res = call("admin_register_person", {"role": "customer", "name": "Short Pw", "phone": "09170%s" % TAIL[-5:], "password": "abc"}, admin)
check("a short password is refused", res.get("success") is False, res)
st, res = call("admin_register_person", {"role": "customer", "name": "Bad Phone", "phone": "12345", "password": "custpass123"}, admin)
check("a bad phone is refused", res.get("success") is False, res)
st, res = call("admin_register_person", {"role": "admin", "name": "Sneaky", "phone": "09180%s" % TAIL[-5:], "password": "custpass123"}, admin)
check("this panel cannot make admins or shops", res.get("success") is False, res)
for who, tok in (("a customer", cust), ("signed out", None)):
    st, res = call("admin_register_person", {"role": "customer", "name": "Nope", "phone": "09190%s" % TAIL[-5:], "password": "custpass123"}, tok)
    check(who + " cannot register people", st in (401, 403) or res.get("success") is False, (st, res))

if fails:
    print("\nFAILED: %d" % len(fails))
    sys.exit(1)
print("\nOK")
