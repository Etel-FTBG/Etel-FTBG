"""Make each system's OWN icon set (browser tab icon, install icon, maskable icon, apple-touch icon, opening-splash picture) from its ETEL badge.
Run from the repo root:  python3 tools/brand/make_system_icons.py [group ...]     (needs Pillow and numpy; no argument = every group)
The badges are the transparent pictures in EtelGroup/icons/brand/etel-<name>.webp. Each icon is the badge on a navy square (any = badge 92 percent,
maskable = 70 percent so it stays inside the safe circle). File names follow what each system already uses, so the pages and manifests keep working.
Delivery, Ride and Driver use the transport logo and are made by make_transport_icons.py, not here."""
import os
import sys
import numpy as np
from PIL import Image

BRAND = 'EtelGroup/icons/brand/etel-%s.webp'
SIZES = (72, 96, 128, 144, 152, 192, 384, 512)

def navy(size):
    y, x = np.mgrid[0:size, 0:size].astype(np.float32) / (size - 1)
    d = np.clip(np.hypot(x - 0.5, y - 0.42) / 0.75, 0, 1)[..., None]
    c0 = np.array([30, 46, 92], np.float32); c1 = np.array([8, 13, 32], np.float32)
    return Image.fromarray((c0 * (1 - d) + c1 * d).clip(0, 255).astype(np.uint8), 'RGB').convert('RGBA')

def icon(badge, size, scale=0.92):
    base = navy(size)
    b = badge.resize((int(round(size * scale)),) * 2, Image.LANCZOS)
    off = (size - b.size[0]) // 2
    base.alpha_composite(b, (off, off))
    return base.convert('RGB').quantize(256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG)

def save_set(name, folder, prefix, sizes=SIZES, maskable=True, apple='%sapple-touch-icon.png'):
    badge = Image.open(BRAND % name).convert('RGBA')
    os.makedirs(folder, exist_ok=True)
    for s in sizes:
        icon(badge, s).save(os.path.join(folder, '%sicon-%d.png' % (prefix, s)), optimize=True)
    if maskable:
        for s in (192, 512):
            icon(badge, s, 0.70).save(os.path.join(folder, '%sicon-%d-maskable.png' % (prefix, s)), optimize=True)
    if apple:
        icon(badge, 180).save(os.path.join(folder, apple % prefix), optimize=True)
    print(name, '->', folder, prefix or '(no prefix)')

def save_splash(name, out, size=320):
    Image.open(BRAND % name).convert('RGBA').resize((size, size), Image.LANCZOS).save(out, 'WEBP', quality=90, method=6, exact=True)
    print('splash', name, '->', out)

def save_logo(name, base, size=512):
    """the logo shown at the top of a login page: a transparent picture, PNG + WebP (the Admin > Branding screen can overwrite these same files)"""
    im = Image.open(BRAND % name).convert('RGBA').resize((size, size), Image.LANCZOS)
    im.save(base + '.png', optimize=True)
    im.save(base + '.webp', 'WEBP', quality=90, method=6, exact=True)
    print('login logo', name, '->', base)

GROUPS = {
    # the Delivery family (icons keep the <role>-icon-N.png names the manifests use)
    'delivery': lambda: [
        save_set('courier', 'delivery/icons', 'courier-'), save_set('service', 'delivery/icons', 'service-'),
        save_set('admin', 'delivery/icons', 'admin-'), save_set('agent', 'delivery/icons', 'agent-'),
        save_set('equb', 'delivery/icons', 'equb-', sizes=(96,), maskable=False), save_set('investor', 'delivery/icons', 'investor-', sizes=(96,), maskable=False),
        [save_splash(n, 'delivery/assets/images/splash-%s.webp' % o) for n, o in (('transport', 'transport'), ('courier', 'courier'), ('service', 'service'), ('admin', 'admin'))],
    ],
    # the logo at the top of delivery/login.html, admin-login.html and install.html (assets/images/logo*.png/.webp)
    'login': lambda: [
        save_logo('transport', 'delivery/assets/images/logo'), save_logo('transport', 'delivery/assets/images/logo-ride'),
        save_logo('transport', 'delivery/assets/images/logo-driver'), save_logo('courier', 'delivery/assets/images/logo-courier'),
        save_logo('service', 'delivery/assets/images/logo-service'), save_logo('admin', 'delivery/assets/images/logo-admin'),
    ],
    # the master page (ETEL Group)
    'group': lambda: save_set('group-master', 'EtelGroup/icons', ''),
}

if __name__ == '__main__':
    want = sys.argv[1:] or list(GROUPS)
    for g in want:
        GROUPS[g]()
