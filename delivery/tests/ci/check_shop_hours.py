"""Guards shop opening hours / sold-out: order-time block, customer feed, new actions routed, shop and customer screens, both languages."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
core = read('api/lib/delivery_order_core.php')
boot = read('api/boot.php')
sgc = read('api/controllers/ServiceGiverController.php')
cust = read('js/customer.js')
sg = read('js/servicegiver.js')
html = read('servicegiver.html')
bad = []
if 'etel_order_blockers(' not in core:
    bad.append('create order does not call etel_order_blockers')
if 'etel_shop_feed_apply(' not in boot:
    bad.append('customer_get_items does not apply the shop hours / sold-out feed')
for act in ('sg_set_hours', 'sg_sold_out'):
    if "case '" + act + "'" not in boot:
        bad.append(act + ' is not routed in boot.php')
    if "case '" + act + "': {" not in sgc:
        bad.append(act + ' has no handler in ServiceGiverController')
if 'sgp-opens' not in html or 'saveSgHours' not in sg or 'setSoldOut' not in sg:
    bad.append('shop app is missing the hours box or the sold-out button')
if 'shopClosedByHours(' not in cust:
    bad.append('customer app does not stop adding from a shop closed by hours')
for key in ('sold_today', 'item_unavail', 'closed_hours'):
    if len(re.findall(key + ':', cust)) != 2:
        bad.append(key + ' must exist in both the English and Amharic tables')
if bad:
    print('check_shop_hours FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_shop_hours ok')
