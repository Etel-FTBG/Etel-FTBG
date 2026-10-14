"""Guards the one proper cancel: lib, the three entry points, the admin status list going through it, the admin page, migration."""
import os
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
boot = read('api/boot.php')
adm_ctl = read('api/controllers/AdminController.php')
ord_ctl = read('api/controllers/OrderController.php')
sg_ctl = read('api/controllers/ServiceGiverController.php')
md_ctl = read('api/controllers/ManualDispatchController.php')
js = read('js/admin-dispatch.js')
bad = []
only = boot.split('$ETEL_DELIVERY_ONLY_ACTIONS', 1)[1].split(');', 1)[0]
if "'admin_cancel_order'" not in only:
    bad.append('admin_cancel_order is not in the delivery-only admin action list')
for a in ('admin_cancel_order', 'customer_cancel_order', 'sg_reject_order'):
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
if not re.search(r"case 'admin_cancel_order': \{\s*\$pdo = db\(\);\s*\$admin = require_admin\(\$pdo, 'orders\.manage'\)", md_ctl):
    bad.append('admin_cancel_order must require orders.manage')
if "etel_cancel_order($pdo, isset($body['order_id']) ? $body['order_id'] : 0, 'customer'" not in ord_ctl:
    bad.append('customer_cancel_order must call etel_cancel_order as the customer')
if "'shop'" not in sg_ctl.split("case 'sg_reject_order'", 1)[1].split("case 'sg_set_hours'", 1)[0] or "sg_require_capability($auth_user, $ctx, 'orders.manage')" not in sg_ctl.split("case 'sg_reject_order'", 1)[1].split("case 'sg_set_hours'", 1)[0]:
    bad.append('sg_reject_order must check orders.manage and cancel as the shop')
upd = adm_ctl.split("case 'admin_update_order'", 1)[1].split("case 'admin_get_order_receipt'", 1)[0]
if "$status === 'cancelled'" not in upd or 'etel_cancel_order' not in upd:
    bad.append('admin_update_order must send a cancel through etel_cancel_order (no cancel without a reason or courier release)')
if 'admin_cancel_order' not in js or 'data-cancel' not in js:
    bad.append('admin-dispatch.js has no Cancel order action')
if not os.path.exists(root + '/api/migrations/079_order_cancel_reason.sql'):
    bad.append('migration 079_order_cancel_reason.sql is missing')
if bad:
    print('check_order_cancel FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_order_cancel ok')
