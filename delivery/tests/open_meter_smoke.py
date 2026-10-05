import urllib.request
import json
import time
import sys

API_URL = "http://127.0.0.1:8765/api/boot.php"
suffix = str(int(time.time()))

def call_api(action, body, token=None):
    body["action"] = action
    data = json.dumps(body).encode('utf-8')
    headers = {
        'Content-Type': 'application/json'
    }
    if token:
        headers['Authorization'] = f'Bearer {token}'
    req = urllib.request.Request(API_URL, data=data, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req) as f:
            res_text = f.read().decode('utf-8')
            res = json.loads(res_text)
            if not res.get("success"):
                print(f"API Error in {action}: {res.get('message')}")
                sys.exit(1)
            return res.get("data")
    except Exception as e:
        print(f"Network error in {action}: {e}")
        sys.exit(1)

print(f"1. Registering customer (cust_{suffix}) + driver (drv_{suffix})...")
cust_phone = f"+251961{suffix[-6:]}"
cust_res = call_api("register", {"name": f"Cust {suffix}", "phone": cust_phone, "password": "test1234", "role": "customer"})
cust_token = cust_res["token"]
cust_id = cust_res["user_id"]
print(f"   Customer registered successfully (id={cust_id}, phone={cust_phone})")

drv_phone = f"+251962{suffix[-6:]}"
drv_res = call_api("register", {"name": f"Drv {suffix}", "phone": drv_phone, "password": "test1234", "role": "courier"})
drv_token = drv_res["token"]
drv_id = drv_res["user_id"]
print(f"   Driver registered successfully (id={drv_id}, phone={drv_phone})")

print("2. Onboarding driver...")
# Real onboarding needs a selfie, licence photo and identity check (a deliberate gate),
# so mark the driver onboarded directly in the TEST database instead of faking uploads.
import os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import etel_test_db
call_api("driver_get_profile", {}, token=drv_token)   # creates the ride_drivers row
if not etel_test_db.onboard_driver(drv_id):
    print("SKIP: cannot reach the test database to mark the driver onboarded "
          "(set ETEL_TEST_DB_* or api/.env). Nothing was tested.")
    sys.exit(0)
print("   Driver onboarded (test database)!")

print("3. Going online as driver...")
call_api("driver_go_online", {"lat": 7.05, "lng": 38.49}, token=drv_token)
print("   Driver is online!")

print("4. Creating Street Hail open trip...")
hail_res = call_api("driver_hail_customer", {"phone": cust_phone, "km": 0, "lat": 7.05, "lng": 38.49}, token=drv_token)
open_trip = hail_res.get("open_trip")
ride_id = hail_res.get("id")
if str(open_trip) != '1':
    print(f"Error: expected open trip (open_trip=1), got: {hail_res}")
    sys.exit(1)
print(f"   Open trip created successfully! (ride_id={ride_id})")

print("5. Starting ride...")
call_api("driver_start_ride", {"ride_id": ride_id}, token=drv_token)
print("   Ride in progress!")

print("6. Updating GPS coordinates...")
lat0 = 7.0500
lng0 = 38.4900
d_lng = 0.00452
accumulated_km = 0.0
for i in range(1, 5):
    lng = lng0 + d_lng * i
    # The meter rejects pings that imply an impossible speed (anti GPS-glitch rule: 120 km/h
    # plus slack over the time since the last accepted ping). These test pings are sent
    # back to back, so back-date the previous ping by 90 s: ~0.5 km in 90 s = 20 km/h.
    etel_test_db.run_sql("UPDATE rides SET last_location_at = NOW() - INTERVAL 90 SECOND WHERE id = %d" % int(ride_id))
    ping_res = call_api("driver_update_location", {"lat": lat0, "lng": lng}, token=drv_token)
    meter = ping_res.get("meter")
    if meter:
        accumulated_km = float(meter.get("traveled_km", 0))
    print(f"   Step {i}: Lng={lng:.6f} -> traveled_km={accumulated_km:.3f} km")

print(f"   Final accumulated distance: {accumulated_km:.3f} km")
if accumulated_km < 1.0:
    print(f"Error: expected accumulated_km >= 1.0 km, got {accumulated_km}")
    sys.exit(1)

print("7. Ending trip (driver_arrived)...")
arr_res = call_api("driver_arrived", {"ride_id": ride_id}, token=drv_token)
arrived = arr_res.get("arrived")
if not arrived:
    print(f"Error: expected arrived=True, got: {arr_res}")
    sys.exit(1)
fare = float(arr_res.get("fare", 0))
print(f"   Arrived! Final fare: ETB {fare:.2f}")
if fare < 120.0:
    print(f"Error: expected fare >= base 120, got {fare}")
    sys.exit(1)

print("8. Customer confirming arrival and payment...")
conf_res = call_api("customer_confirm_arrival", {"ride_id": ride_id, "payment_method": "cash"}, token=cust_token)
completed = conf_res.get("completed")
if not completed:
    print(f"Error: expected completed=True, got: {conf_res}")
    sys.exit(1)
print("   Customer confirmed payment! Ride status: completed")

print("9. Verifying driver earnings and total rides count...")
profile_res = call_api("driver_get_profile", {}, token=drv_token)
driver = profile_res.get("driver")
total_rides = int(driver.get("total_rides", 0))
total_earnings = float(driver.get("total_earnings", 0))
print(f"   Driver Total Rides: {total_rides}, Total Earnings: ETB {total_earnings:.2f}")
if total_rides < 1:
    print(f"Error: expected total_rides >= 1, got {total_rides}")
    sys.exit(1)

print("\nALL CHECKS PASSED")
print(f"Test ride #{ride_id} (open meter, customer={cust_id}, driver={drv_id}) completed successfully!")
