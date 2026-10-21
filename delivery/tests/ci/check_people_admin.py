"""Guards Admin -> People: the list and profile are read only (users.view); the controls need users.manage, refuse the caller's own account,
leave other admins to owner admins, and disabling signs the person out. Routed, script loaded, no inline handlers."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
ctl, boot, js, html = read('api/controllers/UserAdminController.php'), read('api/boot.php'), read('js/admin-people.js'), read('admin.html')
bad = []
for a in ('admin_people_list', 'admin_person_get', 'admin_person_set_active', 'admin_person_force_logout'):
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if "case '" + a + "': {" not in ctl:
        bad.append(a + ' has no handler')
for a in ('admin_people_list', 'admin_person_get'):
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo, 'users\.view'\)", ctl):
        bad.append(a + " must require 'users.view'")
for a in ('admin_person_set_active', 'admin_person_force_logout'):
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo, 'users\.manage'\)", ctl):
        bad.append(a + " must require 'users.manage'")
head = ctl.split("case 'admin_person_set_active'")[0]
if re.search(r'\b(INSERT|UPDATE|DELETE)\b', head):
    bad.append('the People list and profile must stay read only: only the controls may write')
if 'You cannot do this to your own account' not in ctl or 'etel_people_target($pdo, $u, $id, false)' not in ctl:
    bad.append('the controls must refuse the caller\'s own account')
if "in_array('*', user_capabilities(" not in ctl:
    bad.append('only an owner admin may change another admin')
if 'etel_bump_token_version(' not in ctl.split("case 'admin_person_set_active'")[1].split("case 'admin_person_force_logout'")[0]:
    bad.append('disabling an account must sign the person out (etel_bump_token_version)')
if 'js/admin-people.js' not in html:
    bad.append('admin.html does not load admin-people.js')
if re.search(r'\bon[a-z]+="', js):
    bad.append('admin-people.js must not use inline handlers')
if bad:
    print('check_people_admin FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_people_admin ok')
