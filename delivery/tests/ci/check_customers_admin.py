"""Guards Admin -> Customers: read only, needs users.view, routed, page and script loaded, no inline handlers or raw message injection."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, js = read('admin.html'), read('js/admin-customers.js')
ctl, boot = read('api/controllers/CustomerAdminController.php'), read('api/boot.php')
bad = []
if 'js/admin-customers.js' not in html:
    bad.append('admin.html does not load admin-customers.js')
for a in ('admin_customers_list', 'admin_customer_get'):
    if a not in js:
        bad.append('admin-customers.js does not call ' + a)
    if "case '" + a + "': {" not in ctl:
        bad.append(a + ' has no handler')
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo, 'users\.view'\)", ctl):
        bad.append(a + " must require 'users.view'")
if re.search(r'\b(INSERT|UPDATE|DELETE)\b', ctl):
    bad.append('Customers is read only: the controller must not write')
if re.search(r'\bon[a-z]+="|\.innerHTML\s*=[^;]*res\.message', js):
    bad.append('admin-customers.js must not use inline handlers or put server messages into innerHTML unescaped')
if bad:
    print('check_customers_admin FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_customers_admin ok')
