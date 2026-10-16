"""Guards the package 'Delivery journey' on the customer tracking screen: the server sends stage times on the database clock,
both languages have every string, the timers read data-pt-from (no inline handlers), and the generic step list is only hidden when the journey is shown."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
js, order, lib, html = read('js/customer-package.js'), read('api/controllers/OrderController.php'), read('api/lib/package_orders.php'), read('customer.html')
bad = []
if 'function etel_package_times(' not in lib or 'UNIX_TIMESTAMP()' not in lib:
    bad.append('etel_package_times (stage times + the database clock) is missing')
if "$order['pkg_times'] = etel_package_times(" not in order:
    bad.append('track_order must return order.pkg_times for packages')
en = re.search(r"\ben: \{(.*?)\n        \},\n        am: \{", js, re.S)
am = re.search(r"\bam: \{(.*?)\n        \}\n    \}\);", js, re.S)
keys = lambda m: set(re.findall(r"\b(pkg_j_[a-z_]+):", m.group(1))) if m else set()
if not keys(en):
    bad.append('no pkg_j_ strings found in English')
if keys(en) != keys(am):
    bad.append('English and Amharic pkg_j_ strings differ: ' + ', '.join(sorted(keys(en) ^ keys(am))))
for must in ('data-pt-from', 'journeyHtml', 'pkg_times', "style.display = journey ? 'none' : ''"):
    if must not in js:
        bad.append('customer-package.js lost: ' + must)
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('customer-package.js must not use inline handlers')
if 'css/customer-v5.css?v=7.1' not in html or 'pkg-j-hero' not in read('css/customer-v5.css'):
    bad.append('the journey styles are missing from customer-v5.css')
if bad:
    print('check_package_journey FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_package_journey ok')
