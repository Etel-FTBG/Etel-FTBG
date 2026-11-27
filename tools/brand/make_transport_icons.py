"""Make every Delivery / Ride / Driver app picture from ONE source logo (delivery/icons/source/etel-transport-logo.jpg: the gold ring, blue car, parcels, runner, ETEL on black marble).
Run from the repo root:  python3 tools/brand/make_transport_icons.py        (needs Pillow and numpy)
Writes (same file names the manifests and pages already use):
  delivery/icons/{icon,ride-icon,driver-icon}-{72,96,128,144,152,192,384,512}.png        square, marble kept (purpose "any")
  delivery/icons/{icon,ride-icon,driver-icon}-{192,512}-maskable.png                     logo inside the 80 percent safe circle, marble extended
  delivery/icons/{,ride-,driver-}apple-touch-icon.png                                    180 px
  delivery/icons/{splash,ride-splash}-WxH.png                                            the old coin is covered by the new logo (same size files already in the repo are rewritten)
  delivery/assets/images/etel-{ride,driver,delivery}-coin.{png,webp}, etel-delivery-coin-crop.{png,webp}, delivery/icons/etel-*-coin.*   transparent cut-out for the logo boxes
The three apps get the SAME logo (Dagi's choice, 27 Nov 2026). The ring is a tilted ellipse, so it is fitted as an ellipse, not a circle."""
import glob, os, re
import numpy as np
from PIL import Image

SRC = 'delivery/icons/source/etel-transport-logo.jpg'
IMG = Image.open(SRC).convert('RGB')
A = np.asarray(IMG).astype(np.float32)
H, W, _ = A.shape
R_, G_, B_ = A[..., 0], A[..., 1], A[..., 2]
MX = A.max(2)
WARM = (R_ - B_) / np.maximum(MX, 1)

def fit_ellipse():
    warm = (WARM > 0.2) & (MX > 110)
    cx0, cy0 = W * 0.517, H * 0.493
    pts = []
    for t in np.linspace(0, 2 * np.pi, 720, endpoint=False):
        rr = np.arange(300, 470)
        xs = (cx0 + rr * np.cos(t)).round().astype(int); ys = (cy0 + rr * np.sin(t)).round().astype(int)
        ok = (xs >= 0) & (xs < W) & (ys >= 0) & (ys < H)
        rr, xs, ys = rr[ok], xs[ok], ys[ok]
        v = np.nonzero(warm[ys, xs])[0]
        if len(v) >= 6:
            pts.append((xs[v.max()], ys[v.max()]))
    x, y = np.array(pts, float).T
    def fit(x, y):
        M = np.c_[x ** 2, y ** 2, x, y]
        c, *_ = np.linalg.lstsq(M, np.ones_like(x), rcond=None)
        a, b, c1, d = c
        x0 = -c1 / (2 * a); y0 = -d / (2 * b); k = 1 + a * x0 ** 2 + b * y0 ** 2
        return x0, y0, np.sqrt(k / a), np.sqrt(k / b)
    for _ in range(8):
        x0, y0, ax, ay = fit(x, y)
        d = np.abs(np.hypot((x - x0) / ax, (y - y0) / ay) - 1) * np.mean([ax, ay])
        keep = d < max(3, np.percentile(d, 75) * 1.6)
        x, y = x[keep], y[keep]
    return fit(x, y)

X0, Y0, AX, AY = fit_ellipse()
print('ring ellipse: centre %.1f,%.1f semi-axes %.1f x %.1f' % (X0, Y0, AX, AY))

def cutout():
    """transparent logo: inside the ring opaque, outside only the warm gold bits (the speed lines)"""
    warm = (WARM > 0.2) & (MX > 110)
    N = 1440
    th = np.linspace(0, 2 * np.pi, N, endpoint=False)
    edge = np.zeros(N)
    for i, t in enumerate(th):
        er = 1 / np.sqrt((np.cos(t) / AX) ** 2 + (np.sin(t) / AY) ** 2)
        rr = np.arange(er - 26, er + 14)
        xs = np.clip((X0 + rr * np.cos(t)).round().astype(int), 0, W - 1); ys = np.clip((Y0 + rr * np.sin(t)).round().astype(int), 0, H - 1)
        v = np.nonzero(warm[ys, xs])[0]
        edge[i] = rr[v.max()] if len(v) else er - 4
    pad = np.concatenate([edge[-40:], edge, edge[:40]]); sm = np.array([np.median(pad[i:i + 81]) for i in range(N)])
    pad = np.concatenate([sm[-20:], sm, sm[:20]]); sm = np.array([pad[i:i + 41].mean() for i in range(N)])
    yy, xx = np.mgrid[0:H, 0:W]
    ang = (np.arctan2(yy - Y0, xx - X0) % (2 * np.pi)) / (2 * np.pi) * N
    e = np.interp(ang, np.arange(N + 1), np.append(sm, sm[0]))
    rad = np.hypot(xx - X0, yy - Y0)
    inside = np.clip((e + 0.5 - rad) / 1.6 + 0.5, 0, 1)
    bgc = np.array([12, 13, 18], np.float32)
    out_a = np.clip((MX - 60) / 120, 0, 1) * np.clip((WARM - 0.14) / 0.14, 0, 1)
    out_a = np.where(rad > e, out_a, 0)
    alpha = np.maximum(inside, out_a * (1 - inside))
    Aa = np.maximum(out_a, 1e-3)[..., None]
    un = np.clip((A - (1 - Aa) * bgc) / Aa, 0, 255)
    col = np.where(((inside < 1) & (out_a > 0))[..., None], un, A)
    return Image.fromarray(np.dstack([col, alpha * 255]).clip(0, 255).astype(np.uint8), 'RGBA')

CUT = cutout()
HALF = 440
CUT_SQ = CUT.crop((int(round(X0 - HALF)), int(round(Y0 - HALF)), int(round(X0 + HALF)), int(round(Y0 + HALF))))   # ring centre = picture centre

def pal(im):
    return im.convert('RGB').quantize(256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG)

# 1. app icons
ANY = IMG.crop((int(round(X0 - 455)), int(round(Y0 - 455)), int(round(X0 + 455)), int(round(Y0 + 455))))
P = 80
padded = Image.fromarray(np.pad(A.astype(np.uint8), ((P, P), (P, P), (0, 0)), mode='reflect'))
MASK = padded.crop((int(round(X0 + P - 530)), int(round(Y0 + P - 530)), int(round(X0 + P + 530)), int(round(Y0 + P + 530))))
for pre in ('', 'ride-', 'driver-'):
    for s in (72, 96, 128, 144, 152, 192, 384, 512):
        pal(ANY.resize((s, s), Image.LANCZOS)).save('delivery/icons/%sicon-%d.png' % (pre, s), optimize=True)
    for s in (192, 512):
        pal(MASK.resize((s, s), Image.LANCZOS)).save('delivery/icons/%sicon-%d-maskable.png' % (pre, s), optimize=True)
    pal(ANY.resize((180, 180), Image.LANCZOS)).save('delivery/icons/%sapple-touch-icon.png' % pre, optimize=True)

# 2. start screens: lay the new logo over the old coin (centre 50 percent across, 44.9 percent down, 59 percent of the width wide)
for f in sorted(glob.glob('delivery/icons/splash-*.png') + glob.glob('delivery/icons/ride-splash-*.png')):
    sp = Image.open(f).convert('RGBA')
    w, h = sp.size
    scale = (0.66 * w) / (2 * AX)
    side = int(round(2 * HALF * scale))
    logo = CUT_SQ.resize((side, side), Image.LANCZOS)
    sp.alpha_composite(logo, (int(round(0.5 * w - side / 2)), int(round(0.449 * h - side / 2))))
    pal(sp).save(f, optimize=True)

# 3. logo boxes inside the apps (transparent, square, ring centred)
coin = CUT_SQ.resize((640, 640), Image.LANCZOS)
for d in ('delivery/assets/images/', 'delivery/icons/'):
    for k in ('ride', 'driver', 'delivery'):
        for ext in ('png', 'webp'):
            p = d + 'etel-%s-coin.%s' % (k, ext)
            if d.endswith('icons/') and not os.path.exists(p):
                continue          # in icons/ only the pictures that already exist are refreshed
            coin.save(p, optimize=True) if ext == 'png' else coin.save(p, 'WEBP', quality=90, method=6)
coin.save('delivery/assets/images/etel-delivery-coin-crop.webp', 'WEBP', quality=90, method=6)
coin.save('delivery/assets/images/etel-delivery-coin-crop.png', optimize=True)
print('done')
