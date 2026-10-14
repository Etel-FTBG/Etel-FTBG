"""Guards the customer-app speed pass: own fonts (no Google Fonts), Maps loaded on demand, every font file present."""
import os
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html = read('customer.html')
js = read('js/customer.js')
fonts_css = read('css/etel-fonts.css')
bad = []
if 'fonts.googleapis.com' in html or 'fonts.gstatic.com' in html:
    bad.append('customer.html still loads Google Fonts')
if 'css/etel-fonts.css' not in html:
    bad.append('customer.html does not load css/etel-fonts.css')
if re.search(r"loadGoogleMaps\(\s*'geometry,places'", html):
    bad.append('customer.html loads Google Maps at start-up again')
if js.count('etelEnsureMaps();') < 2:
    bad.append('Maps must be requested by both the checkout and the order tracking screen (etelEnsureMaps)')
if 'window.initCustomerMap = function' not in js:
    bad.append('customer.js does not define the Maps-ready callback initCustomerMap')
files = re.findall(r'url\(\.\./(fonts/[^)]+)\)', fonts_css)
if not files:
    bad.append('etel-fonts.css has no font files')
for f in sorted(set(files)):
    if not os.path.exists(root + '/' + f):
        bad.append('missing font file ' + f)
if bad:
    print('check_speed_pass FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_speed_pass ok (%d font faces, %d files)' % (len(files), len(set(files))))
