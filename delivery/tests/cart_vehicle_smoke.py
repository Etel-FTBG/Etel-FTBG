"""
ETEL Delivery -- the vehicle picked in the customer cart, over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/cart_vehicle_smoke.py

The cart lists the real vehicle types (get_delivery_tiers -> vehicles) with how many couriers are online for each. The vehicle the customer
picks is saved on the order (orders.vehicle_class), prices the delivery by its tier, and only couriers with that vehicle see / take it.
Needs the seeded demo admin. Creates real rows: throwaway database only.
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
            return e.code, {"success": False, "message": "(non-JSON)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def register(phone, role):
    s, r = call("register", {"name": role + " " + SUFFIX, "phone": phone, "password": "testpass123", "role": role})
    return (r.get("data") or {}).get("token")


def sql1(q):
    ok, out, _ = etel_test_db.query_text(q)
    return out.strip().split("\n")[-1] if ok and out.strip() else ""


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.park_couriers()
    print("1. The cart lists the real vehicles")
    s, r = call("get_delivery_tiers")
    d = r.get("data") or {}
    veh = {v["tier_key"]: v for v in d.get("vehicles") or []}
    check("get_delivery_tiers still lists the three tiers (old apps)", len(d.get("tiers") or []) >= 3, d)
    check("... and the real vehicles: bicycle, motorbike, car, van, each with online / registered counts and available",
          {"bike", "moto", "car", "van"} <= set(veh) and all("online" in v and "couriers" in v and "available" in v and v.get("label") for v in veh.values()), veh)
    tiers = {t["tier_key"]: float(t["multiplier"]) for t in d.get("tiers") or []}
    check("a car is priced by the car tier and a van by express cargo, a bicycle like a moto",
          abs(veh["car"]["multiplier"] - tiers["car"]) < 1e-6 and abs(veh["van"]["multiplier"] - tiers["express_cargo"]) < 1e-6 and abs(veh["bike"]["multiplier"] - tiers["moto"]) < 1e-6, (veh, tiers))

    print("2. Orders with a vehicle")
    sg_phone = "+251955" + SUFFIX[-6:]
    s, r = call("admin_create_service_giver", {"name": "Cart SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Cart Kitchen",
                                                "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    s, r = call("sg_add_item", {"name": "Cart Meal", "category": "food", "price": 100, "stock_quantity": 50}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + SUFFIX[-6:]
    cust = register(cust_phone, "customer")

    def order(extra):
        body = {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "R", "recipient_phone": cust_phone, "payment_method": "cash"}
        body.update(extra)
        s, r = call("customer_create_order", body, cust)
        return r

    base = order({})
    check("an order with no vehicle works as before (no vehicle saved)", base.get("success") is True, base)
    base_id = (base.get("data") or {}).get("order_id")
    base_fee = float(sql1("SELECT delivery_fee FROM orders WHERE id=%d" % int(base_id)) or 0)
    car = order({"delivery_tier": "car", "vehicle_class": "car"})
    car_id = (car.get("data") or {}).get("order_id")
    check("a car order is placed", car.get("success") is True, car)
    car_fee = float(sql1("SELECT delivery_fee FROM orders WHERE id=%d" % int(car_id)) or 0)
    check("the car order's delivery fee is the zone fee x the car tier", abs(car_fee - round(base_fee * tiers["car"])) <= 1 and car_fee > base_fee, (base_fee, car_fee))
    check("the order keeps the vehicle class and the priced tier", sql1("SELECT CONCAT(IFNULL(vehicle_class,'-'),'/',delivery_tier) FROM orders WHERE id=%d" % int(car_id)) == "car/car")
    bike = order({"delivery_tier": "bike", "vehicle_class": "bike"})
    bike_id = (bike.get("data") or {}).get("order_id")
    check("a bicycle order is priced like a moto and keeps the bike class", sql1("SELECT CONCAT(IFNULL(vehicle_class,'-'),'/',delivery_tier,'/',delivery_fee) FROM orders WHERE id=%d" % int(bike_id)).startswith("bike/moto/"))
    junk = order({"vehicle_class": "zz9"})
    junk_id = (junk.get("data") or {}).get("order_id")
    check("an unknown vehicle is ignored (no vehicle saved), the order still works", junk.get("success") is True and sql1("SELECT IFNULL(vehicle_class,'-') FROM orders WHERE id=%d" % int(junk_id)) == "-", junk)

    print("3. Only couriers with that vehicle see and take it")
    moto_phone = "+251977" + SUFFIX[-6:]; car_phone = "+251988" + SUFFIX[-6:]
    moto_t = register(moto_phone, "courier"); car_t = register(car_phone, "courier")
    cid = sql1("SELECT c.id FROM couriers c JOIN users u ON u.id = c.user_id WHERE u.phone = '%s'" % car_phone)
    s, r = call("admin_update_courier", {"courier_id": int(cid), "vehicle_type": "car"}, admin)
    check("admin can set a courier's vehicle to car", s == 200, r)
    s, r = call("courier_get_available_orders", {}, moto_t)
    ids = [str(o.get("id")) for o in (r.get("data") or {}).get("orders", [])]
    check("a motorbike courier sees the no-vehicle order but not the car order or the bicycle order", str(base_id) in ids and str(car_id) not in ids and str(bike_id) not in ids, ids)
    s, r = call("courier_accept_order", {"order_id": car_id}, moto_t)
    check("a motorbike courier cannot take the car order", s == 403 and r.get("success") is False, (s, r))
    s, r = call("courier_get_available_orders", {}, car_t)
    ids = [str(o.get("id")) for o in (r.get("data") or {}).get("orders", [])]
    check("a car courier sees the car order, and not the bicycle one", str(car_id) in ids and str(bike_id) not in ids, ids)
    s, r = call("courier_accept_order", {"order_id": car_id}, car_t)
    check("the car courier takes the car order", r.get("success") is True, (s, r))
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
