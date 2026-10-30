"""Guards the courier live-map-marker add-on (js/courier-marker-vehicle.js, courier release v13): it parses, has no inline handlers, only touches the bundle's own
emoji circle through the public Advanced Marker content property, and the release starts with the exact previous bundle (v12) and carries the current add-on
(releases are never edited after they go out: build a NEW version instead)."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
addon = read('js/courier-marker-vehicle.js')
for must in ('gmp-advanced-marker', 'EtelVehiclePic', 'data-vpic-marker', 'MutationObserver', 'borderTopColor'):
    if must not in addon:
        bad.append('courier-marker-vehicle.js lost: ' + must)
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", addon):
    bad.append('courier-marker-vehicle.js must not use inline handlers')
v13 = read('js/releases/courier/v13/courier-bundle.js')
v12 = read('js/releases/courier/v12/courier-bundle.js')
if not v13.startswith(v12.rstrip('\n')):
    bad.append('release v13 must start with the exact previous bundle (v12)')
if addon not in v13:
    bad.append('release v13 must contain the current js/courier-marker-vehicle.js: build a NEW version instead of editing a released one')
if 'release v13: js/courier-marker-vehicle.js (added to v12)' not in v13:
    bad.append('release v13 lost its header line (tools/build_courier_release.py --from v12 --to v13 --addons js/courier-marker-vehicle.js)')
if bad:
    print('check_courier_marker FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_marker ok')
