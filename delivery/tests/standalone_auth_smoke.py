"""
ETEL Delivery -- the API files that read the login token themselves must apply the same sign-in rules as boot.php.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/standalone_auth_smoke.py

updater-api.php (it can write PHP files on the server!), upload.php, admin-branding.php, documents_upload.php and
documents_download.php used to check only the token signature, age and token_version. They did not refuse a device the
person signed out, a disabled account, an admin without two-step sign-in, or (updater) a staff admin without the
owner permission 'system.backup' (the code asked for it, but require_admin() ignored the argument).
Now they call lib/standalone_auth.php. Needs the seeded demo admin and customer (api/seeds/admin_demo_accounts.sql).
Throwaway database only. Puts every setting back at the end.
"""
import json
import sys
import urllib.error
import urllib.request

import etel_test_db

BASE = "http://127.0.0.1:8765/api/"
ADMIN = ("0900000000", "admin1234")
PASS, FAIL = [], []


def call(url, body=None, token=None, method=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, headers=headers, method=method or ("POST" if data else "GET"))
    try:
        with urllib.request.urlopen(req, timeout=20) as f:
            raw = f.read().decode("utf-8")
            code = f.status
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8")
        code = e.code
    try:
        return code, json.loads(raw)
    except Exception:
        return code, {"success": False, "message": "(non-JSON) " + raw[:120]}


def login(phone, pw):
    s, r = call(BASE + "boot.php", {"action": "login", "phone": phone, "password": pw})
    d = r.get("data")
    return d.get("token") if isinstance(d, dict) else None


def updater(token):
    return call(BASE + "updater-api.php?action=system_info", None, token)


def upload(token):
    # no file on purpose: a request that passes the sign-in checks ends at "No file" (400); one that does not ends at 401/403
    return call(BASE + "upload.php", {"type": "hub_icon"}, token)


def sql(q):
    ok, err = etel_test_db.run_sql(q)
    if not ok:
        print("SQL failed: " + q + " -> " + str(err))
        sys.exit(1)


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:300]))


def rule(v):
    if v is None:
        sql("DELETE FROM pricing_rules WHERE rule_key = 'require_admin_2fa'")
    else:
        sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('require_admin_2fa', %s, 'CI') ON DUPLICATE KEY UPDATE rule_value = %s" % (v, v))


def main():
    tok = login(*ADMIN)
    cust = login("+251911000001", "customer1234")
    if not tok:
        print("demo admin login failed")
        sys.exit(1)
    try:
        # 1. two-step required (the launch default) and this admin has none
        rule(None)
        s, r = updater(tok)
        check("updater: an admin WITHOUT two-step is refused (default rule)", s == 403 and "Two-step" in (r.get("message") or ""), (s, r))
        s, r = upload(tok)
        check("upload.php: an admin WITHOUT two-step is refused", s == 403 and "Two-step" in (r.get("message") or ""), (s, r))

        # 2. rule off: the normal path still works
        rule(0)
        s, r = updater(tok)
        check("updater: a signed-in admin still works when two-step is not required", s == 200 and r.get("success") is True, (s, r))
        s, r = upload(tok)
        check("upload.php: a signed-in admin still passes the sign-in checks (ends at 'no file')", s == 400, (s, r))
        if cust:
            s, r = updater(cust)
            check("updater: a customer is still refused", s == 403, (s, r))

        # 3. a staff admin without the owner permission 'system.backup'
        sql("UPDATE users SET is_etelgroup_managed = 1, staff_role = 'NoSuchRole' WHERE phone = '0900000000'")
        s, r = updater(tok)
        check("updater: a staff admin without 'system.backup' is refused", s == 403 and "system.backup" in (r.get("message") or ""), (s, r))
        sql("UPDATE users SET is_etelgroup_managed = 0, staff_role = NULL WHERE phone = '0900000000'")
        s, r = updater(tok)
        check("updater: the owner admin (not staff-managed) works again", s == 200, (s, r))

        # 4. a device the person signed out
        sql("UPDATE auth_sessions SET revoked_at = NOW() WHERE user_id = (SELECT id FROM users WHERE phone = '0900000000')")
        s, r = updater(tok)
        check("updater: a device that was signed out is refused", s == 401, (s, r))
        s, r = upload(tok)
        check("upload.php: a device that was signed out is refused", s == 401, (s, r))
        sql("UPDATE auth_sessions SET revoked_at = NULL WHERE user_id = (SELECT id FROM users WHERE phone = '0900000000')")
        s, r = updater(tok)
        check("updater: the same token works again once the device is allowed", s == 200, (s, r))

        # 5. a disabled account
        sql("UPDATE users SET active = 0 WHERE phone = '0900000000'")
        s, r = updater(tok)
        check("updater: a disabled account is refused", s == 401 and "disabled" in (r.get("message") or ""), (s, r))
        s, r = upload(tok)
        check("upload.php: a disabled account is refused", s == 401, (s, r))
        for name, body in (("documents_upload.php", {"action": "upload"}), ("documents_download.php", {"id": 1}), ("admin-branding.php", {})):
            s, r = call(BASE + name, body, tok)
            check(name + ": a disabled account is refused", s == 401, (s, r))
    finally:
        sql("UPDATE users SET active = 1, is_etelgroup_managed = 0, staff_role = NULL WHERE phone = '0900000000'")
        sql("UPDATE auth_sessions SET revoked_at = NULL WHERE user_id = (SELECT id FROM users WHERE phone = '0900000000')")
        rule(0)

    print("\nPASSED: %d FAILED: %d" % (len(PASS), len(FAIL)))
    sys.exit(1 if FAIL else 0)


if __name__ == "__main__":
    main()
