"""Guards the courier side of packages: the v6 release exists and carries the add-on, the server gates packages on the app's pkg=1 signal,
PINs are checked server side, a plain status change cannot skip them, and the PINs never reach the courier."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
addon = read('js/courier-packages.js')
v6 = read('js/releases/courier/v6/courier-bundle.js')
v5 = read('js/releases/courier/v5/courier-bundle.js')
if addon.strip() not in v6 or not v6.startswith(v5.rstrip('\n')[:2000]):
    bad.append('release v6 must be release v5 plus js/courier-packages.js (run tools/build_courier_release.py)')
if "pkg: 1" not in addon:
    bad.append('the add-on must tell the server the app can run packages (pkg=1)')
if re.search(r'\bon[a-z]+="', addon):
    bad.append('courier-packages.js must not use inline handlers')
orders = read('api/controllers/OrderController.php')
lib = read('api/lib/package_orders.php')
for f, needle, why in (
    ('api/lib/courier_offers.php', 'etel_package_capable_sql', 'offers must go only to couriers whose app can run packages'),
    ('api/lib/order_dispatch.php', 'etel_package_capable_sql', 'dispatch must go only to couriers whose app can run packages'),
    ('api/lib/manual_dispatch.php', 'etel_package_courier_capable', 'staff must not assign a package to a courier whose app cannot run it'),
):
    if needle not in read(f):
        bad.append(why)
if "etel_package_courier_capable($pdo, $courier['id'])" not in orders:
    bad.append('accepting a package must need a capable app')
if "Packages are picked up and delivered with the PIN" not in orders:
    bad.append('courier_update_order_status must refuse picked_up / delivered for a package')
my = orders.split("case 'courier_get_my_orders'", 1)[1].split("case 'courier_earnings_summary'", 1)[0]
if 'pickup_pin' in my or 'delivery_pin' in my:
    bad.append('the courier order list must never carry the PINs')
avail = orders.split("case 'courier_get_available_orders'", 1)[1].split("case 'courier_accept_order'", 1)[0]
if 'pickup_pin' in avail or 'delivery_pin' in avail:
    bad.append('the available jobs list must never carry the PINs')
if 'hash_equals' not in lib or 'package_pin_tries' not in lib:
    bad.append('PINs must be compared in constant time with a wrong-try lock')
ctl = read('api/controllers/PackageController.php')
for a in ('courier_package_pickup', 'courier_package_deliver'):
    if "case '" + a + "'" not in ctl or ("case '" + a + "'") not in read('api/boot.php'):
        bad.append(a + ' is not handled or routed')
if 'require_courier($pdo)' not in ctl.split("case 'courier_package_pickup'", 1)[1]:
    bad.append('the PIN steps must be couriers only')
if bad:
    print('check_courier_packages FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_packages ok')
