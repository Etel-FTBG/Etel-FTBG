"""
Crash reports from the ETEL phone app, through the real server: a note shaped exactly like the one the app sends is accepted by api/client-error.php,
shows up once in the admin list (admin_client_errors) under the app name "etelapp" with a counter, a repeat only raises the counter, a different app
version is its own row, and notes the app never sends (wrong kind, no message) are ignored. Uses the seeded demo admin. Needs the running API
(php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
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


def post(url, body, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            raw = f.read().decode()
            return f.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        return e.code, {}


def note(**kw):
    n = {"app": "etelapp", "kind": "js", "message": "Cannot read property x of undefined", "page": "screen - app 1.8.2 - android 14",
         "source": "src/screens/Foo.tsx", "line": 88, "stack": "TypeError: Cannot read property x of undefined\n    at src/screens/Foo.tsx:88:3", "uid": 2,
         "ua": "ETEL app 1.8.2 (android 14)"}
    n.update(kw)
    return n


def rows(token):
    st, r = post(BASE + "boot.php", {"action": "admin_client_errors", "days": 7}, token)
    return [x for x in (r.get("data") or {}).get("errors", []) if x["app"] == "etelapp"]


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("app crash reports")
etel_test_db.run_sql("DROP TABLE IF EXISTS client_errors")
st, r = post(BASE + "boot.php", {"action": "login", "phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
if not admin:
    check("the demo admin can sign in", False, r)
    sys.exit(1)

CE = BASE + "client-error.php"
st, _ = post(CE, note())
check("the note is accepted (204, nothing echoed back)", st == 204, st)
got = rows(admin)
check("it is in the admin list once, under the app name etelapp", len(got) == 1 and got[0]["kind"] == "js" and got[0]["hits"] == 1, got)
check("the message, screen text and app version are kept", got and got[0]["message"].startswith("Cannot read property") and "app 1.8.2" in got[0]["page"] and "1.8.2" in got[0]["ua"], got)
check("the file and line are kept", got and got[0]["source"] == "src/screens/Foo.tsx" and got[0]["line_no"] == 88, got)
check("the signed-in user id is kept", got and got[0]["user_id"] == 2, got)

post(CE, note())
post(CE, note())
got = rows(admin)
check("the same error again only raises the counter", len(got) == 1 and got[0]["hits"] == 3, got)

post(CE, note(page="screen - app 1.9.0 - android 14", ua="ETEL app 1.9.0 (android 14)"))
got = rows(admin)
check("the same error from a newer app version is its own row (so a fix can be seen)", len(got) == 2, [g["page"] for g in got])

post(CE, note(kind="promise", message="Nobody caught this", page="promise - app 1.8.2 - android 14"))
got = rows(admin)
check("an unhandled promise is stored as kind promise", len(got) == 3 and any(g["kind"] == "promise" for g in got), [g["kind"] for g in got])

before = len(got)
post(CE, note(kind="fatal", message="Wrong kind"))
post(CE, note(message=""))
post(CE, note(app=""))
check("a wrong kind, an empty message and no app name are ignored", len(rows(admin)) == before, len(rows(admin)))

post(CE, note(message="Failed with token=abc123 for order 77", page="screen - app 1.8.2 - android 14", source="src/x.ts?token=SECRET", line=5))
got = [g for g in rows(admin) if g["message"].startswith("Failed with")]
check("a query string on the file name never reaches the list", got and "SECRET" not in json.dumps(got), got)

st, r = post(BASE + "boot.php", {"action": "admin_client_errors", "days": 7})
check("the list itself needs an admin sign-in", st in (401, 403), st)
etel_test_db.run_sql("DROP TABLE IF EXISTS client_errors")
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
