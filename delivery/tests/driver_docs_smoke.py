"""
Driver documents, through the real server: Driver trust lists a driver with an expired document, the owner's switch stops that driver going online
(and only while it is on), a renewed document lets the driver in again, and the cron reports its reminder count.
Uses the seeded demo accounts (the demo courier is given a ride-driver row). Everything is put back at the end.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import subprocess
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

print("driver documents")
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
etel_test_db.run_sql("DELETE FROM app_hub_settings WHERE setting_key = 'trust_block_expired_docs'")
had_row = etel_test_db.query_text("SELECT COUNT(*) FROM ride_drivers WHERE user_id = %d" % UID)[1].strip() not in ("", "0")
check("the demo courier can be made a ride driver", etel_test_db.onboard_driver(UID))
# make sure the date columns exist (the driver app creates them when Documents is opened)
call("driver_get_compliance_status", {}, drv)
etel_test_db.run_sql("UPDATE ride_drivers SET insurance_expiry_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE user_id = %d" % UID)

st, r = call("admin_driver_trust", {})
check("nobody signed in is refused", st == 401, (st, r))
st, r = call("admin_driver_trust", {}, cust)
check("a customer is refused", st == 403, (st, r))
st, r = call("admin_driver_trust", {}, admin)
d = (r.get("data") or {}).get("documents") or {}
mine = [x for x in d.get("drivers", []) if x.get("user_id") == UID]
check("an admin sees the driver with the expired insurance (3 days ago), switch off", st == 200 and d.get("block_expired") is False and d.get("columns_ready") is True and len(mine) == 1 and mine[0]["docs"]["insurance"]["state"] == "expired" and mine[0]["docs"]["insurance"]["days_left"] == -3, (st, str(r)[:300]))

st, r = call("driver_go_online", {"lat": 7.06, "lng": 38.47}, drv)
check("with the switch OFF the driver with an expired insurance can still go online", st == 200 and r.get("success") is True, (st, r))
call("driver_go_offline", {}, drv)
st, r = call("admin_hub_settings_save", {"settings": {"trust_block_expired_docs": "1"}}, cust)
check("a customer cannot switch it on", st == 403, (st, r))
st, r = call("admin_hub_settings_save", {"settings": {"trust_block_expired_docs": "1"}}, admin)
check("the owner switches it on", st == 200 and r.get("success") is True, (st, r))
st, r = call("driver_go_online", {"lat": 7.06, "lng": 38.47}, drv)
check("with the switch ON the driver cannot go online and is told which document", st == 403 and "insurance" in (r.get("message") or "") and r.get("code") == "docs_expired" and r.get("documents") == ["insurance"], (st, r))
etel_test_db.run_sql("UPDATE ride_drivers SET insurance_expiry_date = DATE_ADD(CURDATE(), INTERVAL 100 DAY) WHERE user_id = %d" % UID)
st, r = call("driver_go_online", {"lat": 7.06, "lng": 38.47}, drv)
check("after the renewal (a date in the future) the driver can go online again", st == 200 and r.get("success") is True, (st, r))
call("driver_go_offline", {}, drv)
st, r = call("admin_driver_trust", {}, admin)
d = (r.get("data") or {}).get("documents") or {}
check("the renewed driver is no longer listed; the switch shows ON", d.get("block_expired") is True and not [x for x in d.get("drivers", []) if x.get("user_id") == UID], d.get("block_expired"))
st, r = call("admin_hub_settings_save", {"settings": {"trust_block_expired_docs": "0"}}, admin)
check("the owner switches it off again", st == 200, (st, r))

# the cron reports its reminder count
etel_test_db.run_sql("UPDATE ride_drivers SET insurance_expiry_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY) WHERE user_id = %d" % UID)
etel_test_db.run_sql("DELETE FROM driver_doc_reminders WHERE driver_user_id = %d" % UID)
root = os.path.join(HERE, "..", "api")
out = subprocess.run(["php", os.path.join(root, "cron_ride_monitor.php")], capture_output=True, text=True, timeout=120, cwd=root)
try:
    j = json.loads(out.stdout)
except Exception:
    j = {"raw": out.stdout[:300], "err": out.stderr[:300]}
w = j.get("watch") or {}
check("the cron reports how many document reminders it sent (a number)", isinstance(w.get("doc_reminders"), int), j)
n1 = etel_test_db.query_text("SELECT COUNT(*) FROM driver_doc_reminders WHERE driver_user_id = %d" % UID)[1].strip()
out = subprocess.run(["php", os.path.join(root, "cron_ride_monitor.php")], capture_output=True, text=True, timeout=120, cwd=root)
n2 = etel_test_db.query_text("SELECT COUNT(*) FROM driver_doc_reminders WHERE driver_user_id = %d" % UID)[1].strip()
check("running the cron again does not repeat a reminder", n1 == n2, (n1, n2))

etel_test_db.run_sql("DELETE FROM app_hub_settings WHERE setting_key = 'trust_block_expired_docs'")
etel_test_db.run_sql("DELETE FROM driver_doc_reminders WHERE driver_user_id = %d" % UID)
if had_row:
    etel_test_db.run_sql("UPDATE ride_drivers SET insurance_expiry_date = NULL, online = 0 WHERE user_id = %d" % UID)
else:
    etel_test_db.run_sql("DELETE FROM ride_drivers WHERE user_id = %d" % UID)
print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
