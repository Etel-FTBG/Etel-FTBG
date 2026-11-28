#!/usr/bin/env python3
"""The browser-tab icon for EVERY Etel system: the gold 'ae' monogram on navy inside its gold ring (Dagi, 28 Nov 2026).
Source: EtelGroup/icons/brand/etel-monogram-source.jpg (1024 px).  Run from the repo root:  python3 tools/brand/make_tab_icons.py
Writes EtelGroup/icons/tab-icon-{16,32,48,96,192}.png, the site-root favicon.ico, delivery/favicon.ico + .png and Etel.Pay/wallet/favicon.ico."""
from PIL import Image, ImageDraw
src = Image.open('EtelGroup/icons/brand/etel-monogram-source.jpg').convert('RGB')
R = 398   # inside the thin outer ring and its balls: only the gold ring, the navy disc and the monogram are left (readable at 16 px)
im = src.crop((512 - R, 512 - R, 512 + R, 512 + R)).convert('RGBA')
n = im.width
m = Image.new('L', (n * 4, n * 4), 0); ImageDraw.Draw(m).ellipse((0, 0, n * 4 - 1, n * 4 - 1), fill=255)
im.putalpha(m.resize((n, n), Image.LANCZOS))
def at(s): return im.resize((s, s), Image.LANCZOS)
for s in (16, 32, 48, 96, 192):
    at(s).save('EtelGroup/icons/tab-icon-%d.png' % s, optimize=True)
for path in ('favicon.ico', 'delivery/favicon.ico', 'Etel.Pay/wallet/favicon.ico'):
    at(64).save(path, sizes=[(16, 16), (32, 32), (48, 48), (64, 64)])
at(32).save('delivery/favicon.png', optimize=True)
at(32).save('favicon-32.png', optimize=True)
print('tab icons written')
