#!/usr/bin/env python3
"""Builds a new courier app release folder: the previous release's bundle plus js/courier-packages.js.

Why not rebuild from js/courier.js: the shipped bundles (v5 and later) carry code that is not in the plain source files any more, so a rebuild
from source would silently drop it. A release is therefore "previous release + a readable add-on file".

    python3 tools/build_courier_release.py --from v5 --to v6

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
a = ap.parse_args()
src = os.path.join(root, 'js/releases/courier', a.src, 'courier-bundle.js')
dst_dir = os.path.join(root, 'js/releases/courier', a.dst)
dst = os.path.join(dst_dir, 'courier-bundle.js')
if not os.path.isfile(src):
    sys.exit('missing ' + src)
if os.path.exists(dst):
    sys.exit('release ' + a.dst + ' already exists: pick a new version name (releases are never overwritten)')
base = open(src, encoding='utf-8').read()
addon = open(os.path.join(root, 'js/courier-packages.js'), encoding='utf-8').read()
os.makedirs(dst_dir)
with open(dst, 'w', encoding='utf-8') as f:
    f.write(base.rstrip('\n') + '\n\n/* ---- release ' + a.dst + ': js/courier-packages.js (added to ' + a.src + ') ---- */\n' + addon)
print('wrote', os.path.relpath(dst, root), os.path.getsize(dst), 'bytes')
