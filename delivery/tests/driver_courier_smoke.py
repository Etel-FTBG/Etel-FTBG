"""
ETEL Delivery -- a ride driver's extra job (deliveries), over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/driver_courier_smoke.py

A verified ride driver asks for the courier job in the Driver app (driver_courier_apply). Until an admin approves in Admin -> Couriers he gets no
delivery job. On approval the same account becomes a courier account (the ride driver profile stays) and can take deliveries.
Needs the seeded demo admin. Creates real rows: throwaway database only.
"""
import json
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
PASS, FAIL = [], []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as f:
            return f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8"))
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def sql1(q):
    ok, out, _ = etel_test_db.query_text(q)
    return out.strip().split("\n")[-1] if ok and out.strip() else ""


def register(phone, role):
    # Self sign-up only makes customers and couriers; a ride driver account is made by an admin. The test makes one the same way in the database.
    s, r = call("register", {"name": role + " " + SUFFIX, "phone": phone, "password": "testpass123", "role": "customer" if role == "driver" else role})
    d = r.get("data") or {}
    if role == "driver" and d.get("user_id"):
        etel_test_db.run_sql("UPDATE users SET role='driver' WHERE id=%d" % int(d["user_id"]))
    return d.get("token"), d.get("user_id")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.park_couriers()

    print("1. Who can ask")
    d1, d1id = register("+251921" + SUFFIX[-6:], "driver")
    s, r = call("driver_courier_status", {}, d1)
    st = r.get("data") or {}
    check("a new driver has no courier job and cannot ask until verified", st.get("state") == "none" and st.get("can_apply") is False, st)
    s, r = call("driver_courier_apply", {}, d1)
    check("asking before the driver verification is done is refused", s == 400 and r.get("success") is False, (s, r))
    etel_test_db.onboard_driver(d1id)
    etel_test_db.run_sql("UPDATE ride_drivers SET vehicle_type='bajaj', vehicle_plate='1 SD 77777' WHERE user_id=%d" % int(d1id))
    s, r = call("driver_courier_status", {}, d1)
    st = r.get("data") or {}
    check("a verified ride driver can ask", st.get("can_apply") is True and st.get("state") == "none" and st.get("vehicle") == "bajaj", st)
    c1, c1id = register("+251922" + SUFFIX[-6:], "courier")
    s, r = call("driver_courier_apply", {}, c1)
    check("a courier account cannot ask (already has the job)", s == 400, (s, r))
    cust, _ = register("+251923" + SUFFIX[-6:], "customer")
    s, r = call("driver_courier_apply", {}, cust)
    check("a customer cannot ask", s == 400, (s, r))

    print("2. Waiting for the admin")
    s, r = call("driver_courier_apply", {}, d1)
    check("the driver's request is sent", r.get("success") is True and ((r.get("data") or {}).get("status") or {}).get("state") == "pending", r)
    s, r = call("driver_courier_apply", {}, d1)
    check("asking twice says it is already waiting", s == 400, (s, r))
    check("the courier row is pending and offline, with the vehicle and plate of the ride driver",
          sql1("SELECT CONCAT(approval_status,'/',status,'/',IFNULL(vehicle_type,''),'/',IFNULL(plate_number,'')) FROM couriers WHERE user_id=%d" % int(d1id)) == "pending/offline/bajaj/1 SD 77777")
    s, r = call("courier_get_available_orders", {}, d1)
    check("while waiting the driver cannot take deliveries", s in (401, 403) or r.get("success") is False, (s, r))
    s, r = call("admin_couriers_board", {}, admin)
    row = [c for c in (r.get("data") or {}).get("couriers", []) if c.get("user_id") == d1id]
    check("the admin sees the request in Couriers as waiting approval, marked as a ride driver", row and row[0].get("approval_status") == "pending" and row[0].get("ride_driver") is True, row)

    print("3. Admin approves")
    s, r = call("admin_courier_set_approval", {"id": d1id, "status": "approved"}, admin)
    check("the admin can approve the driver", r.get("success") is True and (r.get("data") or {}).get("now_courier") is True, r)
    check("the account is now a courier account and keeps its ride driver profile",
          sql1("SELECT role FROM users WHERE id=%d" % int(d1id)) == "courier" and sql1("SELECT COUNT(*) FROM ride_drivers WHERE user_id=%d" % int(d1id)) == "1")
    s, r = call("driver_courier_status", {}, d1)
    check("the status says approved", (r.get("data") or {}).get("state") == "approved" and (r.get("data") or {}).get("role") == "courier", r)
    s, r = call("courier_get_available_orders", {}, d1)
    check("the approved driver can now work as a courier", r.get("success") is True, (s, r))

    print("4. Not approved")
    d2, d2id = register("+251924" + SUFFIX[-6:], "driver")
    etel_test_db.onboard_driver(d2id)
    call("driver_courier_apply", {}, d2)
    s, r = call("admin_courier_set_approval", {"id": d2id, "status": "rejected", "note": "Need a clearer plate photo"}, admin)
    check("the admin can say no, with a reason", r.get("success") is True and (r.get("data") or {}).get("now_courier") is False, r)
    s, r = call("driver_courier_status", {}, d2)
    st = r.get("data") or {}
    check("the driver sees the answer and the reason, and stays a driver who can ask again", st.get("state") == "rejected" and "plate" in st.get("note", "") and st.get("role") == "driver" and st.get("can_apply") is True, st)
    s, r = call("driver_courier_apply", {}, d2)
    check("asking again puts the request back to waiting", r.get("success") is True and sql1("SELECT approval_status FROM couriers WHERE user_id=%d" % int(d2id)) == "pending", r)
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


def give_back_rate_limit_budget():
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")


if __name__ == "__main__":
    try:
        rc = main()
    finally:
        give_back_rate_limit_budget()
    sys.exit(rc)
