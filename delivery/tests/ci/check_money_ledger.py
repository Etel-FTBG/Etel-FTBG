"""Guards the refunds / courier cash bookkeeping: actions routed and delivery-only, finance capabilities, admin page wired, dashboard row."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
boot = read('api/boot.php')
ctl = read('api/controllers/MoneyLedgerController.php')
html = read('admin.html')
adm = read('js/admin.js')
pulse = read('api/lib/platform_pulse.php')
bad = []
actions = ('admin_refunds_report', 'admin_refund_mark', 'admin_courier_cash_report', 'admin_cash_remit')
for a in actions:
    if "'" + a + "'" not in boot.split('$ETEL_DELIVERY_ONLY_ACTIONS', 1)[1].split(');', 1)[0]:
        bad.append(a + ' is not in the delivery-only admin action list')
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if "case '" + a + "': {" not in ctl:
        bad.append(a + ' has no handler')
for a in ('admin_refunds_report', 'admin_courier_cash_report'):
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*require_admin\(\$pdo, 'finance\.view'\)", ctl):
        bad.append(a + ' must require finance.view')
for a in ('admin_refund_mark', 'admin_cash_remit'):
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*\$admin = require_admin\(\$pdo, 'finance\.manage'\)", ctl):
        bad.append(a + ' must require finance.manage')
if 'js/admin-money.js' not in html:
    bad.append('admin.html does not load admin-money.js')
if "link('money'" not in adm or 'refunds_owed' not in pulse:
    bad.append('dashboard "waiting for a refund" row is missing')
if bad:
    print('check_money_ledger FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_money_ledger ok')
