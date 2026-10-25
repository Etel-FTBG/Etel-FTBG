"""Guards the 3D vehicle pictures (js/etel-vehicle-art.js, img/vehicles/): the add-on parses and has no inline handlers, the rider and driver pages load it,
the vehicle list and the map-marker hook use it, a missing picture can never leave an empty icon (every use has a fallback), and any picture that has
been added is a transparent PNG of a sensible size."""
import os
import re
import struct
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

art = read('js/etel-vehicle-art.js')
if subprocess.call(['node', '--check', root + '/js/etel-vehicle-art.js']) != 0:
    bad.append('js/etel-vehicle-art.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", art):
    bad.append('etel-vehicle-art.js must not use inline handlers')
types = re.search(r"TYPES\s*=\s*\[([^\]]*)\]", art)
types = re.findall(r"'(\w+)'", types.group(1)) if types else []
if sorted(types) != sorted(['bajaj', 'qute', 'moto', 'car', 'ev_car', 'minibus']):
    bad.append('the add-on must cover bajaj, qute, moto, car, ev_car and minibus; found %s' % types)
for must in ('EtelUI', 'vehicleMarker', 'google.maps', "'etel:vehicle-art'", 'toDataURL'):
    if must not in art:
        bad.append('etel-vehicle-art.js lost: ' + must)
if 'return m || orig.apply(this, arguments)' not in art:
    bad.append('the marker wrapper must fall back to the drawn badge when there is no picture')

for page in ('ride/index.html', 'driver/index.html'):
    if 'js/etel-vehicle-art.js' not in read(page):
        bad.append(page + ' does not load js/etel-vehicle-art.js')
ride = read('ride/index.html')
if ride.find('etel-vehicle-art.js') > ride.find('ride-vehicle-list.js'):
    bad.append('etel-vehicle-art.js must load before ride-vehicle-list.js')
lst = read('js/ride-vehicle-list.js')
if 'EtelVehicleArt' not in lst or 'EtelUI.vehicleGlyph' not in lst:
    bad.append('the vehicle list must use the picture when there is one and the drawn icon otherwise')
if "'etel:vehicle-art'" not in lst:
    bad.append('the vehicle list must redraw when a picture finishes loading')

# pictures that exist must be usable: transparent PNG, square-ish, big enough, small enough
d = os.path.join(root, 'img', 'vehicles')
for f in sorted(os.listdir(d)) if os.path.isdir(d) else []:
    if not f.endswith('.png'):
        continue
    if f[:-4] not in types:
        bad.append('img/vehicles/%s is not a known vehicle type (%s)' % (f, ', '.join(types)))
    b = open(os.path.join(d, f), 'rb').read()
    if b[:8] != b'\x89PNG\r\n\x1a\n':
        bad.append('img/vehicles/%s is not a PNG' % f)
        continue
    w, h, depth, ctype = struct.unpack('>IIBB', b[16:26])
    has_alpha = ctype in (4, 6) or (ctype in (0, 2, 3) and b'tRNS' in b[:4096 + len(b) // 2])
    if not has_alpha:
        bad.append('img/vehicles/%s has no transparency (needs a transparent background)' % f)
    if w < 256 or h < 192 or w > 1024 or h > 1024 or abs(w - h) > max(w, h) * 0.35:
        bad.append('img/vehicles/%s is %dx%d: use about 512x512' % (f, w, h))
    if len(b) > 250 * 1024:
        bad.append('img/vehicles/%s is %d KB: keep each under 250 KB' % (f, len(b) // 1024))

if bad:
    print('check_vehicle_art FAILED:')
    for x in bad:
        print(' -', x)
    sys.exit(1)
print('check_vehicle_art ok')
