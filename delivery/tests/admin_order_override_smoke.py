"""
ETEL Delivery -- when staff move an order from the Admin Orders board, the customer's "Your order has been delivered! Pay ..." card must go away.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/admin_order_override_smoke.py

The courier's "please confirm" request (order_confirmations) stayed "pending" after admin_update_order, so the customer app kept the pay card and every tap
was refused ("not out for delivery"). Now the request follows the order, and stale requests are swept. Needs the seeded demo admin. Throwaway database only.
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


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    sg_phone = "+251955" + TAIL
    call("admin_create_service_giver", {"name": "Ovr SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Ovr Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    s, r = call("sg_add_item", {"name": "Ovr Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    s, r = call("register", {"name": "cust " + SUFFIX, "phone": cust_phone, "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token")
    cph = "+251977" + TAIL
    s, r = call("register", {"name": "courier " + SUFFIX, "phone": cph, "password": "testpass123", "role": "courier"})
    ctok = (r.get("data") or {}).get("token")
    etel_test_db.run_sql("UPDATE couriers c JOIN users u ON u.id = c.user_id SET c.approval_status = 'approved' WHERE u.phone = '%s'" % cph)
    s, r = call("login", {"phone": cph, "password": "testpass123"})
    ctok = (r.get("data") or {}).get("token") or ctok
    cid = sql1("SELECT c.id FROM couriers c JOIN users u ON u.id = c.user_id WHERE u.phone = '%s'" % cph)
    check("the test shop, customer and courier are ready", bool(sg and item and cust and ctok and cid))

    def new_order():
        s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "Rita", "recipient_phone": cust_phone,
                                              "payment_method": "cash"}, cust)
        oid = int((r.get("data") or {}).get("order_id"))
        etel_test_db.run_sql("UPDATE orders SET courier_id = %s, status = 'picked_up' WHERE id = %d" % (cid, int(oid)))
        s, r = call("request_delivery_confirmation", {"order_id": oid}, ctok)
        return oid, r

    def pending_ids():
        s, r = call("customer_pending_confirmations", {}, cust)
        return [int(c["order_id"]) for c in (r.get("data") or {}).get("confirmations", [])]

    def conf_status(oid):
        return sql1("SELECT status FROM order_confirmations WHERE order_id = %d AND type = 'delivery' ORDER BY id DESC LIMIT 1" % int(oid))

    # 1. the courier asks, the customer sees the pay card
    o1, r = new_order()
    check("the courier asks the customer to confirm", r.get("success") is True, r)
    check("the customer sees the 'delivered, pay now' card", o1 in pending_ids(), pending_ids())

    # 2. staff set the order to delivered from the Orders board
    s, r = call("admin_update_order", {"order_id": o1, "status": "delivered"}, admin)
    check("staff mark it delivered", r.get("success") is True, r)
    check("the card is gone for the customer", o1 not in pending_ids(), pending_ids())
    check("the courier's request is now confirmed, not pending", conf_status(o1) == "confirmed", conf_status(o1))
    check("the order assignment follows (delivered)", sql1("SELECT COUNT(*) FROM order_assignments WHERE order_id = %d AND status <> 'delivered'" % int(o1)) in ("0", ""), sql1("SELECT status FROM order_assignments WHERE order_id = %d" % int(o1)))
    s, r = call("customer_confirm_delivery", {"order_id": o1, "payment_confirmed": 1, "confirm_payment_method": "cash"}, cust)
    check("nothing is left to confirm for the customer (still refused, but no card to tap)", r.get("success") is False)

    # 3. an older stuck order (delivered by staff before this fix): the pending row is still there, the customer's list sweeps it
    o2, r = new_order()
    etel_test_db.run_sql("UPDATE orders SET status = 'delivered', delivered_at = NOW() WHERE id = %d" % int(o2))
    check("before the sweep the old request is still pending in the table", conf_status(o2) == "pending", conf_status(o2))
    check("the customer does not see a stuck card for an order that is already delivered", o2 not in pending_ids(), pending_ids())
    check("and the old request has been closed", conf_status(o2) == "confirmed", conf_status(o2))

    # 4. a cancelled order closes its request too
    o3, r = new_order()
    etel_test_db.run_sql("UPDATE orders SET status = 'cancelled' WHERE id = %d" % int(o3))
    check("no card for a cancelled order", o3 not in pending_ids(), pending_ids())
    check("its request is rejected", conf_status(o3) == "rejected", conf_status(o3))

    # 5. moving an order back rejects the open request; a normal order still gets its card
    o4, r = new_order()
    s, r = call("admin_update_order", {"order_id": o4, "status": "assigned"}, admin)
    check("staff can move an order back to assigned", r.get("success") is True, r)
    check("the open request is rejected (the courier asks again later)", conf_status(o4) == "rejected", conf_status(o4))
    o5, r = new_order()
    check("a normal order on its way still shows its card (nothing broke)", o5 in pending_ids(), pending_ids())
    s, r = call("customer_confirm_delivery", {"order_id": o5, "payment_confirmed": 1, "confirm_payment_method": "cash"}, cust)
    check("and the customer can still confirm it", r.get("success") is True, r)
    check("then the card is gone", o5 not in pending_ids(), pending_ids())
    s, r = call("admin_update_order", {"order_id": o5, "status": "delivered"}, admin)
    check("staff can mark an already confirmed order delivered again without trouble", r.get("success") is True, r)

    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
