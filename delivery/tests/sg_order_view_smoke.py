"""
ETEL Delivery -- what a SHOP sees of an order: the items and their value, never the delivery fee or the service charge (those are for the courier,
the company and the customer; the courier and the admin still see them). Also the shop's order detail: items, customer, and the courier once there is one.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/sg_order_view_smoke.py

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
    call("admin_create_service_giver", {"name": "View SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "View Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    s, r = call("sg_add_item", {"name": "View Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    cust = register(cust_phone, "customer")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        cust = register(cust_phone, "customer")
    check("the test customer signed up", bool(cust))
    if not cust:
        return 1
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 3}], "to_zone": "Tabor", "recipient_name": "Emu", "recipient_phone": "0936533606",
                                          "notes": "Address: Cheffe\nNo onions", "payment_method": "cash"}, cust)
    oid = (r.get("data") or {}).get("order_id")
    check("an order is placed (3 x 100 and a delivery fee)", r.get("success") is True and oid, r)
    row = etel_test_db.query_text("SELECT CONCAT(subtotal,'|',delivery_fee,'|',service_charge,'|',fare_total) FROM orders WHERE id=%d" % int(oid))[1].strip().split("|")
    sub, fee, svc, total = [float(x) for x in row]
    check("the order itself has a delivery fee (so the test means something)", fee > 0 and total == sub + fee + svc and sub == 300, row)

    print("1. The shop's order list")
    s, r = call("sg_orders", {}, sg)
    mine = [o for o in (r.get("data") or {}).get("orders", []) if str(o.get("order_id")) == str(oid)]
    o = mine[0] if mine else {}
    check("the list has the order", bool(mine), r)
    check("the list shows the items value (300), not the total with the delivery fee", float(o.get("fare_total", 0)) == 300 and float(o.get("items_total", 0)) == 300, o)
    check("the list carries no delivery fee and no service charge", "delivery_fee" not in o and "service_charge" not in o, list(o.keys()))
    check("the list carries no customer name, phone, address or drop-off area", not any(k in o for k in ("recipient_name", "recipient_phone", "recipient_address", "to_zone")), list(o.keys()))
    check("the customer's address is cut out of the note, the kitchen note stays", o.get("notes") == "No onions", o.get("notes"))

    print("2. The shop's order detail")
    s, r = call("sg_order_detail", {"order_id": oid}, sg)
    d = (r.get("data") or {}).get("order") or {}
    check("the detail loads with the order id in the request body", s == 200 and d.get("order_code"), r)
    check("the detail has the items with counts", len(d.get("items") or []) == 1 and int(d["items"][0]["quantity"]) == 3 and d["items"][0]["item_name"] == "View Meal", d.get("items"))
    check("the detail has the kitchen note but NOT the customer's name, phone or address", d.get("notes") == "No onions" and not any(k in d for k in ("recipient_name", "recipient_phone", "recipient_address", "to_zone")), d)
    check("... and the customer's details are nowhere in the answer", "Emu" not in json.dumps(d) and "0936533606" not in json.dumps(d) and "Cheffe" not in json.dumps(d), d)
    check("no courier yet", d.get("courier") is None, d.get("courier"))
    check("the detail shows the items value and no fees", float(d.get("fare_total", 0)) == 300 and "delivery_fee" not in d and "service_charge" not in d, d)
    check("the courier's id is not sent to the shop", "courier_id" not in d, list(d.keys()))

    print("3. The receipt after Preparing")
    s, r = call("sg_update_order_status", {"order_id": oid, "new_status": "preparing"}, sg)
    tk = (r.get("data") or {}).get("ticket") or {}
    check("the ticket comes back", r.get("success") is True and tk.get("order_code"), r)
    check("the ticket has no delivery fee and no service charge", "delivery_fee" not in tk and "service_charge" not in tk, list(tk.keys()))
    check("the ticket has no customer name, phone, address or drop-off area", not any(k in tk for k in ("recipient_name", "recipient_phone", "recipient_address", "to_zone", "from_zone")) and "Emu" not in json.dumps(tk) and "Cheffe" not in json.dumps(tk), tk)
    check("the ticket total is the items value (300)", float(tk.get("fare_total", 0)) == 300 and float(tk.get("subtotal", 0)) == 300, tk)
    s, r = call("sg_get_kitchen_ticket", {"order_id": oid}, sg)
    tk2 = (r.get("data") or {}).get("ticket") or {}
    check("a reprint is the same: no fees", "delivery_fee" not in tk2 and "service_charge" not in tk2 and float(tk2.get("fare_total", 0)) == 300, tk2)

    print("4. The courier and the admin still see the fee")
    cphone = "+251988" + TAIL
    cour = register(cphone, "courier")
    s, r = call("courier_accept_order", {"order_id": oid}, cour)
    check("a courier takes the order", r.get("success") is True, r)
    s, r = call("sg_order_detail", {"order_id": oid}, sg)
    d = (r.get("data") or {}).get("order") or {}
    c = d.get("courier") or {}
    check("the shop now sees who is bringing it (name, phone)", bool(c.get("name")) and bool(c.get("phone")), d.get("courier"))
    s, r = call("admin_orders_board", {"period": "all", "limit": 500}, admin)
    rows = [x for x in (r.get("data") or {}).get("orders", []) if str(x.get("id")) == str(oid)]
    check("the admin's order board still has the delivery fee", rows and float(rows[0].get("delivery_fee", -1)) == fee, rows[:1])
    s, r = call("courier_get_my_orders", {}, cour)
    mine_c = [x for x in (r.get("data") or {}).get("orders", []) if str(x.get("id")) == str(oid)]
    check("the courier's own list still has the delivery fee", mine_c and float(mine_c[0].get("delivery_fee", -1)) > 0, mine_c[:1])

    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
