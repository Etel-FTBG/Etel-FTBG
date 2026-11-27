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


def call(action, body=None, token=None, qs=""):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL + qs, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
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
    check("options list vehicles, sizes and the prohibited-items notice", r.get("success") and {"bike", "moto", "car", "van"} <= set(v["vehicle_class"] for v in d.get("vehicles", [])) and len(d.get("sizes", [])) == 4 and "weapons" in d.get("prohibited", "").lower(), r)
    s, r = call("customer_package_quote", pkg, token=cust)
    d = r.get("data") or {}
    prices = [o["price"] for o in d.get("options", [])]
    check("quote gives distance, time and at least the four built-in vehicles, cheapest first (the Bajaj and Qute types are added too)", r.get("success") and d.get("distance_km", 0) > 1 and prices == sorted(prices) and len(prices) >= 4 and set(["bike", "moto", "car", "van"]) <= set(o["vehicle_class"] for o in d.get("options", [])), r)
    ok_cls = set(o["vehicle_class"] for o in d.get("options", []))
    off = d.get("unavailable")
    check("the quote also lists the vehicles that cannot take it (none of them twice), each with a reason and a rank", isinstance(off, list) and all(o.get("reason") in ("size", "weight") and "rank" in o for o in off) and not (ok_cls & set(o["vehicle_class"] for o in off)), off)
    s, r = call("customer_package_quote", dict(pkg, size="large", weight_kg=60), token=cust)
    d2 = r.get("data") or {}
    check("a heavy large package: the small vehicles are listed as unavailable (size or weight) and cannot be booked", r.get("success") and any(o["vehicle_class"] in ("bike", "moto") and o["reason"] in ("size", "weight") for o in d2.get("unavailable", [])) and "bike" not in [o["vehicle_class"] for o in d2.get("options", [])], d2.get("unavailable"))
    s, r = call("customer_package_quote", dict(pkg, weight_kg=0), token=cust)
    check("a quote with no weight is refused", r.get("success") is False)

    print("2. Booking")
    book = dict(pkg, vehicle_class="van", description="Books", recipient_name="Rita", recipient_phone="0911223344", ack_prohibited=True, payment_method="cash", weight_kg=30)
    s, r = call("customer_package_create", dict(book, ack_prohibited=False), token=cust)
    check("booking needs the prohibited-items tick", r.get("success") is False)
    s, r = call("customer_package_create", book, token=None)
    check("booking needs a sign-in", r.get("success") is False)
    # Every EtelID is also a customer: a courier books from the same account, but never delivers their own order.
    s, r = call("customer_package_create", dict(book, weight_kg=1, vehicle_class="moto"), token=moto)
    own = (r.get("data") or {}).get("order_id")
    check("a courier account can book a package (every EtelID is also a customer)", r.get("success") and own, r)
    s, r = call("courier_get_available_orders", {}, token=moto, qs="?pkg=1")
    listed = [o.get("id") for o in ((r.get("data") or {}).get("orders") or [])]
    check("the courier does not see the package they booked themselves", own not in listed, listed)
    if own:
        s, r = call("courier_accept_order", {"order_id": own}, token=moto, qs="?pkg=1")
        check("the courier cannot accept their own package (403)", s == 403 and "yourself" in (r.get("message") or ""), r)
        call("customer_cancel_order", {"order_id": own, "reason": "other"}, token=moto)
    s, r = call("customer_package_create", book, token=cust)
    d = r.get("data") or {}
    oid = d.get("order_id")
    check("a van package is booked", r.get("success") and oid and d.get("order_code", "").startswith("ETP-"), r)

    s, r = call("customer_package_create", dict(book, payment_method="chapa", weight_kg=30), token=cust)
    cid = (r.get("data") or {}).get("order_id")
    s, t = call("track_order", {"order_id": cid}, token=cust) if cid else (0, {})
    check("an online (Chapa) package is booked and waits for payment, not handed to a courier",
          r.get("success") and (t.get("data") or {}).get("order", {}).get("status") == "pending" and not (t.get("data") or {}).get("order", {}).get("courier_name"), r)
    if cid:
        call("customer_cancel_order", {"order_id": cid, "reason": "other"}, token=cust)

    print("3. Who sees the job")
    s, r = call("courier_get_available_orders", {}, token=moto)
    ids = [o["id"] for o in (r.get("data") or {}).get("orders", [])]
    check("a motorbike courier does not see a van package", r.get("success") and oid not in ids, ids)
    s, r = call("courier_accept_order", {"order_id": oid}, token=moto)
    check("and cannot accept it either", r.get("success") is False and s == 403, r)
    van = register("+251917%06d" % n, "courier", {"vehicle": "van"})
    etel_test_db.park_couriers()
    s, r = call("courier_get_available_orders", {}, token=van)
    check("an app that does not say it can run packages (no pkg=1) is never shown one", r.get("success") and oid not in [o["id"] for o in (r.get("data") or {}).get("orders", [])], r)
    s, r = call("courier_accept_order", {"order_id": oid}, token=van)
    check("and cannot accept one either", r.get("success") is False and s == 403 and "app" in str(r.get("message")).lower(), r)
    s, r = call("courier_get_available_orders", {}, token=van, qs="?pkg=1")
    jobs = (r.get("data") or {}).get("orders", [])
    mine = [o for o in jobs if o["id"] == oid]
    check("a van courier sees it, with the package details", len(mine) == 1 and mine[0].get("order_kind") == "package" and mine[0].get("vehicle_class") == "van" and mine[0].get("pkg_desc") == "Books" and mine[0].get("pickup_address") == "Tabor, Hawassa", jobs[:1])
    s, r = call("courier_accept_order", {"order_id": oid}, token=van)
    check("the van courier accepts it", r.get("success") is True, r)
    s, r = call("courier_get_my_orders", {}, token=van, qs="?pkg=1")
    mo = [o for o in (r.get("data") or {}).get("orders", []) if o.get("id") == oid]
    check("the courier's own list carries the package details and the sender, but never the PINs",
          len(mo) == 1 and mo[0].get("order_kind") == "package" and mo[0].get("pickup_address") and mo[0].get("sender_phone") and "pickup_pin" not in mo[0] and "delivery_pin" not in mo[0], mo)

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

    print("4b. PIN steps at the door")
    s, r = call("customer_package_create", dict(book, vehicle_class="van"), token=cust)
    pid = (r.get("data") or {}).get("order_id")
    s, r = call("courier_accept_order", {"order_id": pid}, token=van) if pid else (0, {})
    if pid and not r.get("success"):   # auto-dispatch may already have given it to the van courier
        pass
    s, t = call("track_order", {"order_id": pid}, token=cust)
    po = (t.get("data") or {}).get("order", {})
    pp, dp = str(po.get("pickup_pin")), str(po.get("delivery_pin"))
    pt = po.get("pkg_times") or {}
    check("tracking carries the stage times: booked and accepted set, nothing later yet, and the database clock",
          isinstance(pt.get("booked"), int) and isinstance(pt.get("accepted"), int) and pt.get("accepted") >= pt.get("booked")
          and pt.get("picked_up") is None and pt.get("delivered") is None and pt.get("now") >= pt.get("accepted"), pt)
    wrong = "0000" if pp != "0000" else "1111"
    s, r = call("courier_update_order_status", {"order_id": pid, "status": "picked_up"}, token=van)
    check("a plain status change cannot pick a package up (the PIN is needed)", r.get("success") is False and s == 400, r)
    s, r = call("courier_update_order_status", {"order_id": pid, "status": "delivered"}, token=van)
    check("nor deliver it", r.get("success") is False and s == 400, r)
    s, r = call("courier_package_pickup", {"order_id": pid, "pin": wrong}, token=van)
    check("a wrong pickup PIN is refused", r.get("success") is False, r)
    s, r = call("courier_package_pickup", {"order_id": pid, "pin": "12"}, token=van)
    check("a PIN that is not 4 digits is refused", r.get("success") is False and s == 400, r)
    s, r = call("courier_package_pickup", {"order_id": pid, "pin": pp}, token=cust)
    check("the sender cannot do the courier's PIN step", r.get("success") is False, r)
    s, r = call("courier_package_pickup", {"order_id": pid, "pin": pp}, token=moto)
    check("another courier cannot do it either", r.get("success") is False, r)
    s, r = call("courier_package_pickup", {"order_id": pid, "pin": pp}, token=van)
    check("the sender's PIN picks it up", r.get("success") is True and (r.get("data") or {}).get("status") == "picked_up", r)
    s, t = call("track_order", {"order_id": pid}, token=cust)
    check("the sender sees it picked up and can no longer cancel", (t.get("data") or {}).get("order", {}).get("status") == "picked_up" and (t.get("data") or {}).get("order", {}).get("can_cancel") is False, t)
    if dp != pp:
        s, r = call("courier_package_deliver", {"order_id": pid, "pin": pp}, token=van)
        check("the pickup PIN does not deliver it", r.get("success") is False, r)
    s, t2 = call("track_order", {"order_id": pid}, token=cust)
    pt = ((t2.get("data") or {}).get("order") or {}).get("pkg_times") or {}
    check("after pickup the pickup time is set and in order", isinstance(pt.get("picked_up"), int) and pt["picked_up"] >= pt["accepted"] >= pt["booked"] and pt.get("delivered") is None, pt)
    s, r = call("courier_get_my_orders", {}, token=van, qs="?pkg=1")
    mine = [o for o in ((r.get("data") or {}).get("orders") or []) if o.get("id") == pid]
    cpt = (mine[0].get("pkg_times") if mine else None) or {}
    check("the courier's own order list carries the stage times for the timers (and still no PINs)",
          isinstance(cpt.get("accepted"), int) and isinstance(cpt.get("picked_up"), int) and cpt.get("delivered") is None and "pickup_pin" not in mine[0] and "delivery_pin" not in mine[0], cpt)
    s, r = call("courier_package_deliver", {"order_id": pid, "pin": dp}, token=van)
    check("the recipient's PIN delivers it", r.get("success") is True and (r.get("data") or {}).get("status") == "delivered", r)
    s, t3 = call("track_order", {"order_id": pid}, token=cust)
    pt = ((t3.get("data") or {}).get("order") or {}).get("pkg_times") or {}
    check("after delivery all four times are set and in order", all(isinstance(pt.get(k), int) for k in ("booked", "accepted", "picked_up", "delivered")) and pt["delivered"] >= pt["picked_up"] >= pt["accepted"] >= pt["booked"], pt)
    s, r = call("courier_package_deliver", {"order_id": pid, "pin": dp}, token=van)
    check("it cannot be delivered twice", r.get("success") is False, r)

    print("4b. Photo proof of delivery")
    import base64, struct, zlib
    def png_url(w, h, color=(200, 160, 40)):
        raw = b"".join(b"\x00" + bytes(color) * w for _ in range(h))
        def chunk(t, d):
            c = struct.pack(">I", len(d)) + t + d
            return c + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
        png = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")
        return "data:image/png;base64," + base64.b64encode(png).decode()
    good = png_url(40, 40)
    s, r = call("courier_package_proof", {"order_id": pid, "photo": good}, token=None)
    check("adding a photo needs a courier sign-in", r.get("success") is False)
    s, r = call("courier_package_proof", {"order_id": pid, "photo": good}, token=cust)
    check("a customer cannot add a photo", r.get("success") is False)
    s, r = call("courier_package_proof", {"order_id": pid, "photo": good}, token=moto)
    check("another courier cannot add a photo to this package", r.get("success") is False and "not your" in str(r.get("message")).lower(), r)
    for name, bad in (("a text that is not a picture", "hello"), ("a picture with the wrong type", good.replace("png", "gif", 1)),
                      ("bytes that are not a picture", "data:image/png;base64," + base64.b64encode(b"not an image at all").decode()),
                      ("a picture that is too small", png_url(4, 4)), ("a picture that is too big", "data:image/png;base64," + "A" * 720000)):
        s, r = call("courier_package_proof", {"order_id": pid, "photo": bad}, token=van)
        check("the courier cannot save " + name, r.get("success") is False, r)
    s, r = call("customer_package_proof", {"order_id": pid}, token=cust)
    check("before any photo the customer gets photo: null", r.get("success") and (r.get("data") or {}).get("photo") is None, r)
    s, t4 = call("track_order", {"order_id": pid}, token=cust)
    check("tracking says there is no photo yet", ((t4.get("data") or {}).get("order") or {}).get("has_proof") is False, ((t4.get("data") or {}).get("order") or {}).get("has_proof"))
    s, r = call("courier_package_proof", {"order_id": pid, "photo": good}, token=van)
    check("the delivering courier saves the photo", r.get("success") is True, r)
    s, r = call("customer_package_proof", {"order_id": pid}, token=cust)
    check("the sender reads the same photo back", (r.get("data") or {}).get("photo") == good and (r.get("data") or {}).get("taken_at"), str(r)[:120])
    s, r = call("customer_package_proof", {"order_id": pid}, token=other)
    check("another customer cannot read it", (r.get("data") or {}).get("photo") is None, str(r)[:120])
    s, t5 = call("track_order", {"order_id": pid}, token=cust)
    check("tracking now says there is a photo", ((t5.get("data") or {}).get("order") or {}).get("has_proof") is True)
    check("the photo itself is not inside the tracking answer", "data:image" not in json.dumps(t5))
    s, r = call("public_track_package", {"code": ((t5.get("data") or {}).get("order") or {}).get("order_code", ""), "token": "0" * 12})
    check("the public link never carries a photo", "data:image" not in json.dumps(r))
    s, r = call("courier_package_proof", {"order_id": pid, "photo": png_url(30, 30, (10, 120, 200))}, token=van)
    s, r2 = call("customer_package_proof", {"order_id": pid}, token=cust)
    check("a second photo replaces the first", r.get("success") is True and (r2.get("data") or {}).get("photo") != good, str(r2)[:100])
    etel_test_db.run_sql("UPDATE orders SET delivered_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE id = %d" % int(pid))
    s, r = call("courier_package_proof", {"order_id": pid, "photo": good}, token=van)
    check("after 2 hours the courier can no longer add or change the photo", r.get("success") is False and "late" in str(r.get("message")).lower(), r)
    s, r = call("courier_package_proof", {"order_id": oid, "photo": good}, token=van)
    check("a package that is not delivered cannot get a photo", r.get("success") is False, r)

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
    check("admin sees the four built-in vehicles (and the Bajaj and Qute types added for the three-wheelers)", r.get("success") and set(["bike", "moto", "car", "van"]) <= set(rates) and len(rates) <= 6, r)
    m = dict(rates["moto"]); old = float(m["base_fee"])
    s, r = call("admin_package_rates_save", dict(m, base_fee=old + 25), token=cust)
    check("a customer cannot change prices", r.get("success") is False)
    s, r = call("admin_package_rates_save", dict(m, base_fee=-5), token=admin)
    check("admin gets a clear error for a bad number", r.get("success") is False and "Base fee" in r.get("message", ""), r)
    s, r = call("admin_package_setting_save", {"key": "tier_on", "value": 0}, token=admin)
    check("admin can switch the tiered price off", r.get("success") is True, r)
    s, q1 = call("customer_package_quote", pkg, token=cust)
    prices = [o["price"] for o in (q1.get("data") or {}).get("options", [])]
    s, r = call("admin_package_rates_save", dict(m, base_fee=old + 25), token=admin)
    s, q2 = call("customer_package_quote", pkg, token=cust)
    p2 = {o["vehicle_class"]: o["price"] for o in (q2.get("data") or {}).get("options", [])}
    check("a saved price shows in the next quote", r.get("success") and abs(p2["moto"] - (prices[1] + 25)) <= 1, (p2, prices))
    call("admin_package_rates_save", dict(m, base_fee=old), token=admin)
    call("admin_package_setting_save", {"key": "tier_on", "value": 1}, token=admin)
    s, q3 = call("customer_package_quote", pkg, token=cust)
    p3 = sorted(set(o["price"] for o in (q3.get("data") or {}).get("options", [])))
    dist = (q3.get("data") or {}).get("distance_km", 0)
    want = 100 if dist <= 5 else 200 if dist <= 10 else 300 if dist <= 25 else None
    byv = dict((o["vehicle_class"], o["price"]) for o in (q3.get("data") or {}).get("options", []))
    check("a bigger vehicle costs more in the tiered price", byv.get("bike", 0) <= byv.get("moto", 0) < byv.get("car", 0) < byv.get("van", 0), byv)
    check("the bicycle price is the distance band price plus the weight fee", want is None or byv.get("bike", 0) - want in range(0, 400), (dist, byv))
    s, r = call("admin_package_setting_save", {"values": {"d1_km": 30}}, token=admin)
    check("bands that do not grow are refused", r.get("success") is False, r)
    s, r = call("admin_package_setting_save", {"values": {"d1_fee": 110}}, token=cust)
    check("a customer cannot change the band prices", r.get("success") is False)
    call("admin_package_setting_save", {"values": {"d1_fee": 100}}, token=admin)

    print("9. Express delivery and the booking switch")
    s, r = call("customer_package_quote", pkg, token=cust)
    dq = r.get("data") or {}
    mo = [o for o in dq.get("options", []) if o["vehicle_class"] == "moto"]
    mo = mo[0] if mo else {}
    want_x = mo.get("price", 0) + max(20, int(round(mo.get("price", 0) * 0.30)))
    check("the quote offers Express: a shorter time and a price for each vehicle (standard + 30 %, at least 20)", (dq.get("express") or {}).get("on") is True and 10 <= dq["express"].get("eta_min", 0) <= dq.get("eta_min", 0) and mo.get("express_price") == want_x, (dq.get("express"), mo))
    book_m = dict(pkg, vehicle_class="moto", description="Books", recipient_name="Rita", recipient_phone="0911223344", ack_prohibited=True, payment_method="cash", weight_kg=1)
    s, r = call("customer_package_create", dict(book_m, speed="express"), token=cust)
    dx = r.get("data") or {}
    check("an Express booking is charged the Express price", r.get("success") and dx.get("total") == want_x and dx.get("speed") == "express", r)
    s, t = call("track_order", {"order_id": dx.get("order_id")}, token=cust)
    od = (t.get("data") or {}).get("order") or t.get("data") or {}
    check("tracking shows the speed and the courier note says EXPRESS", od.get("pkg_speed") == "express" and "EXPRESS" in (od.get("notes") or ""), od.get("pkg_speed"))
    s, r = call("customer_package_create", book_m, token=cust)
    ds = r.get("data") or {}
    check("no speed sent (an older app) is booked as Standard at the normal price", r.get("success") and ds.get("total") == mo.get("price") and ds.get("speed") == "standard", r)
    s, r = call("admin_package_setting_save", {"values": {"express_on": 2}}, token=admin)
    check("Express on/off must be 0 or 1", r.get("success") is False, r)
    s, r = call("admin_package_setting_save", {"values": {"express_pct": 300}}, token=admin)
    check("an Express extra over 200 % is refused", r.get("success") is False, r)
    s, r = call("admin_package_setting_save", {"values": {"express_on": 0}}, token=cust)
    check("a customer cannot switch Express off", r.get("success") is False)
    s, r = call("admin_package_setting_save", {"values": {"express_on": 0}}, token=admin)
    check("the admin switches Express off", r.get("success") is True, r)
    s, r = call("customer_package_quote", pkg, token=cust)
    dn = r.get("data") or {}
    check("with Express off the quote has no Express price", (dn.get("express") or {}).get("on") is False and all("express_price" not in o for o in dn.get("options", [])), dn.get("express"))
    s, r = call("customer_package_create", dict(book_m, speed="express"), token=cust)
    check("an Express request while it is off is booked as Standard", r.get("success") and (r.get("data") or {}).get("total") == mo.get("price") and (r.get("data") or {}).get("speed") == "standard", r)
    call("admin_package_setting_save", {"values": {"express_on": 1}}, token=admin)
    s, r = call("admin_package_setting_save", {"values": {"booking_on": 0}}, token=admin)
    check("the admin pauses package booking", r.get("success") is True, r)
    s, r = call("customer_package_quote", pkg, token=cust)
    check("while paused the quote says so", r.get("success") is False and "paused" in (r.get("message") or ""), r)
    s, r = call("customer_package_create", book_m, token=cust)
    check("while paused nothing can be booked", r.get("success") is False and "paused" in (r.get("message") or ""), r)
    call("admin_package_setting_save", {"values": {"booking_on": 1}}, token=admin)
    s, r = call("customer_package_quote", pkg, token=cust)
    check("booking works again after it is switched back on", r.get("success") is True, r)

    print("10. Public tracking link")
    s, r = call("customer_package_create", dict(book_m, speed="standard"), token=cust)
    dp = r.get("data") or {}
    tok, pcode, poid = dp.get("track_token", ""), dp.get("order_code", ""), dp.get("order_id")
    check("a new package comes with a random 12-digit tracking token", r.get("success") and len(tok) == 12 and all(c in "0123456789abcdef" for c in tok), tok)
    s, r = call("public_track_package", {"code": pcode, "token": tok}, token=None)
    pv = r.get("data") or {}
    check("the link works without signing in and shows the status, the areas and the vehicle", r.get("success") and pv.get("order_code") == pcode and pv.get("status") == "pending" and pv.get("vehicle_class") == "moto" and "from_zone" in pv and "to_zone" in pv, r)
    leaked = [k for k in pv.keys() if any(w in k for w in ("phone", "pin", "address", "price", "fare", "fee", "lat", "lng", "recipient", "sender", "email", "notes", "value"))]
    check("the public view has no phone, PIN, address, price, position or notes", not leaked, leaked)
    s, r = call("public_track_package", {"code": pcode, "token": "0" * 12}, token=None)
    check("a wrong token shows nothing (404)", r.get("success") is False and s == 404, (s, r))
    s, r = call("public_track_package", {"code": pcode}, token=None)
    check("a code without a token shows nothing (404)", r.get("success") is False and s == 404, (s, r))
    s, r = call("public_track_package", {"code": "ETP-000000", "token": tok}, token=None)
    check("an unknown code gives the same 404 answer", r.get("success") is False and s == 404 and r.get("message") == (call("public_track_package", {"code": pcode, "token": "1" * 12}, token=None)[1] or {}).get("message"), (s, r))
    s, r = call("public_track_package", {"code": pcode, "token": "xyz'; DROP TABLE orders;--"}, token=None)
    check("a bad token text is refused, not run", r.get("success") is False and s == 404, (s, r))
    s, r = call("public_track_package", {"code": pcode, "token": tok}, token=None, qs="")
    s2, r2 = call("public_track_package", {}, token=None, qs="?code=" + pcode + "&token=" + tok)
    check("the link also works as a plain web address (GET), which track.html uses", r2.get("success") and (r2.get("data") or {}).get("order_code") == pcode, r2)
    if poid:
        call("customer_cancel_order", {"order_id": poid, "reason": "other"}, token=cust)
        s, r = call("public_track_package", {"code": pcode, "token": tok}, token=None)
        check("after cancelling, the link shows cancelled", (r.get("data") or {}).get("status") == "cancelled", r)
    s, r = call("customer_package_create", dict(book_m), token=cust)
    d_b = r.get("data") or {}
    check("every package has its own token", d_b.get("track_token") and d_b.get("track_token") != tok, d_b.get("track_token"))
    s, r = call("public_track_package", {"code": pcode, "token": d_b.get("track_token", "")}, token=None)
    check("another package's token does not open this package", r.get("success") is False and s == 404, (s, r))

    print("11. My packages and Send again")
    s, r = call("customer_package_recent", {}, token=None)
    check("the recent list needs a sign-in", r.get("success") is False)
    mine = dict(book_m, description="Send again test", recipient_name="Hana", recipient_phone="0911556677")
    s, r = call("customer_package_create", mine, token=cust)
    h1 = r.get("data") or {}
    s, r = call("customer_package_create", dict(mine, recipient_name="Hana 2", speed="express"), token=cust)
    h2 = r.get("data") or {}
    s, r = call("customer_package_recent", {}, token=cust)
    lst = (r.get("data") or {}).get("packages")
    check("the list has the newest package first", r.get("success") and isinstance(lst, list) and len(lst) >= 2 and lst[0].get("order_id") == h2.get("order_id") and lst[1].get("order_id") == h1.get("order_id"), [x.get("order_id") for x in (lst or [])][:4])
    top = (lst or [{}])[0]
    check("each row has what Send again needs (both addresses, recipient, size, weight, what is inside, vehicle, speed)", top.get("recipient_name") == "Hana 2" and top.get("recipient_phone") and top.get("size") == "small" and top.get("desc") == "Send again test" and top.get("vehicle_class") == "moto" and top.get("speed") == "express" and (top.get("pickup") or {}).get("address") and (top.get("drop") or {}).get("address"), top)
    check("a new package is in the active group", top.get("group") == "active" and top.get("status") == "pending", top.get("group"))
    call("customer_cancel_order", {"order_id": h1.get("order_id"), "reason": "other"}, token=cust)
    s, r = call("customer_package_recent", {}, token=cust)
    byid = dict((x["order_id"], x) for x in (r.get("data") or {}).get("packages", []))
    check("a cancelled package is in the cancelled group", (byid.get(h1.get("order_id")) or {}).get("group") == "cancelled", byid.get(h1.get("order_id")))
    s, r = call("customer_package_recent", {}, token=moto)
    other_ids = [x["order_id"] for x in (r.get("data") or {}).get("packages", [])]
    check("another customer's list never has these packages", h1.get("order_id") not in other_ids and h2.get("order_id") not in other_ids, other_ids)
    s, r = call("customer_package_recent", {}, token=cust)
    check("only package orders are listed (no shop orders)", all(str(x.get("order_code", "")).startswith("ETP-") for x in (r.get("data") or {}).get("packages", [])))

    print("12. Address book")
    s, r = call("customer_package_contacts", {}, token=None)
    check("the address book needs a sign-in", r.get("success") is False)
    c1 = {"name": "Hana Bekele", "phone": "0911556677", "address": "Tabor, 2FQM+RHF, Hawassa, Ethiopia", "label": "Hana", "lat": 7.06, "lng": 38.49, "zone": "Tabor"}
    s, r = call("customer_package_contact_save", c1, token=None)
    check("saving needs a sign-in", r.get("success") is False)
    s, r = call("customer_package_contact_save", c1, token=cust)
    saved = (r.get("data") or {}).get("contact") or {}
    check("a contact is saved", r.get("success") is True and saved.get("id") and saved.get("name") == "Hana Bekele" and saved.get("lat") == 7.06 and saved.get("label") == "Hana", r)
    for name, bad in (("no name", dict(c1, name="")), ("a bad phone number", dict(c1, phone="12345")), ("no address", dict(c1, address="  ")), ("a name that is too long", dict(c1, name="x" * 121))):
        s, r = call("customer_package_contact_save", bad, token=cust)
        check("a contact with " + name + " is refused", r.get("success") is False and s == 400, r)
    s, r = call("customer_package_contact_save", dict(c1, label="Sister", name="Hana B."), token=cust)
    check("the same phone and address updates the contact instead of making a second one", r.get("success") is True and (r.get("data") or {}).get("contact", {}).get("id") == saved.get("id"), r)
    s, r = call("customer_package_contact_save", dict(c1, address="Another place", lat=None, lng=None), token=cust)
    second = (r.get("data") or {}).get("contact") or {}
    check("the same person at a second address is a second contact; a missing map point is kept as none", r.get("success") is True and second.get("id") != saved.get("id") and second.get("lat") is None, r)
    s, r = call("customer_package_contacts", {}, token=cust)
    lst = (r.get("data") or {}).get("contacts") or []
    check("the list has both, newest first, with the new name and label", len(lst) == 2 and lst[0].get("id") == second.get("id") and lst[1].get("label") == "Sister" and lst[1].get("name") == "Hana B.", lst)
    s, r = call("customer_package_contacts", {}, token=other)
    check("another customer's address book is empty (never sees these)", (r.get("data") or {}).get("contacts") == [], r)
    s, r = call("customer_package_contact_delete", {"id": saved.get("id")}, token=other)
    check("another customer cannot delete it (404)", r.get("success") is False and s == 404, (s, r))
    s, r = call("customer_package_contact_delete", {"id": saved.get("id")}, token=None)
    check("deleting needs a sign-in", r.get("success") is False)
    s, r = call("customer_package_contact_delete", {"id": saved.get("id")}, token=cust)
    s, r2 = call("customer_package_contacts", {}, token=cust)
    check("the owner deletes it and it is gone from the list", r.get("success") is True and [x["id"] for x in (r2.get("data") or {}).get("contacts", [])] == [second.get("id")], r2)
    okq, outq, _ = etel_test_db.query_text("SELECT id FROM users WHERE phone LIKE '%%%s' ORDER BY id DESC LIMIT 1" % ("914%06d" % n))
    uid = outq.strip().split("\n")[-1] if okq and outq.strip() else ""
    if uid:
        for i in range(29):
            etel_test_db.run_sql("INSERT INTO package_contacts (user_id, label, name, phone, address, created_at) VALUES (%s, '', 'Filler %d', '+2519110000%02d', 'Filler address %d', NOW())" % (uid, i, i, i))
        s, r = call("customer_package_contact_save", dict(c1, phone="0911990000", address="The 31st"), token=cust)
        check("the 31st contact is refused (30 at most)", r.get("success") is False and "full" in str(r.get("message")).lower(), r)
        s, r = call("customer_package_contact_save", dict(second, phone="0911556677", address="Another place", name="Updated name"), token=cust)
        check("an existing contact can still be updated when the book is full", r.get("success") is True, r)
    else:
        print("  SKIP could not find the test customer id")

    print("13. Tracking link for an older package")
    old_id = int(h2.get("order_id"))
    old_code = h2.get("order_code")
    etel_test_db.run_sql("UPDATE orders SET track_token = NULL WHERE id = %d" % old_id)
    s, r = call("public_track_package", {"code": old_code, "token": "a" * 12})
    check("a package booked before the links has no public link yet (404)", r.get("success") is False and s == 404, (s, r))
    s, r = call("track_order", {"order_id": old_id}, token=cust)
    tk = ((r.get("data") or {}).get("order") or {}).get("track_token", "")
    check("the sender opening it gets a token (12 hex digits)", len(tk) == 12 and all(c in "0123456789abcdef" for c in tk), tk)
    okq, outq, _ = etel_test_db.query_text("SELECT track_token FROM orders WHERE id = %d" % old_id)
    check("the token is saved on the order", okq and outq.strip().split("\n")[-1] == tk, outq)
    s, r = call("public_track_package", {"code": old_code, "token": tk})
    check("and the public link now works for it", r.get("success") is True and (r.get("data") or {}).get("order_code") == old_code, r)
    s, r2 = call("track_order", {"order_id": old_id}, token=cust)
    check("asking again gives the same token (it is made once)", ((r2.get("data") or {}).get("order") or {}).get("track_token") == tk)
    s, r3 = call("track_order", {"order_id": old_id}, token=other)
    check("another customer cannot open the package and never gets the token", r3.get("success") is False and tk not in json.dumps(r3), r3)
    s, r4 = call("track_order", {"order_id": old_id}, token=moto)
    check("a courier account that is not on it never gets the token either", tk not in json.dumps(r4), str(r4)[:100])

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
