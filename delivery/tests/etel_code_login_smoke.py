"""
ETEL Delivery -- the Etel Code on the real server: sign-in by code and a code typed in the agent / admin phone boxes.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/etel_code_login_smoke.py

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). The test server has no EtelID service key, so EtelID cannot be asked: a code
is never accepted without it (fails closed). Checks that
  - sign-in with an unknown code answers exactly like a wrong password (nothing is revealed about codes), for login and sg_login;
  - a stranger or a customer who types a code in an agent / admin phone box gets the SAME answer for a code that exists and one that
    does not (a real code cannot be told from a wrong one without being allowed to use the action);
  - an admin who types a code gets the clear "Etel Code not found" 404 when EtelID cannot confirm it (never booked for a guessed number).
The conversion rules themselves (who may, which fields, which actions) are in tests/etel_code_agent_test.php.
"""
import json
import sys
import time
import urllib.error
import urllib.request

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
ADMIN_PHONE = "0900000000"
ADMIN_PASSWORD = "admin1234"
PASS, FAIL = [], []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=20) as f:
            return f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8"))
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON error body)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:300]))


def same(a, b):
    return a[0] == b[0] and (a[1].get("message") == b[1].get("message"))


def main():
    s, r = call("login", {"phone": ADMIN_PHONE, "password": ADMIN_PASSWORD})
    admin = (r.get("data") or {}).get("token") if isinstance(r.get("data"), dict) else None
    if not admin:
        print("  SKIP no seeded demo admin; run api/seeds/admin_demo_accounts.sql")
        return 0
    cphone = "+251911%06d" % (int(SUFFIX) % 1000000)
    s, r = call("register", {"name": "Code Customer " + SUFFIX, "phone": cphone, "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token") if isinstance(r.get("data"), dict) else None
    if not cust:
        s, r = call("login", {"phone": cphone, "password": "testpass123"})
        cust = (r.get("data") or {}).get("token") if isinstance(r.get("data"), dict) else None

    print("1. Sign-in by code")
    wrong = call("login", {"phone": "+251900123456", "password": "nope-nope-1"})
    for code in ("E1616", "ENOBODY", "e-1616", " E 1616 "):
        got = call("login", {"phone": code, "password": "whatever123"})
        check("login with the code '%s' answers 401 like a wrong password" % code, got[0] == 401 and same(got, wrong), (got, wrong))
    got = call("sg_login", {"phone": "E1616", "password": "whatever123"})
    wrong_sg = call("sg_login", {"phone": "+251900123456", "password": "nope-nope-1"})
    check("sg_login with a code answers 401 like a wrong password", got[0] == 401 and same(got, wrong_sg), (got, wrong_sg))
    got = call("login", {"phone": "E12", "password": "x"})
    check("a too-short 'code' (E12) is not treated as a code and does not crash", got[0] in (400, 401, 404, 429), got)

    print("2. A code in the agent / admin phone boxes")
    actions = ("fa_agent_book_ride", "admin_phone_book_ride", "equb_admin_add_member", "equb_admin_grant_access", "equity_link_investor_user", "admin_set_etelpay_override")
    for act in actions:
        a = call(act, {"phone": "E1616"})
        b = call(act, {"phone": "ENOBODY"})
        check("%s, not signed in: a code and a wrong code get the same answer (401, nothing about codes)" % act, a[0] == 401 and same(a, b), (a, b))
        if cust:
            a = call(act, {"phone": "E1616"}, cust)
            b = call(act, {"phone": "ENOBODY"}, cust)
            check("%s, signed in as a customer: the same answer for both, not a 404 about codes" % act, a[0] in (401, 403) and same(a, b), (a, b))
    s, r = call("admin_phone_book_ride", {"phone": "E1616"}, admin)
    check("admin phone booking with a code that EtelID cannot confirm: 404 'Etel Code not found'", s == 404 and "Etel Code not found" in (r.get("message") or ""), (s, r))
    s, r = call("admin_phone_book_ride", {"phone": "0911999999"}, admin)
    check("admin phone booking with a normal phone is NOT answered with the code message", "Etel Code not found" not in (r.get("message") or ""), (s, r))

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
