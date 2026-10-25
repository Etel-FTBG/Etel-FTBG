"""Guards courier offline work (release v12): the add-on runs on the shared engine (js/offline-kit.js), parses, queues only the two safe actions, keeps
release v11's storage keys and reads the app's Auth global (not window.Auth, which does not exist), the server stays idempotent and checks the time it is
given, the service worker never stores the courier's own order list, and v12 is the exact v10 bundle plus the current kit and add-on."""
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
kit = read('js/offline-kit.js')
if subprocess.call(['node', '--check', root + '/js/offline-kit.js']) != 0:
    bad.append('js/offline-kit.js does not parse')
if 'EtelOffline.install(' not in addon:
    bad.append('courier-offline.js must run on the shared engine (EtelOffline.install)')
if "'eof-css'" not in kit:
    bad.append('style id eof-css expected in the kit')
for k in ("queueKey: 'etel_courier_outbox_v1'", "savedKey: 'etel_courier_orders_v1'"):
    if k not in addon:
        bad.append('courier-offline.js must keep release v11 storage key: ' + k)
if 'window.Auth' in addon:
    bad.append('window.Auth is undefined (Auth is a global const): use typeof Auth !== "undefined" && Auth.getToken() (release v11 sent an empty token because of this)')
if "typeof Auth !== 'undefined' && Auth.getToken" not in addon:
    bad.append('the add-on must send the courier\'s token when it sends the waiting actions')

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

v10, v11, v12 = (read('js/releases/courier/v%d/courier-bundle.js' % n) for n in (10, 11, 12))
if not v11.startswith(v10.rstrip('\n')):
    bad.append('v11 must start with the exact v10 bundle')
if not v12.startswith(v10.rstrip('\n')):
    bad.append('v12 must start with the exact v10 bundle (it replaces the v11 add-on, it does not stack on it)')
if not v12.endswith(addon) or kit not in v12:
    bad.append('v12 must contain the current js/offline-kit.js and end with the current js/courier-offline.js: build a NEW version instead of editing a released one')
if 'release v11: js/courier-offline.js' in v12:
    bad.append('v12 must not carry the old v11 add-on')
if bad:
    print('check_courier_offline FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_offline ok')
