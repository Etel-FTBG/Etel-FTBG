"""
ETEL Delivery -- who may call what (authorization matrix) and who may touch whose order.

Runs against a real instance of boot.php, like security_regression_smoke.py:

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/authz_matrix_smoke.py

1. Every admin_* action found in api/boot.php must refuse a caller with no token, a customer, a courier and a
   shop. Every sg_* (shop) action must refuse a customer and a courier; every courier_* action must refuse a
   customer and a shop. "Refuse" = success is false. The call body is empty, so an action that wrongly lets the
   caller in fails on its own missing fields and is still reported (success true would be the problem, but an
   empty body can also stop an unguarded action early, so the check is that the refusal is about permission).
2. Orders: another customer cannot track, cancel or rate somebody else's order; a courier cannot cancel it as a
   customer; a different shop cannot decline it; the owner can cancel it; admin and shop cancels need a reason.

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). Creates real rows: throwaway database only.
"""
import json
import re
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
ADMIN_PHONE = "0900000000"
ADMIN_PASSWORD = "admin1234"
ROOT = __file__.rsplit("/tests/", 1)[0]

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
            status, res = f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        status = e.code
        try:
            res = json.loads(e.read().decode("utf-8"))
        except Exception:
            res = {"success": False, "message": "(non-JSON error body)"}
    return status, res


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def register(phone, role):
    s, r = call("register", {"name": "Authz %s %s" % (role, SUFFIX), "phone": phone, "password": "testpass123", "role": role})
    d = r.get("data") or {}
    return d.get("token"), d.get("user_id")


def action_names():
    src = open(ROOT + "/api/boot.php", encoding="utf-8", errors="replace").read()
    names = set(re.findall(r"'((?:admin|sg|courier)_[a-z0-9_]+)'", src))
    return sorted(names)


# Actions that are meant to be reachable by the role they start with, before sign-in, or by design.
# admin_2fa_* is self-service two-step sign-in for the signed-in account, any role (AuthController): each caller only
# touches their own row, so these are reachable by every signed-in role on purpose.
# courier_*fuel* (CourierFuelController) is every query keyed on the caller's own user id: any signed-in role only ever
# reads or writes their own rows, so nothing of anyone else's is reachable. Not role-gated, not a data leak.
PUBLIC_BY_DESIGN = set(["sg_login", "courier_login", "admin_login", "sg_register", "courier_register", "admin_2fa_verify",
                        "admin_2fa_status", "admin_2fa_begin", "admin_2fa_enable", "admin_2fa_disable", "admin_2fa_backup_codes",
                        "courier_add_fuel_log", "courier_list_fuel_logs", "courier_delete_fuel_log", "courier_get_fuel_summary"])


def refused(status, res):
    return res.get("success") is False and status in (400, 401, 403, 404, 422, 429)


def main():
    s, r = call("login", {"phone": ADMIN_PHONE, "password": ADMIN_PASSWORD})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("  SKIP no seeded demo admin; run api/seeds/admin_demo_accounts.sql")
        return 0

    etel_test_db.park_couriers()
    cust_a, _ = register("+251911%06d" % (int(SUFFIX) % 1000000), "customer")
    cust_b, _ = register("+251912%06d" % (int(SUFFIX) % 1000000), "customer")
    courier, _ = register("+251913%06d" % (int(SUFFIX) % 1000000), "courier")
    shops = []
    for n in (1, 2):
        phone = "+25193%d%06d" % (n, int(SUFFIX) % 1000000)
        call("admin_create_service_giver", {"name": "Authz Shop %d %s" % (n, SUFFIX), "phone": phone, "password": "testpass123",
                                            "business_name": "Authz Kitchen %d" % n, "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, token=admin)
        s, r = call("sg_login", {"phone": phone, "password": "testpass123"})
        shops.append((r.get("data") or {}).get("token"))
    shop1, shop2 = shops
    check("test accounts exist (2 customers, courier, 2 shops)", all([cust_a, cust_b, courier, shop1, shop2]), [bool(x) for x in (cust_a, cust_b, courier, shop1, shop2)])
    if not all([cust_a, cust_b, courier, shop1, shop2]):
        return 1

    print("1. Role matrix over every admin_*, sg_* and courier_* action")
    names = [n for n in action_names() if n not in PUBLIC_BY_DESIGN]
    print("   %d actions found" % len(names))
    who = {"nobody": None, "customer": cust_a, "courier": courier, "shop": shop1}
    leaks = []
    for n in names:
        if n.startswith("admin_"):
            blocked = ["nobody", "customer", "courier", "shop"]
        elif n.startswith("sg_"):
            blocked = ["nobody", "customer", "courier"]
        else:
            blocked = ["nobody", "customer", "shop"]
        for w in blocked:
            s, r = call(n, {}, token=who[w])
            if not refused(s, r):
                leaks.append((n, w, s, str(r)[:120]))
    check("no admin/shop/courier action lets the wrong role in", not leaks, leaks[:12])

    print("2. Orders belong to their customer and shop")
    s, r = call("sg_add_item", {"name": "Authz Meal", "category": "food", "price": 100, "stock_quantity": 50}, token=shop1)
    item = (r.get("data") or {}).get("item_id")
    s, r = call("customer_create_order", {"items": [{"item_id": item, "quantity": 1}], "to_zone": "Tabor", "recipient_name": "A",
                                          "recipient_phone": "+251911000099", "payment_method": "cash"}, token=cust_a)
    oid = (r.get("data") or {}).get("order_id")
    check("customer A placed an order", bool(oid), r)
    if not oid:
        return 1

    s, r = call("track_order", {"order_id": oid}, token=cust_b)
    check("customer B cannot track A's order", r.get("success") is False, r)
    s, r = call("customer_cancel_order", {"order_id": oid, "reason": "changed_mind"}, token=cust_b)
    check("customer B cannot cancel A's order", r.get("success") is False, r)
    s, r = call("customer_cancel_order", {"order_id": oid, "reason": "changed_mind"}, token=courier)
    check("a courier cannot cancel A's order as a customer", r.get("success") is False, r)
    s, r = call("customer_cancel_order", {"order_id": oid, "reason": "changed_mind"}, token=None)
    check("nobody (no token) cannot cancel it", r.get("success") is False, r)
    s, r = call("sg_reject_order", {"order_id": oid, "reason": "too_busy"}, token=shop2)
    check("a different shop cannot decline it", r.get("success") is False, r)
    s, r = call("sg_reject_order", {"order_id": oid}, token=shop1)
    check("its own shop must give a reason to decline", r.get("success") is False, r)
    s, r = call("customer_cancel_order", {"order_id": oid}, token=cust_a)
    check("the owner must give a reason to cancel", r.get("success") is False, r)
    s, r = call("admin_cancel_order", {"order_id": oid, "reason": "other"}, token=cust_a)
    check("a customer cannot use the admin cancel", r.get("success") is False, r)
    s, r = call("track_order", {"order_id": oid}, token=cust_a)
    check("the order is still live after all those attempts", (r.get("data") or {}).get("order", r.get("data") or {}).get("status") not in ("cancelled",), r)
    s, r = call("customer_cancel_order", {"order_id": oid, "reason": "changed_mind"}, token=cust_a)
    check("the owner can cancel it", r.get("success") is True, r)
    s, r = call("customer_cancel_order", {"order_id": oid, "reason": "changed_mind"}, token=cust_a)
    check("cancelling twice is refused", r.get("success") is False, r)

    print("3. Saved addresses belong to their customer")
    s, r = call("customer_add_address", {"label": "Home", "address_line": "Authz street 1", "zone": "Tabor"}, token=cust_a)
    aid = (r.get("data") or {}).get("id")
    check("customer A saved an address", bool(aid), r)
    s, r = call("customer_update_address", {"id": aid, "address_line": "hijacked"}, token=cust_b)
    check("customer B cannot edit A's address", r.get("success") is False, r)
    s, r = call("customer_delete_address", {"id": aid}, token=cust_b)
    s, r = call("customer_get_addresses", {}, token=cust_a)
    lines = [a.get("address_line") for a in ((r.get("data") or {}).get("addresses") or [])]
    check("B's edit and delete attempts changed nothing", "Authz street 1" in lines and "hijacked" not in lines, lines)
    s, r = call("customer_get_addresses", {}, token=cust_b)
    check("customer B's list does not show A's address", "Authz street 1" not in [a.get("address_line") for a in ((r.get("data") or {}).get("addresses") or [])], r)

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
