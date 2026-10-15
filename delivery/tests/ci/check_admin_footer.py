"""Guards the admin header app links and the footer: Customer and Service Giver links in both header menus and the footer,
the footer bar and its script/CSS are loaded, no inline handlers were added, and the page area is shortened by the footer height."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, css, js = read('admin.html'), read('css/admin-footer.css'), read('js/admin-footer.js')
bad = []
for href in ('customer.html', 'servicegiver.html'):
    if html.count('href="' + href + '" target="_blank" class="header-stat"') < 2:
        bad.append('the header (desktop and mobile menu) must link to ' + href)
foot = html.split('<footer class="admin-footer"', 1)[1].split('</footer>', 1)[0] if '<footer class="admin-footer"' in html else ''
if not foot:
    bad.append('admin.html has no footer')
for href in ('customer.html', 'servicegiver.html', 'privacy.html', 'terms.html'):
    if 'href="' + href + '"' not in foot:
        bad.append('the footer must link to ' + href)
if re.search(r'\bon[a-z]+="', foot):
    bad.append('the footer must not use inline handlers')
if 'css/admin-footer.css' not in html or 'js/admin-footer.js' not in html:
    bad.append('admin.html must load admin-footer.css and admin-footer.js')
if '--af-h' not in css or 'calc(100vh - 72px - var(--af-h))' not in css or 'bottom: var(--af-h)' not in css:
    bad.append('the page area must be shortened by the footer height (desktop and mobile)')
if 'sys-status' not in js:
    bad.append('the footer status must mirror the header status')
for f in ('privacy.html', 'terms.html', 'customer.html', 'servicegiver.html'):
    try:
        open(root + '/' + f).close()
    except OSError:
        bad.append(f + ' does not exist')
if bad:
    print('check_admin_footer FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_admin_footer ok')
