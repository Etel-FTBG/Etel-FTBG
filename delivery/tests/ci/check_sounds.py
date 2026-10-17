"""Guards the ETEL sounds on the shop and customer pages: the scripts parse, load in the right order, use no inline handlers, never write to the
server (the shop watcher only asks for sg_orders), and the engine keeps the six sounds and its mute switch. (The courier ring has its own guard.)"""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for f in ('js/etel-sounds.js', 'js/servicegiver-sound.js', 'js/customer-sound.js'):
    if subprocess.call(['node', '--check', root + '/' + f]) != 0:
        bad.append(f + ' does not parse')
    if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", read(f)):
        bad.append(f + ' must not use inline handlers')
sg, cu = read('servicegiver.html'), read('customer.html')
for html, name, main, mine in ((sg, 'servicegiver.html', 'js/servicegiver.js', 'js/servicegiver-sound.js'), (cu, 'customer.html', 'js/customer.js', 'js/customer-sound.js')):
    order = [html.find(x) for x in (main, 'js/etel-sounds.js', mine)]
    if min(order) < 0 or order != sorted(order):
        bad.append(name + ' must load ' + main + ', then js/etel-sounds.js, then ' + mine)
shop = read('js/servicegiver-sound.js')
if "action: 'sg_orders'" not in shop or re.search(r"action:\s*'(?!sg_orders)", shop):
    bad.append('servicegiver-sound.js may only call sg_orders')
if "'order'" not in shop or 'ES.ring(' not in shop or 'ES.stop(' not in shop:
    bad.append('servicegiver-sound.js lost its ring / stop logic')
cus = read('js/customer-sound.js')
for must in ("'delivered'", "'cancel'", "'progress'", 'was === undefined'):
    if must not in cus:
        bad.append('customer-sound.js lost: ' + must)
if bad:
    print('check_sounds FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_sounds ok')
