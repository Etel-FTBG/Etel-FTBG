"""Guards the admin Courier desk (js/admin-courier-register.js): it parses, has no inline handlers, calls admin_register_courier and uploads the ID, selfie and
licence to the private upload types, admin.html loads it before the "Register a person" panel that hands couriers over to it, the API action is registered, the
upload types land in the private folder, the details table is wiped by a system reset, and the courier drawer shows the details."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
js = read('js/admin-courier-register.js')
if subprocess.call(['node', '--check', root + '/js/admin-courier-register.js']) != 0:
    bad.append('js/admin-courier-register.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('admin-courier-register.js must not use inline handlers')
for must in ('admin_register_courier', 'courier_id', 'courier_selfie', 'courier_licence', 'EtelCourierRegister', 'new-courier', 'confirm_duplicate'):
    if must not in js:
        bad.append('admin-courier-register.js lost: ' + must)
adm = read('admin.html')
a, b = adm.find('js/admin-courier-register.js'), adm.find('js/admin-people-register.js')
if a < 0 or b < 0 or a > b:
    bad.append('admin.html must load admin-courier-register.js before admin-people-register.js')
if 'EtelCourierRegister' not in read('js/admin-people-register.js'):
    bad.append('"Register a person" must hand the Courier choice over to EtelCourierRegister')
if read('api/boot.php').count('admin_register_courier') < 3:
    bad.append('admin_register_courier must be in the write-actions list, the delivery-only list and the dispatcher in boot.php')
up = read('api/upload.php')
for t, pre in (('courier_id', 'cid_'), ('courier_selfie', 'csel_'), ('courier_licence', 'clic_')):
    if not re.search(r"'%s'\s*=>\s*\['folder' => 'driver_documents', 'prefix' => '%s'\]" % (t, pre), up):
        bad.append('upload.php must save %s in the private driver_documents folder (prefix %s)' % (t, pre))
if "'courier_details'" not in read('api/controllers/SystemResetController.php'):
    bad.append('SystemResetController must wipe courier_details')
if 'idBox' not in read('js/admin-couriers-pro.js') or "'details' => $details" not in read('api/controllers/PartnerAdminController.php'):
    bad.append('the courier drawer must show the details (idBox in admin-couriers-pro.js, details in admin_courier_detail)')
if bad:
    print('check_courier_desk FAILED:')
    for x in bad:
        print(' -', x)
    sys.exit(1)
print('check_courier_desk ok')
