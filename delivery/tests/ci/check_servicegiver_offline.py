"""Guards shop (service giver) offline work: the kit and add-on parse, only the four safe actions can be queued, nothing that changes what a
courier/customer sees or moves money is queueable, the page loads the scripts in the right order, and resends are harmless on the server."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for f in ('js/offline-kit.js', 'js/servicegiver-offline.js'):
    if subprocess.call(['node', '--check', root + '/' + f]) != 0:
        bad.append(f + ' does not parse')
    if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", read(f)):
        bad.append(f + ' must not use inline handlers')
addon = read('js/servicegiver-offline.js')
m = re.search(r'WRITES\s*=\s*\{([^}]*)\}', addon)
writes = sorted(re.findall(r'(\w+)\s*:', m.group(1))) if m else []
if writes != ['sg_accept_order', 'sg_sold_out', 'sg_update_order_status']:
    bad.append('queueable writes must be exactly accept / update order status / sold out (found %s)' % writes)
m = re.search(r'READS\s*=\s*\{([^}]*)\}', addon)
reads = re.findall(r'(\w+)\s*:', m.group(1)) if m else []
for a in reads:
    if not re.match(r'sg_(orders|order_detail|get_my_items|profile|my_role|stats)$', a):
        bad.append('only read-only actions may be saved for offline: ' + a)
for never in ('sg_reject_order', 'sg_scan_qr', 'sg_complete_order', 'sg_add_item', 'sg_delete_item', 'sg_get_kitchen_ticket'):
    if re.search(r'(WRITES|READS)\s*=\s*\{[^}]*' + never, addon):
        bad.append(never + ' must never be saved offline')
if "k === 'item_id' || k === 'is_available'" not in addon:
    bad.append('sg_update_item may only be queued for the is_available switch (a full edit would overwrite price/stock late)')
if "'eof-css'" in addon or "cssId = 'eof-css'" not in read('js/offline-kit.js'):
    bad.append('offline-kit.js must own the style id eof-css')

html = read('servicegiver.html')
order = [html.find(x) for x in ('js/core.js', 'js/servicegiver.js?', 'js/offline-kit.js', 'js/servicegiver-offline.js')]
if -1 in order or order != sorted(order):
    bad.append('servicegiver.html must load core.js, servicegiver.js, offline-kit.js, servicegiver-offline.js in that order')

ctl = read('api/controllers/ServiceGiverController.php')
if ctl.count("!empty($body['client_req_id'])") < 2:
    bad.append('sg_accept_order and sg_update_order_status must treat a resend (client_req_id) of a finished step as done')
if 'etel_offline_forget' not in read('api/controllers/CourierFuelController.php'):
    bad.append('a failed fuel-log insert must forget its request id so the resend is tried again')

if bad:
    print('check_servicegiver_offline FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_servicegiver_offline ok')
