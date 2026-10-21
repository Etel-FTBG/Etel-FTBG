"""Guards courier approval: a self-registered courier starts pending, every courier action goes through require_courier's approval gate,
the dispatch queries only pick approved couriers, and only users.manage admins decide."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
boot, auth, ctl = read('api/boot.php'), read('api/controllers/AuthController.php'), read('api/controllers/UserAdminController.php')
bad = []
if "require_once __DIR__ . '/lib/courier_approval.php'" not in boot:
    bad.append('boot.php must load lib/courier_approval.php')
if not re.search(r"function require_courier\(\$pdo\) \{.*?etel_courier_assert_approved\(\$pdo, \$u\)", boot, re.S):
    bad.append('require_courier must call etel_courier_assert_approved')
if "UPDATE couriers SET approval_status = 'pending'" not in auth:
    bad.append('register must put a self-registered courier on pending')
for f in ('api/lib/order_dispatch.php', 'api/lib/courier_offers.php', 'api/lib/manual_dispatch.php'):
    if 'etel_courier_approved_sql(' not in read(f):
        bad.append(f + ' must only pick approved couriers')
for a in ('admin_courier_set_approval', 'admin_courier_approval_mode'):
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);", ctl):
        bad.append(a + ' has no handler')
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
if not re.search(r"case 'admin_courier_set_approval': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo, 'users\.manage'\)", ctl):
    bad.append("admin_courier_set_approval must require 'users.manage'")
if bad:
    print('check_courier_approval FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_approval ok')
