"""
Ride tips: the rider can tip for a completed trip (one tip per trip, with the bank transfer reference), the tip waits as
'pending' and the driver gets nothing until an admin approves it; then the driver gets all of it (no commission), once.

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


def register(phone, role, name):
    st, res = call("register", {"name": name, "phone": phone, "password": "test1234", "role": role})
    d = data(res)
    return d.get("token"), d.get("user_id")


def q1(sql):
    ok, out, err = etel_test_db.query_text(sql)
    return out.strip() if ok else None


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("ride tips")
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)

rider, rider_id = register("+251943%s" % TAIL, "customer", "Tip Rider")
rider2, rider2_id = register("+251942%s" % TAIL, "customer", "Tip Rider 2")
drv, drv_id = register("+251941%s" % TAIL, "courier", "Tip Driver")
if not (rider and rider2 and drv):
    check("test users registered", False, (rider, rider2, drv))
    sys.exit(1)
call("driver_get_profile", {}, drv)
if not etel_test_db.onboard_driver(drv_id):
    print("SKIP: cannot mark the driver onboarded in the test database")
    sys.exit(0)
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")


def finish_ride(tok):
    call("driver_go_online", {"lat": 7.05, "lng": 38.49}, drv)
    st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, tok)
    trip = {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash", "quote_token": data(est).get("quote_token")}
    st, res = call("book_ride", trip, tok)
    rid = data(res).get("request_id")
    if not rid:
        return None, res
    call("driver_accept_ride", {"request_id": rid}, drv)
    call("driver_start_ride", {"ride_id": rid}, drv)
    call("driver_arrived", {"ride_id": rid}, drv)
    st, res = call("customer_confirm_arrival", {"ride_id": rid, "payment_method": "cash"}, tok)
    return (rid if res.get("success") else None), res


# a trip that is not finished cannot be tipped
st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, rider2)
st, res = call("book_ride", {"pickup_lat": 7.05, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash", "quote_token": data(est).get("quote_token")}, rider2)
open_id = data(res).get("request_id")
st, res = call("ride_tip_info", {"ride_id": open_id}, rider2)
check("a trip that is not completed cannot be tipped", data(res).get("eligible") is False, res)
st, res = call("ride_tip_give", {"ride_id": open_id, "amount": 20, "payment_ref": "REF-OPEN-%s" % TAIL}, rider2)
check("and a tip for it is refused", res.get("success") is False, (st, res))
call("cancel_ride", {"id": open_id}, rider2)

rid1, res = finish_ride(rider)
check("a trip is completed", bool(rid1), res)
if not rid1:
    sys.exit(1)

st, res = call("ride_tip_info", {"ride_id": rid1}, rider)
check("a completed trip can be tipped", data(res).get("eligible") is True and data(res).get("presets"), res)
check("the tip limit is shown", float(data(res).get("max", 0)) >= 10, res)
st, res = call("ride_tip_info", {"ride_id": rid1}, rider2)
check("another rider cannot tip for it", data(res).get("eligible") is False, res)
check("the tip screen says whether Chapa is on", data(call("ride_tip_info", {"ride_id": rid1}, rider)[1]).get("chapa") in (True, False), res)
st, res = call("ride_tip_chapa_init", {"ride_id": rid1, "amount": 20}, rider)
check("a Chapa tip is refused while Chapa is not set up", res.get("success") is False and st in (503, 502), (st, res))
st, res = call("ride_tip_chapa_init", {"ride_id": rid1, "amount": 20}, rider2)
check("another rider cannot start a Chapa tip for it", res.get("success") is False, (st, res))

bal0 = q1("SELECT total_earnings FROM ride_drivers WHERE user_id=%d" % int(drv_id))
st, res = call("ride_tip_give", {"ride_id": rid1, "amount": 3, "payment_ref": "REF-A-%s" % TAIL}, rider)
check("a tip under ETB 5 is refused", res.get("success") is False and st == 400, (st, res))
st, res = call("ride_tip_give", {"ride_id": rid1, "amount": 100000, "payment_ref": "REF-A-%s" % TAIL}, rider)
check("a huge tip is refused", res.get("success") is False and st == 400, (st, res))
st, res = call("ride_tip_give", {"ride_id": rid1, "amount": 20, "payment_ref": "x"}, rider)
check("a missing transfer reference is refused", res.get("success") is False and st == 400, (st, res))
st, res = call("ride_tip_give", {"ride_id": rid1, "amount": 20, "payment_ref": "REF-A-%s" % TAIL}, rider2)
check("another rider cannot tip for it", res.get("success") is False, (st, res))
st, res = call("ride_tip_give", {"ride_id": rid1, "amount": 20, "bank_key": "cbe", "payment_ref": "REF-A-%s" % TAIL}, rider)
tip1 = data(res).get("id")
check("the rider leaves a tip", res.get("success") is True and data(res).get("status") == "pending", res)
st, res = call("ride_tip_give", {"ride_id": rid1, "amount": 30, "payment_ref": "REF-B-%s" % TAIL}, rider)
check("only one tip per trip", res.get("success") is False and st == 409, (st, res))
st, res = call("ride_tip_info", {"ride_id": rid1}, rider)
check("the rider sees the tip is pending", (data(res).get("existing") or {}).get("status") == "pending", res)

bal1 = q1("SELECT total_earnings FROM ride_drivers WHERE user_id=%d" % int(drv_id))
check("the driver gets nothing before approval", bal0 is not None and bal0 == bal1, (bal0, bal1))
st, res = call("admin_ride_tip_review", {"id": tip1, "decision": "approve"}, rider)
check("a rider cannot approve", res.get("success") is False and st in (401, 403), (st, res))
st, res = call("admin_ride_tip_list", {"status": "pending"}, admin)
check("admin sees the tip waiting", any(t["id"] == tip1 for t in data(res).get("tips", [])) and data(res).get("pending_count", 0) >= 1, res)

st, res = call("admin_ride_tip_review", {"id": tip1, "decision": "approve", "note": "arrived"}, admin)
check("admin approves", res.get("success") is True and data(res).get("status") == "approved", res)
bal2 = q1("SELECT total_earnings FROM ride_drivers WHERE user_id=%d" % int(drv_id))
check("the driver gets ALL of it (+20, no commission)", bal0 is not None and bal2 is not None and abs(float(bal2) - float(bal0) - 20) < 0.01, (bal0, bal2))
st, res = call("admin_ride_tip_review", {"id": tip1, "decision": "approve"}, admin)
check("approving twice is refused", res.get("success") is False and st == 409, (st, res))
bal3 = q1("SELECT total_earnings FROM ride_drivers WHERE user_id=%d" % int(drv_id))
check("and the driver is not paid twice", bal3 == bal2, (bal2, bal3))
st, res = call("driver_get_tips", {}, drv)
check("the driver sees the tip", abs(float(data(res).get("total", 0)) - 20) < 0.01, res)

# a rejected tip adds nothing; a transfer reference cannot be reused
rid2, res = finish_ride(rider)
check("a second trip is completed", bool(rid2), res)
if rid2:
    st, res = call("ride_tip_give", {"ride_id": rid2, "amount": 15, "payment_ref": "REF-A-%s" % TAIL}, rider)
    check("a used transfer reference is refused", res.get("success") is False and st == 409, (st, res))
    st, res = call("ride_tip_give", {"ride_id": rid2, "amount": 15, "payment_ref": "REF-C-%s" % TAIL}, rider)
    tip2 = data(res).get("id")
    check("a tip for the second trip", res.get("success") is True, res)
    st, res = call("admin_ride_tip_review", {"id": tip2, "decision": "reject", "note": "no transfer found"}, admin)
    check("admin rejects it", res.get("success") is True and data(res).get("status") == "rejected", res)
    bal4 = q1("SELECT total_earnings FROM ride_drivers WHERE user_id=%d" % int(drv_id))
    check("a rejected tip adds nothing", bal4 == bal2, (bal2, bal4))

etel_test_db.run_sql("UPDATE pricing_rules SET rule_value=20 WHERE rule_key='ride_offer_wave_secs'")
if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All ride tip checks passed")
