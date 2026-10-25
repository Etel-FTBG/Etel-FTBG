"""Guards the courier add-on js/courier-fixes.js (release v9): it parses, has no inline handlers, pins pop-up colours, and the newest release
carrying it ends with the exact current text and starts with the exact previous bundle (releases are never edited after they go out)."""
import glob
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(p, encoding='utf-8').read()
bad = []
addon = read(root + '/js/courier-fixes.js')
for must in ('window.__etelCourierFixes', 'MutationObserver', "'important'", 'pkg-done-overlay', 'pkg-pin-overlay'):
    if must not in addon:
        bad.append('courier-fixes.js lost: ' + must)
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", addon):
    bad.append('courier-fixes.js must not use inline handlers')
rel = sorted((int(re.search(r'/v(\d+)/', f).group(1)), f) for f in glob.glob(root + '/js/releases/courier/v*/courier-bundle.js'))
# the release that introduced the add-on (its own header line), not later releases that merely build on it
carrying = [(n, f) for n, f in rel if 'release v%d: js/courier-fixes.js (added to' % n in read(f)]
if not carrying:
    bad.append('no courier release carries courier-fixes.js yet (tools/build_courier_release.py --addons js/courier-fixes.js)')
else:
    n, f = carrying[-1]
    body = read(f)
    if addon not in body:
        bad.append('release v%d must contain the current js/courier-fixes.js: build a NEW version instead of editing a released one' % n)
    earlier = [x for x in rel if x[0] < n]
    if earlier and not body.startswith(read(earlier[-1][1]).rstrip('\n')):
        bad.append('release v%d must start with the exact previous bundle (v%d)' % (n, earlier[-1][0]))
if bad:
    print('check_courier_fixes FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_fixes ok')
