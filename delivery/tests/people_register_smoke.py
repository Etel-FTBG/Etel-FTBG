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

print("driver desk (admin_register_driver)")
import struct, zlib


def tiny_png():
    raw = b"".join(b"\x00" + bytes([120, 80, 40]) * 8 for _ in range(8))
    def ch(t, d):
        c = struct.pack(">I", len(d)) + t + d
        return c + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", 8, 8, 8, 2, 0, 0, 0)) + ch(b"IDAT", zlib.compress(raw)) + ch(b"IEND", b"")


def upload(kind, token):
    boundary = "----etel" + SUFFIX
    body = ("--%s\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\n%s\r\n--%s\r\nContent-Disposition: form-data; name=\"image\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\n" % (boundary, kind, boundary)).encode() + tiny_png() + ("\r\n--%s--\r\n" % boundary).encode()
    req = urllib.request.Request(API_URL.replace("boot.php", "upload.php"), data=body, headers={"Content-Type": "multipart/form-data; boundary=" + boundary, "Authorization": "Bearer " + token}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return json.loads(f.read().decode()).get("data", {}).get("url", "")
    except Exception:
        return ""


selfie = upload("driver_selfie", admin)
licph = upload("driver_license", admin)
ins = upload("driver_insurance_photo", admin)
check("the admin can upload the selfie, licence and document photos", bool(selfie and licph and ins), (selfie, licph, ins))
drv = {"name": "Full Driver", "phone": "09210%s" % TAIL[-5:], "password": "drivpass123", "vehicle_type": "bajaj", "vehicle_make": "TVS", "vehicle_color": "Blue", "vehicle_year": 2021, "vehicle_plate": "et 7788",
       "license_name": "Full Driver", "license_number": "DL-99%s" % TAIL, "license_dob": "1990-05-17", "selfie_url": selfie, "license_photo_url": licph,
       "docs": {"insurance": {"expiry_date": "2030-01-31", "receipt_url": ins}}}
st, res = call("admin_register_driver", dict(drv, activate=1), admin)
check("admin registers and activates a full driver", res.get("success") is True and data(res).get("activated") is True and not data(res).get("missing"), (st, res))
row = sql("SELECT CONCAT(r.onboarded, '|', r.identity_match, '|', r.vehicle_plate, '|', r.license_number, '|', r.license_dob, '|', IFNULL(r.insurance_expiry_date, '')) FROM ride_drivers r JOIN users u ON u.id = r.user_id WHERE u.phone LIKE '%%%s'" % drv["phone"][-9:])
check("the driver is onboarded with licence, plate and the insurance paper recorded", row == "1|matched|ET 7788|DL-99%s|1990-05-17|2030-01-31" % TAIL, row)
st, res = call("login", {"phone": drv["phone"], "password": "drivpass123"})
check("the driver signs in", res.get("success") is True, (st, res))
role = sql("SELECT role FROM users WHERE phone LIKE '%%%s'" % drv["phone"][-9:])
check("and has the driver role", role == "driver", role)

d2 = dict(drv, phone="09220%s" % TAIL[-5:], license_number="", license_name="", license_dob="", license_photo_url="", selfie_url="", docs={})
st, res = call("admin_register_driver", d2, admin)
check("without activating, a driver can be saved for review with parts missing", res.get("success") is True and data(res).get("activated") is False and "selfie" in data(res).get("missing", []), (st, res))
row = sql("SELECT onboarded FROM ride_drivers r JOIN users u ON u.id = r.user_id WHERE u.phone LIKE '%%%s'" % d2["phone"][-9:])
check("and is not onboarded", row == "0", row)
st, res = call("admin_register_driver", dict(d2, phone="09230%s" % TAIL[-5:], activate=1), admin)
check("activating needs the licence and the selfie", res.get("success") is False, res)
st, res = call("admin_register_driver", dict(drv, phone="09240%s" % TAIL[-5:], selfie_url="http://x.test/uploads/items/a.png"), admin)
check("a picture from the wrong folder is refused", res.get("success") is False, res)
st, res = call("admin_register_driver", dict(drv, phone="09250%s" % TAIL[-5:], license_dob="2015-01-01"), admin)
check("a driver under 18 is refused", res.get("success") is False, res)
st, res = call("admin_register_driver", dict(drv, phone="09260%s" % TAIL[-5:], vehicle_type="rocket"), admin)
check("an unknown service is refused", res.get("success") is False, res)
st, res = call("admin_register_driver", dict(drv, phone="09270%s" % TAIL[-5:], docs={"insurance": {"expiry_date": "soon", "receipt_url": ins}}), admin)
check("a bad document date is refused", res.get("success") is False, res)
st, res = call("admin_register_driver", dict(drv, phone=drv["phone"]), admin)
check("the same phone cannot be used twice", res.get("success") is False and st == 409, (st, res))
st, res = call("admin_register_driver", dict(drv, phone="09280%s" % TAIL[-5:]), cust)
check("a customer cannot use the driver desk", st in (401, 403) or res.get("success") is False, (st, res))

if fails:
    print("\nFAILED: %d" % len(fails))
    sys.exit(1)
print("\nOK")
