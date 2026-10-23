"""Guards the grouped admin menu and the dashboard "Needs your attention" list: admin_attention is routed, read only and
checks the capability behind each line, the menu script loads last (so it can move items added by other scripts), every
page keeps a menu home (no page can vanish), and the new scripts use no inline handlers or eval."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
boot, html, ctl = read('api/boot.php'), read('admin.html'), read('api/controllers/AdminAttentionController.php')
nav, home = read('js/admin-nav.js'), read('js/admin-home.js')
bad = []
if "case 'admin_attention'" not in boot:
    bad.append('admin_attention is not routed in boot.php')
if not re.search(r"case 'admin_attention': \{\s*\$pdo = db\(\);\s*\$u = require_admin\(\$pdo\);", ctl):
    bad.append('admin_attention must require an admin')
for cap in ('orders.view', 'users.view', 'service_givers.view'):
    if "user_has_capability($pdo, $u, '" + cap + "')" not in ctl:
        bad.append('admin_attention must check ' + cap + ' before counting its lines')
if 'etel_is_ride_only' not in ctl:
    bad.append('admin_attention must give Ride-only staff no Delivery lines')
if re.search(r'\b(INSERT|UPDATE|DELETE|ALTER|DROP)\b\s+(INTO|TABLE|FROM|`?\w+`?\s+SET)', ctl):
    bad.append('admin_attention must stay read only')
pos = dict((f, html.find('src="js/' + f)) for f in ('admin-kit.js', 'admin-home.js', 'admin-people.js', 'admin-packages.js', 'admin-nav.js'))
if min(pos.values()) < 0:
    bad.append('admin.html must load ' + ', '.join(f for f, p in pos.items() if p < 0))
elif pos['admin-nav.js'] < max(pos['admin-people.js'], pos['admin-packages.js'], pos['admin-home.js']):
    bad.append('admin-nav.js must load after the scripts that add menu items')
elif pos['admin-kit.js'] > pos['admin-home.js']:
    bad.append('admin-kit.js must load before admin-home.js')
pages = set(re.findall(r'<div class="nav-item[^"]*" data-page="([a-z]+)"', html)) | set(['customers', 'packages'])
grouped = set(re.findall(r"'([a-z#0-9]+)'", ''.join(re.findall(r"pages: \[([^\]]*)\]", nav))))
for p in sorted(pages - grouped):
    bad.append('menu page "' + p + '" has no group in admin-nav.js (it would land under System)')
for f, js in (('admin-nav.js', nav), ('admin-home.js', home)):
    if re.search(r'\bon[a-z]+="|\beval\(|document\.write', js):
        bad.append(f + ' uses inline handlers, eval or document.write')
if bad:
    print('check_admin_nav FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_admin_nav ok')
