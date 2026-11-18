"""
ETEL app golden light: hub_counts must be REAL numbers from the database (no sample numbers).
A customer sees their own live trip and their orders on the way; a driver sees open ride requests and own trips; a courier sees
pending delivery offers and own deliveries; a service giver sees pending orders and own jobs; nobody is counted for another role.

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


def register(phone, role, name):
    st, res = call("register", {"name": name, "phone": phone, "password": "test1234", "role": role})
    d = res.get("data") or {}
    return d.get("token"), d.get("user_id")


def sql(q):
    return etel_test_db.run_sql("SET time_zone = '+03:00'; " + q)


def qid(sql_):
    ok_, out, _ = etel_test_db.query_text("SET time_zone = '+03:00'; " + sql_)
    return int(out.strip().split()[0]) if ok_ and out.strip() else 0


def _old_qid(sql):
    ok_, out, _ = etel_test_db.query_text(sql)
    return int(out.strip().split()[0]) if ok_ and out.strip() else 0


def counts(tok):
    st, res = call("hub_counts", {}, tok)
    d = res.get("data") or {}
    return st, tuple(int(d.get(k, -1)) for k in ("trips", "requests", "orders", "deliveries")), d


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("hub_counts: real numbers")
st, _ = call("hub_counts", {})
check("needs a signed-in user", st == 401, st)

cust_tok, cust = register("+251981%s" % TAIL, "customer", "Hub Cust")
drv_tok, drv = register("+251983%s" % TAIL, "courier", "Hub Driver")   # a ride driver with a courier-role account, as many bajaj drivers are
cou_tok, cou = register("+251984%s" % TAIL, "courier", "Hub Courier")
if not (cust_tok and drv_tok and cou_tok):
    check("test users registered", False, (cust_tok, drv_tok, cou_tok))
    sys.exit(1)

st, c, raw = counts(cust_tok)
check("a new customer has nothing going on (0,0,0,0)", st == 200 and c == (0, 0, 0, 0), raw)
check("the answer has all four counts and the total", all(k in raw for k in ("trips", "requests", "orders", "deliveries", "total")) and raw.get("total") == 0, raw)
st, c, raw = counts(cou_tok)
check("a new courier with nothing going on reads 0", c == (0, 0, 0, 0), raw)

# --- a customer books a ride: their own live trip is counted, for them only
call("driver_get_profile", {}, drv_tok)
etel_test_db.onboard_driver(drv)
call("driver_go_online", {"lat": 7.05, "lng": 38.49}, drv_tok)
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")
st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, cust_tok)
quote = (est.get("data") or {}).get("quote_token")
st, res = call("book_ride", {"pickup_lat": 7.0505, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash", "quote_token": quote}, cust_tok)
rid = (res.get("data") or {}).get("request_id")
check("ride booked", bool(rid), res)
st, c, raw = counts(cust_tok)
check("the customer sees 1 live trip, no requests", c == (1, 0, 0, 0), raw)
st, c, raw = counts(drv_tok)
check("the driver sees 1 open request", c[1] == 1 and c[0] == 0, raw)
st, c, raw = counts(cou_tok)
check("a courier who is not a ride driver does not see ride requests", c[1] == 0, raw)

# --- the driver accepts: the request becomes the driver's trip
st, acc = call("driver_accept_ride", {"request_id": rid}, drv_tok)
st, c, raw = counts(drv_tok)
check("after accepting: the driver has 1 trip and 0 requests", c[0] == 1 and c[1] == 0, (acc, raw))
st, c, raw = counts(cust_tok)
check("the customer still counts 1 trip", c[0] == 1, raw)

# --- the ride ends: nothing is counted any more
etel_test_db.run_sql("UPDATE rides SET status='completed' WHERE id=%d" % int(rid))
check("finished trip: customer 0", counts(cust_tok)[1][0] == 0)
check("finished trip: driver 0", counts(drv_tok)[1][0] == 0)
etel_test_db.run_sql("UPDATE rides SET status='cancelled' WHERE id=%d" % int(rid))

# --- orders and deliveries
ok1, _ = etel_test_db.run_sql("INSERT INTO couriers (user_id, courier_code, vehicle_type, status, active) VALUES (%d, 'HC%s', 'bike', 'online', 1) ON DUPLICATE KEY UPDATE active=1" % (cou, TAIL))
cid = qid("SELECT id FROM couriers WHERE user_id=%d" % cou)
etel_test_db.run_sql("INSERT INTO orders (order_code, customer_id, status) VALUES ('HO%s1', %d, 'pending')" % (TAIL, cust))
st, c, raw = counts(cust_tok)
check("customer with a pending order: 1 delivery in progress", c == (0, 0, 0, 1), raw)
oid = qid("SELECT id FROM orders WHERE order_code='HO%s1'" % TAIL)
sql("INSERT INTO order_courier_offers (order_id, courier_id, offered_at, expires_at) VALUES (%d, %d, NOW(), DATE_ADD(NOW(), INTERVAL 5 MINUTE))" % (oid, cid))
st, c, raw = counts(cou_tok)
check("courier with a live offer: 1 new order", c[2] == 1, raw)
sql("UPDATE order_courier_offers SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE order_id=%d" % oid)
check("an expired offer is not counted", counts(cou_tok)[1][2] == 0)
etel_test_db.run_sql("UPDATE orders SET courier_id=%d, status='picked_up' WHERE id=%d" % (cid, oid))
check("courier with a delivery in progress: 1 delivery", counts(cou_tok)[1][3] == 1)
etel_test_db.run_sql("UPDATE orders SET status='delivered' WHERE id=%d" % oid)
check("delivered: courier 0 and customer 0", counts(cou_tok)[1][3] == 0 and counts(cust_tok)[1][3] == 0)

# --- service giver
sg_tok, sg = cou_tok, cou
etel_test_db.run_sql("UPDATE users SET role='service_giver' WHERE id=%d" % sg)   # approval of a service giver changes the role (this account was the courier above)
etel_test_db.run_sql("INSERT INTO service_givers (user_id, service_giver_code) VALUES (%d, 'HS%s')" % (sg, TAIL))
sid = qid("SELECT id FROM service_givers WHERE user_id=%d" % sg)
etel_test_db.run_sql("INSERT INTO orders (order_code, customer_id, status) VALUES ('HO%s2', %d, 'pending')" % (TAIL, cust))
oid2 = qid("SELECT id FROM orders WHERE order_code='HO%s2'" % TAIL)
etel_test_db.run_sql("INSERT INTO order_assignments (order_id, service_giver_id, status) VALUES (%d, %d, 'pending')" % (oid2, sid))
check("service giver with a pending order: 1 new order", counts(sg_tok)[1][2] == 1, counts(sg_tok))
etel_test_db.run_sql("UPDATE order_assignments SET status='accepted' WHERE order_id=%d" % oid2)
c = counts(sg_tok)[1]
check("accepted: 0 new orders, 1 in progress", c[2] == 0 and c[3] == 1, c)

# leave nothing behind that changes other smokes (free couriers, open orders, drivers online)
etel_test_db.run_sql("UPDATE ride_drivers SET online = 0 WHERE user_id = %d" % drv)
etel_test_db.run_sql("UPDATE orders SET status = 'cancelled' WHERE order_code IN ('HO%s1', 'HO%s2')" % (TAIL, TAIL))
etel_test_db.run_sql("DELETE FROM order_courier_offers WHERE courier_id = %d" % cid)
etel_test_db.run_sql("UPDATE order_assignments SET status = 'cancelled' WHERE order_id = %d" % oid2)
etel_test_db.run_sql("UPDATE couriers SET status = 'offline' WHERE id = %d" % cid)

print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
