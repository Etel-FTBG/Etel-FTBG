"""Guards the Delivery / Ride / Driver brand pictures (27 Nov 2026): the three apps use ONE logo (delivery/icons/source/etel-transport-logo.jpg, made into every size by
tools/brand/make_transport_icons.py). No Pillow needed here: PNG sizes are read from the file header and the three sets are compared byte for byte.
 1. every icon a manifest lists exists and has exactly the size it says;
 2. icon, ride-icon and driver-icon are byte-identical at every size (same logo for all three), also the maskable and apple-touch icons;
 3. the start screens (splash, ride-splash) exist for every size and the two families have the same sizes;
 4. the logo-box pictures exist (png 640 x 640 and webp) where the pages read them, and the pages ask for them with ?v=2 so phones fetch the new ones;
 5. the service worker cache name moved on (v299 or later) so installed apps drop the old pictures."""
import glob
import hashlib
import json
import os
import re
import struct

root = __file__.rsplit('/tests/', 1)[0]
bad = []

def png_size(p):
    with open(p, 'rb') as f:
        h = f.read(24)
    if h[:8] != b'\x89PNG\r\n\x1a\n':
        return None
    return struct.unpack('>II', h[16:24])

def sha(p):
    return hashlib.sha256(open(p, 'rb').read()).hexdigest()

# 1. manifests
for mf, prefix in (('manifest.json', ''), ('manifest-ride.json', 'ride-'), ('manifest-driver.json', 'driver-')):
    path = os.path.join(root, mf)
    try:
        data = json.load(open(path, encoding='utf-8'))
    except Exception as e:
        bad.append('cannot read %s: %s' % (mf, e))
        continue
    for ic in data.get('icons', []):
        src = ic.get('src', '')
        if 'icons/' not in src:
            continue
        rel = src.split('delivery/', 1)[-1].lstrip('/') if src.startswith('/delivery/') else src.lstrip('./')
        p = os.path.join(root, rel)
        if not os.path.exists(p):
            bad.append('%s lists %s but the file is missing' % (mf, src))
            continue
        want = ic.get('sizes', '')
        m = re.match(r'(\d+)x(\d+)$', want)
        if m and png_size(p) != (int(m.group(1)), int(m.group(2))):
            bad.append('%s: %s should be %s but is %s' % (mf, src, want, png_size(p)))

# 2. same logo for all three apps, at every size
names = ['icon-%d.png' % s for s in (72, 96, 128, 144, 152, 192, 384, 512)] + ['icon-192-maskable.png', 'icon-512-maskable.png', 'apple-touch-icon.png']
for n in names:
    shas = {}
    for pre in ('', 'ride-', 'driver-'):
        p = os.path.join(root, 'icons', pre + n)
        if not os.path.exists(p):
            bad.append('missing icons/%s' % (pre + n))
        else:
            shas[pre or 'delivery'] = sha(p)
    if len(set(shas.values())) > 1:
        bad.append('icons/%s differs between the apps (they must all use the same logo): %s' % (n, sorted(shas)))
if not os.path.exists(os.path.join(root, 'icons/source/etel-transport-logo.jpg')):
    bad.append('the source logo icons/source/etel-transport-logo.jpg is missing (the pictures cannot be rebuilt)')

# 3. start screens
a = sorted(re.search(r'splash-(\d+x\d+)\.png$', f).group(1) for f in glob.glob(root + '/icons/splash-*.png'))
b = sorted(re.search(r'splash-(\d+x\d+)\.png$', f).group(1) for f in glob.glob(root + '/icons/ride-splash-*.png'))
if not a or a != b:
    bad.append('splash and ride-splash must exist in the same sizes: %s vs %s' % (a, b))
for f in glob.glob(root + '/icons/*splash-*.png'):
    m = re.search(r'(\d+)x(\d+)\.png$', f)
    if m and png_size(f) != (int(m.group(1)), int(m.group(2))):
        bad.append('%s has the wrong size %s' % (os.path.basename(f), png_size(f)))

# 4. logo boxes
for k in ('ride', 'driver', 'delivery'):
    p = os.path.join(root, 'assets/images/etel-%s-coin.png' % k)
    if not os.path.exists(p):
        bad.append('missing assets/images/etel-%s-coin.png' % k)
    elif png_size(p) != (640, 640):
        bad.append('assets/images/etel-%s-coin.png should be 640 x 640, is %s' % (k, png_size(p)))
    if not os.path.exists(os.path.join(root, 'assets/images/etel-%s-coin.webp' % k)):
        bad.append('missing assets/images/etel-%s-coin.webp' % k)
for n in ('etel-delivery-coin-crop.png', 'etel-delivery-coin-crop.webp'):
    if not os.path.exists(os.path.join(root, 'assets/images', n)):
        bad.append('missing assets/images/' + n)
for page in ('driver/index.html', 'ride/index.html', 'index.html', 'customer.html', 'track.html'):
    t = open(os.path.join(root, page), encoding='utf-8', errors='replace').read()
    for m in re.finditer(r'etel-(?:ride|driver|delivery)-coin(?:-crop)?\.(?:png|webp)([^"\'\s)]*)', t):
        if m.group(1) != '?v=2':
            bad.append('%s: %s must end with ?v=2' % (page, m.group(0)))

# 5. cache
sw = open(os.path.join(root, 'sw.js'), encoding='utf-8', errors='replace').read()
m = re.search(r"CACHE_NAME\s*=\s*'etel-v(\d+)'", sw)
if not m or int(m.group(1)) < 299:
    bad.append('sw.js CACHE_NAME must be etel-v299 or later so the new logo reaches installed apps')

if bad:
    print('FAIL: transport brand pictures')
    for x in bad:
        print(' - ' + x)
    raise SystemExit(1)
print('ok: the Delivery, Ride and Driver apps use one logo at every size; manifests, start screens, logo boxes and cache are in step')
