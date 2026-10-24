"""
ETEL Delivery -- Admin -> Orders / Couriers / Service givers boards over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/admin_boards_smoke.py

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). Creates real rows: throwaway database only.
"""
import json
import sys
import time
import urllib.error
import urllib.request

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


def register(phone, role, name):
    s, r = call("register", {"name": name, "phone": phone, "password": "testpass123", "role": role})
    d = r.get("data") or {}
    return d.get("token"), d.get("user_id")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("  SKIP no seeded demo admin")
        return 0
    n = int(SUFFIX) % 1000000
    kname = "Board Cour %s" % SUFFIX
    cust, cid = register("+251981%06d" % n, "customer", "Board Cust %s" % SUFFIX)
    cour, kid = register("+251982%06d" % n, "courier", kname)
    check("a customer and a courier were created", bool(cust and cour and kid))

    print("1. Who may open the boards")
    for act in ("admin_orders_board", "admin_couriers_board", "admin_service_givers_board"):
        s1, _ = call(act, {})
        s2, _ = call(act, {}, cust)
        s3, _ = call(act, {}, cour)
        s4, _ = call(act, {}, admin)
        check(act + ": no login refused", s1 in (401, 403), s1)
        check(act + ": customer and courier refused", s2 in (401, 403) and s3 in (401, 403), (s2, s3))
        check(act + ": admin allowed", s4 == 200, s4)
    for act, body in (("admin_order_detail", {"id": 1}), ("admin_courier_detail", {"id": 1}), ("admin_service_giver_detail", {"id": 1})):
        s2, _ = call(act, body, cust)
        check(act + ": customer refused", s2 in (401, 403), s2)
    for act, body in (("admin_verify_service_giver", {"id": 1}), ("admin_suspend_service_giver", {"id": 1}), ("admin_update_courier", {"courier_id": 1, "zone": "x"})):
        s2, _ = call(act, body, cust)
        s3, _ = call(act, body, cour)
        check(act + ": customer and courier refused", s2 in (401, 403) and s3 in (401, 403), (s2, s3))

    print("2. Orders board")
    s, r = call("admin_orders_board", {"period": "all", "limit": 50}, admin)
    d = r.get("data") or {}
    check("orders board answers with rows and a summary", s == 200 and isinstance(d.get("orders"), list) and isinstance(d.get("summary"), dict), r)
    for k in ("total", "pending", "assigned", "on_way", "delivered", "cancelled", "today", "revenue_today", "late"):
        check("summary has " + k, k in (d.get("summary") or {}), d.get("summary"))
    for st in ("pending", "delivered", "bogus"):
        s, r = call("admin_orders_board", {"period": "all", "status": st}, admin)
        rows = (r.get("data") or {}).get("orders") or []
        check("status filter %s works" % st, s == 200 and all(o["status"] == st for o in rows) if st != "bogus" else s == 200, r)
    s, r = call("admin_orders_board", {"period": "30d", "q": "zz-no-such-order-%s" % SUFFIX}, admin)
    check("search with no match is an empty list", s == 200 and (r.get("data") or {}).get("orders") == [], r)
    s, r = call("admin_orders_board", {"period": "all", "q": "50%_\\'\""}, admin)
    check("search with wildcard and quote characters is safe", s == 200, r)
    first = (d.get("orders") or [None])[0]
    if first:
        s, r = call("admin_order_detail", {"id": first["id"]}, admin)
        dd = r.get("data") or {}
        check("order detail has the order, controls and couriers", s == 200 and dd.get("order", {}).get("id") == first["id"] and "controls" in dd and "couriers" in dd, r)
    s, r = call("admin_order_detail", {"id": 999999999}, admin)
    check("unknown order is a 404", s == 404, (s, r))
    s, r = call("admin_order_detail", {}, admin)
    check("order detail needs an id", s == 400, (s, r))

    print("3. Couriers board")
    s, r = call("admin_couriers_board", {}, admin)
    d = r.get("data") or {}
    mine = [c for c in (d.get("couriers") or []) if c.get("user_id") == kid]
    check("couriers board lists the new courier", s == 200 and len(mine) == 1, r)
    sm = d.get("summary") or {}
    for k in ("total", "online", "busy", "offline", "pending", "suspended", "deliveries_today"):
        check("courier summary has " + k, k in sm, sm)
    if mine:
        cr = mine[0]
        for k in ("name", "phone", "courier_code", "today_deliveries", "live_jobs", "suspended", "rating"):
            check("courier row has " + k, k in cr, cr)
        s, r = call("admin_courier_detail", {"id": cr["id"]}, admin)
        dd = r.get("data") or {}
        check("courier detail has the profile, stats and recent jobs", s == 200 and dd.get("courier", {}).get("id") == cr["id"] and "stats" in dd and isinstance(dd.get("recent"), list), r)
        s, r = call("admin_update_courier", {"courier_id": cr["id"], "zone": "Board Zone"}, admin)
        check("admin can edit a courier's zone", s == 200, r)
        s, r = call("admin_courier_detail", {"id": cr["id"]}, admin)
        check("the edit shows in the detail", ((r.get("data") or {}).get("courier") or {}).get("zone") == "Board Zone", r)
    s, r = call("admin_courier_detail", {"id": 999999999}, admin)
    check("unknown courier is a 404", s == 404, (s, r))

    print("4. Service givers board")
    s, r = call("admin_service_givers_board", {}, admin)
    d = r.get("data") or {}
    check("service givers board answers", s == 200 and isinstance(d.get("service_givers"), list) and isinstance(d.get("summary"), dict), r)
    for k in ("total", "verified", "pending", "suspended", "online", "orders_today"):
        check("service giver summary has " + k, k in (d.get("summary") or {}), d.get("summary"))
    first = (d.get("service_givers") or [None])[0]
    if first:
        s, r = call("admin_service_giver_detail", {"id": first["id"]}, admin)
        dd = r.get("data") or {}
        check("service giver detail has the profile and stats", s == 200 and dd.get("service_giver", {}).get("id") == first["id"] and "stats" in dd, r)
    s, r = call("admin_service_giver_detail", {"id": 999999999}, admin)
    check("unknown service giver is a 404", s == 404, (s, r))

    print("5. Needs your attention (dashboard)")
    s1, _ = call("admin_attention", {})
    s2, _ = call("admin_attention", {}, cust)
    s3, _ = call("admin_attention", {}, cour)
    check("attention: no login, customer and courier refused", s1 in (401, 403) and s2 in (401, 403) and s3 in (401, 403), (s1, s2, s3))
    s, r = call("admin_attention", {}, admin)
    d = r.get("data") or {}
    check("attention answers with a list and an all_clear flag", s == 200 and isinstance(d.get("items"), list) and isinstance(d.get("all_clear"), bool), r)
    check("all_clear matches the list", d.get("all_clear") == (len(d.get("items") or []) == 0), d)
    for it in d.get("items") or []:
        check("attention line %s is complete and positive" % it.get("key"), all(k in it for k in ("key", "label", "count", "page", "filter")) and it["count"] > 0, it)

    print("6. Equb oversight")
    for act, body in (("equb_admin_group_detail", {"group_id": 1}), ("equb_admin_update_package", {"id": 1, "active": 0})):
        s1, _ = call(act, body)
        s2, _ = call(act, body, cust)
        s3, _ = call(act, body, cour)
        check(act + ": no login, customer and courier refused", s1 in (401, 403) and s2 in (401, 403) and s3 in (401, 403), (s1, s2, s3))
    s, r = call("equb_admin_group_detail", {}, admin)
    check("group detail needs a group_id", s == 400, (s, r))
    s, r = call("equb_admin_update_package", {}, admin)
    check("package update needs an id", s == 400, (s, r))
    s, r = call("equb_admin_update_package", {"id": 1}, admin)
    check("package update with nothing to change is refused", s == 400, (s, r))
    s, r = call("equb_admin_update_package", {"id": 1, "tier": "diamond"}, admin)
    check("package update refuses an unknown tier", s == 400, (s, r))

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
