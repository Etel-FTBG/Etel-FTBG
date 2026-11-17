"""
ETEL Delivery & Ride -- online payments (Chapa) end to end against Chapa's REAL TEST API.

NOT part of CI (needs a Chapa TEST secret key and the internet). Run it by hand against a THROWAWAY local database:

    # api/.env of the local server:  CHAPA_SECRET_KEY=CHASECK_TEST-...   (a TEST key; the script refuses a live key)
    php -S 127.0.0.1:8765 -t . &
    CHAPA_SANDBOX=1 python3 tests/chapa_sandbox_e2e.py

What is real: our own server code (chapa_init, chapa_status, the return page, the webhook, the reconcile cron, settlement of an order and of a
ride) and Chapa's test API (initialize, verify, and a test payment). What is not: the customer never taps through Chapa's hosted checkout page
(a person does that in the manual test), so the test payment is made with Chapa's documented direct-charge TEST route (test mobile 0900123456),
and Chapa's own webhook call to the server is signed here with the local key (Chapa cannot reach a local server).
Safety: it stops at once unless Chapa answers mode = "test".
"""
import hashlib
import hmac
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
BASE = API_URL.rsplit("/api/", 1)[0]
SUFFIX = str(int(time.time()))
TAIL = SUFFIX[-6:]
fails, passes = [], []
TEST_MOBILE = "0900123456"


def check(name, cond, detail=""):
    (passes if cond else fails).append(name)
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)[:300]))


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=60) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def data(res):
    return res.get("data") or {}


def chapa(method, path, body=None, form=False):
    """Chapa's API. The secret key is added by the environment (credential proxy) or CHAPA_KEY_FOR_TEST; never printed."""
    headers = {"Content-Type": "application/json"}
    if os.environ.get("CHAPA_KEY_FOR_TEST"):
        headers["Authorization"] = "Bearer " + os.environ["CHAPA_KEY_FOR_TEST"]
    req = urllib.request.Request("https://api.chapa.co/v1" + path, data=json.dumps(body).encode() if body is not None else None, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=40) as f:
            return json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return json.loads(e.read().decode())
        except Exception:
            return {"status": "failed", "message": "HTTP %d" % e.code}


def sql(q):
    ok, out, err = etel_test_db.query_text(q)
    return out.strip() if ok else None


def one(q):
    out = sql(q)
    if not out:
        return None
    lines = out.splitlines()
    return lines[-1].strip() if lines else None


def register(phone, role, name):
    st, res = call("register", {"name": name, "phone": phone, "password": "test1234", "role": role})
    d = data(res)
    return d.get("token"), d.get("user_id")


def new_order(uid, total, tag):
    ok, err = etel_test_db.run_sql("INSERT INTO orders (order_code, customer_id, from_zone, to_zone, status, fare_total, payment_status, placed_at) VALUES "
                                   "('CS%s%s', %d, 'a', 'b', 'pending', %s, 'unpaid', NOW())" % (SUFFIX, tag, uid, total))
    return int(one("SELECT id FROM orders WHERE order_code='CS%s%s'" % (SUFFIX, tag)))


def pay_at_chapa(tx_ref, amount):
    """A customer paying: Chapa's documented TEST direct charge. Returns True when Chapa says the payment is a success."""
    res = {}
    for attempt in range(5):
        res = chapa("POST", "/charges?type=telebirr", {"amount": "%.2f" % amount, "currency": "ETB", "tx_ref": tx_ref, "mobile": TEST_MOBILE})
        if res.get("status") == "success":
            break
        if "try again" in str(res.get("message", "")).lower():     # Chapa's test mode slows down rapid charges: wait and retry
            print("   (Chapa asks to slow down; waiting 30 s, attempt %d of 5)" % (attempt + 1))
            time.sleep(30)
            continue
        break
    if res.get("status") != "success":
        print("   (Chapa refused the test charge: %s)" % json.dumps(res)[:200])
        return False
    for _ in range(12):
        time.sleep(2)
        v = chapa("GET", "/transaction/verify/" + urllib.parse.quote(tx_ref))
        if (v.get("data") or {}).get("status") == "success":
            return True
    return False


def add_payment_row(tx_ref, kind, target, uid, amount):
    etel_test_db.run_sql("INSERT INTO chapa_payments (tx_ref, kind, target_id, user_id, amount, status) VALUES ('%s', '%s', %d, %d, %s, 'pending')" % (tx_ref, kind, target, uid, amount))


def main():
    if os.environ.get("CHAPA_SANDBOX") != "1":
        print("SKIP: set CHAPA_SANDBOX=1 to run against Chapa's test API")
        return 0
    ok, why = etel_test_db.run_sql("SELECT 1")
    if not ok:
        print("SKIP: no test database (%s)" % why)
        return 0
    # SAFETY: only ever in test mode
    probe = "etel-safety-%s" % SUFFIX
    res = chapa("POST", "/transaction/initialize", {"amount": "10.00", "currency": "ETB", "tx_ref": probe, "first_name": "Etel", "last_name": "Test", "return_url": "https://example.com"})
    if res.get("status") != "success":
        print("SKIP: cannot reach Chapa or the key is not accepted: %s" % json.dumps(res)[:200])
        return 0
    v = chapa("GET", "/transaction/verify/" + probe)
    if (v.get("data") or {}).get("mode") != "test":
        print("STOP: Chapa did not answer mode=test. This script only runs with a TEST key.")
        return 1
    print("Chapa answered mode=test: safe to go on.\n")

    st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = data(res).get("token")
    cust, cust_id = register("+251944%s" % TAIL, "customer", "Chapa Customer")
    other, other_id = register("+251945%s" % TAIL, "customer", "Chapa Other")
    if not (cust and other):
        check("test customers registered", False, (cust, other))
        return 1

    print("1. Start a payment (real Chapa)")
    oid = new_order(cust_id, "100.00", "A")
    st, res = call("chapa_init", {"kind": "order", "id": oid, "amount": 1}, cust)
    d = data(res)
    check("chapa_init answers with a hosted Chapa checkout link", st == 200 and str(d.get("checkout_url", "")).startswith("https://checkout.chapa.co/"), (st, res))
    check("the amount is the ORDER total (100), not what the phone sent (1)", float(d.get("amount", 0)) == 100.0, d)
    ref_a = d.get("tx_ref", "")
    check("the payment is recorded as pending with the right amount", one("SELECT CONCAT(status,'|',amount) FROM chapa_payments WHERE tx_ref='%s'" % ref_a) == "pending|100.00", ref_a)
    v = chapa("GET", "/transaction/verify/" + ref_a)
    check("Chapa knows this reference, in test mode, amount 100 ETB, still pending", (v.get("data") or {}).get("status") == "pending" and (v.get("data") or {}).get("mode") == "test" and float((v.get("data") or {}).get("amount", 0)) == 100.0, v)

    print("2. The customer walks away without paying")
    st, res = call("chapa_status", {"tx_ref": ref_a}, cust)
    check("status is 'pending' (never paid)", data(res).get("result") == "pending", res)
    check("the order is still unpaid", one("SELECT payment_status FROM orders WHERE id=%d" % oid) == "unpaid")
    st, res = call("chapa_status", {"tx_ref": ref_a}, other)
    check("another customer cannot ask about this payment (404)", st == 404, (st, res))
    st, res = call("chapa_init", {"kind": "order", "id": oid}, other)
    check("another customer cannot pay for this order (404)", st == 404, (st, res))
    st, res = call("chapa_init", {"kind": "ride", "id": 999999}, cust)
    check("a ride that is not waiting for payment is refused (404)", st == 404, (st, res))

    print("3. The customer pays; we settle by the return page / status")
    oid2 = new_order(cust_id, "100.00", "B")
    ref_b = "EDO-%d-T%s" % (oid2, TAIL)
    add_payment_row(ref_b, "order", oid2, cust_id, "100.00")
    check("test payment made at Chapa (success)", pay_at_chapa(ref_b, 100.0))
    req = urllib.request.Request(BASE + "/api/chapa-return.php?tx_ref=" + urllib.parse.quote(ref_b) + "&kind=order", method="GET")
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k):
            return None
    try:
        urllib.request.build_opener(NoRedirect).open(req, timeout=60)
        loc = ""
    except urllib.error.HTTPError as e:
        loc = e.headers.get("Location", "")
    check("the return page sends the browser back with chapa=completed", "chapa=completed" in loc and "customer.html" in loc, loc)
    check("the order is now PAID", one("SELECT payment_status FROM orders WHERE id=%d" % oid2) == "paid")
    check("the payment row is completed with a time", one("SELECT CONCAT(status,'|',completed_at IS NOT NULL) FROM chapa_payments WHERE tx_ref='%s'" % ref_b) == "completed|1")
    st, res = call("chapa_status", {"tx_ref": ref_b}, cust)
    check("asking again says 'already' (settled once only)", data(res).get("result") == "already", res)

    print("4. Protection: the amount must match")
    oid3 = new_order(cust_id, "150.00", "C")
    ref_c = "EDO-%d-T%s" % (oid3, TAIL)
    add_payment_row(ref_c, "order", oid3, cust_id, "150.00")         # we expect 150 ...
    check("test payment of only 100 made at Chapa", pay_at_chapa(ref_c, 100.0))   # ... but 100 was paid
    st, res = call("chapa_status", {"tx_ref": ref_c}, cust)
    check("amount mismatch: NOT settled ('failed')", data(res).get("result") == "failed", res)
    check("amount mismatch: the order stays unpaid", one("SELECT payment_status FROM orders WHERE id=%d" % oid3) == "unpaid")

    print("5. The webhook (signed with the local key; Chapa cannot reach a local server)")
    key = "proxy-injected" if not os.environ.get("CHAPA_KEY_FOR_TEST") else os.environ["CHAPA_KEY_FOR_TEST"]
    oid4 = new_order(cust_id, "100.00", "D")
    ref_d = "EDO-%d-T%s" % (oid4, TAIL)
    add_payment_row(ref_d, "order", oid4, cust_id, "100.00")
    check("test payment made at Chapa (success)", pay_at_chapa(ref_d, 100.0))
    raw = json.dumps({"event": "charge.success", "tx_ref": ref_d}).encode()
    def hook(body, sig):
        h = {"Content-Type": "application/json"}
        if sig is not None:
            h["Chapa-Signature"] = sig
        r = urllib.request.Request(BASE + "/api/chapa-callback.php", data=body, headers=h, method="POST")
        try:
            with urllib.request.urlopen(r, timeout=60) as f:
                return f.status, json.loads(f.read().decode())
        except urllib.error.HTTPError as e:
            try:
                return e.code, json.loads(e.read().decode())
            except Exception:
                return e.code, {}
    s1, r1 = hook(raw, None)
    check("webhook with no signature is refused", s1 in (401, 503), (s1, r1))
    s2, r2 = hook(raw, "0" * 64)
    check("webhook with a wrong signature is refused (401) and settles nothing", s2 == 401 and one("SELECT payment_status FROM orders WHERE id=%d" % oid4) == "unpaid", (s2, r2))
    s3, r3 = hook(b'{"event":"x"}', hmac.new(key.encode(), b'{"event":"x"}', hashlib.sha256).hexdigest())
    check("a signed webhook without tx_ref is refused (422)", s3 == 422, (s3, r3))
    s4, r4 = hook(raw, hmac.new(key.encode(), raw, hashlib.sha256).hexdigest())
    check("a correctly signed webhook settles the order once", s4 == 200 and r4.get("result") == "completed" and one("SELECT payment_status FROM orders WHERE id=%d" % oid4) == "paid", (s4, r4))
    s5, r5 = hook(raw, hmac.new(key.encode(), raw, hashlib.sha256).hexdigest())
    check("the same webhook again does nothing (already)", s5 == 200 and r5.get("result") == "already", (s5, r5))
    unk = json.dumps({"tx_ref": "EDO-0-NOPE"}).encode()
    s6, r6 = hook(unk, hmac.new(key.encode(), unk, hashlib.sha256).hexdigest())
    check("a signed webhook for an unknown payment settles nothing ('unknown')", s6 == 200 and r6.get("result") == "unknown", (s6, r6))

    print("6. The reconcile job rescues a payment whose webhook and return page were lost")
    oid5 = new_order(cust_id, "100.00", "E")
    ref_e = "EDO-%d-T%s" % (oid5, TAIL)
    add_payment_row(ref_e, "order", oid5, cust_id, "100.00")
    check("test payment made at Chapa (success)", pay_at_chapa(ref_e, 100.0))
    check("before the job the order is unpaid", one("SELECT payment_status FROM orders WHERE id=%d" % oid5) == "unpaid")
    etel_test_db.run_sql("UPDATE chapa_payments SET created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE tx_ref IN ('%s', '%s')" % (ref_e, ref_a))
    out = subprocess.run(["php", os.path.join(HERE, "..", "api", "cron_chapa_reconcile.php")], capture_output=True, text=True, timeout=120).stdout
    try:
        j = json.loads(out)
    except Exception:
        j = {}
    jr = j.get("result") or {}
    check("the job ran and settled the paid payment", jr.get("completed", 0) >= 1 and one("SELECT payment_status FROM orders WHERE id=%d" % oid5) == "paid", out[:300])
    check("the job left the abandoned payment pending", one("SELECT status FROM chapa_payments WHERE tx_ref='%s'" % ref_a) == "pending")

    print("7. A ride paid with Chapa credits the driver as a platform-collected (not cash) fare")
    drv, drv_id = register("+251946%s" % TAIL, "courier", "Chapa Driver")
    call("driver_get_profile", {}, drv)
    if not (drv and etel_test_db.onboard_driver(drv_id)):
        print("   SKIP the ride part: cannot create an onboarded driver in this database")
    else:
        etel_test_db.run_sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")
        call("driver_go_online", {"lat": 7.05, "lng": 38.49}, drv)
        st, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, cust)
        st, res = call("book_ride", {"pickup_lat": 7.05, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "chapa", "quote_token": data(est).get("quote_token")}, cust)
        rid = data(res).get("request_id")
        check("a ride is booked", bool(rid), res)
        if rid:
            call("driver_accept_ride", {"request_id": rid}, drv)
            call("driver_start_ride", {"ride_id": rid}, drv)
            call("driver_arrived", {"ride_id": rid}, drv)
            fare = float(one("SELECT fare_final FROM rides WHERE id=%d" % rid))
            check("the ride is waiting for payment (arrived)", one("SELECT status FROM rides WHERE id=%d" % rid) == "arrived")
            st, res = call("chapa_init", {"kind": "ride", "id": rid}, cust)
            check("chapa_init for the ride gives a Chapa link for the ride fare", float(data(res).get("amount", 0)) == fare and str(data(res).get("checkout_url", "")).startswith("https://"), res)
            ref_r = "EDR-%d-T%s" % (rid, TAIL)
            add_payment_row(ref_r, "ride", rid, cust_id, "%.2f" % fare)
            check("test payment of the fare made at Chapa (success)", pay_at_chapa(ref_r, fare))
            st, res = call("chapa_status", {"tx_ref": ref_r}, cust)
            check("the ride payment is settled ('completed')", data(res).get("result") == "completed", res)
            check("the ride is completed and marked paid by chapa", one("SELECT CONCAT(status,'|',payment_method) FROM rides WHERE id=%d" % rid) == "completed|chapa", one("SELECT CONCAT(status,'|',IFNULL(payment_method,'-')) FROM rides WHERE id=%d" % rid))

    print("\n%d passed, %d failed" % (len(passes), len(fails)))
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
