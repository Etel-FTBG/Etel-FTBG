"""
ETEL Delivery -- the courier sees pickup km and dropoff km (and the two map points) for a job BEFORE accepting, over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/courier_job_geo_smoke.py

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


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    sg_phone = "+251955" + TAIL
    call("admin_create_service_giver", {"name": "Geo SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Geo Kitchen",
                                        "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    sgid = int(sql1("SELECT sg.id FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE u.phone = '%s'" % sg_phone))
    etel_test_db.run_sql("UPDATE service_givers SET lat = 7.0500000, lng = 38.4800000, is_online = 1, opens_at = '00:00:00', closes_at = '23:59:00' WHERE id = %d" % sgid)
    s, r = call("sg_add_item", {"name": "Geo Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    s, r = call("register", {"name": "cust " + SUFFIX, "phone": cust_phone, "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        s, r = call("register", {"name": "cust " + SUFFIX, "phone": cust_phone, "password": "testpass123", "role": "customer"})
        cust = (r.get("data") or {}).get("token")
    check("the test customer signed up", bool(cust))
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "Secret Name", "recipient_phone": cust_phone,
                                          "payment_method": "cash", "delivery_lat": 7.0600000, "delivery_lng": 38.4900000}, cust)
    oid = (r.get("data") or {}).get("order_id")
    check("the order was placed", bool(oid), r)
    # a courier registered AFTER the order, so nothing auto-assigns it
    cph = "+251977" + TAIL
    s, r = call("register", {"name": "courier " + SUFFIX, "phone": cph, "password": "testpass123", "role": "courier"})
    ctok = (r.get("data") or {}).get("token")
    etel_test_db.run_sql("UPDATE couriers c JOIN users u ON u.id = c.user_id SET c.approval_status = 'approved' WHERE u.phone = '%s'" % cph)
    s, r = call("login", {"phone": cph, "password": "testpass123"})
    ctok = (r.get("data") or {}).get("token") or ctok
    check("the courier is ready", bool(ctok))

    s, r = call("courier_job_geo", {"order_ids": [oid], "lat": 7.0400000, "lng": 38.4700000}, ctok)
    j = ((r.get("data") or {}).get("jobs") or {}).get(str(oid)) or {}
    check("the action answers", s == 200 and r.get("success") is True, r)
    check("pickup is the shop's pin", (j.get("pickup") or {}).get("lat") == 7.05 and (j.get("pickup") or {}).get("lng") == 38.48, j)
    check("dropoff is the customer's pin", (j.get("drop") or {}).get("lat") == 7.06 and (j.get("drop") or {}).get("lng") == 38.49, j)
    check("km from the courier to the pickup is about 2 (1.56 straight x road factor)", j.get("pickup_km") is not None and 1.5 < j["pickup_km"] < 3.5, j)
    check("km from the pickup to the dropoff is about 2", j.get("drop_km") is not None and 1.5 < j["drop_km"] < 3.5, j)
    check("the shop name is the pickup label", j.get("pickup_label") == "Geo Kitchen", j)
    s, r2 = call("courier_job_geo", {"order_ids": [oid]}, ctok)
    j2 = ((r2.get("data") or {}).get("jobs") or {}).get(str(oid)) or {}
    check("without the courier's position the pickup km is left out, the points stay", j2.get("pickup_km") is None and j2.get("pickup") and j2.get("drop_km") is not None, j2)
    check("the customer's name and phone are not in the answer", "Secret Name" not in json.dumps(r) and cust_phone not in json.dumps(r), r)
    s, r3 = call("courier_job_geo", {"order_ids": [oid]}, cust)
    check("a customer cannot use it", r3.get("success") is False, r3)
    s, r4 = call("courier_job_geo", {"order_ids": [oid]})
    check("no login, no answer", r4.get("success") is False, r4)
    check("a normal order is not flagged Express", j.get("express") is False, j)
    call("customer_package_quote", {"pickup": {"address": "A", "lat": 7.05, "lng": 38.48}, "drop": {"address": "B", "lat": 7.06, "lng": 38.49}, "size": "small", "weight_kg": 1}, cust)   # makes sure orders.pkg_speed exists
    etel_test_db.run_sql("UPDATE orders SET pkg_speed = 'express' WHERE id = %d" % int(oid))
    s, rx = call("courier_job_geo", {"order_ids": [oid]}, ctok)
    jx = ((rx.get("data") or {}).get("jobs") or {}).get(str(oid)) or {}
    check("an Express order is flagged express for the courier's job card", jx.get("express") is True, jx)
    etel_test_db.run_sql("UPDATE orders SET status = 'delivered' WHERE id = %d" % int(oid))
    s, r5 = call("courier_job_geo", {"order_ids": [oid]}, ctok)
    check("a finished job is not shown", not ((r5.get("data") or {}).get("jobs") or {}).get(str(oid)), r5)
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
