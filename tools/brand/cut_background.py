"""Cut the marble background out of an ETEL round badge (gold ring + navy disc + thin orbit ring with dots) and write a transparent PNG.
Usage: python3 tools/brand/cut_background.py in.jpg out.png [cx cy]      (cx, cy = rough centre of the disc in pixels, default = picture centre)
How it works: fit a circle to the OUTER edge of the thick gold ring, keep everything inside it, and outside it keep only warm gold pixels
(the thin orbit line and its dots), un-mixed from the dark marble so there is no dark fringe. Needs Pillow and numpy.
The cream line "part of ETEL GROUP" under the ring is dropped (cream text on a light page cannot be read); add it as real text in HTML.
Tested on the Group, Pay and Gebeya badges (1024 px) and the 480 px Group badge. For a very different picture check the printed circle."""
import sys
import numpy as np
from PIL import Image

def fit_circle(x, y):
    A = np.c_[2 * x, 2 * y, np.ones_like(x)]
    c, *_ = np.linalg.lstsq(A, x ** 2 + y ** 2, rcond=None)
    return c[0], c[1], float(np.sqrt(c[2] + c[0] ** 2 + c[1] ** 2))

def cut(img, cx0, cy0, drop_caption=True):
    a = np.asarray(img.convert('RGB')).astype(np.float32)
    H, W, _ = a.shape
    r, b = a[..., 0], a[..., 2]
    mx = a.max(2)
    w = (r - b) / np.maximum(mx, 1)
    warm = (w > 0.18) & (mx > 70)
    lo, hi = int(0.33 * W), int(0.40 * W)
    rr = np.arange(lo, hi)
    pts = []
    for t in np.linspace(0, 2 * np.pi, 720, endpoint=False):
        xs = np.clip((cx0 + rr * np.cos(t)).round().astype(int), 0, W - 1)
        ys = np.clip((cy0 + rr * np.sin(t)).round().astype(int), 0, H - 1)
        v = np.nonzero(warm[ys, xs])[0]
        if len(v) >= 8:
            pts.append((cx0 + rr[v.max()] * np.cos(t), cy0 + rr[v.max()] * np.sin(t)))
    px, py = np.array(pts).T
    for _ in range(6):
        cx, cy, R = fit_circle(px, py)
        res = np.abs(np.hypot(px - cx, py - cy) - R)
        keep = res < max(3, np.percentile(res, 70) * 1.5)
        px, py = px[keep], py[keep]
    cx, cy, R = fit_circle(px, py)
    print('circle centre %.1f, %.1f radius %.1f (from %d edge points)' % (cx, cy, R, len(px)))
    yy, xx = np.mgrid[0:H, 0:W]
    rad = np.hypot(xx - cx, yy - cy)
    inside = np.clip((R + 0.5 - rad) / 1.6 + 0.5, 0, 1)
    bgc = np.array([10, 14, 20], np.float32)
    out_a = np.clip((mx - 45) / 130, 0, 1) * np.clip((w - 0.10) / 0.12, 0, 1)
    out_a = np.where(rad > R, out_a, 0)
    if drop_caption:
        out_a = np.where(yy > cy + R * 1.17, 0, out_a)
    alpha = np.maximum(inside, out_a * (1 - inside))
    A = np.maximum(out_a, 1e-3)[..., None]
    un = np.clip((a - (1 - A) * bgc) / A, 0, 255)
    col = np.where(((inside < 1) & (out_a > 0))[..., None], un, a)
    return Image.fromarray(np.dstack([col, alpha * 255]).clip(0, 255).astype(np.uint8), 'RGBA'), (cx, cy, R)

if __name__ == '__main__':
    src = Image.open(sys.argv[1])
    cx0 = float(sys.argv[3]) if len(sys.argv) > 4 else src.size[0] / 2
    cy0 = float(sys.argv[4]) if len(sys.argv) > 4 else src.size[1] / 2
    out, (cx, cy, R) = cut(src, cx0, cy0)
    half = int(R * 1.2)
    out.crop((int(round(cx - half)), int(round(cy - half)), int(round(cx + half)), int(round(cy + half)))).save(sys.argv[2])
