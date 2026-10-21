"""
ETEL Delivery -- Admin -> People (Users tab): list, filters, profile, and the account controls over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/people_admin_smoke.py

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


def login(phone):
    s, r = call("login", {"phone": phone, "password": "testpass123"})
    return s, r, (r.get("data") or {}).get("token")


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    admin_id = ((r.get("data") or {}).get("user") or {}).get("id")
    if not admin:
        print("  SKIP no seeded demo admin")
        return 0
    n = int(SUFFIX) % 1000000
    cphone, kphone = "+251971%06d" % n, "+251972%06d" % n
    cname, kname = "Peop Cust %s" % SUFFIX, "Peop Cour %s" % SUFFIX
    cust, cid = register(cphone, "customer", cname)
    cour, kid = register(kphone, "courier", kname)
    check("a customer and a courier were created", bool(cust and cid and cour and kid))

    print("1. Who may open it")
    for act, body in (("admin_people_list", {}), ("admin_person_get", {"id": cid}),
                      ("admin_person_set_active", {"id": kid, "active": 0}), ("admin_person_force_logout", {"id": kid})):
        s, r = call(act, body, token=cust)
        check("a customer cannot call %s" % act, r.get("success") is False and s == 403, (s, r))
        s, r = call(act, body)
        check("no login, no %s" % act, r.get("success") is False and s in (401, 403), (s, r))

    print("2. The list")
    s, r = call("admin_people_list", {"q": SUFFIX}, token=admin)
    d = r.get("data") or {}
    ids = [p["id"] for p in d.get("people", [])]
    check("search by name finds both new accounts", r.get("success") and cid in ids and kid in ids, r)
    s, r = call("admin_people_list", {"q": SUFFIX, "role": "courier"}, token=admin)
    ppl = (r.get("data") or {}).get("people", [])
    check("the role filter keeps only couriers", [p["id"] for p in ppl] == [kid] and ppl[0]["role"] == "courier", r)
    s, r = call("admin_people_list", {"q": str(cid)}, token=admin)
    check("an account can be found by its id", any(p["id"] == cid for p in (r.get("data") or {}).get("people", [])), r)
    s, r = call("admin_people_list", {"q": "%"}, token=admin)
    check("a percent sign is searched literally", (r.get("data") or {}).get("count") == 0, r)
    sm = (r.get("data") or {}).get("summary") or {}
    check("the summary counts every role", sm.get("total", 0) >= 3 and (sm.get("by_role") or {}).get("customer", 0) >= 1 and "suspended" in sm and "new_7d" in sm, sm)
    for srt in ("recent", "oldest", "name", "orders", "bogus"):
        s, r = call("admin_people_list", {"sort": srt, "limit": 5}, token=admin)
        check("sort '%s' answers" % srt, r.get("success") is True, r)
    s, r = call("admin_people_list", {"status": "suspended", "q": SUFFIX}, token=admin)
    check("nobody new is disabled", (r.get("data") or {}).get("count") == 0, r)

    print("3. A profile")
    etel_test_db.run_sql(
        "INSERT INTO orders (order_code, customer_id, from_zone, to_zone, status, fare_total, placed_at) VALUES "
        "('PP%s-1', %d, 'a', 'b', 'delivered', 150, NOW()), ('PP%s-2', %d, 'a', 'b', 'cancelled', 60, NOW())" % (SUFFIX, cid, SUFFIX, cid))
    s, r = call("admin_person_get", {"id": cid}, token=admin)
    p = (r.get("data") or {}).get("person") or {}
    check("the profile has name, phone and role", r.get("success") and p.get("phone") == cphone and p.get("role") == "customer" and p.get("name") == cname, r)
    check("it is not marked as the viewer's own account", p.get("is_you") is False, p)
    check("it is active and has no duplicate-phone warning", p.get("suspended") is False and p.get("same_phone_accounts") == 0, p)
    s, r = call("admin_person_get", {"id": kid}, token=admin)
    check("a courier profile answers with role details", r.get("success") and isinstance((r["data"]["person"]).get("detail"), (dict, list)), r)
    s, r = call("admin_person_get", {}, token=admin)
    check("id is required", r.get("success") is False and s == 400, (s, r))
    s, r = call("admin_person_get", {"id": 999999999}, token=admin)
    check("an unknown id is 404", r.get("success") is False and s == 404, (s, r))
    s, r = call("admin_person_get", {"id": admin_id}, token=admin)
    check("an admin sees their own profile as theirs", (r.get("data") or {}).get("person", {}).get("is_you") is True, r)

    print("4. Disable and restore")
    s, r = call("admin_person_set_active", {"id": admin_id, "active": 0}, token=admin)
    check("nobody can disable their own account", r.get("success") is False and s == 400, (s, r))
    s, r = call("admin_person_force_logout", {"id": admin_id}, token=admin)
    check("nobody can sign themselves out from here", r.get("success") is False and s == 400, (s, r))
    s, r = call("admin_person_set_active", {"id": 999999999, "active": 0}, token=admin)
    check("disabling an unknown account is 404", r.get("success") is False and s == 404, (s, r))
    s, r = call("admin_person_set_active", {"id": cid, "active": 0, "reason": "smoke test"}, token=admin)
    check("an admin can disable a customer", r.get("success") and (r.get("data") or {}).get("suspended") is True, (s, r))
    s, r = call("customer_get_orders", {}, token=cust)
    check("the disabled customer's old token stops working", r.get("success") is False and s in (401, 403), (s, r))
    s, r, t = login(cphone)
    check("the disabled customer cannot sign in", not t and r.get("success") is False, (s, r))
    s, r = call("admin_people_list", {"status": "suspended", "q": SUFFIX}, token=admin)
    check("they show under Disabled", [x["id"] for x in (r.get("data") or {}).get("people", [])] == [cid], r)
    s, r = call("admin_person_get", {"id": cid}, token=admin)
    check("the profile says disabled", (r.get("data") or {}).get("person", {}).get("suspended") is True, r)
    s, r = call("admin_person_set_active", {"id": cid, "active": 1}, token=admin)
    check("an admin can restore them", r.get("success") and (r.get("data") or {}).get("suspended") is False, (s, r))
    s, r, t = login(cphone)
    check("the restored customer can sign in again", bool(t), (s, r))

    print("5. Sign out everywhere")
    s, r = call("admin_person_force_logout", {"id": cid}, token=admin)
    check("an admin can sign someone out of every device", r.get("success") and (r.get("data") or {}).get("signed_out") is True, (s, r))
    s, r = call("customer_get_orders", {}, token=t)
    check("the token they held stops working", r.get("success") is False and s in (401, 403), (s, r))
    s, r, t2 = login(cphone)
    check("they can sign in again afterwards", bool(t2), (s, r))

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
