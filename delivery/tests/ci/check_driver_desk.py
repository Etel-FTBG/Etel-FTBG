"""Guards the admin Driver desk (js/admin-driver-register.js): it parses, has no inline handlers, calls the real API actions (ride_get_vehicle_types,
admin_register_driver) and uploads to the same photo types the Driver app uses, admin.html loads it before the "Register a person" panel that hands drivers
over to it, and that API action is registered in boot.php."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
js = read('js/admin-driver-register.js')
if subprocess.call(['node', '--check', root + '/js/admin-driver-register.js']) != 0:
    bad.append('js/admin-driver-register.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('admin-driver-register.js must not use inline handlers')
for must in ('admin_register_driver', 'ride_get_vehicle_types', 'driver_license', 'driver_selfie', 'driver_insurance_photo', 'driver_technical_inspection_photo',
             'driver_road_fund_photo', 'EtelDriverRegister', 'new-driver', 'confirm_mismatch'):
    if must not in js:
        bad.append('admin-driver-register.js lost: ' + must)
adm = read('admin.html')
a, b = adm.find('js/admin-driver-register.js'), adm.find('js/admin-people-register.js')
if a < 0 or b < 0 or a > b:
    bad.append('admin.html must load admin-driver-register.js before admin-people-register.js')
if 'EtelDriverRegister' not in read('js/admin-people-register.js'):
    bad.append('"Register a person" must hand the Driver choice over to EtelDriverRegister')
boot = read('api/boot.php')
if boot.count('admin_register_driver') < 2:
    bad.append('admin_register_driver must be in the write-actions list and the dispatcher in boot.php')
ctl = read('api/controllers/PeopleRegisterController.php')
for must in ("case 'admin_register_driver'", 'driver_selfies', 'driver_licenses', 'driver_documents', 'confirm_mismatch', "require_admin($pdo, 'users.manage')"):
    if must not in ctl:
        bad.append('PeopleRegisterController.php lost: ' + must)
if bad:
    print('check_driver_desk FAILED:')
    for x in bad:
        print(' -', x)
    sys.exit(1)
print('check_driver_desk ok')
