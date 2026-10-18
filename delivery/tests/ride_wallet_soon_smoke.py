"""
Wallet payment for rides is "coming soon": booking, scheduling and confirming with payment_method=wallet are refused
with a clear message unless a pricing_rules row ride_wallet_enabled = 1 exists; cash is not affected.

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


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("ride wallet: coming soon")
etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key='ride_wallet_enabled'")
st, res = call("register", {"name": "Wallet Soon", "phone": "+251968%s" % SUFFIX[-6:], "password": "test1234", "role": "customer"})
tok = (res.get("data") or {}).get("token")
check("rider registered", bool(tok), res)
if not tok:
    sys.exit(1)

trip = {"pickup_lat": 7.0500, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52}
st, res = call("book_ride", dict(trip, payment_method="wallet"), tok)
check("booking with the wallet is refused", res.get("success") is False and st == 400, (st, res))
check("and says coming soon", "coming soon" in json.dumps(res).lower(), res)

st, res = call("book_ride", dict(trip, payment_method="cash"), tok)
check("cash is not refused for that reason", "coming soon" not in json.dumps(res).lower(), res)

st, res = call("customer_confirm_arrival", {"ride_id": 1, "payment_method": "wallet"}, tok)
check("confirming a wallet payment is refused", "coming soon" in json.dumps(res).lower(), (st, res))

if fails:
    print("FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All wallet-soon checks passed")
