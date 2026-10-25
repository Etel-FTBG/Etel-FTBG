"""Guards Etel Business (api/lib/sg_business.php): the shop always comes from the signed-in account, walk-in sales never write to `orders`,
every event type checks a capability, the tables the code creates match the migration, and only a platform admin can switch the tools."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
lib = read('api/lib/sg_business.php')
ctl = read('api/controllers/ServiceGiverController.php')
mig = read('api/migrations/093_sg_business.sql')

if re.search(r"\$(body|_GET|_POST|_REQUEST)\b", lib):
    bad.append('sg_business.php must not read the request: the shop id comes from the account (sg_get_context)')
if re.search(r"(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?orders`?\b", lib, re.I):
    bad.append('walk-in sales must never write to orders (no courier, customer or commission)')
for fn, cap in (('etel_sgb_sale', 'pos.sell'), ('etel_sgb_void', 'pos.void'), ('etel_sgb_stock', 'stock.manage'), ('etel_sgb_expense', 'expenses.manage'), ('etel_sgb_cashup', 'cashup.manage')):
    m = re.search(r'function %s\(.*?\n}\n' % fn, lib, re.S)
    if not m or "etel_sgb_cap($ctx, '%s')" % cap not in m.group(0):
        bad.append('%s must check the %s capability first' % (fn, cap))
for cap in ('pos.sell', 'pos.void', 'pos.discount', 'stock.manage', 'expenses.manage', 'cashup.manage'):
    if "'%s'" % cap not in ctl.split('function sg_capability_catalog')[1].split('switch ($action)')[0]:
        bad.append('capability %s is missing from sg_capability_catalog (owners could not grant it)' % cap)
for t in re.findall(r'CREATE TABLE IF NOT EXISTS (sg_\w+)', lib):
    if '`%s`' % t not in mig:
        bad.append('table %s is created by the code but missing from migration 093' % t)
for t in re.findall(r'CREATE TABLE IF NOT EXISTS `(sg_\w+)`', mig):
    if 'CREATE TABLE IF NOT EXISTS %s ' % t not in lib:
        bad.append('table %s is in migration 093 but not created by the code' % t)
for must in ('sg_pos_sync', 'sg_business_state'):
    block = ctl.split("case '%s':" % must)[1].split('\ncase ')[0]
    if 'etel_sgb_enabled' not in block:
        bad.append(must + ' must refuse shops that do not have Etel Business switched on')
block = ctl.split("case 'admin_sg_set_business_tools':")[1].split('\ncase ')[0]
if "require_admin($pdo, 'service_givers.manage')" not in block:
    bad.append('only platform admins with service_givers.manage may switch the business tools')
if "ETEL_SGB_MAX_EVENTS" not in ctl:
    bad.append('sg_pos_sync must limit the batch size')
boot = read('api/boot.php')
for a in ('sg_business_state', 'sg_pos_sync', 'admin_sg_set_business_tools'):
    if "case '%s'" % a not in boot:
        bad.append(a + ' is not routed in boot.php')
if "'admin_sg_set_business_tools','sg_pos_sync'" not in boot:
    bad.append('the two write actions must be in ETEL_WRITE_ACTIONS (cache purge)')
if bad:
    print('check_shop_business FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_shop_business ok')
