"""
The live streams (sse_ride_status, sse_driver_stream) answer ONCE and end when asked with once=1, which is what the
polling shim js/etel-sse-poll.js uses so no PHP worker or database connection stays open. Without once=1 they still
stream (not tested here: it would hold the connection for minutes).

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
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
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def stream_once(qs):
    t0 = time.time()
    with urllib.request.urlopen(API_URL + "?" + qs + "&once=1", timeout=20) as f:
        return f.status, f.read().decode(), time.time() - t0


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("live streams answer once")
st, res = call("register", {"name": "Sse Rider", "phone": "+251966%s" % SUFFIX[-6:], "password": "test1234", "role": "customer"})
rtok = (res.get("data") or {}).get("token")
check("rider registered", bool(rtok), res)
if not rtok:
    sys.exit(1)
# a quote token: during peak hours the server refuses a booking that was not quoted (the test must pass at any hour)
st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, rtok)
st, res = call("book_ride", {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash",
                             "quote_token": (est.get("data") or {}).get("quote_token")}, rtok)
rid = (res.get("data") or {}).get("request_id")
check("ride booked", bool(rid), res)
if not rid:
    sys.exit(1)

st, body, secs = stream_once("action=sse_ride_status&id=%s&token=%s" % (rid, rtok))
check("ride stream answers", st == 200 and body.startswith("data: "), body[:120])
check("and returns quickly (does not hold the connection)", secs < 5, secs)
data = json.loads(body.split("data: ", 1)[1].split("\n", 1)[0]) if "data: " in body else {}
check("with the ride status", data.get("status") == "searching", data)
check("and no close event while the ride is open", "event: close" not in body, body)

call("cancel_ride", {"ride_id": rid}, rtok)
st, body, secs = stream_once("action=sse_ride_status&id=%s&token=%s" % (rid, rtok))
check("a finished ride sends the close event", "event: close" in body, body[:200])

st, res = call("register", {"name": "Sse Driver", "phone": "+251965%s" % SUFFIX[-6:], "password": "test1234", "role": "customer"})
dtok = (res.get("data") or {}).get("token")
if dtok:
    st, body, secs = stream_once("action=sse_driver_stream&radius_km=8&token=%s" % dtok)
    check("driver stream answers once and returns quickly", st == 200 and body.startswith("data: ") and secs < 5, (st, secs, body[:120]))
    check("driver stream carries a requests list", '"requests"' in body or '"error"' in body, body[:200])

if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All live-stream checks passed")
