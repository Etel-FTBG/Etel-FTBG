#!/usr/bin/env python3
"""Builds a new courier app release folder: the previous release's bundle plus one or more readable add-on files (default js/courier-packages.js).

Why not rebuild from js/courier.js: the shipped bundles (v5 and later) carry code that is not in the plain source files any more, so a rebuild
from source would silently drop it. A release is therefore "previous release + a readable add-on file".

    python3 tools/build_courier_release.py --from v5 --to v7   (v6 = v5 + the first version of the add-on; v7 = v5 + the current one)
    python3 tools/build_courier_release.py --from v7 --to v8 --addons js/courier-i18n.js   (v8 = v7 + the Amharic add-on)

Writes js/releases/courier/<to>/courier-bundle.js. Then (no SSH needed): upload the folder, Admin > Releases > set it as the CANARY for the
courier app, add a few testers, check, and only then promote it to stable. A release folder is never overwritten once it exists.
"""
import argparse
import os
import sys

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ap = argparse.ArgumentParser()
ap.add_argument('--from', dest='src', required=True)
ap.add_argument('--to', dest='dst', required=True)
ap.add_argument('--addons', default='js/courier-packages.js', help='comma-separated add-on files, relative to delivery/')
a = ap.parse_args()
src = os.path.join(root, 'js/releases/courier', a.src, 'courier-bundle.js')
dst_dir = os.path.join(root, 'js/releases/courier', a.dst)
dst = os.path.join(dst_dir, 'courier-bundle.js')
if not os.path.isfile(src):
    sys.exit('missing ' + src)
if os.path.exists(dst):
    sys.exit('release ' + a.dst + ' already exists: pick a new version name (releases are never overwritten)')
base = open(src, encoding='utf-8').read()
addons = [x.strip() for x in a.addons.split(',') if x.strip()]
out = base.rstrip('\n')
for rel in addons:
    path = os.path.join(root, rel)
    if not os.path.isfile(path):
        sys.exit('missing add-on ' + rel)
    out += '\n\n/* ---- release ' + a.dst + ': ' + rel + ' (added to ' + a.src + ') ---- */\n' + open(path, encoding='utf-8').read()
os.makedirs(dst_dir)
with open(dst, 'w', encoding='utf-8') as f:
    f.write(out)
print('wrote', os.path.relpath(dst, root), os.path.getsize(dst), 'bytes')
