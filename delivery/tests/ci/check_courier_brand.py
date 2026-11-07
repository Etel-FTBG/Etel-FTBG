"""Guards the courier Amharic brand-spelling add-on (release v14): js/courier-i18n-brand.js parses, is pure ASCII, uses no inline handlers, replaces exactly the old
spelling with the new one (and keeps the language add-on's own record in step), and the newest release carrying it ends with the exact current text and starts with
the exact previous bundle (a released bundle is never edited)."""
import glob
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(p, encoding='utf-8').read()
bad = []
addon = read(root + '/js/courier-i18n-brand.js')
if subprocess.call(['node', '--check', root + '/js/courier-i18n-brand.js']) != 0:
    bad.append('js/courier-i18n-brand.js does not parse')
if re.search(r'[^\x00-\x7f]', addon):
    bad.append('js/courier-i18n-brand.js must be pure ASCII (write the Amharic word as \\u escapes)')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", addon):
    bad.append('js/courier-i18n-brand.js must not use inline handlers')
for must in ("'\\u12a2\\u1274\\u120d'", "'\\u12a2\\u1275\\u12a4\\u120d'", '__etelAm', '__etelCourierBrand', 'MutationObserver', 'SCRIPT'):
    if must not in addon:
        bad.append('js/courier-i18n-brand.js lost: ' + must)
rel = sorted((int(re.search(r'/v(\d+)/', f).group(1)), f) for f in glob.glob(root + '/js/releases/courier/v*/courier-bundle.js'))
carrying = [(n, f) for n, f in rel if 'release v%d: js/courier-i18n-brand.js (added to' % n in read(f)]
if not carrying:
    bad.append('no courier release carries the brand add-on yet (python3 tools/build_courier_release.py --from <latest> --to <next> --addons js/courier-i18n-brand.js)')
else:
    n, f = carrying[-1]
    body = read(f)
    if not body.rstrip('\n').endswith(addon.rstrip('\n')):
        bad.append('release v%d must end with the current js/courier-i18n-brand.js: the add-on changed after that release was built. Build a NEW version, never edit a released one.' % n)
    earlier = [x for x in rel if x[0] < n]
    if earlier:
        prev = read(earlier[-1][1]).rstrip('\n')
        if not body.startswith(prev):
            bad.append('release v%d must start with the exact previous bundle (v%d)' % (n, earlier[-1][0]))
if bad:
    print('check_courier_brand FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_brand ok (release v%d)' % carrying[-1][0])
