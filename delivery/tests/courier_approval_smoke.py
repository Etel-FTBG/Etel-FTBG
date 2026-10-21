"""
ETEL Delivery -- couriers who sign up on their own wait for an admin to approve them.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/courier_approval_smoke.py

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). Creates real rows: throwaway database only.
Switches the approval rule on for the run and puts it back to off (the CI default) at the end.
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


def register(phone, name):
    s, r = call("register", {"name": name, "phone": phone, "password": "testpass123", "role": "courier"})
    d = r.get("data") or {}
    return d.get("token"), d.get("user_id"), d


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("  SKIP no seeded demo admin")
        return 0
    n = int(SUFFIX) % 1000000
    try:
        print("1. The switch")
        s, r = call("admin_courier_approval_mode", {"require": 1}, token=admin)
        check("an admin can switch approval on", r.get("success") and (r.get("data") or {}).get("require") is True, (s, r))
        s, r = call("admin_courier_approval_mode", {}, token=admin)
        check("the switch can be read back", (r.get("data") or {}).get("require") is True, r)
        s, r = call("register", {"name": "Appr Cust %s" % SUFFIX, "phone": "+251961%06d" % n, "password": "testpass123", "role": "customer"})
        cust_tok, cust_id = (r.get("data") or {}).get("token"), (r.get("data") or {}).get("user_id")
        s, r = call("admin_courier_approval_mode", {"require": 0}, token=cust_tok)
        check("a customer cannot change the switch", r.get("success") is False and s == 403, (s, r))

        print("2. A new courier waits")
        tok, uid, d = register("+251962%06d" % n, "Appr Cour %s" % SUFFIX)
        check("sign-up works and says the courier is pending", bool(tok) and d.get("courier_approval") == "pending", d)
        s, r = call("courier_get_profile", {}, token=tok)
        check("the profile can still be read and says pending", r.get("success") and (r.get("data") or {}).get("approval_status") == "pending", (s, r))
        s, r = call("courier_go_online", {}, token=tok)
        check("a pending courier cannot go online", r.get("success") is False and s == 403 and "waiting for approval" in str(r.get("message")), (s, r))
        s, r = call("courier_get_available_orders", {}, token=tok)
        check("a pending courier cannot see jobs", r.get("success") is False and s == 403, (s, r))
        s, r = call("courier_go_offline", {}, token=tok)
        check("a pending courier can still go offline", r.get("success") is True, (s, r))

        print("3. The admin decides")
        s, r = call("admin_courier_set_approval", {"id": uid, "status": "approved"}, token=tok)
        check("the courier cannot approve themselves", r.get("success") is False and s == 403, (s, r))
        s, r = call("admin_people_list", {"q": "Appr Cour %s" % SUFFIX}, token=admin)
        row = ((r.get("data") or {}).get("people") or [{}])[0]
        check("the People list shows the courier as pending", row.get("approval") == "pending", r)
        check("the summary counts couriers to approve", ((r.get("data") or {}).get("summary") or {}).get("pending_couriers", 0) >= 1, r)
        s, r = call("admin_get_couriers", {}, token=admin)
        mine = [c for c in (r.get("data") or {}).get("couriers", []) if c.get("user_id") == uid]
        check("the Couriers list shows it first-class (approval_status pending)", mine and mine[0].get("approval_status") == "pending", r)
        s, r = call("admin_courier_set_approval", {"id": uid, "status": "bogus"}, token=admin)
        check("an unknown status is refused", r.get("success") is False and s == 400, (s, r))
        s, r = call("admin_courier_set_approval", {"id": cust_id, "status": "approved"}, token=admin)
        check("only couriers can be approved", r.get("success") is False, (s, r))
        s, r = call("admin_courier_set_approval", {"id": uid, "status": "rejected", "note": "photo missing"}, token=admin)
        check("an admin can turn a courier down", r.get("success") is True, (s, r))
        s, r = call("courier_go_online", {}, token=tok)
        check("a courier who was not approved cannot go online", r.get("success") is False and s == 403 and "not approved" in str(r.get("message")), (s, r))
        s, r = call("courier_get_profile", {}, token=tok)
        check("they can read why", (r.get("data") or {}).get("approval_status") == "rejected" and (r.get("data") or {}).get("approval_note") == "photo missing", r)
        s, r = call("admin_courier_set_approval", {"id": uid, "status": "approved"}, token=admin)
        check("an admin can approve", r.get("success") is True, (s, r))
        s, r = call("courier_go_online", {}, token=tok)
        check("an approved courier can go online", r.get("success") is True, (s, r))
        s, r = call("courier_get_available_orders", {}, token=tok)
        check("an approved courier can see jobs", r.get("success") is True, (s, r))

        print("4. Switched off")
        call("admin_courier_approval_mode", {"require": 0}, token=admin)
        tok2, uid2, d2 = register("+251963%06d" % n, "Appr Cour2 %s" % SUFFIX)
        check("with the switch off a new courier is approved straight away", d2.get("courier_approval") == "approved", d2)
        s, r = call("courier_go_online", {}, token=tok2)
        check("and can go online", r.get("success") is True, (s, r))
    finally:
        call("admin_courier_approval_mode", {"require": 0}, token=admin)

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
