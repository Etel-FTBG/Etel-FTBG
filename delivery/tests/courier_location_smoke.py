"""
ETEL Delivery -- the courier's position reaches the customer's tracking (courier_update_location -> track_order), over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/courier_location_smoke.py

Needs the seeded demo admin. Throwaway database only.
"""
import json
import random
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
TAIL = "%06d" % random.randint(0, 999999)
PASS, FAIL = [], []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=20) as f:
            return f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8"))
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def register(phone, role):
    s, r = call("register", {"name": role + " " + SUFFIX, "phone": phone, "password": "testpass123", "role": role})
    return (r.get("data") or {}).get("token")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    etel_test_db.park_couriers()
    sg_phone = "+251955" + TAIL
    call("admin_create_service_giver", {"name": "Loc SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Loc Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    s, r = call("sg_add_item", {"name": "Loc Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    cust = register(cust_phone, "customer")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        cust = register(cust_phone, "customer")
    check("the test customer signed up", bool(cust))
    if not cust:
        return 1
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "R", "recipient_phone": cust_phone,
                                          "payment_method": "cash", "delivery_lat": 7.06, "delivery_lng": 38.49}, cust)
    oid = (r.get("data") or {}).get("order_id")
    check("an order is placed", bool(oid), r)
    cour = register("+251988" + TAIL, "courier")
    s, r = call("courier_accept_order", {"order_id": oid}, cour)
    check("the courier accepts", r.get("success") is True, r)

    def order():
        s2, r2 = call("track_order", {"order_id": oid}, cust)
        return (r2.get("data") or {}).get("order") or {}

    o = order()
    check("before any position is sent the customer has no courier position", not o.get("courier_lat"), (o.get("courier_lat"), o.get("courier_lng")))
    s, r = call("courier_update_location", {"lat": 7.0512, "lng": 38.4812}, cour)
    check("the courier sends a position", s == 200 and r.get("success") is True, r)
    o = order()
    check("the customer's tracking now has it", abs(float(o.get("courier_lat") or 0) - 7.0512) < 1e-6 and abs(float(o.get("courier_lng") or 0) - 38.4812) < 1e-6, (o.get("courier_lat"), o.get("courier_lng")))
    s, r = call("courier_update_location", {"lat": 7.0555, "lng": 38.4855}, cour)
    o = order()
    check("a new position replaces the old one (the vehicle moves)", abs(float(o.get("courier_lat") or 0) - 7.0555) < 1e-6, o.get("courier_lat"))
    s, r = call("courier_update_location", {"lat": 7.05}, cour)
    check("a position without a longitude is refused", r.get("success") is False, r)
    s, r = call("courier_update_location", {"lat": 7.05, "lng": 38.48}, cust)
    check("a customer cannot send courier positions", r.get("success") is False, r)
    s, r = call("courier_update_location", {"lat": 7.05, "lng": 38.48})
    check("no login, no position", r.get("success") is False, r)
    etel_test_db.run_sql("UPDATE couriers SET last_seen = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE user_id = (SELECT id FROM users WHERE phone = '+251988%s')" % TAIL)
    o = order()
    check("a position older than 3 minutes is hidden (no stale pin on the map)", not o.get("courier_lat") and o.get("courier_stale") is True, (o.get("courier_lat"), o.get("courier_stale")))
    s, r = call("courier_update_location", {"lat": 7.0560, "lng": 38.4860}, cour)
    o = order()
    check("a fresh position brings the pin back", abs(float(o.get("courier_lat") or 0) - 7.056) < 1e-6, o.get("courier_lat"))
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
