#!/usr/bin/env python3
"""The rider app must not show Google plus codes as the pickup / drop-off address."""
import re, subprocess, sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

js = read('js/ride-address-fix.js')
html = read('ride/index.html')
if 'ride-address-fix.js' not in html:
    bad.append('ride/index.html must load ride-address-fix.js')
for need in ("wrap('setPickup'", "wrap('setDropoff'", 'plus_code'):
    if need not in js:
        bad.append('ride-address-fix.js is missing ' + need)

m = re.search(r'var PLUS = (/.*/i);', js)
if not m:
    bad.append('PLUS regex not found')
else:
    # run the real regex in node against known plus codes and ordinary addresses
    script = 'const P=%s;const y=["3F2F+PH8, Hawassa, Ethiopia","8FVC9G8F+6W","7FG9+2V Addis Ababa"],n=["University, Hawassa","Bole Road, Addis Ababa","123 Main St","Hawassa Referral Hospital"];' \
             'process.exit(y.every(s=>P.test(s))&&n.every(s=>!P.test(s))?0:1)' % m.group(1)
    if subprocess.run(['node', '-e', script]).returncode != 0:
        bad.append('plus-code regex misclassifies sample addresses')

if bad:
    print('check_ride_address_fix FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_ride_address_fix OK')
