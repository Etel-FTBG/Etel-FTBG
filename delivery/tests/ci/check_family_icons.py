"""Guards each family system's OWN icon set and logo (made by tools/brand/make_system_icons.py family, 28 Nov 2026):
Etel.Pay, EtelAi, EtelERP, EtelMadeya, EtelGebeya, EtelId, EtelTaxi. Icon files have the right sizes, the manifests and pages point at files that exist,
the sets are different from each other (no system shows another system's picture), maskable icons exist where a manifest asks for them.
No Pillow needed: sizes come from the PNG header, sets are compared by file hash."""
import hashlib
import json
import os
import re
import struct

root = __file__.rsplit('/delivery/tests/', 1)[0]
bad = []

def P(*a):
    return os.path.join(root, *a)

def size(p):
    try:
        with open(p, 'rb') as f:
            h = f.read(24)
    except OSError:
        return None
    return struct.unpack('>II', h[16:24]) if h[:8] == b'\x89PNG\r\n\x1a\n' else None

def need(path, w):
    s = size(P(path))
    if s is None:
        bad.append('missing or not a PNG: ' + path)
    elif s != (w, w):
        bad.append('%s should be %dx%d, is %s' % (path, w, w, s))

SETS = {
    'Etel.Pay': ('Etel.Pay/wallet/icons/', '', (72, 96, 128, 144, 152, 192, 384, 512)),
    'EtelAi': ('EtelAi/icons/', '', (192, 512)),
    'EtelERP': ('EtelERP/assets/icons/', '', (192, 512)),
    'EtelMadeya': ('EtelMadeya/assets/images/', 'madeya-', (192, 512)),
    'EtelGebeya': ('EtelGebeya/public/', '', (96, 192, 512)),
    'EtelId': ('EtelId/public/images/', 'etelid-', (96, 192, 512)),
    'EtelTaxi': ('EtelTaxi/assets/images/', 'taxi-', (96, 192, 512)),
}
hashes = {}
for name, (folder, pre, sizes) in SETS.items():
    for s in sizes:
        need('%s%sicon-%d.png' % (folder, pre, s), s)
    if name != 'EtelId':
        need('%s%sicon-192-maskable.png' % (folder, pre), 192)
        need('%s%sicon-512-maskable.png' % (folder, pre), 512)
    need('%s%sapple-touch-icon.png' % (folder, pre), 180) if name != 'EtelAi' else None
    p = P('%s%sicon-512.png' % (folder, pre))
    if os.path.exists(p):
        hashes[name] = hashlib.sha256(open(p, 'rb').read()).hexdigest()
for a in hashes:
    for b in hashes:
        if a < b and hashes[a] == hashes[b]:
            bad.append('%s and %s have the same icon' % (a, b))
for name, h in hashes.items():
    g = P('EtelGroup/icons/icon-512.png')
    if os.path.exists(g) and hashlib.sha256(open(g, 'rb').read()).hexdigest() == h:
        bad.append('%s shows the Group icon' % name)

# logos kept under their old names
need('Etel.Pay/wallet/logo.png', 300); need('Etel.Pay/wallet/icons/logo.png', 300)
need('EtelAi/icons/etel-logo.png', 192); need('EtelERP/assets/icons/logo.png', 256)
need('EtelMadeya/assets/img/etel-logo.png', 512); need('EtelTaxi/assets/images/etel-logo.png', 512)
if not os.path.exists(P('Etel.Pay/wallet/favicon.ico')) or open(P('Etel.Pay/wallet/favicon.ico'), 'rb').read(4) != b'\x00\x00\x01\x00':
    bad.append('Etel.Pay/wallet/favicon.ico is not an .ico file')
if os.path.getsize(P('Etel.Pay/wallet/favicon.ico')) < 1500:
    bad.append('Etel.Pay/wallet/favicon.ico is too small to hold several sizes (old plain "E"?)')

# manifests: every icon file exists, maskable ones are separate from "any"
def manifest_icons(path, base):
    m = json.load(open(P(path), encoding='utf-8'))
    out = []
    for i in m.get('icons', []):
        src = i['src']
        out.append((src, i.get('purpose', 'any')))
        local = src
        if src.startswith('/wp-content/plugins/madeya-gas-queue/'):
            local = 'EtelMadeya/' + src.split('madeya-gas-queue/', 1)[1]
        else:
            local = os.path.normpath(os.path.join(base, src))
        if not os.path.exists(P(local)):
            bad.append('%s points at %s, which is missing' % (path, src))
        if 'any maskable' in i.get('purpose', ''):
            bad.append('%s: icon %s is "any maskable" (use separate any and maskable files)' % (path, src))
    if not any('maskable' in pu for _, pu in out):
        bad.append(path + ' has no maskable icon')
for path, base in (('Etel.Pay/wallet/manifest.json', 'Etel.Pay/wallet'), ('EtelAi/manifest.json', 'EtelAi'),
                   ('EtelERP/manifest.webmanifest', 'EtelERP'), ('EtelMadeya/pwa/manifest.json', 'EtelMadeya/pwa')):
    manifest_icons(path, base)

# pages link their own icons
def has(path, *needles):
    t = open(P(path), encoding='utf-8', errors='replace').read()
    for n in needles:
        if n not in t:
            bad.append('%s does not contain %s' % (path, n))
has('Etel.Pay/wallet/login.html', 'icons/icon-96.png', 'icons/apple-touch-icon.png')
has('EtelAi/index.html', 'icons/icon-192.png'); has('EtelAi/admin.html', 'icons/icon-192.png')
has('EtelERP/index.html', 'assets/icons/icon-192.png', 'assets/icons/apple-touch-icon.png')
has('EtelMadeya/index.php', 'madeya-icon-192.png'); has('EtelMadeya/admin/index.php', 'madeya-icon-192.png')
has('EtelGebeya/app/Views/_layouts/public.php', '/public/icon-96.png', '/public/apple-touch-icon.png')
has('EtelGebeya/admin.html', 'public/icon-96.png')
for f in ('index', 'admin', 'driver', 'verify'):
    has('EtelTaxi/%s.html' % f, 'taxi-icon-96.png')
for f in ('portal', 'auth-page', 'verify', 'apply-form'):
    has('EtelId/templates/%s.php' % f, 'etelid-icon-96.png')
t = open(P('EtelGebeya/admin.html'), encoding='utf-8', errors='replace').read()
if 'data:image/svg+xml' in t.split('</head>')[0].split('rel="icon"')[-1][:80]:
    bad.append('EtelGebeya/admin.html still uses the old data: favicon')
# the ERP service worker cache moved on
if "etelerp-shell-v1'" in open(P('EtelERP/sw.js'), encoding='utf-8').read():
    bad.append('EtelERP/sw.js cache name was not bumped')

if bad:
    print('FAIL check_family_icons:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_family_icons')
