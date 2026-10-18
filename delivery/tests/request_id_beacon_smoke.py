"""
Request ids and the browser error beacon: every API answer carries an X-Request-Id header and error answers repeat it as
"rid"; api/client-error.php stores one row per distinct error with a counter, keeps no query strings, ignores junk and
oversized bodies, answers 204 to everything; admins (and only admins) read the list and can clear it.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import re
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
BEACON = API_URL.replace("boot.php", "client-error.php")
SUFFIX = str(int(time.time()))
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
            return f.status, json.loads(f.read().decode()), dict(f.headers)
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode()), dict(e.headers)
        except Exception:
            return e.code, {}, dict(e.headers)


def beacon(payload, raw=None, method="POST"):
    data = raw if raw is not None else json.dumps(payload).encode()
    req = urllib.request.Request(BEACON, data=None if method == "GET" else data, headers={"Content-Type": "text/plain"}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=15) as f:
            return f.status
    except urllib.error.HTTPError as e:
        return e.code


def rows(where="1=1"):
    ok, out, err = etel_test_db.query_text("SELECT hits, source, page, rid, kind FROM client_errors WHERE " + where)
    return [l.split("\t") for l in out.strip().split("\n") if l.strip()] if ok else None


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("request ids and the browser error beacon")
st, res, hdr = call("ping")
rid = hdr.get("X-Request-Id") or hdr.get("x-request-id") or ""
check("a normal answer carries X-Request-Id", re.match(r"^[0-9a-f]{12}$", rid) is not None, hdr)
st, res, hdr2 = call("no_such_action_" + SUFFIX)
rid2 = hdr2.get("X-Request-Id") or hdr2.get("x-request-id") or ""
check("an error answer repeats it as rid", res.get("success") is False and res.get("rid") == rid2 and re.match(r"^[0-9a-f]{12}$", rid2) is not None, (res, hdr2))
check("each request gets its own id", rid != rid2)

st, res, hdr = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (res.get("data") or {}).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)
st, res, _ = call("register", {"name": "Beacon Rider", "phone": "+251971%s" % SUFFIX[-6:], "password": "test1234", "role": "customer"})
rider = (res.get("data") or {}).get("token")

etel_test_db.run_sql("DELETE FROM client_errors WHERE app='ridetest'")
msg = "Cannot read properties of undefined (reading 'x%s')" % SUFFIX
good = {"app": "ridetest", "kind": "js", "message": msg, "page": "/delivery/ride/?token=SECRET", "source": "https://ethelmarket.com/delivery/js/ride.js?v=3&k=SECRET", "line": 42, "stack": "at f (ride.js?token=SECRET:1:2)", "rid": "", "uid": 7, "ua": "Test"}
check("a report is accepted (204)", beacon(good) == 204)
r = rows("app='ridetest'")
check("and stored once", r is not None and len(r) == 1 and r[0][0] == "1", r)
check("without query strings", r and "SECRET" not in "".join(r[0]), r)
check("a GET writes nothing and answers 204", beacon(None, method="GET") == 204 and len(rows("app='ridetest'")) == 1)
beacon(dict(good, message=msg.replace(SUFFIX, "9" + SUFFIX[1:])))
r = rows("app='ridetest'")
check("the same error again only counts (numbers do not make a new row)", r and len(r) == 1 and r[0][0] == "2", r)
beacon(dict(good, kind="api", message="API book_ride answered 500", rid="abcdef012345", source="", line=0))
r = rows("app='ridetest' AND kind='api'")
check("a server-error report keeps its request id", r and r[0][3] == "abcdef012345", r)
check("an unknown kind is ignored (204, nothing stored)", beacon(dict(good, kind="spam", message="zzz")) == 204 and not rows("message='zzz'"))
check("a body that is not JSON is ignored", beacon(None, raw=b"not json") == 204)
check("an oversized body is ignored", beacon(None, raw=json.dumps(dict(good, message="big", stack="x" * 6000)).encode()) == 204 and not rows("message='big'"))
check("a report with no message or app is ignored", beacon({"kind": "js"}) == 204)

st, res, _ = call("admin_client_errors", {}, admin)
errs = (res.get("data") or {}).get("errors", [])
check("an admin reads the list", res.get("success") is True and any(e["app"] == "ridetest" for e in errs), res)
st, res, _ = call("admin_client_errors", {}, rider)
check("a rider cannot", res.get("success") is False and st in (401, 403), (st, res))
st, res, _ = call("admin_client_errors_clear", {}, rider)
check("a rider cannot clear", res.get("success") is False and st in (401, 403), (st, res))
eid = [e["id"] for e in errs if e["app"] == "ridetest" and e["kind"] == "api"]
st, res, _ = call("admin_client_errors_clear", {"id": eid[0]} if eid else {}, admin)
check("an admin clears one error", res.get("success") is True and not rows("app='ridetest' AND kind='api'"), res)
etel_test_db.run_sql("DELETE FROM client_errors WHERE app='ridetest'")

if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All request-id and beacon checks passed")
