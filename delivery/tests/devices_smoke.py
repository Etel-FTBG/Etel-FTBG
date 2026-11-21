"""
Signed-in devices, through the real server: every token the server sees shows up as a device (with a readable name), the person can sign one
device out (it is refused from then on, the others keep working), sign all the others out, cannot touch somebody else's devices, and a signed-out
token cannot be brought back by re-spelling it. Uses the seeded demo accounts. Needs the running API (php -S ... -t .) and a throwaway test
database. Skips cleanly without a database.
"""
import base64
import json
import os
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

BASE = os.environ.get("ETEL_API_BASE", "http://127.0.0.1:8765/api/")
fails = []
APP = "okhttp/4.12.0"
WIN = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36"
IPH = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1"


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None, ua=APP):
    headers = {"Content-Type": "application/json", "User-Agent": ua}
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


def login(phone, pw, ua):
    time.sleep(1.1)  # tokens carry the second they were made in: two sign-ins in one second would be the same token
    st, r = call("login", {"phone": phone, "password": pw}, ua=ua)
    return (r.get("data") or {}).get("token")


def devices(token, ua=APP):
    st, r = call("my_devices", {}, token, ua)
    return st, (r.get("data") or {}).get("devices") or []


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("signed-in devices")
etel_test_db.run_sql("DELETE FROM auth_sessions")
A = login("+251911000001", "etel1234", APP)
B = login("+251911000001", "etel1234", WIN)
C = login("+251911000001", "etel1234", IPH)
ADMIN = login("0900000000", "admin1234", WIN)
if not (A and B and C and ADMIN):
    check("demo accounts can sign in", False, (bool(A), bool(B), bool(C), bool(ADMIN)))
    sys.exit(1)

st, d = devices(B, WIN)
check("a token is registered the first time it is used", st == 200 and len(d) == 1 and d[0]["current"] is True, (st, d))
st, d = devices(C, IPH)
st, d = devices(A, APP)
labels = sorted(x["label"] for x in d)
check("all three devices are listed, with readable names", len(d) == 3 and labels == sorted(["ETEL phone app (Android)", "Chrome on Windows", "Safari on iPhone"]), labels)
check("only the asking device is marked current", [x["current"] for x in d].count(True) == 1 and [x["label"] for x in d if x["current"]] == ["ETEL phone app (Android)"], d)
check("the address is hidden in part", all(("x" in (x["ip"] or "x")) for x in d), [x["ip"] for x in d])
check("the answer carries the server clock", isinstance(((call("my_devices", {}, A)[1]).get("data") or {}).get("now"), str))
check("the token itself is never in the answer", all("token" not in json.dumps(x) for x in d))
idB = [x["id"] for x in d if x["label"] == "Chrome on Windows"][0]
idC = [x["id"] for x in d if x["label"] == "Safari on iPhone"][0]

st, r = call("device_sign_out", {"id": idB}, ADMIN, WIN)
check("somebody else cannot sign my device out", st == 404, (st, r))
st, r = call("device_sign_out", {}, A)
check("an id is required", st == 400, (st, r))
st, r = call("device_sign_out", {"id": idB}, A)
check("I can sign one of my devices out", st == 200 and r.get("success") is True, (st, r))
st, r = call("my_devices", {}, B, WIN)
check("the signed-out device is refused right away", st == 401, (st, r))
st, d = devices(A, APP)
check("the others keep working and the list no longer shows it", st == 200 and len(d) == 2 and idB not in [x["id"] for x in d], (st, d))
st, r = call("device_sign_out", {"id": idB}, A)
check("signing out the same device twice says so", st == 404, (st, r))

raw = base64.b64decode(B).decode()
for name, spelled in (("an extra part added", raw + ":x"), ("a trailing colon", raw + ":"), ("without the last part", ":".join(raw.split(":")[:3]))):
    st, r = call("my_devices", {}, base64.b64encode(spelled.encode()).decode(), WIN)
    check("a signed-out token cannot come back re-spelled (%s)" % name, st == 401, (st, r))

st, r = call("devices_sign_out_others", {}, A)
check("sign out all the others reports how many", st == 200 and (r.get("data") or {}).get("signed_out") == 1, (st, r))
st, r = call("my_devices", {}, C, IPH)
check("the other device (iPhone) is refused", st == 401, (st, r))
st, d = devices(A, APP)
check("this device stays signed in, alone in the list", st == 200 and len(d) == 1 and d[0]["current"] is True, (st, d))
st, d = devices(ADMIN, WIN)
check("another person's devices are untouched", st == 200 and len(d) == 1 and d[0]["label"] == "Chrome on Windows", d)

st, r = call("device_sign_out", {"id": d[0]["id"]}, ADMIN, WIN)
check("signing out the device I am using works too (the app then signs in again)", st == 200, (st, r))
st, r = call("my_devices", {}, ADMIN, WIN)
check("... and that token is refused afterwards", st == 401, (st, r))

st, r = call("my_devices", {})
check("no token: refused as before", st == 401, (st, r))
etel_test_db.run_sql("DELETE FROM auth_sessions")
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
