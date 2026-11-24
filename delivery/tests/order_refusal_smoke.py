"""
ETEL Delivery -- when an order is refused for a reason the customer can act on, the customer reads that reason (not "Order creation failed").

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/order_refusal_smoke.py

Covers: ordering from your own shop, items from two shops, an item that does not exist, a shop that is closed or has the item hidden.
Real faults (database errors) must still give the generic message. Needs the seeded demo admin. Throwaway database only.
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


def shop(admin, n):
    phone = "+25195%d" % n + TAIL[:5]
    call("admin_create_service_giver", {"name": "Refusal SG %d %s" % (n, SUFFIX), "phone": phone, "password": "testpass123", "business_name": "Refusal Kitchen %d" % n,
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": phone, "password": "testpass123"})
    tok = (r.get("data") or {}).get("token")
    s, r = call("sg_add_item", {"name": "Refusal Meal %d" % n, "category": "food", "price": 100, "stock_quantity": 50}, tok)
    return tok, (r.get("data") or {}).get("item_id")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    cust_phone = "+251966" + TAIL
    cust = register(cust_phone, "customer")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        cust = register(cust_phone, "customer")
    check("the test customer signed up", bool(cust))
    if not cust:
        return 1
    tok1, item1 = shop(admin, 1)
    tok2, item2 = shop(admin, 2)

    def order(token, items, extra=None):
        b = {"items": items, "to_zone": "Tabor", "recipient_name": "R", "recipient_phone": cust_phone, "payment_method": "cash"}
        b.update(extra or {})
        s2, r2 = call("customer_create_order", b, token)
        return r2

    print("1. Reasons the customer can act on")
    r1 = order(tok1, [{"item_id": item1, "quantity": 1}])
    check("ordering from your own shop says so", r1.get("success") is False and "own shop" in (r1.get("message") or ""), r1)
    r2 = order(cust, [{"item_id": item1, "quantity": 1}, {"item_id": item2, "quantity": 1}])
    check("items from two shops say so", r2.get("success") is False and "same service giver" in (r2.get("message") or ""), r2)
    r3 = order(cust, [{"item_id": 99999999, "quantity": 1}])
    check("an item that does not exist says so", r3.get("success") is False and "not found" in (r3.get("message") or ""), r3)
    check("none of them is the vague message", all("Order creation failed" not in (x.get("message") or "") for x in (r1, r2, r3)), [r1, r2, r3])
    etel_test_db.run_sql("UPDATE items SET is_available = 0 WHERE id = %s" % int(item1))
    r4 = order(cust, [{"item_id": item1, "quantity": 1}])
    check("a hidden or sold-out item says so", r4.get("success") is False and "Order creation failed" not in (r4.get("message") or "") and (r4.get("message") or ""), r4)
    etel_test_db.run_sql("UPDATE items SET is_available = 1 WHERE id = %s" % int(item1))

    print("2. Nothing else changed")
    r5 = order(cust, [{"item_id": item1, "quantity": 1}])
    check("a normal order still works", r5.get("success") is True, r5)
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
