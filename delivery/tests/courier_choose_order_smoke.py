"""
ETEL Delivery -- a shop order is NOT given to one courier. Every online courier sees it in the Jobs tab and the first one to accept gets it.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/courier_choose_order_smoke.py

Before the fix, one online courier was given the order at once and never had a chance to see it. Needs the seeded demo admin. Throwaway database only.
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
    etel_test_db.run_sql("UPDATE couriers c JOIN users u ON u.id = c.user_id SET c.approval_status = 'approved', c.status = 'online', c.active = 1, "
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
    call("admin_create_service_giver", {"name": "Choose SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Choose Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    sgid = int(sql1("SELECT sg.id FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE u.phone = '%s'" % sg_phone))
    etel_test_db.run_sql("UPDATE service_givers SET lat = 7.0500000, lng = 38.4800000, is_online = 1, opens_at = '00:00:00', closes_at = '23:59:00' WHERE id = %d" % sgid)
    s, r = call("sg_add_item", {"name": "Choose Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    s, r = call("register", {"name": "cust " + SUFFIX, "phone": cust_phone, "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token")
    if not cust:
        clear_limits()
        s, r = call("register", {"name": "cust " + SUFFIX, "phone": cust_phone, "password": "testpass123", "role": "customer"})
        cust = (r.get("data") or {}).get("token")
    check("the test customer signed up", bool(cust))
    t1, c1, u1 = make_courier("1")
    t2, c2, u2 = make_courier("2")
    check("two online couriers are ready", bool(t1 and t2 and c1 and c2))

    # 1. default: nobody is given the order, both couriers see it
    oid, rr = place_order(sg, cust, cust_phone, item)
    check("the order was placed", bool(oid), rr)
    row = sql1("SELECT CONCAT(status, '|', IFNULL(courier_id, 'none')) FROM orders WHERE id = %d" % int(oid))
    check("the order is NOT given to a courier: still pending, no courier", row == "pending|none", row)
    check("neither courier was put on 'busy'", sql1("SELECT COUNT(*) FROM couriers WHERE id IN (%s,%s) AND status = 'busy'" % (c1, c2)) == "0")
    for tag, tok in (("first", t1), ("second", t2)):
        s, r = call("courier_get_available_orders", {}, tok)
        ids = [o.get("id") for o in ((r.get("data") or {}).get("orders") or [])]
        check("the %s courier sees the order in Jobs" % tag, int(oid) in [int(i) for i in ids], r)
    n = sql1("SELECT COUNT(*) FROM notifications WHERE user_id IN (%s,%s) AND title = 'New Delivery Job!'" % (u1, u2))
    check("both couriers were told (a push and an in-app notice)", n == "2", n)

    # 2. the first to accept gets it, the second is told it is taken
    s, r = call("courier_accept_order", {"order_id": oid}, t1)
    check("the first courier accepts", r.get("success") is True, r)
    row = sql1("SELECT CONCAT(status, '|', courier_id) FROM orders WHERE id = %d" % int(oid))
    check("the order is now theirs", row == "assigned|" + c1, row)
    s, r = call("courier_accept_order", {"order_id": oid}, t2)
    check("the second courier is refused (409)", s == 409 and r.get("success") is False, (s, r))
    s, r = call("courier_get_available_orders", {}, t2)
    ids = [int(o.get("id")) for o in ((r.get("data") or {}).get("orders") or [])]
    check("a taken order leaves the other courier's Jobs list", int(oid) not in ids, ids)

    # 3. a courier with another vehicle is not told about an order that needs a motorbike
    etel_test_db.run_sql("UPDATE couriers SET status = 'online' WHERE id IN (%s,%s)" % (c1, c2))
    etel_test_db.run_sql("UPDATE orders SET status = 'delivered' WHERE id = %d" % int(oid))
    etel_test_db.run_sql("UPDATE couriers SET vehicle_type = 'car' WHERE id = %s" % c2)
    oid2, rr2 = place_order(sg, cust, cust_phone, item)
    check("a second order was placed", bool(oid2), rr2)
    etel_test_db.run_sql("UPDATE orders SET vehicle_class = 'moto' WHERE id = %d" % int(oid2))
    s, r = call("courier_accept_order", {"order_id": oid2}, t2)
    check("a courier with the wrong vehicle cannot take it (403)", s == 403, (s, r))
    etel_test_db.run_sql("UPDATE orders SET status = 'cancelled' WHERE id = %d" % int(oid2))

    # 4. the admin switch: courier_auto_assign = 1 brings the old behaviour back
    etel_test_db.run_sql("UPDATE couriers SET vehicle_type = 'motorcycle', status = 'online' WHERE id IN (%s,%s)" % (c1, c2))
    etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('courier_auto_assign', 1, 'test') ON DUPLICATE KEY UPDATE rule_value = 1")
    oid3, rr3 = place_order(sg, cust, cust_phone, item)
    row = sql1("SELECT CONCAT(status, '|', IFNULL(courier_id, 'none')) FROM orders WHERE id = %d" % int(oid3))
    check("with courier_auto_assign = 1 the order is given to a courier at once (old way)", row.startswith("assigned|") and not row.endswith("none"), row)

    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'courier_auto_assign'")
    etel_test_db.run_sql("UPDATE orders SET status = 'cancelled' WHERE id IN (%d, %d)" % (int(oid3), int(oid)))
    etel_test_db.park_couriers()
    clear_limits()
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
