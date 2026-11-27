"""
ETEL Delivery -- photo proof of delivery for a SHOP order (a package has its own PIN flow, see package_smoke.py).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/shop_delivery_proof_smoke.py

The courier can add a small photo while the order is on the way (at the door) or within 2 hours after it is delivered, only for their own order; the customer who
ordered reads it back; nobody else can; it is never in the tracking answer. Needs the seeded demo admin. Throwaway database only.
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
    import base64, struct, zlib
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")

    def png_url(w, h, color=(200, 160, 40)):
        raw = b"".join(b"\x00" + bytes(color) * w for _ in range(h))
        def chunk(t, d):
            c = struct.pack(">I", len(d)) + t + d
            return c + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
        png = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")
        return "data:image/png;base64," + base64.b64encode(png).decode()

    sg_phone = "+251955" + TAIL
    call("admin_create_service_giver", {"name": "Prf SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Prf Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    s, r = call("sg_add_item", {"name": "Prf Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    def reg(phone, role, name):
        s, r = call("register", {"name": name + SUFFIX, "phone": phone, "password": "testpass123", "role": role})
        tok = (r.get("data") or {}).get("token")
        if role == "courier":
            etel_test_db.run_sql("UPDATE couriers c JOIN users u ON u.id = c.user_id SET c.approval_status = 'approved' WHERE u.phone = '%s'" % phone)
            s, r = call("login", {"phone": phone, "password": "testpass123"})
            tok = (r.get("data") or {}).get("token") or tok
        return tok
    cust_phone = "+251966" + TAIL
    cust = reg(cust_phone, "customer", "cust ")
    other = reg("+251944" + TAIL, "customer", "other ")
    ctok = reg("+251977" + TAIL, "courier", "courier ")
    c2tok = reg("+251978" + TAIL, "courier", "courier2 ")
    cid = sql1("SELECT c.id FROM couriers c JOIN users u ON u.id = c.user_id WHERE u.phone = '%s'" % ("+251977" + TAIL))
    check("the shop, two customers and two couriers are ready", bool(sg and item and cust and other and ctok and c2tok and cid))
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "Rita", "recipient_phone": cust_phone,
                                          "payment_method": "cash"}, cust)
    oid = int((r.get("data") or {}).get("order_id"))
    good = png_url(40, 40)

    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, ctok)
    check("a courier who does not have the order cannot add a photo", r.get("success") is False, r)
    etel_test_db.run_sql("UPDATE orders SET courier_id = %s, status = 'assigned' WHERE id = %d" % (cid, oid))
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, ctok)
    check("not yet picked up: no photo", r.get("success") is False, r)
    etel_test_db.run_sql("UPDATE orders SET status = 'in_transit' WHERE id = %d" % oid)
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, c2tok)
    check("another courier still cannot", r.get("success") is False and "not your" in str(r.get("message")).lower(), r)
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, cust)
    check("the customer cannot add it", r.get("success") is False)
    s, r = call("courier_package_proof", {"order_id": oid, "photo": "hello"}, ctok)
    check("not a picture: refused", r.get("success") is False, r)
    s, t0 = call("track_order", {"order_id": oid}, cust)
    check("before a photo the tracking answer says has_proof false", ((t0.get("data") or {}).get("order") or {}).get("has_proof") is False, ((t0.get("data") or {}).get("order") or {}).get("has_proof"))
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, ctok)
    check("on the way (at the door) the courier saves the photo", r.get("success") is True, r)
    s, r = call("customer_package_proof", {"order_id": oid}, cust)
    check("the customer reads the same photo back", (r.get("data") or {}).get("photo") == good and (r.get("data") or {}).get("taken_at"), str(r)[:100])
    s, r = call("customer_package_proof", {"order_id": oid}, other)
    check("another customer cannot read it", (r.get("data") or {}).get("photo") is None, str(r)[:100])
    s, t1 = call("track_order", {"order_id": oid}, cust)
    check("tracking says has_proof true and never carries the picture", ((t1.get("data") or {}).get("order") or {}).get("has_proof") is True and "data:image" not in json.dumps(t1))
    s, r = call("courier_package_proof", {"order_id": oid, "photo": png_url(30, 30, (10, 120, 200))}, ctok)
    s, r2 = call("customer_package_proof", {"order_id": oid}, cust)
    check("a second photo replaces the first", r.get("success") is True and (r2.get("data") or {}).get("photo") != good)
    # the app's database clock is Addis time; this client's is UTC
    etel_test_db.run_sql("UPDATE orders SET status = 'delivered', delivered_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR) WHERE id = %d" % oid)
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, ctok)
    check("right after delivery the courier can still change it", r.get("success") is True, r)
    etel_test_db.run_sql("UPDATE orders SET delivered_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE id = %d" % oid)
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, ctok)
    check("3 hours after delivery it is too late", r.get("success") is False and "late" in str(r.get("message")).lower(), r)
    etel_test_db.run_sql("UPDATE orders SET status = 'cancelled' WHERE id = %d" % oid)
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, ctok)
    check("a cancelled order cannot get a photo", r.get("success") is False, r)

    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
