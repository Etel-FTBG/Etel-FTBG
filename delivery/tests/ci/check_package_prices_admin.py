"""Guards the admin package-price screen: script loaded, uses the two rate actions, both actions are admin-gated."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, js, ctl = read('admin.html'), read('js/admin-packages.js'), read('api/controllers/PackageController.php')
bad = []
if 'js/admin-packages.js' not in html:
    bad.append('admin.html does not load admin-packages.js')
for a in ('admin_package_rates_get', 'admin_package_rates_save'):
    if a not in js:
        bad.append('admin-packages.js does not call ' + a)
if not re.search(r"case 'admin_package_rates_get': \{\s*\$pdo = db\(\);\s*require_admin\(\$pdo, 'orders\.view'\)", ctl):
    bad.append('admin_package_rates_get must require orders.view')
if not re.search(r"case 'admin_package_rates_save': \{\s*\$pdo = db\(\);\s*require_admin\(\$pdo, 'orders\.manage'\)", ctl):
    bad.append('admin_package_rates_save must require orders.manage')
if re.search(r'\bon[a-z]+="|innerHTML\s*=\s*[^;]*\bres\.message', js):
    bad.append('admin-packages.js writes unescaped server text or inline handlers')
if bad:
    print('check_package_prices_admin FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_package_prices_admin ok')
