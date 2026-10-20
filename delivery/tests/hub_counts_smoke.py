"""
hub_counts: the small live counts behind the ETEL app's golden light. It needs a signed-in user, returns the four counts
and their total, and reads 0 for an account with nothing going on (for any role).

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
TAIL = str(int(time.time()))[-6:]
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
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

print("hub counts")
st, res = call("hub_counts", {})
check("needs a signed-in user", st == 401, (st, res))

for i, role in enumerate(("customer", "courier")):
    st, res = call("register", {"name": "Hub %s" % role, "phone": "+25194%d%s" % (i + 5, TAIL), "password": "test1234", "role": role})
    token = (res.get("data") or {}).get("token")
    if not token:
        check("%s registered" % role, False, res)
        continue
    st, res = call("hub_counts", {}, token)
    d = res.get("data") or {}
    check("%s gets the counts" % role, res.get("success") is True and all(k in d for k in ("trips", "requests", "orders", "deliveries", "total")), res)
    check("%s with nothing going on reads 0" % role, d.get("total") == 0, d)

if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All hub count checks passed")
