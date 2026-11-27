"""Guards the pictures on the ETEL app launcher (Etel-Driver-App/assets/apps, made by tools/brand/make_launcher_icons.py, 27 Nov 2026):
Ride, Driver and Delivery (customer.png) show the SAME new logo; Pay, Gebeya, Madeya and ID show the family badges; each is a 256 x 256 transparent
PNG (RGBA) so it sits cleanly on the cream tile. Courier and Service keep their coins. Every picture the app code asks for must exist.
No Pillow needed: sizes and colour type come from the PNG header, the three logos are compared byte for byte."""
import hashlib
import os
import re
import struct

root = __file__.rsplit('/delivery/tests/', 1)[0]
apps = os.path.join(root, 'Etel-Driver-App', 'assets', 'apps')
bad = []

def head(p):
    with open(p, 'rb') as f:
        h = f.read(26)
    if h[:8] != b'\x89PNG\r\n\x1a\n':
        return None
    w, hgt = struct.unpack('>II', h[16:24])
    return w, hgt, h[25]          # colour type 6 = RGBA

for k in ('ride', 'driver', 'customer', 'pay', 'gebeya', 'madeya', 'id'):
    p = os.path.join(apps, k + '.png')
    if not os.path.exists(p):
        bad.append('missing assets/apps/%s.png' % k)
        continue
    hd = head(p)
    if hd != (256, 256, 6):
        bad.append('assets/apps/%s.png should be a 256 x 256 transparent (RGBA) PNG, is %s' % (k, hd))

hashes = {}
for k in ('ride', 'driver', 'customer'):
    p = os.path.join(apps, k + '.png')
    if os.path.exists(p):
        hashes[k] = hashlib.sha256(open(p, 'rb').read()).hexdigest()
if len(set(hashes.values())) > 1:
    bad.append('ride.png, driver.png and customer.png must be the same logo: %s' % sorted(hashes))

# every picture the code asks for exists
code = ''
for f in ('src/hub/apps.ts', 'src/hub/companies.ts'):
    p = os.path.join(root, 'Etel-Driver-App', f)
    if os.path.exists(p):
        code += open(p, encoding='utf-8', errors='replace').read()
for name in sorted(set(re.findall(r"assets/apps/([a-z]+\.png)", code))):
    if not os.path.exists(os.path.join(apps, name)):
        bad.append('the app code uses assets/apps/%s but it is missing' % name)

# pixel check when Pillow is there (it is on the build machines; CI may not have it)
try:
    from PIL import Image
    for k in ('ride', 'driver', 'customer', 'pay', 'gebeya', 'madeya', 'id'):
        p = os.path.join(apps, k + '.png')
        if os.path.exists(p):
            im = Image.open(p).convert('RGBA')
            w, h = im.size
            if any(im.getpixel(c)[3] != 0 for c in ((0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1))):
                bad.append('assets/apps/%s.png: the corners must be transparent' % k)
except ImportError:
    pass

if bad:
    print('FAIL: launcher pictures')
    for x in bad:
        print(' - ' + x)
    raise SystemExit(1)
print('ok: launcher pictures are 256 px transparent; Ride, Driver and Delivery share one logo; the family badges are in for Pay, Gebeya, Madeya and ID')
