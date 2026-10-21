"""Guards Admin -> Users/Customers: lists are read only (users.view); the writes are the account controls (users.manage), routed, page and script loaded, no inline handlers or raw message injection."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, js = read('admin.html'), read('js/admin-people.js')
ctl, boot = read('api/controllers/CustomerAdminController.php'), read('api/boot.php')
bad = []
if 'js/admin-people.js' not in html:
    bad.append('admin.html does not load admin-people.js')
for a in ('admin_customers_list', 'admin_people_list', 'admin_person_get', 'admin_person_set_active', 'admin_person_force_logout'):
    if a not in js:
        bad.append('admin-people.js does not call ' + a)
for a in ('admin_customers_list', 'admin_customer_get'):
    if "case '" + a + "': {" not in ctl:
        bad.append(a + ' has no handler')
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo, 'users\.view'\)", ctl):
        bad.append(a + " must require 'users.view'")
if "case 'admin_customer_set_active'" not in boot:
    bad.append('suspend action is not routed in boot.php')
if not re.search(r"case 'admin_customer_set_active': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo, 'users\.manage'\)", ctl):
    bad.append("admin_customer_set_active must require 'users.manage'")
head = ctl.split("case 'admin_customer_set_active'")[0]
if re.search(r'\b(INSERT|UPDATE|DELETE)\b', head):
    bad.append('the Customers lists must stay read only: only admin_customer_set_active may write')
for f in ('api/lib/delivery_order_core.php', 'api/lib/package_orders.php'):
    if 'etel_customer_suspended(' not in read(f):
        bad.append(f + ' must refuse suspended customers')
if re.search(r'\bon[a-z]+="|\.innerHTML\s*=[^;]*res\.message', js):
    bad.append('admin-people.js must not use inline handlers or put server messages into innerHTML unescaped')
if bad:
    print('check_customers_admin FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_customers_admin ok')
