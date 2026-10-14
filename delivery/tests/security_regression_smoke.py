"""
ETEL Delivery -- Security/validation regression smoke test

Exercises the fixes made in this session against a real running instance of
boot.php, so future changes to auth, validation, or the Telebirr webhook get
caught by actually executing the code - not just a static syntax check.

Usage (same convention as open_meter_smoke.py):

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/security_regression_smoke.py

Exit code 0 = all checks passed. Any failure prints what was expected vs.
what the API actually returned and exits non-zero.

What this covers (see the "why" comment on each test for the specific bug
it would have caught):
  1. Registration rejects a malformed phone number
  2. Registration rejects a password under 8 characters
  3. Registration + login round-trip succeeds and returns a working token
  4. logout() actually revokes the token server-side (not just client-side)
  5. Repeated failed logins get rate-limited
  6. Telebirr callback rejects a request with no signature header
  7. Item creation rejects a non-numeric / negative price

This intentionally creates real rows in the target database (same tradeoff
open_meter_smoke.py makes) - point it at a throwaway/dev database, not
production.
"""
import json
import sys
import time
import urllib.error
import urllib.request

API_URL = "http://127.0.0.1:8765/api/boot.php"
CALLBACK_URL = "http://127.0.0.1:8765/api/telebirr-callback.php"
SUFFIX = str(int(time.time()))

PASS = []
FAIL = []


def call(action, body=None, token=None, expect_success=None, url=API_URL):
    body = dict(body or {})
    body["action"] = action
    data = json.dumps(body).encode("utf-8")
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(url, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=10) as f:
            status = f.status
            res = json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        status = e.code
        try:
            res = json.loads(e.read().decode("utf-8"))
        except Exception:
            res = {"success": False, "message": "(non-JSON error body)"}
    return status, res


def check(name, condition, detail=""):
    if condition:
        PASS.append(name)
        print(f"  OK   {name}")
    else:
        FAIL.append(name)
        print(f"  FAIL {name}  {detail}")


def main():
    print("1. Registration input validation")
    # Why: registration used to strip junk characters and store whatever was
    # left with no length/format check at all (see api/validate.php).
    status, res = call("register", {
        "name": "Bad Phone Test", "phone": "12345", "password": "testpass123",
    })
    check("register rejects malformed phone", res.get("success") is False, res)

    status, res = call("register", {
        "name": "Weak Pw Test", "phone": f"+251911{SUFFIX[-6:]}", "password": "short",
    })
    check("register rejects password under 8 chars", res.get("success") is False, res)

    print("2. Registration + login round-trip")
    phone = f"+251922{SUFFIX[-6:]}"
    status, res = call("register", {
        "name": f"Smoke {SUFFIX}", "phone": phone, "password": "testpass123", "role": "customer",
    })
    check("register succeeds with valid input", res.get("success") is True, res)
    token = (res.get("data") or {}).get("token")
    check("register returns a token", bool(token), res)

    status, res = call("login", {"phone": phone, "password": "testpass123"})
    check("login succeeds with correct password", res.get("success") is True, res)
    login_token = (res.get("data") or {}).get("token")
    check("login returns a token", bool(login_token), res)

    print("3. Token revocation (logout)")
    # Why: bearer tokens used to be pure stateless HMACs with no way to
    # revoke one before its 7-day expiry - logout didn't actually invalidate
    # anything server-side. See migration 006 + etel_bump_token_version().
    status, res = call("ping", {}, token=login_token)
    check("token works before logout", res.get("success") is True, res)

    status, res = call("logout", {}, token=login_token)
    check("logout call succeeds", res.get("success") is True, res)

    status, res = call("driver_get_profile", {}, token=login_token)
    check(
        "same token is rejected after logout",
        res.get("success") is False and status == 401,
        f"status={status} res={res}",
    )

    print("4. Login rate limiting")
    # Why: registration had no rate limiting at all before this session;
    # confirming login's existing limiter still trips is a good canary that
    # the shared rate_limit_check()/rate_limit_fail() helpers still work.
    rl_phone = f"+251933{SUFFIX[-6:]}"
    call("register", {"name": "RL Test", "phone": rl_phone, "password": "testpass123"})
    tripped = False
    for _ in range(7):
        status, res = call("login", {"phone": rl_phone, "password": "wrong-password"})
        if status == 429:
            tripped = True
            break
    check("repeated failed logins eventually get rate-limited (429)", tripped)

    print("5. Telebirr webhook signature enforcement")
    # Why: the callback used to accept a request with NO signature header at
    # all (`if ($signature && ...)` - a missing header skipped verification
    # entirely). This is the single most important regression check in this
    # file: without it, anyone could POST a fake "payment completed" event.
    status, res = call(
        "COMPLETED",  # unused, callback reads its own JSON body directly
        {"merchant_transaction_id": "SMOKE-TEST-1", "status": "COMPLETED"},
        url=CALLBACK_URL,
    )
    check(
        "callback with no signature header is rejected (401)",
        status == 401 and res.get("success") is not True,
        f"status={status} res={res}",
    )

    print("6. Currency amount validation")
    # Why: amounts pulled straight from client input (item prices, wallet
    # cashout amounts) used to go through floatval() with inconsistent or
    # no bounds checking - a non-numeric or negative value could slip
    # through as 0 instead of being rejected outright. See
    # etel_validate_amount() in api/validate.php. driver_cashout is used
    # here because, unlike item creation, it only requires a plain
    # 'courier'-role token (already registered above), not an admin/
    # service-giver account this script doesn't have set up.
    drv_phone = f"+251944{SUFFIX[-6:]}"
    call("register", {"name": "Cashout Test Drv", "phone": drv_phone, "password": "testpass123", "role": "courier"})
    status, res = call("login", {"phone": drv_phone, "password": "testpass123"})
    drv_token = (res.get("data") or {}).get("token")

    status, res = call("driver_cashout", {"amount": "not-a-number"}, token=drv_token)
    check("cashout rejects a non-numeric amount", res.get("success") is False, res)

    status, res = call("driver_cashout", {"amount": -50}, token=drv_token)
    check("cashout rejects a negative amount", res.get("success") is False, res)

    print("7. Admin ratings report is for admins only")
    status, res = call("admin_ratings_report", {"days": 30})
    check("ratings report without a token is refused (401)", res.get("success") is False and status == 401, f"status={status} res={res}")
    status, res = call("admin_ratings_report", {"days": 30}, token=drv_token)
    check("ratings report for a non-admin account is refused (403)", res.get("success") is False and status == 403, f"status={status} res={res}")

    print("8. Shop hours and sold-out are for the shop's own staff only")
    for act, body in (("sg_set_hours", {"opens_at": "08:00", "closes_at": "21:00"}), ("sg_sold_out", {"item_id": 1, "sold_out": 1})):
        status, res = call(act, body)
        check(act + " without a token is refused (401)", res.get("success") is False and status == 401, f"status={status} res={res}")
        status, res = call(act, body, token=drv_token)
        check(act + " for a courier account is refused (403)", res.get("success") is False and status == 403, f"status={status} res={res}")

    print()
    print(f"{len(PASS)} passed, {len(FAIL)} failed")
    if FAIL:
        print("Failed checks:", ", ".join(FAIL))
        sys.exit(1)
    print("ALL CHECKS PASSED")


if __name__ == "__main__":
    main()
