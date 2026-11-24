"""
ETEL Delivery -- a shop is open or closed by its OPENING HOURS, not by the online switch (the switch only matters for a shop with no hours), over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/shop_open_hours_smoke.py

Covers: the Admin list and drawer, the shop's own profile (sg_profile) and sg_set_hours, the customer feed, ordering inside and outside the hours,
and that the owner, the owner's staff and the admin still see an order after the shop has closed. Needs the seeded demo admin. Throwaway database only.
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


def register(phone, role):
    s, r = call("register", {"name": role + " " + SUFFIX, "phone": phone, "password": "testpass123", "role": role})
    return (r.get("data") or {}).get("token")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    sg_phone = "+251955" + TAIL
    s, r = call("admin_create_service_giver", {"name": "Hours SG " + SUFFIX, "phone": sg_phone, "password": "testpass123", "business_name": "Hours Kitchen",
                                                "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    s, r = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
    sg = (r.get("data") or {}).get("token")
    sgid = int(sql1("SELECT sg.id FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE u.phone = '%s'" % sg_phone))
    s, r = call("sg_add_item", {"name": "Hours Meal", "category": "food", "price": 100, "stock_quantity": 90}, sg)
    item = (r.get("data") or {}).get("item_id")
    cust_phone = "+251966" + TAIL
    cust = register(cust_phone, "customer")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        cust = register(cust_phone, "customer")
    check("the test customer signed up", bool(cust))
    if not cust:
        return 1

    def set_hours(o, c):
        return call("admin_sg_set_hours", {"id": sgid, "opens_at": o, "closes_at": c}, admin)

    def board_row():
        s2, r2 = call("admin_service_givers_board", {}, admin)
        d = r2.get("data") or {}
        row = [x for x in d.get("service_givers", []) if x.get("id") == sgid]
        return (row[0] if row else {}), (d.get("summary") or {})

    def feed_row():
        s2, r2 = call("customer_get_items", {}, cust)
        rows = (r2.get("data") or {}).get("items") or (r2.get("data") or {}).get("feed") or []
        mine = [x for x in rows if int(x.get("service_giver_id") or 0) == sgid]
        return mine[0] if mine else {}

    def profile():
        s2, r2 = call("sg_profile", {}, sg)
        return (r2.get("data") or {}).get("profile") or (r2.get("data") or {}).get("service_giver") or (r2.get("data") or {})

    def order():
        s2, r2 = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "R", "recipient_phone": cust_phone, "payment_method": "cash"}, cust)
        return r2

    # What hour is it for the server? Try one-hour windows until the Admin list says "open now": that is the server's current hour.
    etel_test_db.run_sql("UPDATE service_givers SET is_online = 0 WHERE id = %d" % sgid)
    H = None
    for h in range(24):
        set_hours("%02d:00" % h, "%02d:59" % h)
        if int(board_row()[0].get("open_now") or 0) == 1:
            H = h; break
    check("the server's current hour was found (a one-hour window around now is open)", H is not None)
    if H is None:
        return 1
    OPEN = ("00:00", "23:59"); CLOSED = ("%02d:00" % ((H + 3) % 24), "%02d:00" % ((H + 5) % 24))

    print("1. Hours set, now inside them, online switch OFF: the shop is OPEN")
    set_hours(*OPEN)
    row, summ = board_row()
    check("Admin list: open now, opening hours in charge", row.get("open_now") == 1 and row.get("hours_mode") == 1 and row.get("is_online") == 0, row)
    check("Admin list: the Open now count includes it", int(summ.get("online") or 0) >= 1, summ)
    s, r = call("admin_service_giver_detail", {"id": sgid}, admin)
    g = (r.get("data") or {}).get("service_giver") or {}
    check("Admin drawer: open now", g.get("open_now") == 1 and g.get("hours_mode") == 1, g)
    p = profile()
    check("the shop's own profile says open now, by hours, with the times", p.get("open_now") == 1 and p.get("hours_mode") == 1 and str(p.get("closes_at"))[:5] == "23:59", p)
    f = feed_row()
    check("the customer feed shows the shop open", f and int(f.get("is_online")) == 1 and not f.get("closed_reason"), f)
    o1 = order()
    check("a customer can order", o1.get("success") is True, o1)
    oid = (o1.get("data") or {}).get("order_id")

    print("2. Hours set, now OUTSIDE them, online switch ON: the shop is CLOSED")
    etel_test_db.run_sql("UPDATE service_givers SET is_online = 1 WHERE id = %d" % sgid)
    set_hours(*CLOSED)
    row, summ = board_row()
    check("Admin list: closed", row.get("open_now") == 0 and row.get("hours_mode") == 1 and row.get("is_online") == 1, row)
    p = profile()
    check("the shop's own profile says closed", p.get("open_now") == 0 and p.get("hours_mode") == 1, p)
    f = feed_row()
    check("the customer feed shows it closed because of the hours", f and int(f.get("is_online")) == 0 and f.get("closed_reason") == "hours", f)
    o2 = order()
    check("a customer is told it is closed, with its hours", o2.get("success") is False and "closed right now" in (o2.get("message") or "") and CLOSED[0] in (o2.get("message") or ""), o2)

    print("3. The order placed earlier is still seen by the owner, the owner's staff and the admin")
    s, r = call("sg_orders", {}, sg)
    ids = [str(x.get("order_id") or x.get("id")) for x in ((r.get("data") or {}).get("orders") or [])]
    check("the shop owner still sees the order", str(oid) in ids, ids[:10])
    s, r = call("sg_save_role", {"role_name": "Counter", "capabilities": ["orders.view"]}, sg)
    role_id = (r.get("data") or {}).get("role_id") or (r.get("data") or {}).get("id")
    sp = "+251932" + TAIL
    s, r = call("sg_add_staff", {"name": "Counter Kid", "phone": sp, "password": "testpass123", "role_id": role_id}, sg)
    s, r = call("login", {"phone": sp, "password": "testpass123"})
    staff = (r.get("data") or {}).get("token")
    s, r = call("sg_orders", {}, staff)
    sids = [str(x.get("order_id") or x.get("id")) for x in ((r.get("data") or {}).get("orders") or [])]
    check("the owner's staff still sees the order", staff and str(oid) in sids, (r, sids[:10]))
    s, r = call("admin_orders_board", {"period": "all", "limit": 500}, admin)
    aids = [str(x.get("id")) for x in ((r.get("data") or {}).get("orders") or [])]
    check("the admin still sees the order", str(oid) in aids, aids[:10])

    print("4. No hours: the online switch decides, as before")
    set_hours("", "")
    row, summ = board_row()
    check("no hours, switch on: open, not by hours", row.get("open_now") == 1 and row.get("hours_mode") == 0, row)
    etel_test_db.run_sql("UPDATE service_givers SET is_online = 0 WHERE id = %d" % sgid)
    row, summ = board_row()
    check("no hours, switch off: closed", row.get("open_now") == 0 and row.get("hours_mode") == 0, row)
    f = feed_row()
    check("... and the customer feed says the switch is why", f and int(f.get("is_online")) == 0 and f.get("closed_reason") == "switch", f)

    print("5. The shop saves its own hours and learns the answer at once")
    s, r = call("sg_set_hours", {"opens_at": "00:00", "closes_at": "23:59"}, sg)
    d = r.get("data") or {}
    check("sg_set_hours says open now by hours", s == 200 and d.get("open_now") == 1 and d.get("hours_mode") == 1, r)
    s, r = call("sg_set_hours", {"opens_at": CLOSED[0], "closes_at": CLOSED[1]}, sg)
    d = r.get("data") or {}
    check("a closed window says closed", d.get("open_now") == 0 and d.get("hours_mode") == 1, r)
    s, r = call("sg_set_hours", {"opens_at": "", "closes_at": ""}, sg)
    d = r.get("data") or {}
    check("clearing the hours goes back to the switch (off = closed)", d.get("hours_mode") == 0 and d.get("open_now") == 0, r)

    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
