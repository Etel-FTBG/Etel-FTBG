"""Guards the release folders: every app has the v1 baseline the loader falls back to, every release folder holds a script, and the
prune tool only lists (never deletes)."""
import os
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
bad = []
rel = os.path.join(root, 'js', 'releases')
for app in sorted(os.listdir(rel)):
    d = os.path.join(rel, app)
    if not os.path.isdir(d):
        continue
    if not os.path.isdir(os.path.join(d, 'v1')):
        bad.append('js/releases/%s/v1 is missing (release-loader.js falls back to it)' % app)
    for v in os.listdir(d):
        p = os.path.join(d, v)
        if os.path.isdir(p) and re.match(r'^v\d+$', v) and not any(f.endswith('.js') for f in os.listdir(p)):
            bad.append('js/releases/%s/%s has no .js file' % (app, v))
src = open(root + '/tools/prune_releases.py', encoding='utf-8').read()
for word in ('os.remove', 'os.unlink', 'shutil.rmtree', 'os.rmdir', 'rmtree('):
    if word in src:
        bad.append('tools/prune_releases.py must only list, found ' + word)
if bad:
    print('check_releases FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_releases OK')
