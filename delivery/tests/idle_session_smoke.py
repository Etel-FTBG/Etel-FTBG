"""
ETEL Delivery -- automatic sign-out when the app is not used (session_ping, the idle limit per role, couriers who are working, safety for older apps), over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/idle_session_smoke.py

Needs the seeded demo admin. Throwaway database only.
"""
import json
import random
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
TAIL = "%06d" % random.randint(0, 999999)
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
            return e.code, {"success": False, "message": "(non-JSON)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def sql1(q):
    ok, out, _ = etel_test_db.query_text(q)
    return out.strip().split("\n")[-1] if ok and out.strip() else ""


def register(phone, role):
    s, r = call("register", {"name": role + " " + SUFFIX, "phone": phone, "password": "testpass123", "role": role})
    return (r.get("data") or {}).get("token")


def idle_for(uid, minutes):
    """Pretend the person last used the app `minutes` minutes ago (None = never reported activity)."""
    if minutes is None:
        etel_test_db.run_sql("UPDATE auth_sessions SET last_active = NULL WHERE user_id = %s" % int(uid))
    else:
        etel_test_db.run_sql("SET time_zone = '+03:00'; UPDATE auth_sessions SET last_active = DATE_SUB(NOW(), INTERVAL %d MINUTE) WHERE user_id = %s" % (int(minutes), int(uid)))   # PHP runs the database in Addis time (boot.php), so the test does too


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key LIKE 'idle_minutes_%'")
    cph = "+251966" + TAIL
    cust = register(cph, "customer")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        cust = register(cph, "customer")
    check("the test customer signed up", bool(cust))
    if not cust:
        return 1
    cuid = int(sql1("SELECT id FROM users WHERE phone = '%s'" % cph))

    print("1. The app reports use; the server answers with the limit")
    s, r = call("session_ping", {"active": True}, cust)
    d = r.get("data") or {}
    check("a ping answers with the customer limit (30 minutes) and not exempt", s == 200 and d.get("idle_minutes") == 30 and d.get("exempt") is False, r)
    check("the ping with active:true saved the time of use", sql1("SELECT last_active IS NOT NULL FROM auth_sessions WHERE user_id = %d" % cuid) == "1")
    s, r = call("session_ping", {"active": True})
    check("a ping without a login is refused", s == 401, r)

    print("2. Not using the app ends the session (and the message says why)")
    idle_for(cuid, 29)
    s, r = call("customer_get_orders", {}, cust)
    check("29 minutes without use: still signed in", s == 200 and r.get("success") is True, r)
    idle_for(cuid, 31)
    s, r = call("customer_get_orders", {}, cust)
    check("31 minutes without use: signed out with code idle_expired", s == 401 and r.get("code") == "idle_expired", r)
    check("the message says the app was not used", "not used" in (r.get("message") or ""), r)
    s, r = call("session_ping", {"active": True}, cust)
    check("an expired session cannot revive itself with a ping", s == 401 and r.get("code") == "idle_expired", r)

    print("3. Safety: an app that never reported use is never signed out for being idle")
    idle_for(cuid, None)
    s, r = call("customer_get_orders", {}, cust)
    check("no activity ever reported: still signed in", s == 200 and r.get("success") is True, r)

    print("4. A ping that only asks does not count as use")
    s, r = call("session_ping", {"active": True}, cust)
    idle_for(cuid, 10)
    s, r = call("session_ping", {"active": False}, cust)
    d = r.get("data") or {}
    still = sql1("SET time_zone = '+03:00'; SELECT TIMESTAMPDIFF(MINUTE, last_active, NOW()) FROM auth_sessions WHERE user_id = %d" % cuid)
    check("active:false leaves the last time of use alone (still about 10 minutes ago)", still in ("10", "11"), still)
    check("... and tells how many seconds are left (about 20 minutes)", 1100 <= int(d.get("seconds_left") or 0) <= 1210, d)

    print("5. Couriers who are working are not signed out")
    kph = "+251977" + TAIL
    cour = register(kph, "courier")
    kuid = int(sql1("SELECT id FROM users WHERE phone = '%s'" % kph))
    etel_test_db.run_sql("UPDATE couriers SET status = 'online' WHERE user_id = %d" % kuid)
    s, r = call("session_ping", {"active": True}, cour)
    idle_for(kuid, 300)
    s, r = call("courier_get_profile", {}, cour)
    check("an ONLINE courier idle for 5 hours is still signed in", s == 200 and r.get("success") is True, r)
    s, r = call("session_ping", {"active": False}, cour)
    check("... and the ping says exempt", (r.get("data") or {}).get("exempt") is True, r)
    etel_test_db.run_sql("UPDATE couriers SET status = 'offline' WHERE user_id = %d" % kuid)
    s, r = call("courier_get_profile", {}, cour)
    check("the same courier, offline with no job and 5 hours idle: signed out", s == 401 and r.get("code") == "idle_expired", r)

    print("6. Admin: shorter limit (15 minutes); the owner can change every limit")
    s, r = call("session_ping", {"active": True}, admin)
    check("admin limit is 15 minutes", (r.get("data") or {}).get("idle_minutes") == 15, r)
    aid = int(sql1("SELECT id FROM users WHERE phone = '0900000000'"))
    s, r = call("admin_idle_get", {}, admin)
    d = r.get("data") or {}
    check("admin_idle_get shows the limits and the defaults", (d.get("settings") or {}).get("customer") == 30 and (d.get("defaults") or {}).get("service_giver") == 240, d)
    s, r = call("admin_idle_save", {"settings": {"customer": 60}}, admin)
    check("the owner sets customers to 60 minutes", s == 200 and ((r.get("data") or {}).get("settings") or {}).get("customer") == 60, r)
    s, r = call("login", {"phone": cph, "password": "testpass123"})
    cust2 = (r.get("data") or {}).get("token")
    s, r = call("session_ping", {"active": True}, cust2)
    idle_for(cuid, 45)
    s, r = call("customer_get_orders", {}, cust2)
    check("45 minutes idle is fine now (limit 60)", s == 200 and r.get("success") is True, r)
    s, r = call("admin_idle_save", {"settings": {"customer": 3}}, admin)
    check("less than 5 minutes is refused", s == 400 and r.get("success") is False, r)
    s, r = call("admin_idle_save", {"settings": {"customer": 0}}, admin)
    idle_for(cuid, 3 * 24 * 60)
    s, r = call("customer_get_orders", {}, cust2)
    check("0 = never: even 3 days idle stays signed in (the 7-day token limit still applies)", s == 200 and r.get("success") is True, r)
    s, r = call("admin_idle_save", {"settings": {"customer": 30}}, cust2)
    check("a customer cannot change the limits", s in (401, 403) and r.get("success") is False, r)
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key LIKE 'idle_minutes_%'")
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
