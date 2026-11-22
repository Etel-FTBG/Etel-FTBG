"""
A shop owner puts their own shop on the map: sg_update_profile saves lat/lng, sg_profile returns them, nonsense is refused, and a shop with a pin in Hawassa
shows to a customer in Hawassa while the same shop without a pin does not (the city filter matches by the pin). Uses the seeded demo admin to make a test shop.
Needs the running API (php -S ... -t .) and a throwaway test database with the Hawassa service area switched on. Skips cleanly without them.
"""
import json
import os
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
SUFFIX = str(int(time.time()))
TAIL = SUFFIX[-6:]
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None, query=""):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL + query, data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def data(res):
    return res.get("data") or {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin")
    sys.exit(0)

phone = "+251994%s" % TAIL
st, res = call("admin_create_service_giver", {"name": "Pin Owner", "phone": phone, "password": "testpass123", "business_name": "Pin Kitchen %s" % SUFFIX,
                                              "service_type": "food", "vehicle_type": "on_foot", "zone": "Piazza"}, admin)
sg_id = data(res).get("service_giver_id")
st, res = call("sg_login", {"phone": phone, "password": "testpass123"})
owner = data(res).get("token")
check("a test shop with an owner login", bool(sg_id and owner), (st, res))
if not (sg_id and owner):
    sys.exit(1)

print("the owner sets the pin")
st, res = call("sg_profile", {}, owner)
g = data(res).get("service_giver") or {}
check("sg_profile now returns lat and lng (empty for a new shop)", "lat" in g and "lng" in g and g["lat"] in (None, "", "0", 0, "0.0000000"), g)
st, res = call("sg_update_profile", {"lat": 7.0512, "lng": 38.4850}, owner)
check("the owner saves a pin", st == 200 and res.get("success") is True, (st, res))
st, res = call("sg_profile", {}, owner)
g = data(res).get("service_giver") or {}
check("the pin reads back", abs(float(g.get("lat") or 0) - 7.0512) < 1e-6 and abs(float(g.get("lng") or 0) - 38.485) < 1e-6, g)
for bad in ({"lat": 95, "lng": 38}, {"lat": 7, "lng": 200}, {"lat": "abc", "lng": 38}, {"lat": 7, "lng": "x"}):
    st, res = call("sg_update_profile", bad, owner)
    check("refused: %s" % json.dumps(bad), st == 400 and res.get("success") is False, (st, res))
st, res = call("sg_profile", {}, owner)
g = data(res).get("service_giver") or {}
check("a refused pin changed nothing", abs(float(g.get("lat") or 0) - 7.0512) < 1e-6, g)

print("the readiness checklist")
st, res = call("sg_golive", {}, owner)
rows = (data(res).get("readiness") or {}).get("items") or []
loc = [r for r in rows if r.get("key") == "location"]
check("the checklist points the owner to Settings > Shop location", loc and "Shop location" in loc[0].get("hint", ""), loc)

print("customers see a shop by its pin")
etel_test_db.run_sql("UPDATE service_givers SET status = 'verified' WHERE id = %d" % sg_id)
st, res = call("sg_add_item", {"name": "Pin Burger", "category": "food", "price": 100, "stock_quantity": 20}, owner)
st, res = call("customer_get_items", {}, None, "?area=hawassa")
if st == 200 and "area" in data(res) and data(res).get("area") is None:
    print("  (the city filter is off in this database: the visibility checks are skipped)")
else:
    names = [i.get("business_name", "") for i in data(res).get("items", [])]
    check("a shop with a Hawassa pin shows to a Hawassa customer", any("Pin Kitchen %s" % SUFFIX in n for n in names), names[:5])
    etel_test_db.run_sql("UPDATE service_givers SET lat = NULL, lng = NULL, city = 'Addis Ababa' WHERE id = %d" % sg_id)
    st, res = call("customer_get_items", {}, None, "?area=hawassa")
    names = [i.get("business_name", "") for i in data(res).get("items", [])]
    check("the same shop without a pin does not", not any("Pin Kitchen %s" % SUFFIX in n for n in names), names[:5])
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
