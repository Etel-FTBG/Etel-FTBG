"""
Paying a ride from the Etel Pay wallet: the Ride page asks ride_wallet_status BEFORE it sends any money (the payment goes to Etel Pay first and is
confirmed here afterwards, so a refused confirm would leave the rider charged). Through the real server: off by default, on when the pricing rule
ride_wallet_enabled = 1 exists, signed-in only, and the confirm step still refuses wallet payment while it is off. Uses the seeded demo rider.
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

print("ride wallet switch")
etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'ride_wallet_enabled'")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
rider = (r.get("data") or {}).get("token")
if not rider:
    check("the demo rider can sign in", False, r)
    sys.exit(1)

st, r = call("ride_wallet_status", {}, rider)
check("off by default (no pricing rule): enabled is false", st == 200 and (r.get("data") or {}).get("enabled") is False, (st, r))
st, r = call("ride_wallet_status", {})
check("needs a sign-in", st == 401, (st, r))

etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_wallet_enabled', '1', 'CI') ON DUPLICATE KEY UPDATE rule_value = '1'")
st, r = call("ride_wallet_status", {}, rider)
check("with the rule set to 1: enabled is true", st == 200 and (r.get("data") or {}).get("enabled") is True, (st, r))
etel_test_db.run_sql("UPDATE pricing_rules SET rule_value = '0' WHERE rule_key = 'ride_wallet_enabled'")
st, r = call("ride_wallet_status", {}, rider)
check("with the rule set to 0: enabled is false again", st == 200 and (r.get("data") or {}).get("enabled") is False, (st, r))


# ---- the Admin switch (Settings > Etel Pay): a member's Grant / Deny and "Live for everyone" decide too ----
FEATURES = os.path.join(HERE, "..", "api", "etel-features.json")
try:
    saved = open(FEATURES).read()
except Exception:
    saved = None


def set_live(on):
    open(FEATURES, "w").write(json.dumps({"etelpay_live": bool(on), "updated_at": "CI"}))


def enabled():
    st, r = call("ride_wallet_status", {}, rider)
    return (r.get("data") or {}).get("enabled")


etel_test_db.run_sql("DELETE FROM etelpay_user_overrides WHERE user_id = (SELECT id FROM users WHERE phone = '+251911000001' LIMIT 1)")
set_live(False)
check("Admin 'Coming Soon' and no grant: off", enabled() is False)
etel_test_db.run_sql("INSERT INTO etelpay_user_overrides (user_id, allowed, note, updated_by, updated_at) SELECT id, 1, 'CI', 'ci', NOW() FROM users WHERE phone = '+251911000001' LIMIT 1")
check("a Grant for this member turns it on even while Coming Soon", enabled() is True)
set_live(True)
etel_test_db.run_sql("UPDATE etelpay_user_overrides SET allowed = 0 WHERE user_id = (SELECT id FROM users WHERE phone = '+251911000001' LIMIT 1)")
check("a Deny wins over 'Live for everyone'", enabled() is False)
etel_test_db.run_sql("DELETE FROM etelpay_user_overrides WHERE user_id = (SELECT id FROM users WHERE phone = '+251911000001' LIMIT 1)")
check("no override and 'Live for everyone': on", enabled() is True)
set_live(False)
check("back to Coming Soon: off again", enabled() is False)
if saved is not None:
    open(FEATURES, "w").write(saved)

st, r = call("customer_confirm_arrival", {"ride_id": 999999, "payment_method": "wallet", "wallet_tx_ref": "EP-TEST"}, rider)
check("the confirm step refuses wallet payment while it is off (this is why the page must ask first)", st == 400 and "coming soon" in json.dumps(r), (st, r))

etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'ride_wallet_enabled'")
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
