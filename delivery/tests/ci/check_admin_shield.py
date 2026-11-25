"""Guards the Admin Etel Shield page: admin.html links to it, it signs the admin out when idle like every app, every API action it calls exists in the server
(boot.php routes it and the controller handles it), it never writes raw server text into the page, and it has no eval or inline handlers."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
page = read('admin-shield.html')
if 'admin-shield.html' not in read('admin.html'):
    bad.append('admin.html does not link to admin-shield.html')
if 'etel-idle-logout.js' not in page:
    bad.append('admin-shield.html does not load etel-idle-logout.js')
if re.search(r'\beval\(|document\.write|\sonclick=|\sonchange=', page):
    bad.append('admin-shield.html uses eval, document.write or inline handlers')
boot = read('api/boot.php')
ctl = read('api/controllers/ShieldController.php') + read('api/controllers/SessionController.php')
for a in sorted(set(re.findall(r"api\('((?:admin_shield|admin_idle)_[a-z_]+)'", page))):
    if "case '" + a + "'" not in boot:
        bad.append(a + ' is not routed in boot.php')
    if "case '" + a + "'" not in ctl:
        bad.append(a + ' has no handler in the controllers')
if '#dark-theme-toggle,#_diag_fab{display:none!important}' not in page:
    bad.append('admin-shield.html lets the floating round buttons cover the dropdowns again')
if page.count('esc(') < 10:
    bad.append('admin-shield.html no longer escapes server text')
if len(page.encode('utf-8')) > 30000:
    bad.append('admin-shield.html is over 30KB')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: admin shield page guarded')
