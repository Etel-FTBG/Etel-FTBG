# NOTE: each smoke test owns distinct phone prefixes (security 911-944, business 951-988, open_meter 961-962)
# so tests started within the same second (as in CI) can never collide on a number.
"""
ETEL Delivery -- Order / ride / service-giver business-logic smoke test

Fills the gap flagged in the 2026-07-12 audit: security_regression_smoke.py
and open_meter_smoke.py cover auth, validation, webhooks, and the
driver-initiated "street hail" open-meter ride flow end-to-end - but nothing
executable exercised the order lifecycle, the customer-initiated
book_ride -> driver_accept_ride path, or the service-giver item/order flow.
This file covers those three.

Usage (same convention as the other smoke tests):

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/business_logic_smoke.py

Exit code 0 = all checks passed (or gracefully skipped where noted). Any
hard failure prints what was expected vs. actual and exits non-zero.

The order/service-giver section needs an admin account to create a service
giver and its catalogue - the project ships one via
api/seeds/admin_demo_accounts.sql (phone 0900000000 / password admin1234,
see tests/README.md). If that seed hasn't been applied to whatever
database this is pointed at, that section SKIPS instead of failing, since
"no admin fixture in this DB" is an environment fact, not a regression.
The ride section needs no fixtures and always runs.
"""
import json
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))

ADMIN_PHONE = "0900000000"
ADMIN_PASSWORD = "admin1234"

PASS = []
FAIL = []
SKIP = []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    data = json.dumps(body).encode("utf-8")
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=10) as f:
            status = f.status
            res = json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        status = e.code
        try:
            res = json.loads(e.read().decode("utf-8"))
        except Exception:
            res = {"success": False, "message": "(non-JSON error body)"}
    return status, res


def check(name, condition, detail=""):
    if condition:
        PASS.append(name)
        print(f"  OK   {name}")
    else:
        FAIL.append(name)
        print(f"  FAIL {name}  {detail}")


def skip(name, reason):
    SKIP.append(name)
    print(f"  SKIP {name}  ({reason})")


LAST_REG = {}


def register(phone, role, name_prefix="User"):
    global LAST_REG
    status, res = call("register", {
        "name": f"{name_prefix} {SUFFIX}", "phone": phone,
        "password": "testpass123", "role": role,
    })
    LAST_REG = {"http": status, "phone": phone, "res": res}
    return res.get("success"), (res.get("data") or {}).get("token"), (res.get("data") or {}).get("user_id")


def main():
    # ---------------------------------------------------------------
    # 1. Order lifecycle: admin creates a service giver -> service giver
    #    lists an item -> customer orders it -> courier accepts and
    #    delivers -> customer rates it.
    # ---------------------------------------------------------------
    print("1. Order lifecycle (service giver -> customer -> courier)")
    status, res = call("login", {"phone": ADMIN_PHONE, "password": ADMIN_PASSWORD})
    admin_token = (res.get("data") or {}).get("token")
    if not res.get("success") or not admin_token:
        skip("order lifecycle (admin/sg/customer/courier chain)",
             "no seeded admin account (phone 0900000000) in this database - "
             "run api/seeds/admin_demo_accounts.sql to enable this section")
    else:
        sg_phone = f"+251955{SUFFIX[-6:]}"
        status, res = call("admin_create_service_giver", {
            "name": f"Test SG {SUFFIX}", "phone": sg_phone, "password": "testpass123",
            "business_name": "Smoke Test Kitchen", "service_type": "food",
            "vehicle_type": "on_foot", "zone": "Mehal Ketema",
        }, token=admin_token)
        check("admin_create_service_giver succeeds", res.get("success") is True, res)

        status, res = call("sg_login", {"phone": sg_phone, "password": "testpass123"})
        sg_token = (res.get("data") or {}).get("token")
        check("sg_login succeeds for the new service giver", res.get("success") is True, res)

        status, res = call("sg_add_item", {
            "name": "Smoke Test Meal", "category": "food",
            "price": 150, "stock_quantity": 20,
        }, token=sg_token)
        check("sg_add_item succeeds", res.get("success") is True, res)
        item_id = (res.get("data") or {}).get("item_id")

        status, res = call("sg_add_item", {
            "name": "Bad Price Meal", "category": "food", "price": -10,
        }, token=sg_token)
        check("sg_add_item rejects a negative price", res.get("success") is False, res)

        status, res = call("sg_get_my_items", {}, token=sg_token)
        items = (res.get("data") or {}).get("items", [])
        check("sg_get_my_items lists the newly-created item",
              res.get("success") is True and any(str(i.get("id")) == str(item_id) for i in items),
              res)

        etel_test_db.park_couriers()   # no leftover online courier may auto-grab the order
        cust_phone = f"+251966{SUFFIX[-6:]}"
        ok_reg, cust_token, _ = register(cust_phone, "customer", "Order Cust")
        check("customer registration for order test succeeds", ok_reg, LAST_REG)

        status, res = call("customer_create_order", {
            "items": [{"item_id": item_id, "quantity": 2}],
            "to_zone": "Tabor", "recipient_name": "Smoke Recipient",
            "recipient_phone": cust_phone, "payment_method": "cash",
        }, token=cust_token)
        check("customer_create_order succeeds", res.get("success") is True, res)
        order_id = (res.get("data") or {}).get("order_id")

        courier_phone = f"+251977{SUFFIX[-6:]}"
        ok_reg, courier_token, _ = register(courier_phone, "courier", "Order Courier")
        check("courier registration for order test succeeds", ok_reg, LAST_REG)

        status, res = call("courier_get_available_orders", {}, token=courier_token)
        avail = (res.get("data") or {}).get("orders", [])
        check("courier_get_available_orders lists the new order",
              res.get("success") is True and any(str(o.get("id")) == str(order_id) for o in avail),
              res)

        status, res = call("courier_accept_order", {"order_id": order_id}, token=courier_token)
        check("courier_accept_order succeeds", res.get("success") is True, res)

        # A second courier must not be able to accept an already-assigned order.
        courier2_phone = f"+251988{SUFFIX[-6:]}"
        ok_reg, courier2_token, _ = register(courier2_phone, "courier", "Order Courier2")
        status, res = call("courier_accept_order", {"order_id": order_id}, token=courier2_token)
        check("a second courier cannot accept an already-assigned order",
              res.get("success") is False and status == 409, f"status={status} res={res}")

        status, res = call("courier_update_order_status",
                            {"order_id": order_id, "status": "picked_up"}, token=courier_token)
        check("courier_update_order_status(picked_up) succeeds", res.get("success") is True, res)

        status, res = call("courier_update_order_status",
                            {"order_id": order_id, "status": "delivered"}, token=courier_token)
        check("courier_update_order_status(delivered) succeeds", res.get("success") is True, res)

        status, res = call("rate_order", {"order_id": order_id, "stars": 5,
                                           "comment": "smoke test"}, token=cust_token)
        check("rate_order succeeds once the order is delivered", res.get("success") is True, res)

        status, res = call("rate_order", {"order_id": order_id, "stars": 4}, token=cust_token)
        check("rate_order rejects a second rating on the same order",
              res.get("success") is False, res)

    # ---------------------------------------------------------------
    # 2. Ride lifecycle: customer books by pickup/dropoff (not the
    #    street-hail path open_meter_smoke.py already covers), driver
    #    accepts, ride is cancellable before acceptance, not after.
    # ---------------------------------------------------------------
    print("2. Ride lifecycle (customer book_ride -> driver_accept_ride)")
    cust_phone = f"+251951{SUFFIX[-6:]}"
    ok_reg, cust_token, _ = register(cust_phone, "customer", "Ride Cust")
    check("customer registration for ride test succeeds", ok_reg, LAST_REG)

    drv_phone = f"+251953{SUFFIX[-6:]}"
    ok_reg, drv_token, drv_id = register(drv_phone, "courier", "Ride Driver")
    check("driver registration for ride test succeeds", ok_reg, LAST_REG)
    # Drivers must be onboarded before they can go online / accept rides (deliberate
    # gate). Flip the flag in the TEST database; skip the accept step if that is not possible.
    call("driver_get_profile", {}, token=drv_token)   # creates the ride_drivers row
    drv_onboarded = bool(drv_id) and etel_test_db.onboard_driver(drv_id)
    call("driver_go_online", {"lat": 7.05, "lng": 38.49}, token=drv_token)

    # During a peak-hour surge window book_ride refuses (409) unless the client
    # sends the price-lock token it got from ride_estimate_fare (the customer has
    # "seen and accepted the real number"). Real clients always do, and CI runs at
    # any hour, so the test does too - otherwise it only passed outside peak hours.
    status, est = call("ride_estimate_fare", {"distance_km": 2.6, "vehicle_type": "bajaj"}, token=cust_token)
    quote_token = ((est.get("data") or {}).get("quote_token")) if isinstance(est, dict) else None
    check("ride_estimate_fare returns a price-lock token", bool(quote_token), est)

    status, res = call("book_ride", {
        "pickup_lat": 7.0500, "pickup_lng": 38.4900,
        "dropoff_lat": 7.0700, "dropoff_lng": 38.5000,
        "payment_method": "cash", "quote_token": quote_token,
    }, token=cust_token)
    check("book_ride succeeds with pickup and dropoff set", res.get("success") is True, res)
    ride_id = (res.get("data") or {}).get("request_id")

    status, res = call("book_ride", {
        "pickup_lat": 7.0500, "pickup_lng": 38.4900,
        "dropoff_lat": 7.0700, "dropoff_lng": 38.5000,
        "quote_token": quote_token,
    }, token=cust_token)
    check("book_ride rejects a second active ride for the same customer",
          res.get("success") is False and status == 409, f"status={status} res={res}")

    status, res = call("driver_accept_ride", {"request_id": ride_id}, token=drv_token)
    if drv_onboarded:
        check("driver_accept_ride succeeds", res.get("success") is True, res)
    else:
        check("driver_accept_ride is refused for a driver who has not onboarded (gate works)",
              res.get("success") is False and "verified" in str(res.get("message", "")).lower(), res)

    status, res = call("cancel_ride", {"id": ride_id}, token=cust_token)
    check("cancel_ride succeeds on an accepted-but-not-arrived ride",
          res.get("success") is True, res)

    status, res = call("cancel_ride", {"id": ride_id}, token=cust_token)
    check("cancel_ride rejects cancelling an already-cancelled ride",
          res.get("success") is False, res)

    # ---------------------------------------------------------------
    # 3. Password reset (dev fallback channel - no Telegram/WhatsApp/SMS
    #    configured in this test environment): full request/verify/reset
    #    round trip, then login with the new password.
    # ---------------------------------------------------------------
    print("3. Password reset flow (dev-fallback OTP)")
    reset_phone = f"+251954{SUFFIX[-6:]}"
    ok_reg, _, _ = register(reset_phone, "customer", "Reset Cust")
    check("customer registration for reset test succeeds", ok_reg, LAST_REG)

    status, res = call("reset_request", {"phone": reset_phone})
    check("reset_request succeeds", res.get("success") is True, res)
    otp = (res.get("data") or {}).get("dev_otp")
    if not otp:
        skip("password reset round trip",
             "no dev_otp in response - a real OTP channel (TG_BOT_TOKEN/AT_API_KEY) "
             "is configured in this environment, so the OTP was actually delivered "
             "and isn't recoverable by this script")
    else:
        status, res = call("reset_verify", {"phone": reset_phone, "otp": "000000"})
        check("reset_verify rejects a wrong OTP", res.get("success") is False, res)

        status, res = call("reset_verify", {"phone": reset_phone, "otp": otp})
        check("reset_verify accepts the correct OTP", res.get("success") is True, res)

        status, res = call("reset_password", {
            "phone": reset_phone, "otp": otp, "new_password": "newpass456",
        })
        check("reset_password succeeds with the OTP", res.get("success") is True, res)

        status, res = call("login", {"phone": reset_phone, "password": "newpass456"})
        check("login succeeds with the new password after reset", res.get("success") is True, res)

        status, res = call("reset_password", {
            "phone": reset_phone, "otp": otp, "new_password": "anotherpass789",
        })
        check("reset_password rejects reusing the same (already-consumed) OTP",
              res.get("success") is False, res)

    print()
    print(f"{len(PASS)} passed, {len(FAIL)} failed, {len(SKIP)} skipped")
    if FAIL:
        print("Failed checks:", ", ".join(FAIL))
        sys.exit(1)
    print("ALL CHECKS PASSED" + (" (with skips)" if SKIP else ""))


if __name__ == "__main__":
    main()
