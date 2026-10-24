"""Guards courier offline work (release v11): the add-on parses and queues only the two safe actions, the server stays idempotent and checks the
time it is given, the service worker never stores the courier's own order list, and v11 is the exact v10 bundle plus the current add-on."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
addon = read('js/courier-offline.js')
if subprocess.call(['node', '--check', root + '/js/courier-offline.js']) != 0:
    bad.append('js/courier-offline.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", addon):
    bad.append('courier-offline.js must not use inline handlers')
m = re.search(r'QUEUE_ACTIONS\s*=\s*\{([^}]*)\}', addon)
queued = sorted(re.findall(r'(\w+)\s*:', m.group(1))) if m else []
if queued != ['courier_add_fuel_log', 'courier_update_order_status']:
    bad.append('only courier_update_order_status and courier_add_fuel_log may be queued offline (money, PIN and assignment actions stay online); found %s' % queued)
if "'cof-css'" not in addon:
    bad.append('style id cof-css expected')

sync = read('api/lib/offline_sync.php')
for must in ('INSERT IGNORE', 'PRIMARY KEY (user_id, req_id)', '86400', '+ 120'):
    if must not in sync:
        bad.append('offline_sync.php lost: ' + must)
order = read('api/controllers/OrderController.php')
if 'etel_offline_when($body[\'occurred_at\']' not in order:
    bad.append('courier_update_order_status must validate occurred_at with etel_offline_when')
if 'etel_offline_seen' not in read('api/controllers/CourierFuelController.php'):
    bad.append('courier_add_fuel_log must dedupe on client_req_id')

sw = read('sw.js')
if 'courier_get_my_orders' not in sw or 'Response.error()' not in sw:
    bad.append('sw.js must not store courier_get_my_orders and must fail it cleanly offline')
if 'qrcodejs/1.0.0/qrcode.min.js' not in sw:
    bad.append('sw.js must keep the QR script for offline use')

v10, v11 = read('js/releases/courier/v10/courier-bundle.js'), read('js/releases/courier/v11/courier-bundle.js')
if not v11.startswith(v10.rstrip('\n')):
    bad.append('v11 must start with the exact v10 bundle')
if not v11.endswith(addon):
    bad.append('v11 must end with the current js/courier-offline.js: build a NEW version instead of editing a released one')
if bad:
    print('check_courier_offline FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_offline ok')
