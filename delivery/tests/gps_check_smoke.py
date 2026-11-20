"""
Fake GPS checks, through the real server: a position that jumps farther than a vehicle can travel is recorded, a position the phone says is mocked is
recorded, with the owner's switch OFF the driver carries on, with it ON the driver is put offline and cannot go online until the owner clears the flags.
Uses the seeded demo accounts (the demo courier is given a ride-driver row). Everything is put back at the end.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import subprocess
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

BASE = os.environ.get("ETEL_API_BASE", "http://127.0.0.1:8765/api/")
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(BASE + "boot.php", data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("fake gps")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000002", "password": "etel1234"})
drv = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
cust = (r.get("data") or {}).get("token")
if not (admin and drv and cust):
    check("demo accounts can sign in", False, (bool(admin), bool(drv), bool(cust)))
    sys.exit(1)
UID = 3
etel_test_db.run_sql("DELETE FROM app_hub_settings WHERE setting_key = 'trust_block_fake_gps'")
etel_test_db.run_sql("DELETE FROM driver_gps_flags WHERE driver_user_id = %d" % UID)
had_row = etel_test_db.query_text("SELECT COUNT(*) FROM ride_drivers WHERE user_id = %d" % UID)[1].strip() not in ("", "0")
check("the demo courier can be made a ride driver", etel_test_db.onboard_driver(UID))
etel_test_db.run_sql("UPDATE ride_drivers SET insurance_expiry_date = NULL WHERE user_id = %d" % UID)


def flags():
    st, r = call("admin_driver_trust", {}, admin)
    g = (r.get("data") or {}).get("gps") or {}
    mine = [x for x in g.get("drivers", []) if x.get("user_id") == UID]
    return st, g, (mine[0] if mine else None)


st, r = call("driver_go_online", {"lat": 7.06, "lng": 38.47}, drv)
check("the driver goes online", st == 200, (st, r))
st, r = call("driver_update_location", {"lat": 7.06, "lng": 38.47, "accuracy": 10}, drv)
check("a normal ping is accepted", st == 200, (st, r))
st, g, mine = flags()
check("nothing is flagged yet", st == 200 and mine is None and g.get("block_fake") is False, (st, g))
time.sleep(1.5)
st, r = call("driver_update_location", {"lat": 7.30, "lng": 38.70, "accuracy": 10}, drv)
check("a ping 35 km away 1 second later is still accepted (a jump only warns)", st == 200, (st, r))
st, g, mine = flags()
check("but it is recorded as a position jump, with the speed in the detail", mine is not None and mine["jumps"] == 1 and mine["mocks"] == 0 and "km/h" in mine["last_detail"], mine)
st, r = call("driver_update_location", {"lat": 7.30, "lng": 38.70, "accuracy": 10, "mocked": True}, drv)
check("with the switch OFF a mocked ping is accepted", st == 200, (st, r))
st, g, mine = flags()
check("and recorded as a mock location", mine is not None and mine["mocks"] == 1, mine)
st, r = call("driver_update_location", {"lat": 7.30, "lng": 38.70, "accuracy": 10, "mocked": False}, drv)
check("a ping that says mocked: false is ordinary", st == 200, (st, r))

st, r = call("admin_hub_settings_save", {"settings": {"trust_block_fake_gps": "1"}}, cust)
check("a customer cannot switch the block on", st == 403, (st, r))
st, r = call("admin_hub_settings_save", {"settings": {"trust_block_fake_gps": "1"}}, admin)
check("the owner switches it on", st == 200, (st, r))
st, r = call("driver_update_location", {"lat": 7.30, "lng": 38.70, "accuracy": 10, "mocked": True}, drv)
check("with the switch ON a mocked ping is refused with a clear message", st == 403 and r.get("code") == "fake_gps" and "mock" in (r.get("message") or "").lower(), (st, r))
online = etel_test_db.query_text("SELECT online FROM ride_drivers WHERE user_id = %d" % UID)[1].strip()
check("and the driver is put offline", online == "0", online)
st, r = call("driver_go_online", {"lat": 7.06, "lng": 38.47}, drv)
check("the driver cannot go online again (mock sighting in the last 6 hours)", st == 403 and r.get("code") == "fake_gps", (st, r))
st, r = call("admin_gps_clear", {"driver_user_id": UID}, cust)
check("a customer cannot clear the flags", st == 403, (st, r))
st, r = call("admin_gps_clear", {"driver_user_id": UID}, admin)
check("the owner clears the flags", st == 200 and (r.get("data") or {}).get("cleared", 0) >= 2, (st, r))
st, r = call("driver_go_online", {"lat": 7.06, "lng": 38.47}, drv)
check("after clearing the driver can go online again", st == 200, (st, r))
st, g, mine = flags()
check("and is no longer in the list", mine is None and g.get("block_fake") is True, mine)

call("driver_go_offline", {}, drv)
etel_test_db.run_sql("DELETE FROM app_hub_settings WHERE setting_key = 'trust_block_fake_gps'")
etel_test_db.run_sql("DELETE FROM driver_gps_flags WHERE driver_user_id = %d" % UID)
if had_row:
    etel_test_db.run_sql("UPDATE ride_drivers SET online = 0 WHERE user_id = %d" % UID)
else:
    etel_test_db.run_sql("DELETE FROM ride_drivers WHERE user_id = %d" % UID)
print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
