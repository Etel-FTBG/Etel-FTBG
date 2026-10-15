"""Guards Payments & banks: super-admin only, secrets never returned or logged, page loaded, payment code reads the saved keys."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, js = read('admin.html'), read('js/admin-integrations.js')
ctl, lib, boot = read('api/controllers/IntegrationController.php'), read('api/lib/integrations.php'), read('api/boot.php')
chapa, etb, cb = read('api/chapa_bridge.php'), read('api/config-etb.php'), read('api/telebirr-callback.php')
bad = []
if 'js/admin-integrations.js' not in html:
    bad.append('admin.html does not load admin-integrations.js')
for a in ('admin_integrations_get', 'admin_integrations_save', 'admin_integrations_test'):
    if a not in js:
        bad.append('admin-integrations.js does not call ' + a)
    if "case '" + a + "': {" not in ctl:
        bad.append(a + ' has no handler')
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*(\$admin = )?require_admin\(\$pdo, 'integrations\.manage'\)", ctl):
        bad.append(a + " must require 'integrations.manage' (held by * roles only)")
if re.search(r"'value'\s*=>\s*\(?\s*etel_integration_get\(", lib) and "$f['secret'] ||" not in lib:
    bad.append('the overview may return secret values')
if 'error_log' in ctl or re.search(r'error_log\([^)]*(value|secret)', lib, re.I):
    bad.append('secrets might be logged')
if "etel_integration_get('chapa'" not in chapa:
    bad.append('chapa_bridge.php does not read the key saved in Admin')
if '_etb_cfg' not in etb or "define('APP_VER'" not in cb:
    bad.append('Telebirr config / callback do not read the keys saved in Admin')
if re.search(r'\bon[a-z]+="|\.innerHTML\s*=[^;]*res\.message', js):
    bad.append('admin-integrations.js has inline handlers or unescaped server text')
if bad:
    print('check_integrations_admin FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_integrations_admin ok')
