"""Make the ETEL app's own pictures (Etel-Driver-App/assets) from the ETEL GROUP master logo, Dagi's choice 27 Nov 2026.
Run from the repo root:  python3 tools/brand/make_app_icons.py     (needs Pillow and numpy)
Source: EtelGroup/icons/brand/etel-group-master.webp (transparent badge).
  icon.png (1024)                    store / iOS icon: badge on the navy square
  android-icon-foreground.png        adaptive icon: transparent badge inside the 66 percent safe zone
  android-icon-background.png        adaptive icon background: the navy square
  android-icon-monochrome.png        themed icon + notification icon: white shape of the badge
  splash-icon.png, splash-logo.png   opening splash and login logo (384, transparent)
  etel-master.png                    logo on the hub, the loader and the intro (384, transparent)
  favicon.png                        196
The JavaScript pictures (etel-master, splash-logo) reach phones over the air; icon, adaptive icon and notification icon need a new APK."""
import sys
sys.path.insert(0, 'tools/brand')
import numpy as np
from PIL import Image
from make_system_icons import navy

A = 'Etel-Driver-App/assets/'
badge = Image.open('EtelGroup/icons/brand/etel-group-master.webp').convert('RGBA')

def fit(size, scale):
    return badge.resize((int(round(size * scale)),) * 2, Image.LANCZOS)

def on(canvas, im):
    o = (canvas.size[0] - im.size[0]) // 2
    canvas.alpha_composite(im, (o, o))
    return canvas


ic = on(navy(1024), fit(1024, 0.88)).convert('RGB'); ic.save(A + 'icon.png', optimize=True)
fg = on(Image.new('RGBA', (1024, 1024), (0, 0, 0, 0)), fit(1024, 0.64)); fg.save(A + 'android-icon-foreground.png', optimize=True)
navy(1024).save(A + 'android-icon-background.png', optimize=True)
# monochrome: white where the badge is bright (gold ring, letters, mark), see-through where it is dark navy
b = fit(1024, 0.64); arr = np.asarray(b).astype(np.float32)
lum = arr[..., :3].mean(axis=2)
alpha = np.clip((lum - 55.0) / 90.0, 0, 1) * (arr[..., 3] / 255.0)
mono = np.zeros((1024, 1024, 4), np.uint8); off = (1024 - b.size[0]) // 2
sl = mono[off:off + b.size[1], off:off + b.size[0]]
sl[..., :3] = 255; sl[..., 3] = (alpha * 255).astype(np.uint8)
Image.fromarray(mono, 'RGBA').save(A + 'android-icon-monochrome.png', optimize=True)
for f in ('splash-icon.png', 'splash-logo.png', 'etel-master.png'):
    badge.resize((384, 384), Image.LANCZOS).save(A + f, optimize=True)
badge.resize((196, 196), Image.LANCZOS).save(A + 'favicon.png', optimize=True)
print('done')
