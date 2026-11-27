"""Make the pictures on the ETEL app launcher (Etel-Driver-App/assets/apps/*.png, 256 x 256, transparent) from the brand files already in the repo.
Run from the repo root:  python3 tools/brand/make_launcher_icons.py     (needs Pillow)
  ride, driver, customer (Delivery)  <- the new transport logo   delivery/assets/images/etel-ride-coin.png   (same logo for all three, Dagi's choice 27 Nov 2026)
  pay, gebeya, madeya, id            <- the family badges        EtelGroup/icons/brand/etel-<name>.webp
  courier, service                   <- not touched (no new logo yet)
The pictures are bundled JavaScript assets: phones get them over the air (publish-update.yml), no new APK."""
from PIL import Image

OUT = 'Etel-Driver-App/assets/apps/%s.png'
SRC = {
    'ride': 'delivery/assets/images/etel-ride-coin.png',
    'driver': 'delivery/assets/images/etel-ride-coin.png',
    'customer': 'delivery/assets/images/etel-ride-coin.png',
    'pay': 'EtelGroup/icons/brand/etel-pay.webp',
    'gebeya': 'EtelGroup/icons/brand/etel-gebeya.webp',
    'madeya': 'EtelGroup/icons/brand/etel-madeya.webp',
    'id': 'EtelGroup/icons/brand/etel-id.webp',
}
for k, p in SRC.items():
    im = Image.open(p).convert('RGBA').resize((256, 256), Image.LANCZOS)
    im.save(OUT % k, optimize=True)
    print(k, '<-', p)
