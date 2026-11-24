"""
ETEL Delivery -- the customer's tracking (track_order) follows the SHOP's own steps and shows the order details, over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/track_stage_smoke.py

The shop's "preparing" and "ready" are saved on the shop side (order_assignments), not on orders.status. The customer's timeline used to ignore them
(it stayed at "Assigned to service giver"). Now: placed -> being prepared -> ready for pickup -> (courier) picked up ..., and "Courier assigned" only
ticks when a courier really has the order. The customer also gets the items and the money lines; someone tracking with the recipient's phone gets the
items but not the prices. Needs the seeded demo admin. Throwaway database only.
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
    call("admin_create_service_giver", {"name": "Trk SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Trk Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    s, r = call("sg_add_item", {"name": "Track Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    cust = register(cust_phone, "customer")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        cust = register(cust_phone, "customer")
    guest_phone = "+251977" + TAIL
    guest = register(guest_phone, "customer")        # the person the food is FOR: tracks with the phone number the order was made for
    check("the test accounts signed up", bool(cust and guest))
    if not (cust and guest):
        return 1
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 2}], "to_zone": "Tabor", "recipient_name": "Emu", "recipient_phone": guest_phone,
                                          "notes": "Address: Cheffe", "payment_method": "cash"}, cust)
    oid = (r.get("data") or {}).get("order_id")
    check("an order is placed", r.get("success") is True and oid, r)

    def track(tok):
        s2, r2 = call("track_order", {"order_id": oid}, tok)
        d = r2.get("data") or {}
        tl = d.get("timeline") or []
        cur = [x for x in tl if x.get("current")]
        return d, tl, (cur[0] if cur else {}), {x["status"]: x for x in tl}

    print("1. Just placed")
    d, tl, cur, by = track(cust)
    o = d.get("order") or {}
    check("stage is pending, the current step is Order placed", o.get("stage") == "pending" and cur.get("label") == "Order placed", (o.get("stage"), cur))
    check("nothing after it is ticked", all(not x["done"] for x in tl if x["status"] != "pending"), tl)

    print("2. The shop starts preparing")
    s, r = call("sg_update_order_status", {"order_id": oid, "new_status": "preparing"}, sg)
    check("the shop presses Preparing", r.get("success") is True, r)
    d, tl, cur, by = track(cust)
    o = d.get("order") or {}
    check("the customer's stage is now preparing: Being prepared is the current step", o.get("stage") == "preparing" and cur.get("label") == "Being prepared", (o.get("stage"), cur))
    check("Order placed is ticked, but Courier assigned is NOT (no courier yet)", by["pending"]["done"] and not by["assigned"]["done"] and by["assigned"]["label"] == "Courier assigned", by)
    check("Ready for pickup is not ticked yet", not by["accepted"]["done"], by)

    print("3. The shop marks it ready")
    s, r = call("sg_update_order_status", {"order_id": oid, "new_status": "accepted"}, sg)
    check("the shop presses Ready for pickup", r.get("success") is True, r)
    d, tl, cur, by = track(cust)
    o = d.get("order") or {}
    check("the stage is accepted: Ready for pickup is the current step and Being prepared is ticked", o.get("stage") == "accepted" and cur.get("label") == "Ready for pickup" and by["preparing"]["done"], (o.get("stage"), cur))

    print("4. A courier takes it")
    cour = register("+251988" + TAIL, "courier")   # signed up only now: a courier who is online when the order is placed would be given it at once
    s, r = call("courier_accept_order", {"order_id": oid}, cour)
    check("the courier accepts", r.get("success") is True, r)
    d, tl, cur, by = track(cust)
    o = d.get("order") or {}
    check("the order status is assigned but the stage stays at Ready for pickup", o.get("status") == "assigned" and o.get("stage") == "accepted" and cur.get("label") == "Ready for pickup", (o.get("status"), o.get("stage"), cur))
    check("now Courier assigned is ticked", by["assigned"]["done"], by)

    print("4b. The map pin of the shop (for the live map)")
    check("the shop has no pin yet: pickup_point is empty and the raw columns are not sent", o.get("pickup_point") is None and "shop_lat" not in o and "pickup_lat" not in o, o)
    etel_test_db.run_sql("UPDATE service_givers SET lat = 7.0500000, lng = 38.4800000 WHERE business_name = 'Trk Kitchen'")
    d, tl, cur, by = track(cust)
    o = d.get("order") or {}
    check("with a pin, the customer gets the shop's point", (o.get("pickup_point") or {}).get("lat") == 7.05 and (o.get("pickup_point") or {}).get("lng") == 38.48, o.get("pickup_point"))
    check("the raw columns are still not sent", "shop_lat" not in o and "pickup_lat" not in o, sorted(o.keys()))

    print("5. Order details")
    items = o.get("items") or []
    check("the customer gets the items (2 x Track Meal)", len(items) == 1 and items[0].get("item_name") == "Track Meal" and int(items[0].get("quantity")) == 2, items)
    mo = o.get("money") or {}
    check("the customer gets the money lines: subtotal 200, delivery fee, service charge, total", mo.get("subtotal") == 200 and "delivery_fee" in mo and "service_charge" in mo and mo.get("fare_total") == mo["subtotal"] + mo["delivery_fee"] + mo["service_charge"], mo)
    check("the note and the recipient are there", o.get("notes") == "Address: Cheffe" and o.get("recipient_name") == "Emu", o)
    gd, _, _, _ = track(guest)
    go = gd.get("order") or {}
    check("the person the food is for sees the items and the same stage", [i.get("item_name") for i in go.get("items") or []] == ["Track Meal"] and go.get("stage") == "accepted", go)
    check("... but not the prices", "money" not in go, go.get("money"))
    s, r = call("track_order", {"order_id": oid}, admin)
    check("an unrelated account cannot track it", r.get("success") is False or not (r.get("data") or {}).get("order"), r)

    check("the courier taking the job did not overwrite the shop's step (assignment still ready)", etel_test_db.query_text("SELECT status FROM order_assignments WHERE order_id=%d" % int(oid))[1].strip() == "accepted")

    print("6. The other way round: a courier takes it BEFORE the shop starts")
    etel_test_db.park_couriers()
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "Emu", "recipient_phone": guest_phone, "payment_method": "cash"}, cust)
    oid2 = (r.get("data") or {}).get("order_id")
    cour2 = register("+251989" + TAIL, "courier")
    s, r = call("courier_accept_order", {"order_id": oid2}, cour2)
    check("a second courier takes the new order straight away", r.get("success") is True, r)
    s, r = call("track_order", {"order_id": oid2}, cust)
    d2 = r.get("data") or {}
    by2 = {x["status"]: x for x in d2.get("timeline") or []}
    check("Courier assigned is ticked while the shop has not started", by2["assigned"]["done"] and (d2.get("order") or {}).get("stage") == "assigned" and not by2["preparing"]["done"], d2.get("timeline"))
    check("the customer may still cancel (the shop has not started)", (d2.get("order") or {}).get("can_cancel") is True, (d2.get("order") or {}).get("can_cancel"))
    s, r = call("sg_update_order_status", {"order_id": oid2, "new_status": "preparing"}, sg)
    check("the shop can still press Preparing (it was stuck before)", r.get("success") is True, r)
    s, r = call("track_order", {"order_id": oid2}, cust)
    d2 = r.get("data") or {}
    cur2 = [x for x in d2.get("timeline") or [] if x.get("current")]
    check("the customer now sees Being prepared, with Courier assigned still ticked", cur2 and cur2[0]["label"] == "Being prepared" and {x["status"]: x for x in d2["timeline"]}["assigned"]["done"], d2.get("timeline"))
    check("and can no longer cancel (the shop has started)", (d2.get("order") or {}).get("can_cancel") is False, (d2.get("order") or {}).get("can_cancel"))

    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
