"""Guards the courier position reports (the customer's live map needs them): both courier pages load js/courier-location-report.js, the script sends
courier_update_location, has no inline handlers / eval, and courier_update_location is not a cache-purging write action (it fires every few seconds)."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for page in ('courier/index.html', 'courier/courier-index.html'):
    if 'courier-location-report.js' not in read(page):
        bad.append(page + ' does not load courier-location-report.js')
js = read('js/courier-location-report.js')
if 'courier_update_location' not in js:
    bad.append('courier-location-report.js does not send courier_update_location')
if re.search(r'\beval\(|document\.write|\bonclick=', js):
    bad.append('courier-location-report.js uses inline handlers, eval or document.write')
if subprocess.call(['node', '--check', root + '/js/courier-location-report.js']) != 0:
    bad.append('courier-location-report.js does not parse')
boot = read('api/boot.php')
m = re.search(r"define\('ETEL_WRITE_ACTIONS', array\((.*?)\)\);", boot, re.S)
if m and "'courier_update_location'" in m.group(1):
    bad.append("courier_update_location is in ETEL_WRITE_ACTIONS: every position ping would purge the site cache")
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: courier position reports guarded')
