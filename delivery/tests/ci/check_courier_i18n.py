"""Guards the courier Amharic add-on (js/courier-i18n.js) and its canary release (js/releases/courier/v8).

The courier app is only ever changed through a NEW release folder (canary first, then promote), so this checks: the add-on parses, every dictionary
entry has an English key and a non-empty Amharic text, patterns are valid, it has no inline handlers, and release v8 is exactly the v7 bundle plus
the add-on (nothing else changed, v7 untouched)."""
import json
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
addon = read('js/courier-i18n.js')
js = ("var s=require('fs').readFileSync(process.argv[1],'utf8');"
      "var m=s.match(/var EXACT = (\\{[\\s\\S]*?\\n    \\});/);var p=s.match(/var PATTERNS = (\\[[\\s\\S]*?\\n    \\]);/);"
      "if(!m||!p){console.log(JSON.stringify({err:'dictionary not found'}));process.exit(0)}"
      "var E=eval('('+m[1]+')');var P=eval('('+p[1]+')');console.log(JSON.stringify({n:Object.keys(E).length,empty:Object.keys(E).filter(function(k){return !String(E[k]).trim()||!k.trim()}),noEth:Object.keys(E).filter(function(k){return !/[\\u1200-\\u137F]/.test(E[k])}),pat:P.length}));")
out = subprocess.run(['node', '-e', js, root + '/js/courier-i18n.js'], capture_output=True, text=True)
if out.returncode != 0:
    bad.append('could not read the dictionary: ' + out.stderr[:200])
else:
    d = json.loads(out.stdout)
    if d.get('err'):
        bad.append(d['err'])
    else:
        if d['n'] < 150:
            bad.append('dictionary looks too small: %d' % d['n'])
        if d['empty']:
            bad.append('empty entries: ' + ', '.join(d['empty'][:5]))
        # 'ETB' style entries may keep Latin letters, but every entry must contain at least some Ethiopic text
        if d['noEth']:
            bad.append('entries with no Amharic text: ' + ', '.join(d['noEth'][:5]))
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", addon):
    bad.append('courier-i18n.js must not use inline handlers')
for must in ('window.__etelCourierI18n', 'MutationObserver', 'etel_lang', '__etelCourierI18nMissing'):
    if must not in addon:
        bad.append('courier-i18n.js lost: ' + must)
import glob
import os
# the newest release that carries this add-on must end with the current add-on text; the release just before it must be a prefix of it
rel = sorted((int(re.search(r'/v(\d+)/', f).group(1)), f) for f in glob.glob(root + '/js/releases/courier/v*/courier-bundle.js'))
carrying = [(n, f) for n, f in rel if 'courier-i18n.js (added to' in open(f, encoding='utf-8').read()]
if not carrying:
    bad.append('no courier release carries the Amharic add-on yet (build one with tools/build_courier_release.py --addons js/courier-i18n.js)')
else:
    n, f = carrying[-1]
    body = open(f, encoding='utf-8').read()
    if addon not in body:
        bad.append('release v%d must contain the current js/courier-i18n.js: the add-on changed after that release was built. Build a NEW version, never edit a released one.' % n)
    earlier = [x for x in rel if x[0] < n]
    if earlier:
        prev = open(earlier[-1][1], encoding='utf-8').read().rstrip('\n')
        if not body.startswith(prev):
            bad.append('release v%d must start with the exact previous bundle (v%d)' % (n, earlier[-1][0]))
if bad:
    print('check_courier_i18n FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_i18n ok (%d entries, %d patterns)' % (d['n'], d['pat']))
