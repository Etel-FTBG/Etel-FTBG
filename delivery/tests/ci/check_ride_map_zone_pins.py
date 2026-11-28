"""Guards the rider map quick-zone pins (delivery/js/ride-map-zone-pins.js, 28 Nov 2026): the file exists, is plain ASCII (Amharic only as \\u escapes), is small,
reads the same places API as the Quick zones box, writes place names with textContent (never innerHTML), remembers the choice, and the ride page loads it."""
import os
import re

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
bad = []
p = os.path.join(D, 'js', 'ride-map-zone-pins.js')
if not os.path.exists(p):
    bad.append('js/ride-map-zone-pins.js is missing')
else:
    raw = open(p, 'rb').read()
    if any(b > 127 for b in raw):
        bad.append('js/ride-map-zone-pins.js must be ASCII only (use \\u escapes for Amharic)')
    if len(raw) > 30000:
        bad.append('js/ride-map-zone-pins.js is over 30 KB')
    t = raw.decode('ascii', 'replace')
    for needle in ('get_area_places', 'ControlPosition.RIGHT_CENTER', 'etel_ride_zone_pins', 'window.__etelZonePins', 'setPickup', 'setDropoff', 'textContent'):
        if needle not in t:
            bad.append('js/ride-map-zone-pins.js does not contain ' + needle)
    # place names must never go through innerHTML (only the fixed button picture may)
    for m in re.finditer(r'innerHTML\s*=\s*([^;]+);', t):
        if 'pname' in m.group(1) or '.name' in m.group(1):
            bad.append('a place name is written with innerHTML: ' + m.group(0)[:80])
page = open(os.path.join(D, 'ride', 'index.html'), encoding='utf-8', errors='replace').read()
if 'js/ride-map-zone-pins.js' not in page:
    bad.append('ride/index.html does not load js/ride-map-zone-pins.js')
if bad:
    print('FAIL check_ride_map_zone_pins:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_ride_map_zone_pins')
