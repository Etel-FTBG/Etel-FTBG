"""
ETEL Delivery -- a courier's vehicle (qute, minibus, EV car ...) really is saved, even when couriers.vehicle_type is an old ENUM column.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/courier_vehicle_column_smoke.py

Some installs have couriers.vehicle_type as ENUM('motorcycle','bicycle','car', ...). A vehicle not on the list was stored as an empty value while Admin said
"Courier updated". The server now widens the column to VARCHAR(30) first (api/lib/courier_schema.php). Needs the seeded demo admin. Throwaway database only.
"""
import json
import sys
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
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
            return e.code, {"success": False, "message": "(non-JSON)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def coltype():
    ok, out, _ = etel_test_db.query_text("SHOW COLUMNS FROM couriers LIKE 'vehicle_type'")
    return out.split("\t")[1].lower() if ok and out.strip() else ""


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    s, r = call("admin_couriers_board", {}, admin)
    rows = (r.get("data") or {}).get("couriers") or []
    if not rows:
        print("SKIP: no courier to edit"); return 0
    cid = rows[0]["id"]
    # make the column the old ENUM (first put every value on its list)
    etel_test_db.run_sql("UPDATE couriers SET vehicle_type='motorcycle' WHERE vehicle_type IS NULL OR vehicle_type NOT IN ('motorcycle','bicycle','car','bajaj')")
    ok, _ = etel_test_db.run_sql("ALTER TABLE couriers MODIFY vehicle_type ENUM('motorcycle','bicycle','car','bajaj') NULL DEFAULT 'motorcycle'")
    check("the test column is the old ENUM", ok and coltype().startswith("enum"), coltype())
    s, r = call("admin_update_courier", {"courier_id": cid, "vehicle_type": "qute"}, admin)
    check("saving a qute courier succeeds", r.get("success") is True, r)
    s, r = call("admin_courier_detail", {"id": cid}, admin)
    kept = (((r.get("data") or {}).get("courier")) or {}).get("vehicle_type")
    check("... and the vehicle really is qute (not an empty value)", kept == "qute", repr(kept))
    check("the column is now plain text (VARCHAR 30)", coltype().startswith("varchar(30)"), coltype())
    for v in ("minibus", "ev_car", "on_foot", "motorcycle"):
        call("admin_update_courier", {"courier_id": cid, "vehicle_type": v}, admin)
        s, r = call("admin_courier_detail", {"id": cid}, admin)
        check("saving " + v + " keeps it", (((r.get("data") or {}).get("courier")) or {}).get("vehicle_type") == v, r)
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
