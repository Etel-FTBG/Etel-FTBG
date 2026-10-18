"""Guards the rider Book screen vehicle list: the script and stylesheet exist and are loaded, the API returns rates and ETAs, admin-added vehicle types are not forced to bajaj."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

page = read('ride/index.html')
if 'js/ride-vehicle-list.js' not in page:
    bad.append('ride/index.html does not load js/ride-vehicle-list.js')
if 'css/ride-step2.css' not in page:
    bad.append('ride/index.html does not load css/ride-step2.css')
if re.search(r'<span class="poi">[^<]*(\U0001F7E2|\U0001F4B5|\U0001F4B3)', page):
    bad.append('the payment options must use drawn icons, not emoji (cash and wallet looked identical)')
if 'role="radiogroup"' not in page.split('<!-- Payment row -->', 1)[1].split('slide-book-wrap', 1)[0]:
    bad.append('the payment options must be a radio group')

js = read('js/ride-vehicle-list.js')
for needle in ('ride_get_vehicle_types', 'ride_estimate_fare', 'EtelUI.vehicleGlyph', 'role', 'radiogroup', 'selectVtype' if False else 'click()'):
    if needle not in js:
        bad.append('ride-vehicle-list.js is missing ' + needle)

ctl = read('api/controllers/RideController.php')
types = ctl.split("case 'ride_get_vehicle_types'", 1)[1].split("case 'ride_estimate_fare'", 1)[0]
if "'rates' => $rates" not in types or "'eta_min' => $eta" not in types:
    bad.append('ride_get_vehicle_types must return rates and eta_min')
if 'rides_normalize_vehicle_type' not in read('api/lib/ride_fare.php'):
    bad.append('rides_normalize_vehicle_type is missing')
if re.search(r"in_array\(\$vtype, array\('bajaj',\s*'moto',\s*'car',\s*'minibus',\s*'qute'\), true\)\) \$vtype = 'bajaj'", ctl):
    bad.append('RideController.php still has a fixed list of vehicle types that turns admin-added types into bajaj')

if bad:
    print('check_rider_vehicle_list FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_rider_vehicle_list OK')
