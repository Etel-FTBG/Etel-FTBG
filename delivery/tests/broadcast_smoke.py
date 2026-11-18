"""
Messages to phones from Admin, through the real server: only the owner admin can preview, send and read the history; the people are counted and
checked; a message goes out and the history says how it went. The push itself goes to a fake address that is refused at once (no real network call).
Uses the seeded demo accounts (no sign-ups, so the shared register limit is not touched). Everything is put back at the end.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

BASE = os.environ.get("ETEL_API_BASE", "http://127.0.0.1:8765/api/")
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(BASE + "boot.php", data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("broadcast: preview, send, history")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
cust = (r.get("data") or {}).get("token")
if not (admin and cust):
    check("demo accounts can sign in", False, (bool(admin), bool(cust)))
    sys.exit(1)
etel_test_db.run_sql("DELETE FROM push_broadcasts")
etel_test_db.run_sql("DELETE FROM push_subscriptions WHERE endpoint = 'https://push.invalid/smoke'")
M = {"audience": "user", "phone": "+251911000001", "title": "Smoke test", "body": "Hello from the smoke test"}

for a in ("admin_broadcast_preview", "admin_broadcast_send", "admin_broadcast_list"):
    st, r = call(a, M, cust)
    check(a + ": a customer is refused", st == 403, (st, r))
    st, r = call(a, M)
    check(a + ": nobody signed in is refused", st == 401, (st, r))

st, r = call("admin_broadcast_preview", {"audience": "everyone"}, admin)
check("an unknown audience is refused", st == 422, (st, r))
st, r = call("admin_broadcast_preview", {"audience": "user", "phone": "0999999999"}, admin)
check("an unknown phone number is refused", st == 422, (st, r))
st, r = call("admin_broadcast_preview", {"audience": "user", "phone": "+251911000001"}, admin)
d = r.get("data") or {}
check("the demo customer is found and has no device yet", st == 200 and d.get("people") == 1 and d.get("reachable") == 0 and d.get("name"), (st, r))
st, r = call("admin_broadcast_send", M, admin)
check("sending to somebody with no device is refused with a reason", st == 422 and "notification" in (r.get("message") or "").lower(), (st, r))
st, r = call("admin_broadcast_send", dict(M, title=""), admin)
check("an empty title is refused", st == 422, (st, r))

ok2, why2 = etel_test_db.run_sql("INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth) SELECT id, 'https://push.invalid/smoke', SHA2('https://push.invalid/smoke', 256), 'x', 'y' FROM users WHERE phone = '+251911000001' LIMIT 1")
if not ok2:
    check("test device row could be added", False, why2)
else:
    st, r = call("admin_broadcast_preview", {"audience": "user", "phone": "+251911000001"}, admin)
    check("with a device the person can receive", (r.get("data") or {}).get("reachable") == 1, r)
    st, r = call("admin_broadcast_send", M, admin)
    check("the owner sends to one person", st == 200 and (r.get("data") or {}).get("recipients") == 1, (st, r))
    st, r = call("admin_broadcast_send", M, admin)
    check("the same message again is refused (double click)", st == 422, (st, r))
    st, r = call("admin_broadcast_list", {}, admin)
    h = (r.get("data") or {}).get("broadcasts") or []
    check("the history has it, finished, with the person and the result", st == 200 and len(h) == 1 and h[0]["status"] == "sent" and h[0]["recipients"] == 1 and h[0]["reached"] + h[0]["failed"] == 1 and h[0]["target_name"], h)

etel_test_db.run_sql("DELETE FROM push_broadcasts")
etel_test_db.run_sql("DELETE FROM push_subscriptions WHERE endpoint = 'https://push.invalid/smoke'")
print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
