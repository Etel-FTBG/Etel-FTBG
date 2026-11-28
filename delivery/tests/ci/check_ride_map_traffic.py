"""Guards the rider map Traffic button (delivery/js/ride-map-traffic.js, 28 Nov 2026): the file exists, is plain ASCII (Amharic only as \\u escapes),
is small, uses Google's TrafficLayer and the right-centre control stack, remembers the choice, and the ride page loads it."""
import os

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
bad = []
p = os.path.join(D, 'js', 'ride-map-traffic.js')
if not os.path.exists(p):
    bad.append('js/ride-map-traffic.js is missing')
else:
    raw = open(p, 'rb').read()
    if any(b > 127 for b in raw):
        bad.append('js/ride-map-traffic.js must be ASCII only (use \\u escapes for Amharic)')
    if len(raw) > 30000:
        bad.append('js/ride-map-traffic.js is over 30 KB')
    t = raw.decode('ascii', 'replace')
    for needle in ('TrafficLayer', 'ControlPosition.RIGHT_CENTER', 'etel_ride_traffic', 'aria-pressed', 'window.__etelRideTraffic'):
        if needle not in t:
            bad.append('js/ride-map-traffic.js does not contain ' + needle)
page = open(os.path.join(D, 'ride', 'index.html'), encoding='utf-8', errors='replace').read()
if 'js/ride-map-traffic.js' not in page:
    bad.append('ride/index.html does not load js/ride-map-traffic.js')
if bad:
    print('FAIL check_ride_map_traffic:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_ride_map_traffic')
