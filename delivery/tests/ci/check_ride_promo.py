"""Guards ride promo codes: the rider and admin screens are wired in, every action is routed and handled, the money
rules are where they belong (discount taken off the final fare, Etel funds it, a use is counted at completion)."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

page = read('ride/index.html')
for needle in ('css/ride-promo.css', 'js/ride-promo.js'):
    if needle not in page:
        bad.append('ride/index.html does not load ' + needle)
if 'js/admin-ride-promos.js' not in read('admin.html'):
    bad.append('admin.html does not load js/admin-ride-promos.js')

boot = read('api/boot.php')
ctl = read('api/controllers/RideController.php')
for action in ('ride_promo_check', 'ride_promo_propose', 'ride_promo_mine', 'admin_ride_promo_list', 'admin_ride_promo_review', 'admin_ride_promo_save'):
    if "case '%s'" % action not in boot:
        bad.append('boot.php does not route ' + action)
    if "case '%s': {" % action not in ctl:
        bad.append('RideController.php does not handle ' + action)
for action in ('admin_ride_promo_review', 'admin_ride_promo_save'):
    body = ctl.split("case '%s': {" % action, 1)[1].split("\ncase '", 1)[0]
    if "'rides.manage'" not in body:
        bad.append(action + ' must require rides.manage')
prop = ctl.split("case 'ride_promo_propose': {", 1)[1].split("\ncase '", 1)[0]
if "'pending'" not in prop or "'approved'" in prop:
    bad.append('a proposed code must start as pending and never as approved')

# the discount is applied when the trip ends, at every place a trip becomes arrived
if ctl.count('rides_promo_apply_to_arrived(') < 3 or 'rides_promo_apply_to_arrived(' not in read('api/lib/ride_sweeper.php'):
    bad.append('rides_promo_apply_to_arrived must run wherever a ride becomes arrived (driver_arrived, early end, customer_confirm_arrival path, sweeper)')
fare = read('api/lib/ride_fare.php')
if 'rides_promo_settle(' not in fare or 'round($grossFare * $commissionRate, 2)' not in fare:
    bad.append('commission must stay 5% of the FULL fare (fare + promo discount)')
if 'etel_money_balance+?' not in fare:
    bad.append('a cash trip discount must be credited back to the driver (etel_money_balance)')
if 'book_ride' not in ctl or 'rides_promo_check($pdo, $promoIn' not in ctl:
    bad.append('book_ride must check promo_code')
promo = read('api/lib/ride_promo.php')
if '$gross - 10.0' not in promo or '$gross * 0.8' not in promo:
    bad.append('a discount must never take a fare below ETB 10 or more than 80% off')

mig = read('api/migrations/088_ride_promo_codes.sql')
for t in ('ride_promo_codes', 'ride_promo_redemptions', 'promo_discount'):
    if t not in mig:
        bad.append('migration 088 is missing ' + t)

if bad:
    print('check_ride_promo FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_ride_promo OK')
