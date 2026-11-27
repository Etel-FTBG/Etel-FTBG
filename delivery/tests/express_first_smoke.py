"""
ETEL Delivery -- the courier's job list shows Express packages first.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/express_first_smoke.py

The list is oldest first; an order that was booked with Express (orders.pkg_speed = 'express') goes to the top, and every job says express true/false.
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


def sql1(q):
    ok, out, _ = etel_test_db.query_text(q)
    return out.strip().split("\n")[-1] if ok and out.strip() else ""


def clear_limits():
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")


def make_courier(tag):
    ph = "+25198%s%s" % (tag, TAIL)
    s, r = call("register", {"name": "courier %s %s" % (tag, SUFFIX), "phone": ph, "password": "testpass123", "role": "courier"})
    if not (r.get("data") or {}).get("token"):
        clear_limits()
        call("register", {"name": "courier %s %s" % (tag, SUFFIX), "phone": ph, "password": "testpass123", "role": "courier"})
    etel_test_db.run_sql("UPDATE couriers c JOIN users u ON u.id = c.user_id SET c.status = 'online', c.active = 1, "
                         "c.vehicle_type = 'motorcycle', c.last_lat = 7.0500000, c.last_lng = 38.4800000, c.last_seen = NOW() WHERE u.phone = '%s'" % ph)
    s, r = call("login", {"phone": ph, "password": "testpass123"})
    tok = (r.get("data") or {}).get("token")
    cid = sql1("SELECT c.id FROM couriers c JOIN users u ON u.id = c.user_id WHERE u.phone = '%s'" % ph)
    uid = sql1("SELECT u.id FROM users u WHERE u.phone = '%s'" % ph)
    return tok, cid, uid


def place_order(sg, cust, cust_phone, item):
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "Test Person", "recipient_phone": cust_phone,
                                          "payment_method": "cash", "delivery_lat": 7.0600000, "delivery_lng": 38.4900000}, cust)
    return (r.get("data") or {}).get("order_id"), r


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    clear_limits()
    etel_test_db.park_couriers()
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'courier_auto_assign'")
    sg_phone = "+251955" + TAIL
    call("admin_create_service_giver", {"name": "Xfirst SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Xfirst Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    sgid = int(sql1("SELECT sg.id FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE u.phone = '%s'" % sg_phone))
    etel_test_db.run_sql("UPDATE service_givers SET lat = 7.0500000, lng = 38.4800000, is_online = 1, opens_at = '00:00:00', closes_at = '23:59:00' WHERE id = %d" % sgid)
    s, r = call("sg_add_item", {"name": "Xfirst Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    s, r = call("register", {"name": "cust " + SUFFIX, "phone": cust_phone, "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token")
    if not cust:
        clear_limits()
        s, r = call("register", {"name": "cust " + SUFFIX, "phone": cust_phone, "password": "testpass123", "role": "customer"})
        cust = (r.get("data") or {}).get("token")
    t1, c1, u1 = make_courier("1")
    check("the shop, the customer and an online courier are ready", bool(sg and item and cust and t1 and c1))
    # a quote makes sure the column orders.pkg_speed exists (the app adds it the first time a package is quoted)
    call("customer_package_quote", {"pickup": {"address": "A", "lat": 7.05, "lng": 38.48}, "drop": {"address": "B", "lat": 7.06, "lng": 38.49}, "size": "small", "weight_kg": 1}, cust)

    o1, r1 = place_order(sg, cust, cust_phone, item)
    o2, r2 = place_order(sg, cust, cust_phone, item)
    o3, r3 = place_order(sg, cust, cust_phone, item)
    o1, o2, o3 = int(o1), int(o2), int(o3)
    etel_test_db.run_sql("UPDATE orders SET placed_at = DATE_SUB(placed_at, INTERVAL 30 MINUTE) WHERE id = %d" % o1)
    etel_test_db.run_sql("UPDATE orders SET placed_at = DATE_SUB(placed_at, INTERVAL 20 MINUTE) WHERE id = %d" % o2)
    etel_test_db.run_sql("UPDATE orders SET placed_at = DATE_SUB(placed_at, INTERVAL 10 MINUTE) WHERE id = %d" % o3)

    def listed():
        s, r = call("courier_get_available_orders", {}, t1)
        return [(o["id"], o.get("express")) for o in (r.get("data") or {}).get("orders", []) if o["id"] in (o1, o2, o3)]

    base = listed()
    check("with no Express order the list is oldest first", [i for i, _ in base] == [o1, o2, o3], base)
    check("and every job says express false", all(e is False for _, e in base), base)
    etel_test_db.run_sql("UPDATE orders SET pkg_speed = 'express' WHERE id = %d" % o3)
    now = listed()
    check("an Express order that was booked last goes to the top", [i for i, _ in now] == [o3, o1, o2], now)
    check("it says express true, the others false", dict(now) == {o3: True, o1: False, o2: False}, now)
    etel_test_db.run_sql("UPDATE orders SET pkg_speed = 'express' WHERE id = %d" % o2)
    two = listed()
    check("two Express orders: they keep their own order (oldest first), then the rest", [i for i, _ in two] == [o2, o3, o1], two)
    etel_test_db.run_sql("UPDATE orders SET pkg_speed = 'standard' WHERE id IN (%d, %d)" % (o2, o3))
    check("back to standard: the list is oldest first again", [i for i, _ in listed()] == [o1, o2, o3])

    clear_limits()
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
