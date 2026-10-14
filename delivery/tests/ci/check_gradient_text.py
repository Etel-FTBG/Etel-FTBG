"""Gold gradient headings (background-clip:text) must stay readable.

Text inside such a heading is painted transparent on purpose. Two things went wrong before: the contrast guard judged
that text against the gradient as if it were a flat surface and turned it dark on dark (the Groceries title in dark mode),
and emoji inside the heading lost their colour. This keeps both fixes in place."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
guard = read('js/etel-contrast-guard.js')
if 'function inClippedText(' not in guard or 'if (inClippedText(el)) return;' not in guard:
    bad.append('js/etel-contrast-guard.js must skip text inside a background-clip:text ancestor (inClippedText)')
css = read('css/customer-v5.css')
if not re.search(r'\.brand-name #category-icon[^{]*\{[^}]*-webkit-text-fill-color:\s*initial', css):
    bad.append('css/customer-v5.css must give the emoji inside .brand-name its own paint')
for page in ('admin.html', 'servicegiver.html', 'customer.html'):
    m = re.search(r'etel-contrast-guard\.js\?v=(\d+)', read(page))
    if not m or int(m.group(1)) < 3:
        bad.append(page + ' must load etel-contrast-guard.js?v=3 or newer (older phones keep the cached old copy)')
if bad:
    print('FAIL:\n  ' + '\n  '.join(bad)); sys.exit(1)
print('gradient text: ok')
