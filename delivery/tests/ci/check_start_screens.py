"""Guards the own iOS start screens of Courier, Service Giver, Admin and Agent (delivery/icons/<role>-splash-WxH.jpg, made by
tools/brand/make_system_icons.py startscreens, 28 Nov 2026): all 13 sizes exist for each role with the right pixel size (read from the JPEG header),
the roles' pictures differ from each other, js/etel-start-screens.js lists the same 13 sizes, and each of the four pages loads it BEFORE core.js
(core.js skips a size that already has a link, so the order is what makes the role's picture win)."""
import hashlib
import os
import re
import struct

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
bad = []
SIZES = [(640, 1136), (750, 1334), (1242, 2208), (1125, 2436), (828, 1792), (1242, 2688), (1170, 2532), (1284, 2778), (1179, 2556), (1290, 2796),
         (1536, 2048), (1668, 2388), (2048, 2732)]

def jpeg_size(p):
    try:
        d = open(p, 'rb').read()
    except OSError:
        return None
    if d[:2] != b'\xff\xd8':
        return None
    i = 2
    while i + 9 < len(d):
        if d[i] != 0xFF:
            i += 1
            continue
        m = d[i + 1]
        if m in (0xC0, 0xC1, 0xC2):
            h, w = struct.unpack('>HH', d[i + 5:i + 9])
            return w, h
        i += 2 + struct.unpack('>H', d[i + 2:i + 4])[0]
    return None

hashes = {}
for role in ('courier', 'service', 'admin', 'agent'):
    for w, h in SIZES:
        p = os.path.join(D, 'icons', '%s-splash-%dx%d.jpg' % (role, w, h))
        s = jpeg_size(p)
        if s is None:
            bad.append('missing or not a JPEG: icons/%s-splash-%dx%d.jpg' % (role, w, h))
        elif s != (w, h):
            bad.append('icons/%s-splash-%dx%d.jpg is %s' % (role, w, h, s))
    p = os.path.join(D, 'icons', '%s-splash-1170x2532.jpg' % role)
    if os.path.exists(p):
        hashes[role] = hashlib.sha256(open(p, 'rb').read()).hexdigest()
if len(set(hashes.values())) != len(hashes):
    bad.append('two roles have the same start screen')

js = open(os.path.join(D, 'js', 'etel-start-screens.js'), encoding='utf-8').read()
got = [(int(a), int(b)) for a, b in re.findall(r'\[(\d{3,4}),\s*(\d{3,4})\]', js)]
if got != SIZES:
    bad.append('js/etel-start-screens.js lists different sizes than core.js')
for r in ('courier', 'service', 'admin', 'agent'):
    pass
if "'icons/' + role + '-splash-'" not in js:
    bad.append('js/etel-start-screens.js does not build the role file name')

for page, tag in (('courier/courier-index.html', 'etel-start-screens.js'), ('servicegiver.html', 'etel-start-screens.js'),
                  ('admin.html', 'etel-start-screens.js'), ('agent/index.html', 'etel-start-screens.js')):
    t = open(os.path.join(D, page), encoding='utf-8', errors='replace').read()
    a = t.find(tag)
    c = t.find('js/core.js')
    if a < 0:
        bad.append(page + ' does not load etel-start-screens.js')
    elif c >= 0 and a > c:
        bad.append(page + ' loads etel-start-screens.js AFTER core.js')

sw = open(os.path.join(D, 'sw.js'), encoding='utf-8').read()
m = re.search(r"CACHE_NAME\s*=\s*'etel-v(\d+)'", sw)
if not m or int(m.group(1)) < 304:
    bad.append('sw.js CACHE_NAME should be etel-v304 or newer')

if bad:
    print('FAIL check_start_screens:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_start_screens')
