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

def save_tab(name, out, size=96):
    """a small transparent logo for the role buttons on the login page (and the 'One ID. All Services.' list)"""
    Image.open(BRAND % name).convert('RGBA').resize((size, size), Image.LANCZOS).save(out, optimize=True)
    print('tab logo', name, '->', out)


def save_ico(name, out, sizes=(16, 32, 48)):
    """favicon.ico with several sizes (badge on the navy square, like the PNG icons)"""
    badge = Image.open(BRAND % name).convert('RGBA')
    big = icon(badge, 256).convert('RGBA')
    big.save(out, format='ICO', sizes=[(n, n) for n in sizes])
    print('favicon', name, '->', out)

def save_plain(name, out, size=512):
    """a transparent badge picture, same file name the system already used for its logo"""
    im = Image.open(BRAND % name).convert('RGBA').resize((size, size), Image.LANCZOS)
    if out.endswith('.webp'):
        im.save(out, 'WEBP', quality=90, method=6, exact=True)
    else:
        im.save(out, optimize=True)
    print('logo', name, '->', out)

def family():
    """Etel.Pay, EtelAi, EtelERP, EtelMadeya, EtelGebeya, EtelId and EtelTaxi: each system's OWN icon set and logo (28 Nov 2026)"""
    P = 'Etel.Pay/wallet'
    save_set('pay', P + '/icons', '', maskable=True)                       # icons/icon-N.png, apple-touch-icon.png, icon-192/512-maskable.png
    save_set('pay', P, '', maskable=False)                                 # the same files at the wallet root (older pages still ask for them)
    save_plain('pay', P + '/icons/logo.png', 300); save_plain('pay', P + '/logo.png', 300)
    save_ico('pay', P + '/favicon.ico')
    save_set('ai', 'EtelAi/icons', '', sizes=(192, 512), maskable=True, apple=None)
    save_plain('ai', 'EtelAi/icons/etel-logo.png', 192); save_plain('ai', 'EtelAi/icons/etel-logo-full.png', 192)
    save_set('erp', 'EtelERP/assets/icons', '', sizes=(192, 512), maskable=True)
    save_plain('erp', 'EtelERP/assets/icons/logo.png', 256)
    save_set('madeya', 'EtelMadeya/assets/images', 'madeya-', sizes=(192, 512), maskable=True)
    save_plain('madeya', 'EtelMadeya/assets/img/etel-logo.png', 512); save_plain('madeya', 'EtelMadeya/assets/images/icon.png', 512)
    save_set('gebeya', 'EtelGebeya/public', '', sizes=(96, 192, 512), maskable=True)
    save_plain('gebeya', 'EtelGebeya/public/assets/img/etel-master-seal.webp', 512)
    save_set('id', 'EtelId/public/images', 'etelid-', sizes=(96, 192, 512), maskable=False)
    save_set('taxi', 'EtelTaxi/assets/images', 'taxi-', sizes=(96, 192, 512), maskable=True)
    save_plain('taxi', 'EtelTaxi/assets/images/etel-logo.png', 512)


START_SIZES = ((640, 1136), (750, 1334), (1242, 2208), (1125, 2436), (828, 1792), (1242, 2688), (1170, 2532), (1284, 2778), (1179, 2556), (1290, 2796),
               (1536, 2048), (1668, 2388), (2048, 2732))

def start_screens():
    """iOS start screens (icons/<role>-splash-WxH.jpg): the role's badge on the navy glow, for Courier, Service Giver, Admin and Agent"""
    for name, role in (('courier', 'courier'), ('service', 'service'), ('admin', 'admin'), ('agent', 'agent')):
        badge = Image.open(BRAND % name).convert('RGBA')
        for w, h in START_SIZES:
            y, x = np.mgrid[0:h, 0:w].astype(np.float32)
            d = np.clip(np.hypot((x - w * 0.5) / w, (y - h * 0.44) / h) / 0.62, 0, 1)[..., None]
            c0 = np.array([30, 46, 92], np.float32); c1 = np.array([6, 10, 26], np.float32)
            bg = Image.fromarray((c0 * (1 - d) + c1 * d).clip(0, 255).astype(np.uint8), 'RGB').convert('RGBA')
            side = int(round(min(0.62 * w, 0.40 * h)))
            b = badge.resize((side, side), Image.LANCZOS)
            bg.alpha_composite(b, (int(round(w / 2 - side / 2)), int(round(h * 0.44 - side / 2))))
            bg.convert('RGB').save('delivery/icons/%s-splash-%dx%d.jpg' % (role, w, h), 'JPEG', quality=84, optimize=True, progressive=True)
        print('start screens', role)

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
        [save_tab(n, 'delivery/assets/images/tab-%s.png' % o) for n, o in (('transport', 'customer'), ('transport', 'ride'), ('courier', 'courier'), ('transport', 'driver'),
                                                                          ('service', 'service'), ('pay', 'pay'), ('id', 'id'))],
    ],
    # the master page (ETEL Group)
    'group': lambda: save_set('group-master', 'EtelGroup/icons', ''),
    # the other systems (Pay, Ai, ERP, Madeya, Gebeya, Id, Taxi)
    'family': family,
    'startscreens': start_screens,
}

if __name__ == '__main__':
    want = sys.argv[1:] or list(GROUPS)
    for g in want:
        GROUPS[g]()
