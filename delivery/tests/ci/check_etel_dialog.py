"""Guards the branded confirmation dialog: js/etel-dialog.js is loaded on the customer, shop, courier, driver and ride pages, replaces window.confirm,
and has no inline handlers or eval."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for page in ('customer.html', 'servicegiver.html', 'courier/index.html', 'courier/courier-index.html', 'driver/index.html', 'ride/index.html'):
    if 'etel-dialog.js' not in read(page):
        bad.append(page + ' does not load etel-dialog.js')
js = read('js/etel-dialog.js')
if 'window.confirm = function' not in js or 'window.EtelConfirm' not in js:
    bad.append('etel-dialog.js no longer replaces window.confirm')
if re.search(r'\beval\(|document\.write|\bonclick=', js):
    bad.append('etel-dialog.js uses inline handlers, eval or document.write')
if subprocess.call(['node', '--check', root + '/js/etel-dialog.js']) != 0:
    bad.append('etel-dialog.js does not parse')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: branded confirmation dialog guarded')
