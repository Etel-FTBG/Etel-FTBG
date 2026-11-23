"""
Driver Pull tab and special offers, over the real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/ride_pull_smoke.py

A ride nobody near the rider has taken for ride_pull_after_secs is shown to drivers at least ride_pull_min_km from the pickup (driver_get_pull_requests ->
pull). A ride that pays a lot (ride_vip_min_fare) or is called from a special area staff draw (Admin -> Ride -> Special offers) is a special offer, shown
at once with its tags. The normal request list is untouched. Needs the seeded demo admin and a throwaway database.
"""
import json
import os
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
SUFFIX = str(int(time.time()))
PASS, FAIL = [], []


def call(action, body=None, token=None):
    payload = dict(body or {}, action=action)
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(payload).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def register(phone, role, name):
    s, r = call("register", {"name": name, "phone": phone, "password": "test1234", "role": role})
    d = r.get("data") or {}
    return d.get("token"), d.get("user_id")


def main():
    ok, why = etel_test_db.run_sql("SELECT 1")
    if not ok:
        print("SKIP: no test database (%s)" % why); return 0
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("UPDATE rides SET status='cancelled' WHERE status='searching'")
    etel_test_db.run_sql("UPDATE ride_drivers SET online=0")
    tail = SUFFIX[-6:]
    near_t, near = register("+251931%s" % tail, "courier", "Pull Near")
    far_t, far = register("+251932%s" % tail, "courier", "Pull Far")
    cust_t, _ = register("+251933%s" % tail, "customer", "Pull Cust")
    if not (near_t and far_t and cust_t):
        check("test users registered", False, (near_t, far_t, cust_t)); return 1
    for tok, uid, lat in ((near_t, near, 7.0505), (far_t, far, 7.1005)):
        call("driver_get_profile", {}, tok)
        etel_test_db.onboard_driver(uid)
        etel_test_db.run_sql("UPDATE ride_drivers SET vehicle_type='Bajaj' WHERE user_id=%d" % int(uid))
        call("driver_go_online", {"lat": lat, "lng": 38.49}, tok)
    etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")

    s, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, cust_t)
    quote = (est.get("data") or {}).get("quote_token")
    s, res = call("book_ride", {"pickup_lat": 7.0505, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash", "quote_token": quote}, cust_t)
    rid = (res.get("data") or {}).get("request_id")
    check("a ride is booked at the near driver's spot", bool(rid), res)
    if not rid:
        return 1

    print("1. Settings")
    s, r = call("admin_ride_offers_get", {}, admin)
    d = r.get("data") or {}
    check("admin reads the defaults and the (empty) area list", r.get("success") is True and d.get("settings", {}).get("ride_pull_min_km") == 2 and isinstance(d.get("areas"), list), r)
    s, r = call("admin_ride_offers_save", {"ride_pull_after_secs": 600, "ride_pull_min_km": 2, "ride_pull_max_km": 25, "ride_vip_min_fare": 0, "ride_pull_on": 1}, admin)
    check("admin saves the settings", r.get("success") is True and (r.get("data") or {}).get("settings", {}).get("ride_pull_after_secs") == 600, r)
    s, r = call("admin_ride_offers_save", {"ride_pull_min_km": 10, "ride_pull_max_km": 5}, admin)
    check("a farthest distance below the nearest is refused", r.get("success") is False and s == 400, (s, r))
    s, r = call("admin_ride_offers_save", {"ride_pull_after_secs": "abc"}, admin)
    check("a word instead of a number is refused", r.get("success") is False, r)
    s, r = call("admin_ride_offers_save", {"ride_pull_after_secs": 5}, far_t)
    check("a driver cannot change the settings", s in (401, 403) or r.get("success") is False, (s, r))

    print("2. Pull: waiting trips for drivers far from the pickup")
    def pull(tok):
        s, r = call("driver_get_pull_requests", {}, tok)
        return (r.get("data") or {}), r
    d, r = pull(far_t)
    check("a ride that has not waited long enough is not pullable yet", r.get("success") is True and not d.get("pull") and not d.get("offers"), d)
    call("admin_ride_offers_save", {"ride_pull_after_secs": 0}, admin)
    d, r = pull(far_t)
    row = [x for x in d.get("pull", []) if x["id"] == rid]
    if not row:
        print("   debug ride:", etel_test_db.query_text("SELECT id,status,vehicle_type,pickup_lat,pickup_lng,expires_at,NOW(),created_at,passenger_id FROM rides WHERE id=%d" % int(rid), True)[1])
        print("   debug drivers:", etel_test_db.query_text("SELECT user_id,vehicle_type,lat,lng,online,onboarded FROM ride_drivers WHERE user_id IN (%d,%d)" % (int(near), int(far)), True)[1])
    check("after the wait, the far driver (about 5.5 km away) sees it in Pull with the trip details",
          bool(row) and row[0]["from_driver_km"] > 2 and row[0]["trip_km"] >= 0 and row[0]["pickup_address"] is not None and "fare" in row[0] and "eta_min" in row[0] and not row[0]["tags"], d)
    d2, r2 = pull(near_t)
    check("the near driver (0 km) does not get it in Pull (it is in the normal list)", not [x for x in d2.get("pull", []) if x["id"] == rid], d2)
    s, rl = call("driver_get_nearby_requests", {}, near_t)
    check("the normal Requests list still shows it to the near driver", rid in [int(x["id"]) for x in (rl.get("data") or {}).get("requests", [])], rl)
    call("admin_ride_offers_save", {"ride_pull_on": 0}, admin)
    d, r = pull(far_t)
    check("Pull switched off: nothing in Pull", not d.get("pull"), d)
    call("admin_ride_offers_save", {"ride_pull_on": 1}, admin)

    print("3. Special offers: sweet area and high paying")
    s, r = call("admin_ride_area_save", {"name": "", "lat": 7.05, "lng": 38.49, "radius_km": 1}, admin)
    check("an area without a name is refused", r.get("success") is False, r)
    s, r = call("admin_ride_area_save", {"name": "Test sweet", "kind": "sweet", "lat": 7.0505, "lng": 38.49, "radius_km": 1, "note": "Many calls here"}, admin)
    aid = (r.get("data") or {}).get("id")
    check("admin draws a sweet area around the pickup", r.get("success") is True and aid, r)
    d, r = pull(far_t)
    row = [x for x in d.get("offers", []) if x["id"] == rid]
    check("the ride called from that area is a special offer with the area tag, the note and all details",
          bool(row) and any(t["kind"] == "sweet" and "Test sweet" in t["label"] and t["note"] == "Many calls here" for t in row[0]["tags"]) and "pickup_address" in row[0] and "dropoff_address" in row[0] and "fare" in row[0], d)
    check("... and it is no longer repeated in Pull", not [x for x in d.get("pull", []) if x["id"] == rid], d)
    call("admin_ride_area_save", {"id": aid, "name": "Test sweet", "kind": "sweet", "lat": 7.0505, "lng": 38.49, "radius_km": 1, "note": "x", "active": 0}, admin)
    d, r = pull(far_t)
    check("an area switched off no longer tags the ride", not [x for x in d.get("offers", []) if x["id"] == rid], d)
    call("admin_ride_offers_save", {"ride_vip_min_fare": 1}, admin)
    d, r = pull(far_t)
    row = [x for x in d.get("offers", []) if x["id"] == rid]
    check("a ride paying at least the VIP fare is a high paying offer", bool(row) and any(t["kind"] == "vip" for t in row[0]["tags"]), d)
    call("admin_ride_offers_save", {"ride_vip_min_fare": 0}, admin)
    s, r = call("admin_ride_area_delete", {"id": aid}, admin)
    check("admin deletes the area", r.get("success") is True and not [a for a in (r.get("data") or {}).get("areas", []) if a["id"] == aid], r)

    print("4. Taking a trip from the Pull tab")
    d, r = pull(far_t)
    check("back to a plain Pull trip", [x for x in d.get("pull", []) if x["id"] == rid], d)
    s, r = call("driver_accept_ride", {"request_id": rid}, far_t)
    check("the far driver can take it with the normal accept", r.get("success") is True, r)
    d, r = pull(near_t)
    check("once taken it leaves everyone's Pull", not d.get("pull") and not d.get("offers"), d)
    call("cancel_ride", {"id": rid}, cust_t)
    etel_test_db.run_sql("UPDATE pricing_rules SET rule_value=20 WHERE rule_key='ride_offer_wave_secs'")
    call("admin_ride_offers_save", {"ride_pull_after_secs": 20, "ride_pull_min_km": 2, "ride_pull_max_km": 25, "ride_vip_min_fare": 350, "ride_pull_on": 1}, admin)
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


def give_back():
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")


if __name__ == "__main__":
    try:
        rc = main()
    finally:
        give_back()
    sys.exit(rc)
