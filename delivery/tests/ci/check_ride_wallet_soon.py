"""Guards "Wallet is coming soon" for rides: the rider screen shows it dimmed and not selectable, and the server refuses
wallet payment in every place a ride can be paid unless the ride_wallet_enabled switch is on."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

page = read('ride/index.html')
m = re.search(r'<div class="pay-opt[^"]*"\s+data-method="wallet"[^>]*>', page)
if not m:
    bad.append('ride/index.html has no wallet payment option')
else:
    tag = m.group(0)
    if 'pay-soon' not in tag or 'aria-disabled="true"' not in tag:
        bad.append('the wallet payment option must be marked pay-soon and aria-disabled')
    if 'onclick' in tag:
        bad.append('the wallet payment option must not be selectable (no onclick)')
if 'Coming soon' not in page.split('data-method="wallet"', 1)[1].split('</div>', 1)[0]:
    bad.append('the wallet payment option must say Coming soon')
if '.pay-opt.pay-soon' not in read('css/ride-gold.css'):
    bad.append('css/ride-gold.css must style .pay-opt.pay-soon')

ctl = read('api/controllers/RideController.php')
if ctl.count("$pm === 'wallet' && !rides_wallet_enabled($pdo)") < 3:
    bad.append('book_ride, schedule and customer_confirm_arrival must each refuse wallet while it is coming soon')
fare = read('api/lib/ride_fare.php')
if "'ride_wallet_enabled', 0" not in fare:
    bad.append('rides_wallet_enabled must default to off')
if 'Coming soon' not in read('js/ride-i18n.js'):
    bad.append('js/ride-i18n.js needs an Amharic entry for Coming soon')

if bad:
    print('check_ride_wallet_soon FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_ride_wallet_soon OK')
