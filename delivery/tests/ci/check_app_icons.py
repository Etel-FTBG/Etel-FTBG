"""Guards the ETEL app's own pictures (Etel-Driver-App/assets, made by tools/brand/make_app_icons.py, 27 Nov 2026):
the app icon, adaptive icon, opening splash and the logo on the hub are the ETEL GROUP master logo, not the old car logo.
No Pillow needed for the size checks (PNG header); the pixel check runs when Pillow is there."""
import json
import os
import struct

root = __file__.rsplit('/delivery/tests/', 1)[0]
A = os.path.join(root, 'Etel-Driver-App', 'assets')
bad = []

def head(p):
    with open(p, 'rb') as f:
        h = f.read(26)
    if h[:8] != b'\x89PNG\r\n\x1a\n':
        return None
    return struct.unpack('>II', h[16:24])

for f, size in (('icon.png', (1024, 1024)), ('android-icon-foreground.png', (1024, 1024)), ('android-icon-background.png', (1024, 1024)),
                ('android-icon-monochrome.png', (1024, 1024)), ('splash-icon.png', (384, 384)), ('splash-logo.png', (384, 384)),
                ('etel-master.png', (384, 384)), ('favicon.png', (196, 196))):
    p = os.path.join(A, f)
    if not os.path.exists(p):
        bad.append('missing assets/' + f)
    elif head(p) != size:
        bad.append('assets/%s should be %dx%d, is %s' % (f, size[0], size[1], head(p)))

cfg = json.load(open(os.path.join(root, 'Etel-Driver-App', 'app.base.json')))['expo']
ad = cfg['android']['adaptiveIcon']
if ad.get('backgroundColor', '').lower() == '#0a0703':
    bad.append('adaptive icon background is still the old brown')
if ad.get('backgroundImage') != './assets/android-icon-background.png':
    bad.append('adaptiveIcon.backgroundImage should be ./assets/android-icon-background.png')
if cfg.get('icon') != './assets/icon.png':
    bad.append('app icon should be ./assets/icon.png')
if str(cfg.get('runtimeVersion')) != '1':
    bad.append('runtimeVersion must stay 1 (a picture change must not cut installed apps off from updates)')

try:
    from PIL import Image
    import numpy as np
    m = np.asarray(Image.open(os.path.join(root, 'EtelGroup/icons/brand/etel-group-master.webp')).convert('RGBA').resize((384, 384), Image.LANCZOS)).astype(int)
    for f in ('splash-icon.png', 'splash-logo.png', 'etel-master.png'):
        a = np.asarray(Image.open(os.path.join(A, f)).convert('RGBA')).astype(int)
        if a.shape != m.shape or np.abs(a - m).mean() > 3:
            bad.append('assets/%s is not the group master logo' % f)
    fg = np.asarray(Image.open(os.path.join(A, 'android-icon-foreground.png')).convert('RGBA'))
    ys, xs = np.where(fg[..., 3] > 20)
    if len(xs) == 0 or xs.min() < 1024 * 0.17 or xs.max() > 1024 * 0.83 or ys.min() < 1024 * 0.17 or ys.max() > 1024 * 0.83:
        bad.append('adaptive icon foreground must stay inside the 66 percent safe zone')
    ic = np.asarray(Image.open(os.path.join(A, 'icon.png')).convert('RGB')).astype(int)
    # the master logo has a blue E mark and no car: the old icon had a car wing at the left centre, the new one has plain navy there
    if ic[512, 30].sum() > 250:
        bad.append('icon.png edge is not navy')
except ImportError:
    pass

if bad:
    print('FAIL check_app_icons:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_app_icons')
