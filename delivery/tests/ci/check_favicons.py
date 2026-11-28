#!/usr/bin/env python3
"""Every page people open has a tab icon, and the old green seal pictures are gone from EtelId.
1. /favicon.ico exists at the site root (browsers ask for it when a page has no icon link) with 16, 32 and 48 px pictures.
2. Every user page below has a <link rel="icon">.
3. The EtelId pictures logo-driver, logo-ride and etel-delivery-coin are the new badges (navy, not the old green seal)."""
import os, re, sys
from PIL import Image
R = os.path.join(os.path.dirname(__file__), '..', '..', '..')
bad = []
ico = Image.open(os.path.join(R, 'favicon.ico'))
sizes = ico.info.get('sizes', set())
for s in ((16, 16), (32, 32), (48, 48)):
    if s not in sizes: bad.append('favicon.ico misses %dx%d' % s)
pages = ['portfolio.html', 'EtelAi/customer.html', 'EtelAi/login.html', 'EtelGroup/admin.html', 'EtelGroup/investors/admin.html', 'EtelGroup/investors/portal.html',
         'delivery/claim.html', 'delivery/privacy.html', 'delivery/terms.html', 'delivery/offline.html', 'delivery/delete-account.html', 'delivery/ride/share.html',
         'EtelGebeya/app/Views/_layouts/dark.php', 'EtelGebeya/app/Views/storefront/coming-soon.php']
pages += ['Etel.Pay/wallet/' + f for f in os.listdir(os.path.join(R, 'Etel.Pay/wallet')) if f.endswith('.html') and f not in ('diag.html', 'updater.html')]
pages += ['EtelId/templates/' + f for f in os.listdir(os.path.join(R, 'EtelId/templates')) if f.endswith('.php') and f in ('portal.php', 'auth-page.php', 'verify.php', 'admin-console.php')]
for p in pages:
    t = open(os.path.join(R, p), encoding='utf-8', errors='ignore').read()
    if not re.search(r'rel=["\'](shortcut )?icon["\']', t, re.I): bad.append(p + ' has no tab icon')
for f in ('logo-driver.webp', 'logo-ride.webp', 'etel-delivery-coin.webp', 'etel-id-coin.webp'):
    im = Image.open(os.path.join(R, 'EtelId/public/images', f)).convert('RGBA').resize((64, 64))
    d = im.tobytes(); px = [d[i:i + 4] for i in range(0, len(d), 4) if d[i + 3] > 200]
    r = sum(p[0] for p in px) / len(px); g = sum(p[1] for p in px) / len(px); b = sum(p[2] for p in px) / len(px)
    if g > b + 8 and g > r + 8: bad.append(f + ' still looks like the old green seal')
if bad:
    print('FAIL check_favicons:\n - ' + '\n - '.join(bad)); sys.exit(1)
print('ok check_favicons (%d pages)' % len(pages))
