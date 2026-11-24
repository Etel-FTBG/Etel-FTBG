"""Guards two courier fixes: (1) the QR and scanner libraries come from Etel itself (a phone that cannot reach cdnjs / jsdelivr, as happened on a courier's
phone, must still be able to show the pickup QR); (2) the one-button earnings hide script is loaded on both courier pages and never inlines handlers."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
pages = {'courier/index.html': '../js/', 'courier/courier-index.html': '../js/', 'servicegiver.html': 'js/', 'install.html': 'js/'}
for page, pre in pages.items():
    html = read(page)
    for tag in re.findall(r'<script[^>]*\bsrc="([^"]+)"', html):
        if re.search(r'qrcode|jsqr', tag, re.I) and re.match(r'https?://', tag):
            bad.append(page + ' loads a QR library from an outside website: ' + tag)
    if 'qrcode.min.js' not in html or (page != 'install.html' and pre + 'qrcode.min.js' not in html):
        bad.append(page + ' must load ' + pre + 'qrcode.min.js')
for page in ('courier/index.html', 'courier/courier-index.html'):
    html = read(page)
    if '../js/jsQR.js' not in html:
        bad.append(page + ' must load ../js/jsQR.js (the scanner) from Etel')
    if 'courier-earnings-toggle.js' not in html:
        bad.append(page + ' does not load courier-earnings-toggle.js')
qr = read('js/qrcode.min.js')
if 'var QRCode' not in qr or 'QRCode.CorrectLevel' not in qr:
    bad.append('js/qrcode.min.js is not the qrcodejs library')
if subprocess.call(['node', '--check', root + '/js/qrcode.min.js']) != 0 or subprocess.call(['node', '--check', root + '/js/courier-earnings-toggle.js']) != 0:
    bad.append('a courier script does not parse')
sw = read('sw.js')
if './js/qrcode.min.js' not in sw or './js/jsQR.js' not in sw:
    bad.append('sw.js must keep the QR and scanner libraries for offline use')
tg = read('js/courier-earnings-toggle.js')
if re.search(r'\bon[a-z]+="|\beval\(|document\.write', tg):
    bad.append('courier-earnings-toggle.js uses inline handlers, eval or document.write')
if bad:
    print('check_courier_local_libs FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_local_libs ok')
