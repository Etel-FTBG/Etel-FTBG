"""
ETEL Delivery -- package delivery over the real API (customer books, the right courier sees it, tracking, cancel, admin prices).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/package_smoke.py

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). Creates real rows: throwaway database only.
"""
import json
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
PASS, FAIL = [], []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as f:
            return f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8"))
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON error body)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:300]))


def register(phone, role, extra=None):
    body = {"name": "Pkg %s %s" % (role, SUFFIX), "phone": phone, "password": "testpass123", "role": role}
    body.update(extra or {})
    s, r = call("register", body)
    d = r.get("data") or {}
    return d.get("token")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("  SKIP no seeded demo admin")
        return 0
    etel_test_db.park_couriers()
    n = int(SUFFIX) % 1000000
    cust = register("+251914%06d" % n, "customer")
    other = register("+251915%06d" % n, "customer")
    moto = register("+251916%06d" % n, "courier", {"vehicle": "motorcycle"})
    check("test accounts exist", all([cust, other, moto]))

    A = {"address": "Tabor, Hawassa", "zone": "Tabor"}
    B = {"address": "University gate", "zone": "University"}
    pkg = {"pickup": A, "drop": B, "size": "small", "weight_kg": 2}

    print("1. Options and quote")
    s, r = call("customer_package_options", {}, token=None)
    check("options need a sign-in", r.get("success") is False)
    s, r = call("customer_package_options", {}, token=cust)
    d = r.get("data") or {}
    check("options list vehicles, sizes and the prohibited-items notice", r.get("success") and len(d.get("vehicles", [])) == 4 and len(d.get("sizes", [])) == 4 and "weapons" in d.get("prohibited", "").lower(), r)
    s, r = call("customer_package_quote", pkg, token=cust)
    d = r.get("data") or {}
    prices = [o["price"] for o in d.get("options", [])]
    check("quote gives distance, time and four rising prices", r.get("success") and d.get("distance_km", 0) > 1 and prices == sorted(prices) and len(prices) == 4, r)
    s, r = call("customer_package_quote", dict(pkg, weight_kg=0), token=cust)
    check("a quote with no weight is refused", r.get("success") is False)

    print("2. Booking")
    book = dict(pkg, vehicle_class="van", description="Books", recipient_name="Rita", recipient_phone="0911223344", ack_prohibited=True, payment_method="cash", weight_kg=30)
    s, r = call("customer_package_create", dict(book, ack_prohibited=False), token=cust)
    check("booking needs the prohibited-items tick", r.get("success") is False)
    s, r = call("customer_package_create", book, token=None)
    check("booking needs a sign-in", r.get("success") is False)
    s, r = call("customer_package_create", book, token=cust)
    d = r.get("data") or {}
    oid = d.get("order_id")
    check("a van package is booked", r.get("success") and oid and d.get("order_code", "").startswith("ETP-"), r)

    print("3. Who sees the job")
    s, r = call("courier_get_available_orders", {}, token=moto)
    ids = [o["id"] for o in (r.get("data") or {}).get("orders", [])]
    check("a motorbike courier does not see a van package", r.get("success") and oid not in ids, ids)
    s, r = call("courier_accept_order", {"order_id": oid}, token=moto)
    check("and cannot accept it either", r.get("success") is False and s == 403, r)
    van = register("+251917%06d" % n, "courier", {"vehicle": "van"})
    etel_test_db.park_couriers()
    s, r = call("courier_get_available_orders", {}, token=van)
    jobs = (r.get("data") or {}).get("orders", [])
    mine = [o for o in jobs if o["id"] == oid]
    check("a van courier sees it, with the package details", len(mine) == 1 and mine[0].get("order_kind") == "package" and mine[0].get("vehicle_class") == "van" and mine[0].get("pkg_desc") == "Books" and mine[0].get("pickup_address") == "Tabor, Hawassa", jobs[:1])
    s, r = call("courier_accept_order", {"order_id": oid}, token=van)
    check("the van courier accepts it", r.get("success") is True, r)

    s, r = call("customer_get_orders", {}, token=cust)
    mine = [o for o in (r.get("data") or {}).get("orders", []) if o.get("id") == oid]
    check("the order list marks it as a package", len(mine) == 1 and mine[0].get("order_kind") == "package", r)

    print("4. Tracking")
    s, r = call("track_order", {"order_id": oid}, token=cust)
    o = (r.get("data") or {}).get("order", {})
    tl = [t["label"] for t in (r.get("data") or {}).get("timeline", [])]
    check("the sender sees package details and both pins", r.get("success") and o.get("order_kind") == "package" and len(str(o.get("pickup_pin"))) == 4 and len(str(o.get("delivery_pin"))) == 4, o)
    check("the timeline has package steps, no shop steps", tl == ["Package booked", "Courier on the way to pick it up", "Package picked up", "On the way to the recipient", "Delivered"], tl)
    check("the sender may still cancel it", o.get("can_cancel") is True, o.get("can_cancel"))
    s, r = call("track_order", {"order_id": oid}, token=other)
    check("another customer cannot track it", r.get("success") is False)

    print("5. Cancel")
    s, r = call("customer_cancel_order", {"order_id": oid, "reason": "other"}, token=other)
    check("another customer cannot cancel it", r.get("success") is False)
    s, r = call("customer_cancel_order", {"order_id": oid, "reason": "changed_mind"}, token=cust)
    check("the sender cancels it", r.get("success") is True, r)

    print("6. Prices in Admin")
    s, r = call("admin_package_rates_get", {}, token=cust)
    check("a customer cannot read the price rules", r.get("success") is False)
    s, r = call("admin_package_rates_get", {}, token=admin)
    rates = {x["vehicle_class"]: x for x in (r.get("data") or {}).get("rates", [])}
    check("admin sees all four vehicles", r.get("success") and set(rates) == set(["bike", "moto", "car", "van"]), r)
    m = dict(rates["moto"]); old = float(m["base_fee"])
    s, r = call("admin_package_rates_save", dict(m, base_fee=old + 25), token=cust)
    check("a customer cannot change prices", r.get("success") is False)
    s, r = call("admin_package_rates_save", dict(m, base_fee=-5), token=admin)
    check("admin gets a clear error for a bad number", r.get("success") is False and "Base fee" in r.get("message", ""), r)
    s, r = call("admin_package_rates_save", dict(m, base_fee=old + 25), token=admin)
    s, q2 = call("customer_package_quote", pkg, token=cust)
    p2 = {o["vehicle_class"]: o["price"] for o in (q2.get("data") or {}).get("options", [])}
    check("a saved price shows in the next quote", r.get("success") and abs(p2["moto"] - (prices[1] + 25)) <= 1, (p2, prices))
    call("admin_package_rates_save", dict(m, base_fee=old), token=admin)

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
