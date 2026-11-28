"""
ETEL Ride -- driver hot areas over the real API (api driver_get_demand: hexes, heat, size_m, surge_now; 28 Nov 2026).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/ride_demand_heat_smoke.py

Needs the seeded demo admin. Creates real rows: throwaway database only.
Checks: only drivers may ask; ride requests of the last 45 minutes are counted per hexagon cell; a waiting rider makes the cell hotter; a free driver close by cools it;
the reply keeps 'areas' (top 8, the panel) and adds 'hexes' (q, r, heat), 'size_m' and 'surge_now'; counts only (no rider, address or phone).
"""
import json
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
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
            raw = f.read().decode("utf-8")
            return f.status, json.loads(raw), raw
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8")
        try:
            return e.code, json.loads(raw), raw
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON error body)"}, raw


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:300]))


def sql(q):
    ok, out, err = etel_test_db.query_text("SET time_zone = '+03:00'; " + q)   # the app runs its database session in Ethiopian time
    if not ok:
        print("  SQL ERROR: " + err[:200])
    return out.strip()


def register(phone, role):
    s, r, _ = call("register", {"name": "Hot %s %s" % (role, SUFFIX), "phone": phone, "password": "testpass123", "role": role})
    return (r.get("data") or {}).get("token")


def cell(d, q, r):
    c = [h for h in (d or {}).get("hexes", []) if h.get("q") == q and h.get("r") == r]
    return c[0] if c else {}


def main():
    s, r, _ = call("login", {"phone": "0900000000", "password": "admin1234"})
    if not (r.get("data") or {}).get("token"):
        print("  SKIP no seeded demo admin")
        return 0
    n = int(SUFFIX) % 1000000
    cust = register("+251944%06d" % n, "customer")
    dphone = "+251945%06d" % n
    sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('courier_require_approval', 0, 'CI: couriers start work without approval') ON DUPLICATE KEY UPDATE rule_value = 0")
    drv = register(dphone, "courier")   # a ride driver is a courier account that also has a ride_drivers row (added below)
    check("test accounts exist", all([cust, drv]))
    duid = sql("SELECT id FROM users WHERE phone = '%s' LIMIT 1" % dphone)
    cuid = sql("SELECT id FROM users WHERE phone = '+251944%06d' LIMIT 1" % n)
    sql("INSERT IGNORE INTO ride_drivers (user_id, onboarded, identity_match) VALUES (%s, 1, 'matched')" % duid)   # a ride driver (as the app creates one when a driver signs up for rides)
    sql("DELETE FROM rides WHERE created_at > NOW() - INTERVAL 2 DAY")
    sql("UPDATE ride_drivers SET online = 0")

    print("1. Who may ask")
    s, r, _ = call("driver_get_demand", {})
    check("no sign-in is refused", r.get("success") is False)
    s, r, _ = call("driver_get_demand", {}, token=cust)
    check("a customer is refused", r.get("success") is False, r)
    s, r, _ = call("driver_get_demand", {}, token=drv)
    d = r.get("data") or {}
    check("a driver gets areas, hexes, size 700 and surge_now (a number above 1 or null)", r.get("success") and isinstance(d.get("areas"), list) and isinstance(d.get("hexes"), list)
          and d.get("size_m") == 700 and (d.get("surge_now") is None or d["surge_now"] > 1), r)

    print("2. Requests make a hot cell")
    for i in range(3):
        status = "searching" if i == 0 else "accepted"
        sql("INSERT INTO rides (passenger_id, status, pickup_lat, pickup_lng, created_at) VALUES (%s, '%s', 7.0621, 38.4761, NOW() - INTERVAL %d MINUTE)" % (cuid, status, i + 2))
    s, r, raw = call("driver_get_demand", {"lat": 7.07, "lng": 38.48}, token=drv)
    d = r.get("data") or {}
    c = cell(d, 0, 0)
    check("one hexagon (q 0, r 0) holds the 3 requests, 1 of them still searching, no free driver", c.get("requests") == 3 and c.get("waiting") == 1 and c.get("idle_drivers") == 0, d.get("hexes"))
    check("score (3 + 2x1) / 1 = 5, level high, heat 0.85 (a rider waits and no driver is free)", c.get("score") == 5.0 and c.get("level") == "high" and c.get("heat") == 0.85, c)
    check("'areas' (the panel list) still has the cell, same heat", (d.get("areas") or [{}])[0].get("q") == 0 and (d.get("areas") or [{}])[0].get("heat") == 0.85, d.get("areas"))
    check("the distance is given when the driver sends a position", isinstance(c.get("distance_km"), (int, float)) and 0.5 < c["distance_km"] < 3, c)
    check("counts only: no rider id, phone or address in the reply", all(x not in raw for x in ("passenger", "phone", "address", "+251944")), raw[:200])

    print("3. A free driver close by cools the cell")
    sql("INSERT INTO ride_drivers (user_id, online, lat, lng, last_seen) VALUES (%s, 1, 7.0625, 38.4765, NOW()) ON DUPLICATE KEY UPDATE online = 1, lat = 7.0625, lng = 38.4765, last_seen = NOW()" % duid)
    s, r, _ = call("driver_get_demand", {}, token=drv)
    c2 = cell(r.get("data"), 0, 0)
    check("with one free driver within 600 m: 1 free driver, score 2.5, level med, heat 0.42 (cooler)", c2.get("idle_drivers") == 1 and c2.get("score") == 2.5 and c2.get("level") == "med" and c2.get("heat") == 0.42, c2)

    print("4. Older requests are not counted")
    sql("UPDATE rides SET created_at = NOW() - INTERVAL 2 HOUR WHERE created_at > NOW() - INTERVAL 1 HOUR")
    s, r, _ = call("driver_get_demand", {}, token=drv)
    check("requests older than 45 minutes leave no cell", not cell(r.get("data"), 0, 0), (r.get("data") or {}).get("hexes"))

    sql("DELETE FROM rides WHERE created_at > NOW() - INTERVAL 1 DAY OR pickup_lat = 7.0621")
    sql("UPDATE ride_drivers SET online = 0")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    for f in FAIL:
        print("  FAIL: " + f)
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
