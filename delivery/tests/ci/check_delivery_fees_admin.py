"""Guards Delivery fees (fees by distance, one price list per vehicle, the company / courier share, the service charge):
- the admin page script is loaded after AdminKit and the vehicle pictures, before the menu script, and uses the two admin actions;
- the actions are routed in boot.php and admin-gated (read: settings.view, save: settings.manage); the cart quote is public but read only;
- the page has a menu home and a workspace home, and uses no inline handlers, eval, or unescaped server text;
- the order code uses the fee engine, saves the courier share, and the service charge comes from the admin setting (no fixed 5 percent);
- every PHP file stays ASCII and under about 30 KB, and no admin text can reach the customer (the quote never carries the company share)."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, js = read('admin.html'), read('js/admin-delivery-fees.js')
ctl, lib, boot = read('api/controllers/DeliveryFeeController.php'), read('api/lib/delivery_fees.php'), read('api/boot.php')
core, pricing, cust = read('api/lib/delivery_order_core.php'), read('api/lib/delivery_pricing.php'), read('js/customer.js')
nav, ws = read('js/admin-nav.js'), read('js/admin-workspaces.js')
bad = []
pos = dict((f, html.find('src="js/' + f)) for f in ('admin-kit.js', 'etel-vehicle-pic.js', 'admin-delivery-fees.js', 'admin-nav.js'))
if min(pos.values()) < 0:
    bad.append('admin.html must load ' + ', '.join(f for f, p in pos.items() if p < 0))
elif not (pos['admin-kit.js'] < pos['admin-delivery-fees.js'] < pos['admin-nav.js'] and pos['etel-vehicle-pic.js'] < pos['admin-delivery-fees.js']):
    bad.append('admin-delivery-fees.js must load after admin-kit.js and etel-vehicle-pic.js, and before admin-nav.js')
for a in ('admin_dfee_get', 'admin_dfee_save'):
    if a not in js:
        bad.append('admin-delivery-fees.js does not call ' + a)
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
if "case 'delivery_fee_quote'" not in boot:
    bad.append('delivery_fee_quote is not routed in boot.php')
if not re.search(r"case 'admin_dfee_get': \{\s*\$pdo = db\(\); require_admin\(\$pdo, 'settings\.view'\)", ctl):
    bad.append('admin_dfee_get must require settings.view')
if not re.search(r"case 'admin_dfee_save': \{\s*\$pdo = db\(\); \$u = require_admin\(\$pdo, 'settings\.manage'\)", ctl):
    bad.append('admin_dfee_save must require settings.manage')
quote = ctl.split("case 'delivery_fee_quote'")[1].split("case 'admin_dfee_get'")[0]
if re.search(r'\b(INSERT|UPDATE|DELETE|ALTER|DROP)\b', quote) or 'require_admin' in quote:
    bad.append('delivery_fee_quote must be public and read only')
if 'company' in lib.split('function etel_dfee_quote')[1]:
    bad.append('the cart quote must never carry the company share')
if "'dfees'" not in nav or "dfees: 'settings.view'" not in nav:
    bad.append('dfees needs a group and a capability in admin-nav.js')
if "'dfees'" not in ws:
    bad.append('dfees needs a workspace section in admin-workspaces.js')
if re.search(r'\bon[a-z]+="|\beval\(|document\.write', js):
    bad.append('admin-delivery-fees.js uses inline handlers, eval or document.write')
if re.search(r"innerHTML\s*=\s*[^;]*\bres\.message", js):
    bad.append('admin-delivery-fees.js writes unescaped server text')
if 'etel_dfee_for_order' not in core or 'courier_pay = ?' not in core:
    bad.append('delivery_order_core.php must price by distance and save the courier share')
if 'SERVICE_CHARGE_PERCENT' in pricing.split('function delivery_calc_service_charge')[1].split('}')[0]:
    bad.append('the service charge must come from the admin setting, not the fixed percent')
if 'delivery_calc_service_charge($items_subtotal, $pdo)' not in core:
    bad.append('checkout must read the service charge percent from the database')
if 'delivery_fee_quote' not in cust or 'SERVICE_CHARGE_PCT = 5' in cust:
    bad.append('customer.js must ask for the distance fee and not hard-code a 5 percent service charge')
for f in ('api/lib/delivery_fees.php', 'api/controllers/DeliveryFeeController.php'):
    raw = open(root + '/' + f, 'rb').read()
    if any(b > 127 for b in raw):
        bad.append(f + ' is not ASCII only')
    if len(raw) > 30000:
        bad.append(f + ' is over 30 KB')
if bad:
    print('check_delivery_fees_admin FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_delivery_fees_admin ok')
