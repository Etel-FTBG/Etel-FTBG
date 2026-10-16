"""
ETEL Delivery -- Admin -> Customers over the real API (list, search, sort, detail, who may open it).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/customers_admin_smoke.py

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
    phone = "+251915%06d" % n
    name = "Cust Smoke %s" % SUFFIX
    cust, cid = register(phone, "customer", name)
    other, _ = register("+251916%06d" % n, "courier", "Cour Smoke %s" % SUFFIX)
    check("a customer was created", bool(cust) and bool(cid))

    print("1. Who may open it")
    s, r = call("admin_customers_list", {}, token=cust)
    check("a customer cannot list customers", r.get("success") is False and s == 403, r)
    s, r = call("admin_customers_list", {})
    check("no login, no list", r.get("success") is False)
    s, r = call("admin_customer_get", {"id": cid}, token=cust)
    check("a customer cannot open a customer", r.get("success") is False and s == 403, r)

    print("2. The list")
    s, r = call("admin_customers_list", {"q": name}, token=admin)
    d = r.get("data") or {}
    rows = d.get("customers") or []
    check("admin finds the customer by name", r.get("success") and len(rows) == 1 and rows[0]["phone"] == phone, r)
    check("a new customer has no orders", rows and rows[0]["orders_total"] == 0 and rows[0]["spent"] == 0)
    check("the summary counts customers", (d.get("summary") or {}).get("total", 0) >= 1, d)
    s, r = call("admin_customers_list", {"q": phone[-6:]}, token=admin)
    check("search works on part of the phone", any(c["id"] == cid for c in (r.get("data") or {}).get("customers", [])), r)
    s, r = call("admin_customers_list", {"q": "%"}, token=admin)
    check("a percent sign is searched literally", (r.get("data") or {}).get("count") == 0, r)
    for srt in ("recent", "orders", "spent", "name", "bogus"):
        s, r = call("admin_customers_list", {"sort": srt, "limit": 5}, token=admin)
        check("sort '%s' answers" % srt, r.get("success") is True, r)
    s, r = call("admin_customers_list", {"limit": 5}, token=admin)
    check("only customers are listed", all(c["name"] != "Cour Smoke %s" % SUFFIX for c in (r.get("data") or {}).get("customers", [])))

    print("3. Orders add up")
    ok1, _ = etel_test_db.run_sql(
        "INSERT INTO orders (order_code, customer_id, from_zone, to_zone, status, fare_total, placed_at) VALUES "
        "('CS%s-1', %d, 'a', 'b', 'delivered', 120.50, NOW()), ('CS%s-2', %d, 'a', 'b', 'cancelled', 80, NOW()), ('CS%s-3', %d, 'a', 'b', 'delivered', 79.50, NOW())"
        % (SUFFIX, cid, SUFFIX, cid, SUFFIX, cid))
    if ok1:
        s, r = call("admin_customers_list", {"q": name}, token=admin)
        c = ((r.get("data") or {}).get("customers") or [{}])[0]
        check("3 orders, 2 delivered, 1 cancelled", (c.get("orders_total"), c.get("delivered"), c.get("cancelled")) == (3, 2, 1), c)
        check("spent counts delivered orders only", abs(c.get("spent", 0) - 200.0) < 0.01, c)
        s, r = call("admin_customer_get", {"id": cid}, token=admin)
        dd = r.get("data") or {}
        check("detail shows the customer and the orders", r.get("success") and dd.get("customer", {}).get("orders_total") == 3 and len(dd.get("orders", [])) == 3, r)
        check("newest order first", dd.get("orders") and dd["orders"][0]["order_code"].endswith("-3"), dd)
    else:
        print("  SKIP no test database for the order totals")

    print("4. Detail")
    s, r = call("admin_customer_get", {}, token=admin)
    check("id is required", r.get("success") is False and s == 400, r)
    s, r = call("admin_customer_get", {"id": 999999999}, token=admin)
    check("an unknown id is 404", r.get("success") is False and s == 404, r)

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
