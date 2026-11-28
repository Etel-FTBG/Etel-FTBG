"""Guards the driver hexagon heat map and the driver/courier traffic button (28 Nov 2026):
- api driver_get_demand returns 'hexes' (axial q, r), 'heat', 'size_m' and 'surge_now' and still returns 'areas' (the list in the panel);
- js/driver-demand.js draws hexagons (Polygon) with the gold scale, keeps the circle fallback for an old server, and is loaded with a newer ?v=;
- js/etel-map-traffic.js exists (ASCII only, small, one file for both apps) and the driver page loads it with data-app="driver"."""
import os
import re

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
bad = []

def rd(rel):
    return open(os.path.join(D, rel), encoding='utf-8', errors='replace').read()

ctl = rd('api/controllers/RideController.php')
i = ctl.find("case 'driver_get_demand'")
j = ctl.find("case 'driver_get_nearby_requests'", i)
seg = ctl[i:j]
for needle in ("'hexes' =>", "'heat' =>", "'size_m' =>", "'surge_now' =>", "'areas' => array_slice($out, 0, 8)", "'q' => $c['q']", "rides_active_peak_multiplier"):
    if needle not in seg:
        bad.append('driver_get_demand does not contain ' + needle)
js = rd('js/driver-demand.js')
for needle in ('google.maps.Polygon', 'function goldFor', 'function hexCorners', 'meta.hexes', 'google.maps.Circle', 'surgePill'):
    if needle not in js:
        bad.append('js/driver-demand.js does not contain ' + needle)
if 'HEX_LAT0 = 7.0621' not in js or 'HEX_LNG0 = 38.4761' not in js:
    bad.append('js/driver-demand.js hex origin differs from api/lib/hexgrid.php')
hg = rd('api/lib/hexgrid.php')
if "define('HEXGRID_ORIGIN_LAT', 7.0621)" not in hg or "define('HEXGRID_ORIGIN_LNG', 38.4761)" not in hg:
    bad.append('api/lib/hexgrid.php origin changed: update js/driver-demand.js too')
page = rd('driver/index.html')
m = re.search(r'js/driver-demand\.js\?v=([0-9.]+)', page)
if not m or float(m.group(1)) < 3:
    bad.append('driver/index.html should load driver-demand.js?v=3 or newer')
if not re.search(r'<script src="\.\./js/etel-map-traffic\.js\?v=[0-9.]+" data-app="driver" defer></script>', page):
    bad.append('driver/index.html does not load etel-map-traffic.js with data-app="driver"')
p = os.path.join(D, 'js', 'etel-map-traffic.js')
if not os.path.exists(p):
    bad.append('js/etel-map-traffic.js is missing')
else:
    raw = open(p, 'rb').read()
    if any(b > 127 for b in raw):
        bad.append('js/etel-map-traffic.js must be ASCII only')
    if len(raw) > 30000:
        bad.append('js/etel-map-traffic.js is over 30 KB')
    t = raw.decode('ascii', 'replace')
    for needle in ('TrafficLayer', 'ControlPosition.RIGHT_CENTER', 'data-app', '__etelCourierMap', 'DRV'):
        if needle not in t:
            bad.append('js/etel-map-traffic.js does not contain ' + needle)
if bad:
    print('FAIL check_driver_hex_heat:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_driver_hex_heat')
