"""
ETEL Delivery -- the admin sets a business's address and map pin from the shop drawer (sg_update_profile with sg_id), and the go-live checklist follows.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/admin_sg_location_smoke.py

Needs the seeded demo admin. Creates real rows: throwaway database only.
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


def location_item(admin, sgid):
    s, r = call("admin_service_giver_detail", {"id": sgid}, admin)
    d = r.get("data") or {}
    item = [i for i in (d.get("readiness") or {}).get("items", []) if i.get("key") == "location"]
    return (item[0] if item else {}), (d.get("service_giver") or {})


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    phone = "+25195" + TAIL[:1] + TAIL
    s, r = call("admin_create_service_giver", {"name": "Loc SG " + SUFFIX, "phone": phone, "password": "testpass123", "business_name": "Loc Kitchen",
                                                "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
    sgid = int(etel_test_db.query_text("SELECT sg.id FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE u.phone = '%s'" % phone)[1].strip().split("\n")[-1])
    etel_test_db.run_sql("UPDATE service_givers SET address = NULL, lat = NULL, lng = NULL WHERE id = %d" % sgid)

    print("1. The checklist asks for the address and pin")
    item, g = location_item(admin, sgid)
    check("the new business has no address and pin yet (checklist item not done)", item.get("key") == "location" and not item.get("ok"), item)

    print("2. An admin saves them from the drawer")
    s, r = call("sg_update_profile", {"sg_id": sgid, "address": "Piassa, near the market", "lat": 7.0512, "lng": 38.485}, admin)
    check("the save is accepted", s == 200 and r.get("success") is True, r)
    item, g = location_item(admin, sgid)
    check("the checklist item is now done", item.get("ok") is True, item)
    check("the address and the pin read back", g.get("address") == "Piassa, near the market" and abs(float(g.get("lat") or 0) - 7.0512) < 1e-6 and abs(float(g.get("lng") or 0) - 38.485) < 1e-6, g)

    print("3. Wrong numbers and wrong people")
    for bad in ({"lat": 95, "lng": 38}, {"lat": 7, "lng": 200}, {"lat": "abc", "lng": 38}):
        s, r = call("sg_update_profile", dict({"sg_id": sgid, "address": "X"}, **bad), admin)
        check("refused: %s" % json.dumps(bad), r.get("success") is False, r)
    item, g = location_item(admin, sgid)
    check("a refused pin changed nothing", abs(float(g.get("lat") or 0) - 7.0512) < 1e-6 and g.get("address") == "Piassa, near the market", g)
    cphone = "+251966" + TAIL
    s, r = call("register", {"name": "c " + SUFFIX, "phone": cphone, "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token")
    s, r = call("sg_update_profile", {"sg_id": sgid, "address": "Hacked", "lat": 1, "lng": 1}, cust)
    check("a customer cannot change a business's address", r.get("success") is False, (s, r))
    item, g = location_item(admin, sgid)
    check("... and it did not change", g.get("address") == "Piassa, near the market", g)

    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
