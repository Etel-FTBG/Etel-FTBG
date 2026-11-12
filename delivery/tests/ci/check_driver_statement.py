"""Guards the driver weekly statement: the script is loaded on the driver page, it reads driver_get_statement, the API
side is routed, and the Amharic entries exist."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

if 'js/driver-statement.js' not in read('driver/index.html'):
    bad.append('driver/index.html does not load js/driver-statement.js')
js = read('js/driver-statement.js')
for needle in ('driver_get_statement', 'drv-stmt-card', 'data-stmt="share"'):
    if needle not in js:
        bad.append('driver-statement.js is missing ' + needle)
if "case 'driver_get_statement'" not in read('api/boot.php'):
    bad.append('boot.php does not route driver_get_statement')
ctl = read('api/controllers/RideController.php')
if "case 'driver_get_statement'" not in ctl or 'rides_trip_money' not in ctl:
    bad.append('RideController.php is missing driver_get_statement or the per-trip money in the history')
lib = read('api/lib/ride_statement.php')
if 'rides_commission_rate() { return 0.05; }' not in lib:
    bad.append('ride_statement.php must use the same 5% as rides_complete_and_pay()')
# The standard rate is 5% in both places (the statement's default for older trips, the commission plan's standard rate), and a
# completed ride takes its rate from the commission plan and stores it, which the statement then shows per trip.
fare = read('api/lib/ride_fare.php')
if 'rides_commission_for_driver(' not in fare or '$commissionRate = $cm[' not in fare or 'commission_rate' not in fare:
    bad.append('ride_fare.php must take the rate from rides_commission_for_driver() and store commission_rate on the ride')
if "'standard_rate' => 0.05," not in read('api/lib/ride_commission.php'):
    bad.append('ride_commission.php standard rate changed: update rides_commission_rate() in lib/ride_statement.php to match')
if "isset($r['commission_rate'])" not in lib:
    bad.append('ride_statement.php must show the commission rate stored on each trip')
for text in ('Weekly statement', 'Etel commission (5%)', 'Share statement'):
    if "'" + text + "'" not in read('js/driver-i18n.js'):
        bad.append('driver-i18n.js needs the Amharic entry for ' + text)

if bad:
    print('check_driver_statement FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_driver_statement OK')
