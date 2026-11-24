"""Guards delivery/js/admin.js: it must be the Delivery admin file (not another Etel system's admin.js), and admin.html must load the file check right after it."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
js = read('js/admin.js')
if 'orderIssuesFilterBtn' not in js:
    bad.append('js/admin.js does not look like the Delivery admin file (orderIssuesFilterBtn is missing)')
for marker in ("getElementById('login-screen')", 'ega_token', 'function showLogin'):
    if marker in js:
        bad.append("js/admin.js contains '%s': that belongs to the EtelGroup admin, not to Delivery" % marker)
html = read('admin.html')
a, b = html.find('js/admin.js?v='), html.find('js/admin-filecheck.js')
if a < 0 or b < 0 or b < a:
    bad.append('admin.html must load js/admin-filecheck.js right after js/admin.js')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: delivery admin.js is the Delivery file')
