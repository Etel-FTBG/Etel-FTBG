"""Guards the customer's live order map: customer.html loads js/customer-live-map.js AFTER customer.js, customer.js calls the hooks, the script has no
inline handlers / eval, and track_order sends pickup_point but never the raw shop or pickup columns."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
html = read('customer.html')
a, b = html.find('js/customer.js'), html.find('js/customer-live-map.js')
if b < 0 or a < 0 or b < a:
    bad.append('customer.html must load customer-live-map.js after customer.js')
js = read('js/customer-live-map.js')
if re.search(r'\beval\(|document\.write|\bonclick=', js):
    bad.append('customer-live-map.js uses inline handlers, eval or document.write')
if subprocess.call(['node', '--check', root + '/js/customer-live-map.js']) != 0:
    bad.append('customer-live-map.js does not parse')
cj = read('js/customer.js')
if 'etelTrackLive(' not in cj or 'etelTrackReset(' not in cj:
    bad.append('customer.js does not call the live map hooks')
oc = read('api/controllers/OrderController.php')
if "$order['pickup_point']" not in oc or "unset($order['shop_lat'], $order['shop_lng'], $order['pickup_lat'], $order['pickup_lng'])" not in oc:
    bad.append('track_order must send pickup_point and drop the raw shop / pickup columns')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: customer live map guarded')
