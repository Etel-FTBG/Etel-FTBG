"""Guards rider price offers and driver counter-offers: both apps load the scripts (and the shared CSS), every action is
routed, the agreed price is applied at the two normal arrival points only (an early-ended or auto-ended trip is still
billed by the meter), and the Amharic entries exist."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

ride, drv = read('ride/index.html'), read('driver/index.html')
for needle in ('js/ride-offer-price.js', 'css/ride-offer-price.css'):
    if needle not in ride:
        bad.append('ride/index.html does not load ' + needle)
for needle in ('js/driver-counter.js', 'css/ride-offer-price.css', 'css/ride-promo.css'):
    if needle not in drv:
        bad.append('driver/index.html does not load ' + needle)
boot = read('api/boot.php')
for action in ('driver_counter_offer', 'ride_counter_list', 'ride_counter_accept', 'ride_counter_decline', 'ride_raise_offer', 'ride_offer_bounds'):
    if "case '" + action + "'" not in boot:
        bad.append('boot.php does not route ' + action)
ctl = read('api/controllers/RideController.php')
for action in ('driver_counter_offer', 'ride_counter_list', 'ride_counter_accept', 'ride_counter_decline', 'ride_raise_offer', 'ride_offer_bounds'):
    if "case '" + action + "': {" not in ctl:
        bad.append('RideController.php is missing ' + action)
if len(re.findall(r'rides_agreed_apply\(', ctl)) != 2:
    bad.append('rides_agreed_apply must be called at exactly the two normal arrival points (driver_arrived, driver_complete_ride)')
sweeper = read('api/lib/ride_sweeper.php')
if 'rides_agreed_apply' in sweeper:
    bad.append('the auto-end sweeper must bill by the meter, not the agreed price')
if "agreed_fare=IF(rider_offer IS NOT NULL AND rider_offer > 0, fare_final, NULL)" not in ctl:
    bad.append('driver_accept_ride must fix the price of an offer ride')
if 'offer_fare' not in ctl or 'rides_offer_bounds' not in ctl:
    bad.append('book_ride must validate offer_fare against the allowed band')
sse = read('js/etel-sse-poll.js')
if 'etel:stream' not in sse:
    bad.append('etel-sse-poll.js must pass stream answers on as the etel:stream event (the driver counter script reads it)')
for text in ('Offer your own price', 'Offers from drivers', 'Raise offer'):
    if text not in read('js/ride-i18n.js'):
        bad.append('ride-i18n.js needs the Amharic entry for ' + text)
for text in ("Rider's offer", 'Counter-offer', 'Send counter-offer'):
    if text not in read('js/driver-i18n.js'):
        bad.append('driver-i18n.js needs the Amharic entry for ' + text)

if bad:
    print('check_ride_offer_price FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_ride_offer_price OK')
