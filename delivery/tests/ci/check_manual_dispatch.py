"""Guards dispatch by hand: actions routed and delivery-only, capabilities, admin page wired, dashboard row, log table migration."""
import os
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
boot = read('api/boot.php')
ctl = read('api/controllers/ManualDispatchController.php')
html = read('admin.html')
adm = read('js/admin.js')
pulse = read('api/lib/platform_pulse.php')
bad = []
only = boot.split('$ETEL_DELIVERY_ONLY_ACTIONS', 1)[1].split(');', 1)[0]
for a in ('admin_dispatch_board', 'admin_assign_courier', 'admin_take_back_order'):
    if "'" + a + "'" not in only:
        bad.append(a + ' is not in the delivery-only admin action list')
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if "case '" + a + "': {" not in ctl:
        bad.append(a + ' has no handler')
if not re.search(r"case 'admin_dispatch_board': \{\s*\$pdo = db\(\);\s*require_admin\(\$pdo, 'orders\.view'\)", ctl):
    bad.append('admin_dispatch_board must require orders.view')
for a in ('admin_assign_courier', 'admin_take_back_order'):
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*\$admin = require_admin\(\$pdo, 'orders\.manage'\)", ctl):
        bad.append(a + ' must require orders.manage')
if 'js/admin-dispatch.js' not in html:
    bad.append('admin.html does not load admin-dispatch.js')
if "link('dispatch'" not in adm or 'dispatch_needs' not in pulse:
    bad.append('dashboard dispatch row is missing')
if not os.path.exists(root + '/api/migrations/078_order_admin_actions.sql'):
    bad.append('migration 078_order_admin_actions.sql is missing')
if bad:
    print('check_manual_dispatch FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_manual_dispatch ok')
