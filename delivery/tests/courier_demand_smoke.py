"""
ETEL Delivery -- courier hot areas over the real API (api courier_get_demand, 28 Nov 2026).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/courier_demand_smoke.py

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). Creates real rows: throwaway database only.
Checks: only couriers may ask; open jobs without a courier are counted per hexagon cell; a waiting job (10 minutes or more) makes the cell hotter;
an online courier close by cools it; cancelled, taken and unpaid-online jobs are not counted; the reply holds counts only (no name, phone or code).
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


def call(action, body=None, token=None, qs=""):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL + qs, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
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


def register(phone, role, extra=None):
    body = {"name": "Dem %s %s" % (role, SUFFIX), "phone": phone, "password": "testpass123", "role": role}
    body.update(extra or {})
    s, r, _ = call("register", body)
    d = r.get("data") or {}
    return d.get("token"), (d.get("user") or {}).get("id")


def sql(q):
    # the app runs its database session in Ethiopian time (api/boot.php: SET time_zone = date('P')); do the same, or NOW() would be 3 hours off
    ok, out, err = etel_test_db.query_text("SET time_zone = '+03:00'; " + q)
    if not ok:
        print("  SQL ERROR: " + err[:200])
    return out.strip()


def main():
    s, r, _ = call("login", {"phone": "0900000000", "password": "admin1234"})
    if not (r.get("data") or {}).get("token"):
        print("  SKIP no seeded demo admin")
        return 0
    etel_test_db.park_couriers()
    # the other smokes (run_integration.sh) put couriers straight to work: approval off for this run too
    sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('courier_require_approval', 0, 'CI: couriers start work without approval') ON DUPLICATE KEY UPDATE rule_value = 0")
    n = int(SUFFIX) % 1000000
    cust, _ = register("+251924%06d" % n, "customer")
    cphone = "+251925%06d" % n
    cour, _ = register(cphone, "courier", {"vehicle": "motorcycle"})
    cour_uid = sql("SELECT id FROM users WHERE phone = '%s' LIMIT 1" % cphone)
    check("test accounts exist", all([cust, cour, cour_uid]))
    etel_test_db.park_couriers()

    print("1. Who may ask")
    s, r, _ = call("courier_get_demand", {})
    check("no sign-in is refused", r.get("success") is False)
    s, r, _ = call("courier_get_demand", {}, token=cust)
    check("a customer is refused", r.get("success") is False, r)
    s, r, _ = call("courier_get_demand", {}, token=cour)
    d = r.get("data") or {}
    check("a courier gets the reply shape (areas, hexes, size 700, no surge)", r.get("success") and isinstance(d.get("areas"), list) and isinstance(d.get("hexes"), list)
          and d.get("size_m") == 700 and d.get("surge_now") is None, r)

    print("2. Open jobs make a hot cell")
    sql("UPDATE orders SET status='cancelled' WHERE courier_id IS NULL OR courier_id = 0")   # clean slate for the count
    A = {"address": "Tabor, Hawassa", "zone": "Tabor"}
    B = {"address": "University gate", "zone": "University"}
    book = {"pickup": A, "drop": B, "size": "small", "weight_kg": 1, "vehicle_class": "moto", "description": "Books", "recipient_name": "Rita Secret",
            "recipient_phone": "0911223344", "ack_prohibited": True, "payment_method": "cash"}
    ids = []
    for i in range(3):
        s, r, _ = call("customer_package_create", book, token=cust)
        oid = (r.get("data") or {}).get("order_id")
        if oid:
            ids.append(int(oid))
    check("three open packages are booked", len(ids) == 3, ids)
    # put all three at the grid origin (cell 0,0); two have waited 15 minutes, one is new
    sql("UPDATE orders SET pickup_lat=7.0621, pickup_lng=38.4761, courier_id=NULL, placed_at=NOW() - INTERVAL 15 MINUTE WHERE id IN (%d,%d)" % (ids[0], ids[1]))
    sql("UPDATE orders SET pickup_lat=7.0621, pickup_lng=38.4761, courier_id=NULL, placed_at=NOW() WHERE id = %d" % ids[2])
    s, r, raw = call("courier_get_demand", {"lat": 7.07, "lng": 38.48}, token=cour)
    d = r.get("data") or {}
    cells = [h for h in d.get("hexes", []) if h.get("q") == 0 and h.get("r") == 0]
    c = cells[0] if cells else {}
    check("one hexagon (q 0, r 0) holds the three jobs, two of them waiting, no courier near", len(cells) == 1 and c.get("requests") == 3 and c.get("waiting") == 2 and c.get("idle_drivers") == 0, d.get("hexes"))
    check("it is a high level cell, bright (heat 0.85 or more), score (3 + 2x2) / 1 = 7", c.get("level") == "high" and (c.get("heat") or 0) >= 0.85 and c.get("score") == 7.0, c)
    check("the list (areas) and the map cells (hexes) agree on the top cell", (d.get("areas") or [{}])[0].get("q") == 0 and (d.get("areas") or [{}])[0].get("heat") == c.get("heat"), d.get("areas"))
    check("the distance from the courier is given when the courier sends a position", isinstance(c.get("distance_km"), (int, float)) and 0.5 < c["distance_km"] < 3, c)
    check("the reply holds counts only: no recipient name, phone, order code or address", all(x not in raw for x in ("Rita", "0911223344", "ETP-", "Tabor", "University", "recipient")), raw[:200])

    print("3. A courier close by cools the cell")
    sql("UPDATE couriers SET status='online', last_lat=7.0625, last_lng=38.4765, last_seen=NOW() WHERE user_id = %d" % int(cour_uid))
    s, r, _ = call("courier_get_demand", {}, token=cour)
    c2 = [h for h in (r.get("data") or {}).get("hexes", []) if h.get("q") == 0 and h.get("r") == 0]
    c2 = c2[0] if c2 else {}
    check("with one online courier within 600 m the cell has 1 free courier and a lower score (7 / 2 = 3.5, level med)", c2.get("idle_drivers") == 1 and c2.get("score") == 3.5 and c2.get("level") == "med" and c2.get("heat") < c.get("heat"), c2)
    check("without a position in the request it uses the courier's last known position (distance given)", c2.get("distance_km") is not None and c2["distance_km"] < 0.3, c2)
    sql("UPDATE couriers SET last_seen = NOW() - INTERVAL 20 MINUTE WHERE user_id = %d" % int(cour_uid))
    s, r, _ = call("courier_get_demand", {}, token=cour)
    c3 = [h for h in (r.get("data") or {}).get("hexes", []) if h.get("q") == 0 and h.get("r") == 0]
    check("a courier not seen for 20 minutes does not count as free", c3 and c3[0].get("idle_drivers") == 0, c3)

    print("4. What is not counted")
    sql("UPDATE orders SET status='cancelled' WHERE id = %d" % ids[2])
    sql("UPDATE orders SET courier_id = (SELECT id FROM couriers WHERE user_id = %d) WHERE id = %d" % (int(cour_uid), ids[1]))
    s, r, _ = call("courier_get_demand", {}, token=cour)
    c4 = [h for h in (r.get("data") or {}).get("hexes", []) if h.get("q") == 0 and h.get("r") == 0]
    check("a cancelled job and a job a courier already took are not counted (1 job left, waiting)", c4 and c4[0].get("requests") == 1 and c4[0].get("waiting") == 1, c4)
    # an online-payment (Chapa) package that is not paid yet: booked through the API, put in the same cell, waiting 15 minutes
    s, r, _ = call("customer_package_create", dict(book, payment_method="chapa"), token=cust)
    chapa_id = (r.get("data") or {}).get("order_id")
    if chapa_id:
        ids.append(int(chapa_id))
        sql("UPDATE orders SET pickup_lat=7.0621, pickup_lng=38.4761, courier_id=NULL, placed_at=NOW() - INTERVAL 15 MINUTE WHERE id = %d" % int(chapa_id))
    s, r, _ = call("courier_get_demand", {}, token=cour)
    c5 = [h for h in (r.get("data") or {}).get("hexes", []) if h.get("q") == 0 and h.get("r") == 0]
    check("an online-payment job that is not paid yet is not counted (still 1 job)", chapa_id and c5 and c5[0].get("requests") == 1, c5)
    sql("UPDATE orders SET payment_status='paid' WHERE id = %d" % int(chapa_id or 0))
    s, r, _ = call("courier_get_demand", {}, token=cour)
    c6 = [h for h in (r.get("data") or {}).get("hexes", []) if h.get("q") == 0 and h.get("r") == 0]
    check("once it is paid it is counted (2 jobs)", c6 and c6[0].get("requests") == 2, c6)
    sql("UPDATE orders SET status='cancelled' WHERE id = %d" % int(chapa_id or 0))
    sql("UPDATE orders SET placed_at = NOW() - INTERVAL 5 HOUR WHERE id = %d" % ids[0])
    s, r, _ = call("courier_get_demand", {}, token=cour)
    check("a job older than 3 hours is not counted", not [h for h in (r.get("data") or {}).get("hexes", []) if h.get("q") == 0 and h.get("r") == 0])

    for i in ids:
        sql("UPDATE orders SET status='cancelled' WHERE id = %d" % i)
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    for f in FAIL:
        print("  FAIL: " + f)
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
