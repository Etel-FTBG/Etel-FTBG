"""
Ride promo codes: anyone can propose one, it works only after an admin approves it, the discount is taken off the FINAL
fare, Etel (not the driver) pays for it, and a code is used up only when the trip completes.

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

print("ride promo codes")
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)

rider, rider_id = register("+251947%s" % TAIL, "customer", "Promo Rider")
rider2, rider2_id = register("+251948%s" % TAIL, "customer", "Promo Rider 2")
drv, drv_id = register("+251949%s" % TAIL, "courier", "Promo Driver")
if not (rider and rider2 and drv):
    check("test users registered", False, (rider, rider2, drv))
    sys.exit(1)
call("driver_get_profile", {}, drv)
if not etel_test_db.onboard_driver(drv_id):
    print("SKIP: cannot mark the driver onboarded in the test database")
    sys.exit(0)
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")
call("driver_go_online", {"lat": 7.05, "lng": 38.49}, drv)

CODE = "PROMO%s" % TAIL

# --- proposing -----------------------------------------------------------------------------------------------
st, res = call("ride_promo_propose", {"code": CODE, "discount_type": "percent", "value": 20, "max_discount": 30, "max_uses": 5}, rider)
check("a rider can propose a code", res.get("success") is True and data(res).get("status") == "pending", res)
pid = data(res).get("id")
st, res = call("ride_promo_propose", {"code": CODE, "discount_type": "percent", "value": 10}, rider2)
check("the same code cannot be proposed twice", res.get("success") is False and st == 409, (st, res))
st, res = call("ride_promo_propose", {"code": "BIG%s" % TAIL, "discount_type": "percent", "value": 90}, rider)
check("a proposal of 90% is refused", res.get("success") is False and st == 400, (st, res))
st, res = call("ride_promo_propose", {"code": "FIX%s" % TAIL, "discount_type": "fixed", "value": 5000}, rider)
check("a proposal of ETB 5000 off is refused", res.get("success") is False and st == 400, (st, res))
st, res = call("ride_promo_mine", {}, rider)
check("the proposer sees it as pending", any(p["code"] == CODE and p["status"] == "pending" for p in data(res).get("promos", [])), res)

# --- not usable until approved ---------------------------------------------------------------------------------
st, res = call("ride_promo_check", {"code": CODE, "fare": 200}, rider2)
check("a pending code is not valid", res.get("success") is False and st == 422, (st, res))
st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, rider)
quote = data(est).get("quote_token")
trip = {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash", "quote_token": quote}
st, res = call("book_ride", dict(trip, promo_code=CODE), rider)
check("booking with a pending code is refused", res.get("success") is False and st == 422, (st, res))
st, res = call("admin_ride_promo_review", {"id": pid, "decision": "approve"}, rider)
check("a rider cannot approve", res.get("success") is False and st in (403, 401), (st, res))

# --- admin approval ----------------------------------------------------------------------------------------------
st, res = call("admin_ride_promo_list", {"status": "pending"}, admin)
check("admin sees it waiting", any(p["code"] == CODE for p in data(res).get("promos", [])), res)
st, res = call("admin_ride_promo_review", {"id": pid, "decision": "approve", "note": "ok"}, admin)
check("admin approves", res.get("success") is True and data(res).get("status") == "approved", res)
st, res = call("admin_ride_promo_review", {"id": pid, "decision": "approve"}, admin)
check("approving twice is refused", res.get("success") is False and st == 409, (st, res))

# --- discount preview: 20% capped at ETB 30 ----------------------------------------------------------------------
st, res = call("ride_promo_check", {"code": CODE, "fare": 200}, rider2)
check("20% of 200 is capped at ETB 30", res.get("success") is True and abs(float(data(res).get("discount", 0)) - 30) < 0.01, res)
st, res = call("ride_promo_check", {"code": CODE.lower(), "fare": 100}, rider2)
check("the code is not case sensitive; 20% of 100 is 20", res.get("success") is True and abs(float(data(res).get("discount", 0)) - 20) < 0.01, res)
st, res = call("ride_promo_check", {"code": CODE, "fare": 12}, rider2)
check("a tiny fare never goes below ETB 10", res.get("success") is True and float(data(res).get("discount", 0)) <= 2.0 + 0.01, res)
st, res = call("ride_promo_check", {"code": "NOSUCHCODE", "fare": 100}, rider2)
check("an unknown code is refused", res.get("success") is False and st == 422, (st, res))

# --- a whole trip -------------------------------------------------------------------------------------------------
bal0 = q1("SELECT etel_money_balance FROM ride_drivers WHERE user_id=%d" % int(drv_id))
st, res = call("book_ride", dict(trip, promo_code=CODE), rider)
rid = data(res).get("request_id")
check("booking with the approved code works", bool(rid), res)
if not rid:
    sys.exit(1)
st, res = call("book_ride", dict(trip, promo_code=CODE), rider2)
rid2 = data(res).get("request_id")
check("a second rider can book with it too", bool(rid2), res)
call("cancel_ride", {"id": rid2}, rider2)
etel_test_db.run_sql("UPDATE rides SET status='cancelled' WHERE id=%d" % int(rid2 or 0))

st, res = call("driver_accept_ride", {"request_id": rid}, drv)
check("driver accepts", res.get("success") is True, res)
call("driver_start_ride", {"ride_id": rid}, drv)
st, res = call("driver_arrived", {"ride_id": rid}, drv)
check("driver ends the trip", res.get("success") is True and data(res).get("arrived"), res)
row = q1("SELECT fare_final, promo_discount, promo_net, promo_id IS NOT NULL FROM rides WHERE id=%d" % int(rid))
cols = (row or "").split("\t")
check("the ride kept the code", len(cols) == 4 and cols[3] == "1", row)
if len(cols) == 4:
    final, disc, net = float(cols[0]), float(cols[1]), float(cols[2])
    gross = final + disc
    expect = min(gross * 0.2, 30.0)
    check("the discount is 20% capped at 30 of the final fare", abs(disc - expect) < 0.02 and disc > 0, (gross, disc))
    check("fare_final is now what the rider pays", abs(net - final) < 0.01, (final, net))
    check("the driver is shown the discounted fare", abs(float(data(res).get("fare", 0)) - final) < 0.01, res)

    used0 = q1("SELECT used_count FROM ride_promo_codes WHERE id=%d" % int(pid))
    check("the code is not used up before the trip completes", used0 == "0", used0)
    st, res = call("customer_confirm_arrival", {"ride_id": rid, "payment_method": "cash"}, rider)
    check("rider pays", res.get("success") is True and abs(float(data(res).get("fare_paid", 0)) - final) < 0.01, res)
    bal1 = q1("SELECT etel_money_balance FROM ride_drivers WHERE user_id=%d" % int(drv_id))
    if bal0 is not None and bal1 is not None:
        change = float(bal1) - float(bal0)
        want = disc - round(gross * 0.05, 2)
        check("Etel pays the discount: balance = + discount - 5% of the FULL fare", abs(change - want) < 0.02, (change, want))
    used1 = q1("SELECT used_count FROM ride_promo_codes WHERE id=%d" % int(pid))
    check("completing the trip uses the code once", used1 == "1", used1)
    st, res = call("customer_confirm_arrival", {"ride_id": rid, "payment_method": "cash"}, rider)
    used2 = q1("SELECT used_count FROM ride_promo_codes WHERE id=%d" % int(pid))
    check("confirming again does not use it twice", used2 == "1", used2)

# --- one use per rider ------------------------------------------------------------------------------------------------
st, res = call("ride_promo_check", {"code": CODE, "fare": 100}, rider)
check("the same rider cannot use it again", res.get("success") is False and st == 422, (st, res))

# --- switching it off ---------------------------------------------------------------------------------------------------
st, res = call("admin_ride_promo_review", {"id": pid, "decision": "disable"}, admin)
check("admin disables it", res.get("success") is True and data(res).get("status") == "disabled", res)
st, res = call("ride_promo_check", {"code": CODE, "fare": 100}, rider2)
check("a disabled code is refused", res.get("success") is False and st == 422, (st, res))

# --- admin can create a code that works at once ------------------------------------------------------------------------
st, res = call("admin_ride_promo_save", {"code": "ADM%s" % TAIL, "discount_type": "fixed", "value": 25, "max_uses": 2}, admin)
check("admin creates an approved code", res.get("success") is True and data(res).get("status") == "approved", res)
st, res = call("ride_promo_check", {"code": "ADM%s" % TAIL, "fare": 150}, rider2)
check("and it works at once: ETB 25 off", res.get("success") is True and abs(float(data(res).get("discount", 0)) - 25) < 0.01, res)

etel_test_db.run_sql("UPDATE pricing_rules SET rule_value=20 WHERE rule_key='ride_offer_wave_secs'")
if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All ride promo checks passed")
