"""
Live operations numbers, through the real server (real tables): only an admin may read them, the answer has the expected shape, and it changes nothing.
Uses the seeded demo accounts (no sign-ups, so the shared register limit is not touched).

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

print("live operations")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
cust = (r.get("data") or {}).get("token")
if not (admin and cust):
    check("demo accounts can sign in", False, (bool(admin), bool(cust)))
    sys.exit(1)

st, r = call("admin_live_ops", {})
check("nobody signed in is refused", st == 401, (st, r))
st, r = call("admin_live_ops", {}, cust)
check("a customer is refused", st == 403, (st, r))
st, r = call("admin_live_ops", {}, admin)
d = r.get("data") or {}
check("an admin gets the numbers", st == 200 and r.get("success") is True and "now" in d and "speed" in d and "alerts" in d, (st, str(r)[:300]))
n = d.get("now") or {}
check("the numbers right now are real counts (not missing)", all(isinstance(n.get("rides", {}).get(k), int) for k in ("searching", "on_the_way", "in_progress")) and isinstance(n.get("drivers", {}).get("online"), int) and isinstance(n.get("orders", {}).get("waiting"), int) and isinstance(n.get("couriers", {}).get("online"), int), n)
for w in ("today", "week"):
    s = (d.get("speed") or {}).get(w) or {}
    check("speed " + w + ": rides and orders have counts and a time summary", isinstance(s.get("rides", {}).get("requests"), int) and "secs_to_take" in s.get("rides", {}) and isinstance(s.get("orders", {}).get("placed"), int) and "secs_to_deliver" in s.get("orders", {}), s)
check("drivers online = busy + free", n.get("drivers", {}).get("free") is not None and n["drivers"]["online"] == n["drivers"]["busy"] + n["drivers"]["free"], n.get("drivers"))
st2, r2 = call("admin_live_ops", {}, admin)
check("asking twice gives the same counts (it changes nothing)", (r2.get("data") or {}).get("now", {}).get("rides") == n.get("rides"), (n.get("rides"), (r2.get("data") or {}).get("now", {}).get("rides")))

print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
