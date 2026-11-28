"""Guards the courier hot-area hexagons and traffic button (28 Nov 2026):
- api courier_get_demand exists in OrderController.php, is routed in boot.php, and returns areas / hexes (q, r, heat) / size_m like driver_get_demand;
- js/courier-demand.js is ASCII only, small, and uses the SAME hexagon origin, gold range and corner maths as js/driver-demand.js (so the two maps look the same);
- both courier pages load etel-map-traffic.js (data-app="courier") and courier-demand.js;
- the courier map is found through the app's own top-level `map` variable (as the courier bundle declares it)."""
import os
import re

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
bad = []

def rd(rel):
    return open(os.path.join(D, rel), encoding='utf-8', errors='replace').read()

ctl = rd('api/controllers/OrderController.php')
i = ctl.find("case 'courier_get_demand'")
j = ctl.find("case 'courier_get_profile'", i)
if i < 0:
    bad.append("OrderController.php has no case 'courier_get_demand'")
else:
    seg = ctl[i:j]
    for needle in ("require_courier($pdo)", "hexgrid_cell_for", "'hexes' =>", "'heat' =>", "'size_m' =>", "'areas' => array_slice($out, 0, 8)", "'q' => $c['q']", "(o.courier_id IS NULL OR o.courier_id = 0)", "status = 'online'"):
        if needle not in seg:
            bad.append('courier_get_demand does not contain ' + needle)
    for banned in ('recipient_phone', 'recipient_name', 'customer_id', 'order_code'):
        if banned in seg:
            bad.append('courier_get_demand must return counts only, but it mentions ' + banned)
if "case 'courier_get_demand':" not in rd('api/boot.php'):
    bad.append("api/boot.php does not route 'courier_get_demand'")

p = os.path.join(D, 'js', 'courier-demand.js')
if not os.path.exists(p):
    bad.append('js/courier-demand.js is missing')
else:
    raw = open(p, 'rb').read()
    if any(b > 127 for b in raw):
        bad.append('js/courier-demand.js must be ASCII only (Amharic as \\u escapes)')
    if len(raw) > 30000:
        bad.append('js/courier-demand.js is over 30 KB')
    cd = raw.decode('ascii', 'replace')
    dd = rd('js/driver-demand.js')
    for pat in (r'HEX_LAT0 = 7\.0621, HEX_LNG0 = 38\.4761, EARTH_R = 6371000\.0', r'GOLD_LO = \[154, 122, 30\], GOLD_HI = \[255, 226, 122\]'):
        if not re.search(pat, cd) or not re.search(pat, dd):
            bad.append('courier-demand.js and driver-demand.js differ on: ' + pat)
    # the formula lines that matter, in both files (the driver file calls the cell size `size`, the courier file `sz`)
    pairs = (('function hexCorners', ('Math.PI / 180 * 60 * i', 'sz * 1.5 * q', 'Math.sqrt(3) / 2 * q + Math.sqrt(3) * r')),
             ('function goldFor', ('GOLD_HI[i] - v', 'Math.round(v + ')))
    for fn, keys in pairs:
        a = cd.find(fn)
        b = dd.find(fn)
        if a < 0 or b < 0:
            bad.append('missing ' + fn)
            continue
        for key in keys:
            if key not in cd[a:a + 800] or key.replace('sz', 'size') not in dd[b:b + 800]:
                bad.append('%s: formula differs between courier-demand.js and driver-demand.js (%s)' % (fn, key))
    for needle in ('courier_get_demand', 'COURIER_IS_ONLINE', 'typeof map', 'ControlPosition.LEFT_TOP', 'etel_courier_hot_areas', 'window.__etelCourierDemand', 'google.maps.Polygon'):
        if needle not in cd:
            bad.append('js/courier-demand.js does not contain ' + needle)
    if re.search(r'innerHTML\s*=\s*[^\'"\s]', cd.replace("card.innerHTML = '';", '')):
        bad.append('courier-demand.js writes something other than an empty string with innerHTML')
for page in ('courier/index.html', 'courier/courier-index.html'):
    t = rd(page)
    if not re.search(r'<script src="\.\./js/etel-map-traffic\.js\?v=[0-9.]+" data-app="courier" defer></script>', t):
        bad.append(page + ' does not load etel-map-traffic.js with data-app="courier"')
    if 'js/courier-demand.js' not in t:
        bad.append(page + ' does not load js/courier-demand.js')
tr = rd('js/etel-map-traffic.js')
if 'typeof map !== "undefined"' not in tr:
    bad.append('js/etel-map-traffic.js does not look for the courier app\'s own map variable')
if bad:
    print('FAIL check_courier_hot_areas:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_courier_hot_areas')
