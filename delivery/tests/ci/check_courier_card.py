"""Guards "who is coming": customer.html loads the courier card script, customer.js uses it, the courier app loads the vehicle-picture marker script, and the
server card never selects the courier's ID, licence, home address or emergency contact."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
if 'customer-courier-card.js' not in read('customer.html'):
    bad.append('customer.html does not load customer-courier-card.js')
if 'etelCourierCard(' not in read('js/customer.js'):
    bad.append('customer.js does not use etelCourierCard')
for page in ('courier/index.html', 'courier/courier-index.html'):
    if 'courier-vehicle-marker.js' not in read(page):
        bad.append(page + ' does not load courier-vehicle-marker.js')
for f in ('js/customer-courier-card.js', 'js/courier-vehicle-marker.js'):
    js = read(f)
    if re.search(r'\beval\(|document\.write|\bonclick=|\bonerror=', js):
        bad.append(f + ' uses inline handlers, eval or document.write')
    if subprocess.call(['node', '--check', root + '/' + f]) != 0:
        bad.append(f + ' does not parse')
php = read('api/lib/courier_card.php')
code = re.sub(r'/\*.*?\*/', '', php, flags=re.S)   # the header comment names what is NOT sent
if re.search(r'id_number|id_photo|licence|emergency|address', code):
    bad.append('courier_card.php touches ID, licence, address or emergency fields')
if 'courier_card' not in read('api/controllers/OrderController.php'):
    bad.append('track_order does not send courier_card')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: courier card guarded')
