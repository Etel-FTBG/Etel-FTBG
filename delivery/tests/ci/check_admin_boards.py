"""Guards the premium Orders / Couriers / Service givers / Package prices screens: every board action is routed and gated, the
read-only controllers never write, the screens load in the right order, escape server text and use no inline handlers, and
the old plain tables stay hidden (not deleted) so admin.js keeps working."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
boot, html = read('api/boot.php'), read('admin.html')
orders, partner = read('api/controllers/OrderAdminController.php'), read('api/controllers/PartnerAdminController.php')
bad = []
for a, ctl, cap in (('admin_orders_board', orders, 'orders.view'), ('admin_order_detail', orders, 'orders.view'),
                    ('admin_couriers_board', partner, 'users.view'), ('admin_courier_detail', partner, 'users.view'),
                    ('admin_service_givers_board', partner, 'service_givers.view'), ('admin_service_giver_detail', partner, 'service_givers.view')):
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if not re.search(r"case '" + a + r"': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo, '" + re.escape(cap) + r"'\)", ctl):
        bad.append(a + " must require '" + cap + "'")
    if not re.search(r"case '" + a + r"': \{.*?etel_is_ride_only", ctl, re.S):
        bad.append(a + ' must refuse Ride-only staff')
for name, ctl in (('OrderAdminController', orders), ('PartnerAdminController', partner)):
    if re.search(r'\b(INSERT|UPDATE|DELETE|ALTER|DROP)\b\s+(INTO|TABLE|FROM|`?\w+`?\s+SET)', ctl):
        bad.append(name + ' must stay read only')
for a, cap in (('admin_verify_service_giver', 'service_givers.manage'), ('admin_suspend_service_giver', 'service_givers.manage'), ('admin_update_courier', 'users.manage')):
    if not re.search(r"case '" + a + r"': \{\s*(//[^\n]*\n\s*)*\$pdo = db\(\); require_admin\(\$pdo, '" + re.escape(cap) + r"'\)", boot):
        bad.append(a + " must require '" + cap + "'")
files = ('admin-kit.js', 'admin-orders-pro.js', 'admin-couriers-pro.js', 'admin-sg-pro.js', 'admin-packages.js', 'admin-items-pro.js', 'admin-promos-pro.js', 'admin-rides-pro.js', 'admin-menucat-pro.js', 'admin-rides-desk.js', 'admin-settings-pro.js', 'admin-roles-pro.js')
pos = dict((f, html.find('src="js/' + f)) for f in files)
if min(pos.values()) < 0:
    bad.append('admin.html must load ' + ', '.join(f for f in files if pos[f] < 0))
elif any(pos['admin-kit.js'] > pos[f] for f in files):
    bad.append('admin.html must load admin-kit.js before the four screens')
if html.find('src="js/admin-kit.js') < html.find('src="js/admin.js'):
    bad.append('the screens must load after admin.js so they can replace its loaders')
for host in ('ord-pro', 'cou-pro', 'sg-pro', 'ord-legacy', 'cou-legacy', 'sg-legacy', 'items-pro', 'items-legacy', 'promo-pro', 'promo-list', 'rides-pro', 'rides-legacy', 'mc-pro', 'mc-legacy', 'rides-ops-pro', 'rides-ops-legacy', 'rides-desk-pro', 'rides-desk-legacy', 'set-pro', 'roles-pro', 'roles-legacy'):
    if 'id="' + host + '"' not in html:
        bad.append('admin.html lost #' + host)
for f in files:
    js = read('js/' + f)
    if re.search(r'\bon[a-z]+="|\.innerHTML\s*=\s*[^;]*\b(res|r)\.message', js):
        bad.append(f + ' writes unescaped server text or inline handlers')
    if 'eval(' in js or 'document.write' in js:
        bad.append(f + ' must not use eval / document.write')
sw = read('sw.js')
if not re.search(r"CACHE_NAME\s*=\s*'etel-v\d+'", sw):
    bad.append('sw.js cache name not found')
if bad:
    print('check_admin_boards FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_admin_boards ok')
