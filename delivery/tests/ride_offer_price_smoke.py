"""
Rider price offer and driver counter-offers: the rider offers a price inside the allowed band, drivers accept it or
counter once, the rider picks one, the price is fixed and the trip is billed at exactly that price (the meter does not
change it), Etel's 5% is on the agreed fare, and a trip without an offer is untouched.

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

print("price offers and counter-offers")
rider, rider_id = register("+251961%s" % TAIL, "customer", "Offer Rider")
rider2, rider2_id = register("+251962%s" % TAIL, "customer", "Offer Rider 2")
d1, d1_id = register("+251963%s" % TAIL, "courier", "Offer Driver 1")
d2, d2_id = register("+251964%s" % TAIL, "courier", "Offer Driver 2")
if not (rider and rider2 and d1 and d2):
    check("test users registered", False, (rider, rider2, d1, d2))
    sys.exit(1)
for tok, uid in ((d1, d1_id), (d2, d2_id)):
    call("driver_get_profile", {}, tok)
    if not etel_test_db.onboard_driver(uid):
        print("SKIP: cannot mark the driver onboarded in the test database")
        sys.exit(0)
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")
etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_enabled', 1, 'test') ON DUPLICATE KEY UPDATE rule_value=1")
call("driver_go_online", {"lat": 7.05, "lng": 38.49}, d1)
call("driver_go_online", {"lat": 7.051, "lng": 38.491}, d2)

TRIP = {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash"}


def quote(tok):
    st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, tok)
    return float(data(est).get("fare") or 0), data(est).get("quote_token")


E, qt = quote(rider)
check("the quote is a positive fare", E > 0, E)
lo, hi = int(-(-E * 70 // 100)), int(E * 200 // 100)
offer = max(lo, int(round(E * 0.8)))

# --- booking with an offer -------------------------------------------------------------------------------------
st, res = call("book_ride", dict(TRIP, quote_token=qt, offer_fare=max(1, lo - 5)), rider)
check("an offer below the band is refused", res.get("success") is False and st == 422, (st, res))
st, res = call("book_ride", dict(TRIP, quote_token=qt, offer_fare=hi + 5), rider)
check("an offer above the band is refused", res.get("success") is False and st == 422, (st, res))
st, res = call("book_ride", dict(TRIP, quote_token=qt, offer_fare=offer), rider)
rid = data(res).get("request_id")
check("an offer inside the band books the trip", bool(rid), res)
if not rid:
    sys.exit(1)
check("and the booking answers with the offered price", abs(float(data(res).get("fare", 0)) - offer) < 0.01, res)
row = (q1("SELECT rider_offer, fare_final, fare_estimate, agreed_fare IS NULL FROM rides WHERE id=%d" % rid) or "").split("\t")
check("the ride stores the offer, shows it as the fare and keeps the quote", len(row) == 4 and float(row[0]) == offer and float(row[1]) == offer and abs(float(row[2]) - E) < 0.01 and row[3] == "1", row)

st, res = call("driver_get_nearby_requests", {}, d1)
mine = [r for r in data(res).get("requests", []) if r["id"] == rid]
check("drivers see the offered price as the fare", mine and abs(float(mine[0]["fare_final"]) - offer) < 0.01 and float(mine[0]["rider_offer"]) == offer, mine)

with urllib.request.urlopen(API_URL + "?action=sse_driver_stream&radius_km=8&once=1&token=" + d1, timeout=20) as f:
    body = f.read().decode()
check("the live driver stream carries the offer and the driver's own counter slot", '"rider_offer"' in body and '"my_counter"' in body, body[:300])

# --- counters ---------------------------------------------------------------------------------------------------
st, res = call("driver_counter_offer", {"request_id": rid, "price": offer}, d1)
check("a counter equal to the offer is refused (just accept)", res.get("success") is False and st == 422, (st, res))
st, res = call("driver_counter_offer", {"request_id": rid, "price": hi + 10}, d1)
check("a counter above the band is refused", res.get("success") is False and st == 422, (st, res))
c1 = min(hi, offer + 30)
st, res = call("driver_counter_offer", {"request_id": rid, "price": c1}, d1)
check("driver 1 counters", res.get("success") is True and data(res).get("status") == "pending", (st, res))
st, res = call("driver_counter_offer", {"request_id": rid, "price": min(hi, offer + 10)}, d1)
check("a driver can change their own counter", res.get("success") is True, (st, res))
c1 = min(hi, offer + 10)
c2 = min(hi, offer + 20)
st, res = call("driver_counter_offer", {"request_id": rid, "price": c2}, d2)
check("driver 2 counters too", res.get("success") is True, (st, res))
st, res = call("driver_get_nearby_requests", {}, d1)
mine = [r for r in data(res).get("requests", []) if r["id"] == rid]
check("a driver sees their own pending counter on the request", mine and float(mine[0]["my_counter"]) == c1, mine)

st, res = call("ride_counter_list", {"ride_id": rid}, rider)
lst = data(res).get("counters", [])
check("the rider sees both counters, cheapest first", len(lst) == 2 and lst[0]["price"] <= lst[1]["price"] and lst[0]["price"] == c1, res)
check("with the band and their own offer", data(res).get("min") == lo and data(res).get("max") == hi and data(res).get("rider_offer") == offer, data(res))
st, res = call("ride_counter_list", {"ride_id": rid}, rider2)
check("another rider cannot read them", res.get("success") is False and st == 404, (st, res))

# --- raising the offer --------------------------------------------------------------------------------------------
st, res = call("ride_raise_offer", {"ride_id": rid, "offer": offer - 1}, rider)
check("the offer cannot be lowered", res.get("success") is False and st == 422, (st, res))
st, res = call("ride_raise_offer", {"ride_id": rid, "offer": hi + 20}, rider)
check("nor raised above the band", res.get("success") is False and st == 422, (st, res))
raised = offer + 12
st, res = call("ride_raise_offer", {"ride_id": rid, "offer": raised}, rider)
check("the rider can raise it", res.get("success") is True and data(res).get("rider_offer") == raised, (st, res))
fare_now = q1("SELECT fare_final FROM rides WHERE id=%d" % rid)
check("drivers now see the raised price", fare_now and float(fare_now) == raised, fare_now)
st, res = call("ride_counter_list", {"ride_id": rid}, rider)
left = [c["price"] for c in data(res).get("counters", [])]
check("counters at or below the new offer are dropped", all(p > raised for p in left), left)

# --- choosing a driver ----------------------------------------------------------------------------------------------
c3 = min(hi, raised + 15)
st, res = call("driver_counter_offer", {"request_id": rid, "price": c3}, d2)
check("driver 2 counters again above the raised offer", res.get("success") is True, (st, res))
st, res = call("ride_counter_list", {"ride_id": rid}, rider)
cid = [c["id"] for c in data(res).get("counters", []) if c["price"] == c3]
check("the rider sees it", bool(cid), data(res))
if not cid:
    sys.exit(1)
st, res = call("ride_counter_accept", {"ride_id": rid, "counter_id": cid[0]}, rider2)
check("another rider cannot accept it", res.get("success") is False and st == 404, (st, res))
st, res = call("ride_counter_accept", {"ride_id": rid, "counter_id": cid[0]}, rider)
check("the rider accepts driver 2's counter", res.get("success") is True and data(res).get("agreed_fare") == c3, (st, res))
row = (q1("SELECT status, driver_id, agreed_fare, fare_final FROM rides WHERE id=%d" % rid) or "").split("\t")
check("the trip is assigned at that price", len(row) == 4 and row[0] == "accepted" and int(row[1]) == int(d2_id) and float(row[2]) == c3 and float(row[3]) == c3, row)
st, res = call("ride_counter_accept", {"ride_id": rid, "counter_id": cid[0]}, rider)
check("accepting twice is refused", res.get("success") is False and st == 409, (st, res))
st, res = call("driver_accept_ride", {"request_id": rid}, d1)
check("driver 1 can no longer take it", res.get("success") is False and st == 409, (st, res))

# --- the price is fixed at arrival ----------------------------------------------------------------------------------------
call("driver_start_ride", {"ride_id": rid}, d2)
etel_test_db.run_sql("UPDATE rides SET traveled_km=9.5 WHERE id=%d" % rid)   # a long meter reading must not change an agreed price
st, res = call("driver_arrived", {"ride_id": rid}, d2)
check("arrival bills the agreed price, not the meter", res.get("success") is True and abs(float(data(res).get("fare", 0)) - c3) < 0.01, (st, res))
bal0 = q1("SELECT commission_owed FROM ride_drivers WHERE user_id=%d" % int(d2_id))
st, res = call("customer_confirm_arrival", {"ride_id": rid, "payment_method": "cash"}, rider)
check("the rider pays the agreed price", res.get("success") is True and abs(float(data(res).get("fare_paid", 0)) - c3) < 0.01, res)
bal1 = q1("SELECT commission_owed FROM ride_drivers WHERE user_id=%d" % int(d2_id))
if bal0 is not None and bal1 is not None:
    check("Etel's 5% is on the agreed price", abs((float(bal1) - float(bal0)) - round(c3 * 0.05, 2)) < 0.02, (bal0, bal1))

# --- accepting the rider's price as it is -----------------------------------------------------------------------------------
E2, qt2 = quote(rider2)
offer2 = max(int(-(-E2 * 70 // 100)), int(round(E2 * 0.85)))
st, res = call("book_ride", dict(TRIP, quote_token=qt2, offer_fare=offer2), rider2)
rid2 = data(res).get("request_id")
check("a second rider books with an offer", bool(rid2), res)
st, res = call("driver_accept_ride", {"request_id": rid2}, d1)
check("a driver accepts the rider's price", res.get("success") is True, (st, res))
ag = q1("SELECT agreed_fare FROM rides WHERE id=%d" % int(rid2))
check("which becomes the agreed price", ag and float(ag) == offer2, ag)
call("driver_start_ride", {"ride_id": rid2}, d1)
etel_test_db.run_sql("UPDATE rides SET traveled_km=0.4 WHERE id=%d" % int(rid2))
st, res = call("driver_arrived", {"ride_id": rid2}, d1)
check("a short meter reading does not lower it", res.get("success") is True and abs(float(data(res).get("fare", 0)) - offer2) < 0.01, (st, res))
call("customer_confirm_arrival", {"ride_id": rid2, "payment_method": "cash"}, rider2)

# --- a trip without an offer is untouched ----------------------------------------------------------------------------------------
E3, qt3 = quote(rider)
st, res = call("book_ride", dict(TRIP, quote_token=qt3), rider)
rid3 = data(res).get("request_id")
check("a normal booking still works", bool(rid3), res)
st, res = call("driver_counter_offer", {"request_id": rid3, "price": int(E3) + 20}, d1)
check("counters are refused on a trip without an offer", res.get("success") is False and st == 409, (st, res))
call("driver_accept_ride", {"request_id": rid3}, d1)
ag = q1("SELECT agreed_fare IS NULL FROM rides WHERE id=%d" % int(rid3))
check("and it has no agreed price (the meter decides)", ag == "1", ag)
call("cancel_ride", {"id": rid3}, rider)

# --- switched off ----------------------------------------------------------------------------------------------------------------
etel_test_db.run_sql("UPDATE pricing_rules SET rule_value=0 WHERE rule_key='ride_offer_enabled'")
etel_test_db.run_sql("UPDATE rides SET status='cancelled' WHERE passenger_id IN (%d,%d) AND status IN ('searching','accepted','in_progress','arrived')" % (int(rider_id), int(rider2_id)))
E4, qt4 = quote(rider2)
st, res = call("book_ride", dict(TRIP, quote_token=qt4, offer_fare=int(E4)), rider2)
check("an admin can switch offers off", res.get("success") is False and st == 422, (st, res))
etel_test_db.run_sql("UPDATE pricing_rules SET rule_value=1 WHERE rule_key='ride_offer_enabled'")

if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All price-offer checks passed")
