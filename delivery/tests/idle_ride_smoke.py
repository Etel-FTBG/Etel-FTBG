"""
ETEL Delivery -- the Ride apps have no idle timer. A request marked X-Etel-App: ride (and the ride live streams) is never ended for being idle, a Delivery request
still is, and Ride use keeps the shared sign-in fresh.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/idle_ride_smoke.py

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


def call(action, body=None, token=None, app=None, query=""):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if app:
        headers["X-Etel-App"] = app
    req = urllib.request.Request(API_URL + query, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
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


def idle_for(uid, minutes):
    etel_test_db.run_sql("SET time_zone = '+03:00'; UPDATE auth_sessions SET last_active = DATE_SUB(NOW(), INTERVAL %d MINUTE) WHERE user_id = %s" % (int(minutes), int(uid)))


def main():
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key LIKE 'idle_minutes_%'")
    ph = "+251955" + TAIL
    s, r = call("register", {"name": "rider " + SUFFIX, "phone": ph, "password": "testpass123", "role": "customer"})
    tok = (r.get("data") or {}).get("token")
    if not tok:
        print("SKIP: could not sign up"); return 0
    uid = int(sql1("SELECT id FROM users WHERE phone = '%s'" % ph))
    call("session_ping", {"active": True}, tok)
    idle_for(uid, 90)

    print("1. A Ride request is never ended for being idle; a Delivery request still is")
    s, r = call("ride_get_vehicle_types", {}, tok, app="ride")
    check("90 minutes idle, header X-Etel-App: ride: still signed in", s == 200 and r.get("code") != "idle_expired", r)
    s, r = call("customer_get_orders", {}, tok, app="ride")
    check("the header also covers any other action from the Ride page (the Ride page asks for many)", s == 200 and r.get("code") != "idle_expired", r)
    s, r = call("customer_get_orders", {}, tok)
    check("the same session without the header (a Delivery screen): signed out with idle_expired", s == 401 and r.get("code") == "idle_expired", r)
    s, r = call("customer_get_orders", {}, tok, app="delivery")
    check("any other app name does not get the exemption", s == 401 and r.get("code") == "idle_expired", r)

    print("2. Live ride streams and the driver beacon (they cannot send a header)")
    s, r = call("driver_beacon_ping", {}, tok, query="?action=driver_beacon_ping")
    check("driver_beacon_ping is not refused for being idle (it may fail for other reasons, not idle_expired)", r.get("code") != "idle_expired", r)

    print("3. Ride use keeps the shared sign-in fresh")
    s, r = call("session_ping", {"active": True}, tok, app="ride")
    check("a Ride ping saves the time of use", s == 200 and (r.get("data") or {}).get("idle_minutes") == 30, r)
    s, r = call("customer_get_orders", {}, tok)
    check("then a Delivery screen is signed in again with no new sign-in needed", s == 200 and r.get("success") is True, r)

    print("4. The Delivery timer is unchanged for everyone else")
    idle_for(uid, 31)
    s, r = call("customer_get_orders", {}, tok)
    check("31 minutes without use on a Delivery screen: signed out", s == 401 and r.get("code") == "idle_expired", r)
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
