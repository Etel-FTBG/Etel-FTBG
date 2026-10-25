#!/usr/bin/env python3
"""Builds a new driver app release folder: a copy of the previous release plus one or more readable add-on files appended to driver-bundle.js.

Same idea as tools/build_courier_release.py: the shipped bundle carries code that is not in the plain source files any more, so a release is
"previous release + a readable add-on file", never a rebuild from source.

    python3 tools/build_driver_release.py --from v25 --to v26 --addons js/driver-meter-fix.js

Copies every file of js/releases/driver/<from>/ to js/releases/driver/<to>/ and appends the add-ons to driver-bundle.js. Then (no SSH needed):
upload the folder, Admin > Releases > set it as the CANARY for the driver app, add a few testers, check, and only then promote it to stable.
A release folder is never overwritten once it exists.
"""
import argparse
import os
import shutil
import sys

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ap = argparse.ArgumentParser()
ap.add_argument('--from', dest='src', required=True)
ap.add_argument('--to', dest='dst', required=True)
ap.add_argument('--addons', required=True, help='comma-separated add-on files, relative to delivery/')
a = ap.parse_args()
src_dir = os.path.join(root, 'js/releases/driver', a.src)
dst_dir = os.path.join(root, 'js/releases/driver', a.dst)
if not os.path.isfile(os.path.join(src_dir, 'driver-bundle.js')):
    sys.exit('missing ' + src_dir + '/driver-bundle.js')
if os.path.exists(dst_dir):
    sys.exit('release ' + a.dst + ' already exists: pick a new version name (releases are never overwritten)')
addons = [x.strip() for x in a.addons.split(',') if x.strip()]
for rel in addons:
    if not os.path.isfile(os.path.join(root, rel)):
        sys.exit('missing add-on ' + rel)
shutil.copytree(src_dir, dst_dir)
bundle = os.path.join(dst_dir, 'driver-bundle.js')
out = open(bundle, encoding='utf-8').read().rstrip('\n')
for rel in addons:
    out += '\n\n/* ---- release ' + a.dst + ': ' + rel + ' (added to ' + a.src + ') ---- */\n' + open(os.path.join(root, rel), encoding='utf-8').read()
with open(bundle, 'w', encoding='utf-8') as f:
    f.write(out)
print('built', dst_dir, 'from', src_dir, '+', ', '.join(addons))
