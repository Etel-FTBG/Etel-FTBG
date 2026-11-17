"""
ETEL Delivery -- two-step sign-in is REQUIRED for admins and staff (launch-readiness phase 3).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/admin_2fa_required_smoke.py

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql; it has no two-step sign-in). Throwaway database only.
The other smokes run with the rule set to 0 (run_integration.sh). This one switches it on / removes the row for itself and puts it
back to 0 at the end. (The emergency .env switch ADMIN_2FA_REQUIRED is covered by tests/phase3_unit.php; the server's .env is fixed.)
"""
import json
import sys
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
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


def set_rule(value):
    if value is None:
        ok, err = etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'require_admin_2fa'")
    else:
        ok, err = etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('require_admin_2fa', %s, 'CI') ON DUPLICATE KEY UPDATE rule_value = %s" % (value, value))
    if not ok:
        print("could not set the rule: " + err)
        sys.exit(1)


def main():
    s, r = call("login", {"phone": ADMIN_PHONE, "password": ADMIN_PASSWORD})
    tok = (r.get("data") or {}).get("token") if isinstance(r.get("data"), dict) else None
    if not tok:
        print("demo admin login failed: %s" % r)
        sys.exit(1)

    try:
        # no row saved = the launch default = required
        set_rule(None)
        s, r = call("admin_areas_list", {}, tok)
        check("default (no rule saved): an admin without two-step is refused", s == 403 and r.get("code") == "2fa_setup_required", (s, r))
        check("the refusal tells them what to do", "Two-step" in (r.get("message") or ""), r)

        # the ways to SET IT UP and look at health stay open, so nobody is locked out of fixing it
        s, r = call("admin_2fa_status", {}, tok)
        check("two-step set-up (admin_2fa_status) stays open", s == 200 and r.get("success") is not False, (s, r))
        s, r = call("admin_system_health", {}, tok)
        check("the health page stays open", s == 200 and r.get("success") is not False, (s, r))
        s, r = call("admin_get_2fa_rule", {}, tok)
        check("the rule card stays open and says required/default",
              s == 200 and (r.get("data") or {}).get("required") == 1 and (r.get("data") or {}).get("source") == "default", (s, r))

        # owner saved 1
        set_rule(1)
        s, r = call("admin_areas_list", {}, tok)
        check("rule 1: still refused", s == 403 and r.get("code") == "2fa_setup_required", (s, r))

        # owner switched it off
        set_rule(0)
        s, r = call("admin_areas_list", {}, tok)
        check("rule 0 (owner switched it off): the admin page opens", s == 200 and r.get("success") is not False, (s, r))
        s, r = call("admin_get_2fa_rule", {}, tok)
        check("the rule card says not required, source setting", (r.get("data") or {}).get("required") == 0 and (r.get("data") or {}).get("source") == "setting", (s, r))

        # the health page names the problem
        s, r = call("admin_system_health", {}, tok)
        checks = ((r.get("data") or {}).get("checks") or [])
        names = dict((c.get("name"), c) for c in checks)
        check("health shows the Admin 2FA rule as a warning when switched off", names.get("Admin 2FA rule", {}).get("status") == "warn", names.get("Admin 2FA rule"))
        check("health lists admin accounts without two-step", names.get("Admin and staff two-step", {}).get("status") == "warn", names.get("Admin and staff two-step"))
        check("health has a Backups row", "Backups" in names, list(names))
        check("health has a Backup job row", "Backup job" in names, list(names))
    finally:
        set_rule(0)   # the CI default for every other smoke

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    sys.exit(1 if FAIL else 0)


if __name__ == "__main__":
    main()
