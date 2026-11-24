"""
ETEL Delivery -- delivery fees by distance, one price list per vehicle, the 40/60 split and the free service charge, over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/delivery_fees_smoke.py

Needs the seeded demo admin. Creates real rows and changes the dfee_* rules (and puts them back): throwaway database only.
"""
import json
import math
import os
import random
import subprocess
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
TAIL = "%06d" % random.randint(0, 999999)   # phone digits: random, so this test can never clash with a test that uses the clock
PASS, FAIL = [], []
SHOP = (7.0600, 38.4760)          # a shop pin in Hawassa
NEAR = (7.0690, 38.4760)          # about 1.0 km north of the shop
FAR = (7.0870, 38.4760)           # about 3.0 km north of the shop


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


def km(a, b):
    r = 6371.0
    dlat = math.radians(b[0] - a[0]); dlng = math.radians(b[1] - a[1])
    h = math.sin(dlat / 2) ** 2 + math.cos(math.radians(a[0])) * math.cos(math.radians(b[0])) * math.sin(dlng / 2) ** 2
    return r * 2 * math.atan2(math.sqrt(h), math.sqrt(1 - h))


def round_to(v, step):
    return int(math.floor(v / step + 0.5) * step)


def expect(rate, straight_km, road=1.3, step=5):
    """The fee the rules promise: (base + per km x road km), never under the minimum, rounded to the step."""
    fee = max(rate[2], rate[0] + rate[1] * straight_km * road)
    fee = round_to(fee, step)
    return max(fee, int(math.ceil(rate[2])))


LAST_REGISTER = {}


def register(phone, role):
    s, r = call("register", {"name": role + " " + SUFFIX, "phone": phone, "password": "testpass123", "role": role})
    LAST_REGISTER.clear(); LAST_REGISTER.update({"status": s, "answer": r})
    return (r.get("data") or {}).get("token")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key LIKE 'dfee\\_%'")
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")   # earlier smokes in the same run may have used up the sign-up limit

    print("1. The admin page data")
    s, r = call("admin_dfee_get", {}, admin)
    d = r.get("data") or {}
    veh = {v["class"]: v for v in d.get("vehicles") or []}
    st = d.get("settings") or {}
    check("starts on, the company keeps 40 percent, the service charge is free", st.get("enabled") == 1 and st.get("company_pct") == 40 and st.get("service_pct") == 0, st)
    check("every real vehicle is priced: bicycle, motorbike, car, van", {"bike", "moto", "car", "van"} <= set(veh), list(veh))
    check("the bicycle starts at 45 ETB + 15 ETB per km, minimum 60", veh.get("bike", {}).get("base") == 45 and veh["bike"].get("per_km") == 15 and veh["bike"].get("min") == 60, veh.get("bike"))
    check("each vehicle shows its couriers", all("couriers" in v and "online" in v and v.get("label") for v in veh.values()), veh)
    check("prices grow with the size of the vehicle", veh["bike"]["base"] < veh["moto"]["base"] < veh["car"]["base"] < veh["van"]["base"], [veh[k]["base"] for k in ("bike", "moto", "car", "van")])

    print("2. Only an admin can read or change the fees")
    cust_phone = "+251966" + TAIL
    cust = register(cust_phone, "customer")
    if not cust:   # the sign-up limit may have been used up by earlier tests in the run: clear it and try once more
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        cust = register(cust_phone, "customer")
    check("the test customer signed up", bool(cust), LAST_REGISTER)
    if not cust:
        return 1
    s, r = call("admin_dfee_get", {}, cust)
    check("a customer cannot read the admin fee page", s in (401, 403) and r.get("success") is False, (s, r))
    s, r = call("admin_dfee_save", {"settings": {"company_pct": 0}}, cust)
    check("a customer cannot change the fees", s in (401, 403) and r.get("success") is False, (s, r))
    s, r = call("admin_dfee_save", {"settings": {"company_pct": 0}})
    check("nobody signed in cannot change the fees", s in (401, 403) and r.get("success") is False, (s, r))
    s, r = call("admin_dfee_get", {}, admin)
    check("... and nothing changed", ((r.get("data") or {}).get("settings") or {}).get("company_pct") == 40, r)

    print("3. Saving and reading back")
    rates = {"bike": [45, 15, 60], "moto": [55, 18, 75], "car": [80, 28, 120], "van": [120, 40, 180]}
    body = {"settings": {"enabled": 1, "company_pct": 40, "service_pct": 0, "road_factor": 1, "round_step": 1, "max_km": 0},
            "vehicles": {k: {"base": v[0], "per_km": v[1], "min": v[2]} for k, v in rates.items()}}
    s, r = call("admin_dfee_save", body, admin)
    d = r.get("data") or {}
    check("the save is accepted and says so in words", s == 200 and "Saved" in (d.get("message") or ""), r)
    check("the answer is read back from the database (road factor 1, round step 1)", (d.get("settings") or {}).get("road_factor") == 1 and (d.get("settings") or {}).get("round_step") == 1, d.get("settings"))
    check("the bicycle price list was saved", sql1("SELECT CONCAT(rule_value) FROM pricing_rules WHERE rule_key='dfee_bike_per_km'") in ("15", "15.00"), sql1("SELECT rule_value FROM pricing_rules WHERE rule_key='dfee_bike_per_km'"))
    s, r = call("admin_dfee_save", {"settings": {"company_pct": 150, "service_pct": -2, "road_factor": 9}}, admin)
    st = (r.get("data") or {}).get("settings") or {}
    check("absurd numbers are pulled into range (company 100, service 0, road factor 2)", st.get("company_pct") == 100 and st.get("service_pct") == 0 and st.get("road_factor") == 2, st)
    s, r = call("admin_dfee_save", dict(body), admin)

    print("4. The cart quote")
    sg_phone = "+251955" + TAIL
    s, r = call("admin_create_service_giver", {"name": "Fee SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Fee Kitchen",
                                                "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    sgid = sql1("SELECT sg.id FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE u.phone = '%s'" % sg_phone)
    s, r = call("sg_add_item", {"name": "Fee Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    q = lambda p: call("delivery_fee_quote", {"item_id": item, "lat": p[0], "lng": p[1]})
    s, r = q(FAR)
    d = r.get("data") or {}
    check("a shop with no pin cannot be priced by distance: the old zone fee is kept", s == 200 and d.get("mode") == "zone" and not d.get("vehicles"), d)
    etel_test_db.run_sql("UPDATE service_givers SET lat=%s, lng=%s WHERE id=%s" % (SHOP[0], SHOP[1], sgid))
    s, r = q(FAR)
    d = r.get("data") or {}
    dist = km(SHOP, FAR)
    check("with both pins the quote is by distance", s == 200 and d.get("mode") == "distance", d)
    fees = {k: v["fee"] for k, v in (d.get("vehicles") or {}).items()}
    check("the bicycle fee is 45 + 15 per km (about 3 km)", fees.get("bike") == expect(rates["bike"], dist, 1, 1), (fees, dist))
    check("every vehicle has its own fee, bigger vehicles cost more", all(k in fees for k in rates) and fees["bike"] < fees["moto"] < fees["car"] < fees["van"], fees)
    check("a car fee follows the car price list", fees.get("car") == expect(rates["car"], dist, 1, 1), fees)
    check("the quote tells the cart the service charge percent (free = 0) and never the company share", d.get("service_pct") == 0 and "company" not in json.dumps(d).lower(), d)
    s, r = q(NEAR)
    near = ((r.get("data") or {}).get("vehicles") or {}).get("bike", {}).get("fee")
    check("a near place costs less than a far one", near is not None and near < fees["bike"], (near, fees["bike"]))
    s, r = call("delivery_fee_quote", {"item_id": item})
    check("no customer pin: nothing to price by distance (zone fee)", ((r.get("data") or {}).get("mode")) == "zone", r)

    print("5. An order: the fee, the free service charge and the 60/40 split")
    def order(extra, tok=None):
        b = {"items": [{"item_id": item, "quantity": 2}], "to_zone": "Tabor", "recipient_name": "R", "recipient_phone": cust_phone, "payment_method": "cash"}
        b.update(extra)
        s2, r2 = call("customer_create_order", b, tok or cust)
        return r2
    o1 = order({"vehicle_class": "bike", "delivery_tier": "moto", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    oid = (o1.get("data") or {}).get("order_id")
    check("an order by bicycle is placed", o1.get("success") is True and oid, o1)
    row = sql1("SELECT CONCAT(delivery_fee,'|',service_charge,'|',subtotal,'|',fare_total,'|',IFNULL(courier_pay,'-'),'|',IFNULL(vehicle_class,'-'),'|',IFNULL(courier_pay_note,'-')) FROM orders WHERE id=%d" % int(oid)).split("|")
    want = expect(rates["bike"], dist, 1, 1)
    check("the delivery fee is the quoted bicycle fee", float(row[0]) == want, row)
    check("the service charge is free (0)", float(row[1]) == 0, row)
    check("the total is items + delivery fee, nothing else", abs(float(row[3]) - (float(row[2]) + want)) < 0.01, row)
    check("the courier is paid 60 percent of the fee", float(row[4]) == round(want * 0.6), row)
    check("the vehicle is saved and the note says who gets what", row[5] == "bike" and row[6].startswith("Fee %d ETB: courier 60%%" % want), row)
    check("the company keeps the other 40 percent (fee - courier pay)", float(row[0]) - float(row[4]) == want - round(want * 0.6), row)

    print("6. Old apps, no pin, and the switch")
    o2 = order({})
    r2 = sql1("SELECT CONCAT(delivery_fee,'|',IFNULL(courier_pay,'-')) FROM orders WHERE id=%d" % int((o2.get("data") or {}).get("order_id"))).split("|")
    check("an order with no pin keeps the zone fee and no saved courier share (as before)", o2.get("success") is True and float(r2[0]) == 50 and r2[1] == "-", r2)
    o3 = order({"delivery_tier": "car", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    r3 = sql1("SELECT delivery_fee FROM orders WHERE id=%d" % int((o3.get("data") or {}).get("order_id")))
    check("an old app (tier car, no vehicle) is priced with the car list", float(r3 or 0) == expect(rates["car"], dist, 1, 1), r3)
    o4 = order({"vehicle_class": "zz9", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    r4 = sql1("SELECT CONCAT(delivery_fee,'|',IFNULL(vehicle_class,'-')) FROM orders WHERE id=%d" % int((o4.get("data") or {}).get("order_id"))).split("|")
    check("an unknown vehicle is ignored: priced as a motorbike, no vehicle saved", float(r4[0]) == expect(rates["moto"], dist, 1, 1) and r4[1] == "-", r4)

    print("7. Changing the rules never rewrites an old order")
    call("admin_dfee_save", {"settings": dict(body["settings"], company_pct=50), "vehicles": body["vehicles"]}, admin)
    o5 = order({"vehicle_class": "bike", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    r5 = sql1("SELECT courier_pay FROM orders WHERE id=%d" % int((o5.get("data") or {}).get("order_id")))
    check("with the split at 50/50 the next order pays the courier half", float(r5) == round(want * 0.5), r5)
    check("the earlier order still has its 60 percent", float(sql1("SELECT courier_pay FROM orders WHERE id=%d" % int(oid))) == round(want * 0.6))

    print("8. The service charge is under the admin's control")
    call("admin_dfee_save", {"settings": dict(body["settings"], service_pct=5), "vehicles": body["vehicles"]}, admin)
    s, r = q(FAR)
    check("the cart is told the new service charge percent (5)", ((r.get("data") or {}).get("service_pct")) == 5, r)
    o6 = order({"vehicle_class": "bike", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    r6 = sql1("SELECT CONCAT(service_charge,'|',subtotal) FROM orders WHERE id=%d" % int((o6.get("data") or {}).get("order_id"))).split("|")
    check("the order charges 5 percent of the items (10 on 200)", abs(float(r6[0]) - float(r6[1]) * 0.05) < 0.01 and float(r6[0]) > 0, r6)

    print("9. Farthest delivery and the switch")
    call("admin_dfee_save", {"settings": dict(body["settings"], max_km=2), "vehicles": body["vehicles"]}, admin)
    s, r = q(FAR)
    check("a place beyond the limit (2 km here) is refused in the quote", ((r.get("data") or {}).get("too_far")) == 2, r)
    s, r = q(NEAR)
    check("... and a place inside the limit is still priced", ((r.get("data") or {}).get("vehicles") or {}).get("bike", {}).get("fee"), r)
    o7 = order({"vehicle_class": "bike", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    check("... and the order is refused with a clear message", o7.get("success") is False and "too far" in (o7.get("message") or ""), o7)
    call("admin_dfee_save", {"settings": dict(body["settings"], enabled=0), "vehicles": body["vehicles"]}, admin)
    o8 = order({"vehicle_class": "bike", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    r8 = sql1("SELECT CONCAT(delivery_fee,'|',IFNULL(courier_pay,'-')) FROM orders WHERE id=%d" % int((o8.get("data") or {}).get("order_id"))).split("|")
    check("with the switch off the zone fee comes back and no courier share is saved", float(r8[0]) == 50 and r8[1] == "-", r8)
    s, r = q(FAR)
    check("... and the cart quote falls back to the zone fee", ((r.get("data") or {}).get("mode")) == "zone", r)

    print("10. The default road factor and rounding")
    call("admin_dfee_save", {"settings": {"enabled": 1}, "vehicles": body["vehicles"]}, admin)
    s, r = q(FAR)
    fees = {k: v["fee"] for k, v in ((r.get("data") or {}).get("vehicles") or {}).items()}
    check("by default the road is 1.3 x the straight line and the fee is rounded to 5 ETB", fees.get("bike") == expect(rates["bike"], dist, 1.3, 5) and fees["bike"] % 5 == 0, (fees, dist))
    s, r = call("admin_dfee_get", {}, admin)
    stats = (r.get("data") or {}).get("stats") or {}
    check("the admin page counts the orders priced this way", stats.get("orders", 0) >= 3 and stats.get("company", 0) > 0, stats)

    print("11. What the courier sees")
    call("admin_dfee_save", {"settings": dict(body["settings"], company_pct=40), "vehicles": body["vehicles"]}, admin)
    cphone = "+251977" + TAIL
    register(cphone, "courier")
    cid = sql1("SELECT c.id FROM couriers c JOIN users u ON u.id = c.user_id WHERE u.phone = '%s'" % cphone)
    o9 = order({"delivery_tier": "moto", "delivery_lat": FAR[0], "delivery_lng": FAR[1]})
    oid9 = int((o9.get("data") or {}).get("order_id"))
    fee9 = float(sql1("SELECT delivery_fee FROM orders WHERE id=%d" % oid9))
    def apply_pay():
        r = subprocess.run(["php", os.path.join(os.path.dirname(os.path.abspath(__file__)), "courier_pay_cli.php"), "apply", str(oid9), cid, "0"], capture_output=True, text=True, timeout=60)
        return json.loads(r.stdout.strip().splitlines()[-1])
    row9 = apply_pay()
    check("the courier's app shows the saved 60 percent (not the whole fee)", float(row9.get("courier_pay")) == round(fee9 * 0.6) and float(row9["courier_pay"]) < fee9, (row9, fee9))
    subprocess.run(["php", os.path.join(os.path.dirname(os.path.abspath(__file__)), "courier_pay_cli.php"), "save", json.dumps({"enabled": 1, "base": 500})], capture_output=True, text=True, timeout=60)
    check("the old pay-by-distance settings do not override a saved share", float(apply_pay().get("courier_pay")) == round(fee9 * 0.6))
    subprocess.run(["php", os.path.join(os.path.dirname(os.path.abspath(__file__)), "courier_pay_cli.php"), "save", json.dumps({"enabled": 0})], capture_output=True, text=True, timeout=60)

    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key LIKE 'dfee\\_%'")
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
