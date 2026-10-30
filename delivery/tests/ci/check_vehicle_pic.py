"""Guards the shared real-vehicle-picture helper (js/etel-vehicle-pic.js): it parses and has no inline handlers, every picture it points at is either shipped in
img/vehicles/ or one of the two optional ones (bicycle, on_foot), a failed picture is swapped for its emoji, and the screens that use it load it first
(admin panels, the customer app, the courier app) and fall back to the emoji when it is missing."""
import os
import re
import subprocess

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

helper = read('js/etel-vehicle-pic.js')
if subprocess.call(['node', '--check', root + '/js/etel-vehicle-pic.js']) != 0:
    bad.append('js/etel-vehicle-pic.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", helper):
    bad.append('etel-vehicle-pic.js must not use inline handlers')
pic = re.search(r"var PIC = \{([^}]*)\}", helper)
files = set(re.findall(r"'?\w+'?\s*:\s*'(\w+)'", pic.group(1))) if pic else set()
optional = {'bicycle', 'on_foot'}
for f in sorted(files):
    if f not in optional and not os.path.isfile('%s/img/vehicles/%s.png' % (root, f)):
        bad.append('the helper points at img/vehicles/%s.png, which is not there' % f)
for must in ("addEventListener('error'", 'etel-vpic', 'data-vfb', 'has: has'):
    if must not in helper:
        bad.append('etel-vehicle-pic.js lost: ' + must)

for page, line in (('customer.html', 'js/etel-vehicle-pic.js'), ('admin.html', 'js/etel-vehicle-pic.js'), ('courier/index.html', 'js/etel-vehicle-pic.js')):
    if line not in read(page):
        bad.append(page + ' must load ' + line)
html = read('customer.html')
if html.find('js/etel-vehicle-pic.js') > html.find('js/customer.js'):
    bad.append('customer.html must load the helper before customer.js')
adm = read('admin.html')
if adm.find('js/etel-vehicle-pic.js') > adm.find('js/admin-people-register.js'):
    bad.append('admin.html must load the helper before admin-people-register.js')
for js in ('js/customer.js', 'js/admin-people-register.js', 'js/admin-couriers-pro.js', 'js/admin-rides-pro.js'):
    s = read(js)
    if 'EtelVehiclePic' not in s:
        bad.append(js + ' no longer uses EtelVehiclePic')
    if not re.search(r"window\.EtelVehiclePic\s*&&", s):
        bad.append(js + ' must check that EtelVehiclePic exists before using it (the emoji is the fallback)')
if subprocess.call(['node', '--check', root + '/js/courier-vehicle-pictures.js']) != 0:
    bad.append('js/courier-vehicle-pictures.js does not parse')

if bad:
    print('FAIL: vehicle picture helper')
    for b in bad:
        print('  - ' + b)
    raise SystemExit(1)
print('check_vehicle_pic ok')
