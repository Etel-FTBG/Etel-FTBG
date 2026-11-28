"""Guards the OWN icons of each system (27 Nov 2026): every system shows its own logo in the browser tab, on the home screen and while it opens.
 1. Courier, Service, Admin and Agent each have a full icon set (every size a manifest lists, maskable, apple-touch) and the sets are all different
    from each other and from the Delivery set (so a page cannot silently show another system's logo);
 2. Equb and Investor have a tab icon and an apple-touch icon, and their pages link them;
 3. each page links its OWN icons: courier pages -> courier-icon, service page -> service-icon, admin -> admin-icon, landing -> Delivery icon;
 4. the opening splash of the Customer, Driver, Courier, Service and Admin pages shows that system's own logo (splash-<name>.webp), never the group master logo;
 5. the master page and the root pages (privacy, terms, cookie policy, EtelGroup admin) use the ETEL Group icons, not the EtelAi ones, and
    EtelGroup/site.webmanifest lists Group icons that exist;
 6. the logo at the top of the login pages (delivery/assets/images/logo*.png and .webp: customer, ride, driver, courier, service, admin) is each system's own transparent
    logo: 512 x 512, Ride = Driver = Customer (the transport logo), Courier, Service and Admin different from it and from each other;
 7. the role buttons (Customer, Ride, Courier, Driver, Service) and the services list on the login page show the real logos, no emoji;
 8. the Delivery service worker cache name moved on (v300 or later).
No Pillow needed: PNG sizes come from the file header, sets are compared byte for byte."""
import hashlib
import json
import os
import re
import struct

root = __file__.rsplit('/delivery/tests/', 1)[0]
bad = []

def size(p):
    with open(p, 'rb') as f:
        h = f.read(24)
    return struct.unpack('>II', h[16:24]) if h[:8] == b'\x89PNG\r\n\x1a\n' else None

def sha(p):
    return hashlib.sha256(open(p, 'rb').read()).hexdigest()

def read(p):
    return open(os.path.join(root, p), encoding='utf-8', errors='replace').read()

D = os.path.join(root, 'delivery')
# 1. the four full sets
seen = {}
for role in ('courier', 'service', 'admin', 'agent'):
    for s in (72, 96, 128, 144, 152, 192, 384, 512):
        p = os.path.join(D, 'icons/%s-icon-%d.png' % (role, s))
        if not os.path.exists(p) or size(p) != (s, s):
            bad.append('icons/%s-icon-%d.png is missing or not %d x %d' % (role, s, s, s))
    for s in (192, 512):
        p = os.path.join(D, 'icons/%s-icon-%d-maskable.png' % (role, s))
        if not os.path.exists(p) or size(p) != (s, s):
            bad.append('icons/%s-icon-%d-maskable.png is missing or wrong' % (role, s))
    p = os.path.join(D, 'icons/%s-apple-touch-icon.png' % role)
    if not os.path.exists(p) or size(p) != (180, 180):
        bad.append('icons/%s-apple-touch-icon.png is missing or not 180 x 180' % role)
    if os.path.exists(os.path.join(D, 'icons/%s-icon-512.png' % role)):
        seen[role] = sha(os.path.join(D, 'icons/%s-icon-512.png' % role))
seen['delivery'] = sha(os.path.join(D, 'icons/icon-512.png'))
if len(set(seen.values())) != len(seen):
    bad.append('two systems have the same 512 icon (each system needs its own logo): %s' % sorted(seen))
# 2. equb and investor
for k in ('equb', 'investor'):
    for n in ('icons/%s-icon-96.png' % k, 'icons/%s-apple-touch-icon.png' % k):
        if not os.path.exists(os.path.join(D, n)):
            bad.append('missing delivery/' + n)
# 3. pages link their own icons
LINKS = {
    'delivery/courier/index.html': ['courier-icon-96.png', 'courier-apple-touch-icon.png'],
    'delivery/courier/courier-index.html': ['courier-icon-96.png', 'courier-apple-touch-icon.png'],
    'delivery/servicegiver.html': ['service-icon-96.png', 'service-apple-touch-icon.png'],
    'delivery/admin.html': ['admin-icon-96.png', 'admin-apple-touch-icon.png'],
    'delivery/equb/index.html': ['equb-icon-96.png', 'equb-apple-touch-icon.png'],
    'delivery/investor/index.html': ['investor-icon-96.png', 'investor-apple-touch-icon.png'],
    'delivery/index.html': ['icons/icon-96.png', 'icons/apple-touch-icon.png'],
}
for page, need in LINKS.items():
    t = read(page)
    for n in need:
        if n not in t:
            bad.append('%s must link %s' % (page, n))
for page in ('delivery/courier/index.html', 'delivery/courier/courier-index.html'):
    if re.search(r'href="\.\./icons/(?:icon-96|apple-touch-icon)\.png', read(page)):
        bad.append('%s still links the Delivery icons' % page)
if 'etel-delivery-coin' in re.sub(r'<!--.*?-->', '', read('delivery/servicegiver.html'), flags=re.S).split('</head>')[0]:
    bad.append('delivery/servicegiver.html head still uses the Delivery coin')
# 4. opening splash
SPL = {'delivery/customer.html': 'transport', 'delivery/driver/index.html': 'transport', 'delivery/courier/index.html': 'courier',
       'delivery/servicegiver.html': 'service', 'delivery/admin.html': 'admin'}
for page, name in SPL.items():
    t = read(page)
    if 'splash-%s.webp' % name not in t:
        bad.append('%s: the opening splash must show splash-%s.webp' % (page, name))
    if 'master-logo-splash.png' in t:
        bad.append('%s: the opening splash still shows the group master logo' % page)
    p = os.path.join(D, 'assets/images/splash-%s.webp' % name)
    if not os.path.exists(p) or open(p, 'rb').read(4) != b'RIFF':
        bad.append('missing or bad delivery/assets/images/splash-%s.webp' % name)
# 5. the master page and the root pages
for page in ('EtelGroup/index.html', 'privacy.html', 'terms.html', 'cookie-policy.html', 'EtelGroup/admin.html'):
    t = read(page)
    if re.search(r'rel="(?:shortcut )?icon"[^>]*EtelAi/icons', t) or re.search(r'(?:og|twitter):image"[^>]*EtelAi/icons', t):
        bad.append('%s still uses the EtelAi icon as its own' % page)
    if 'EtelGroup/icons/' not in t:
        bad.append('%s does not link the Group icons' % page)
wm = json.loads(read('EtelGroup/site.webmanifest'))
if not wm.get('icons') or any('EtelGroup/icons/' not in i['src'] for i in wm['icons']):
    bad.append('EtelGroup/site.webmanifest must list only EtelGroup/icons/ files')
for i in wm.get('icons', []):
    f = os.path.join(root, 'EtelGroup/icons', i['src'].split('/icons/')[1].split('?')[0])
    want = int(i['sizes'].split('x')[0])
    if not os.path.exists(f) or size(f) != (want, want):
        bad.append('site.webmanifest icon %s is missing or not %d x %d' % (i['src'], want, want))
for n in ('icon-96.png', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png'):
    if not os.path.exists(os.path.join(root, 'EtelGroup/icons', n)):
        bad.append('missing EtelGroup/icons/' + n)
# 6. login logos
logos = {}
for k in ('', '-ride', '-driver', '-courier', '-service', '-admin'):
    p = os.path.join(D, 'assets/images/logo%s.png' % k)
    w = os.path.join(D, 'assets/images/logo%s.webp' % k)
    if not os.path.exists(p) or size(p) != (512, 512):
        bad.append('assets/images/logo%s.png is missing or not 512 x 512' % k)
    else:
        logos[k or '-customer'] = sha(p)
    if not os.path.exists(w) or open(w, 'rb').read(4) != b'RIFF':
        bad.append('assets/images/logo%s.webp is missing or bad' % k)
if logos and not (logos.get('-customer') == logos.get('-ride') == logos.get('-driver')):
    bad.append('the Customer, Ride and Driver login logos must be the same transport logo')
if logos and len({logos.get('-customer'), logos.get('-courier'), logos.get('-service'), logos.get('-admin')}) != 4:
    bad.append('the transport, Courier, Service and Admin login logos must all be different')
# 7. the role buttons and the services list on the login page use the real logos (no emoji icons)
for k in ('customer', 'ride', 'courier', 'driver', 'service', 'pay', 'id'):
    p = os.path.join(D, 'assets/images/tab-%s.png' % k)
    if not os.path.exists(p) or size(p) != (96, 96):
        bad.append('assets/images/tab-%s.png is missing or not 96 x 96' % k)
lt = read('delivery/login.html')
for k in ('customer', 'ride', 'courier', 'driver', 'service'):
    if 'tab-%s.png' % k not in lt:
        bad.append('delivery/login.html: the %s role button must show assets/images/tab-%s.png' % (k, k))
if re.search(r'del-rtab-icon">\s*[^<\s]', lt):
    bad.append('delivery/login.html: a role button still has an emoji icon')
# 8. cache
m = re.search(r"CACHE_NAME\s*=\s*'etel-v(\d+)'", read('delivery/sw.js'))
if not m or int(m.group(1)) < 300:
    bad.append('delivery/sw.js CACHE_NAME must be etel-v300 or later')

if bad:
    print('FAIL: own icons per system')
    for x in bad:
        print(' - ' + x)
    raise SystemExit(1)
print('ok: Courier, Service, Admin, Agent, Equb, Investor and the ETEL Group pages each use their own icons, opening splash and login logos')
