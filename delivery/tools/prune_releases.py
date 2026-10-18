#!/usr/bin/env python3
"""Lists which old app release folders can be deleted. It only READS: it never deletes anything.

Every app release lives in js/releases/<app>/<version>/ and is never overwritten (see js/release-loader.js). Old folders pile up on the
server and in the repo (ride: 16, driver: 25 versions, about 9 MB). A folder is safe to remove when nobody can be sent to it any more:
it is not the stable, canary or previous-stable version of that app (Admin > Releases shows these three), it is not the v1 baseline the loader falls
back to, and it is not one of the newest few kept for a quick rollback.

    python3 delivery/tools/prune_releases.py --keep 4 --protect ride:v15 --protect driver:v24

--protect app:version  adds a version you must keep (give the stable, canary and previous-stable shown in Admin > Releases; repeat the flag).
--keep N               also keeps the newest N versions of each app (default 4).
Then delete the listed folders in the cPanel File Manager (js/releases/<app>/<version>), oldest first, and run the post-deploy check.
"""
import argparse
import os
import re
import sys

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
rel_root = os.path.join(root, 'js', 'releases')


def size(path):
    total = 0
    for d, _, files in os.walk(path):
        for f in files:
            total += os.path.getsize(os.path.join(d, f))
    return total


def vnum(name):
    m = re.match(r'^v(\d+)$', name)
    return int(m.group(1)) if m else None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--keep', type=int, default=4)
    ap.add_argument('--protect', action='append', default=[])
    a = ap.parse_args()
    protected = {}
    for p in a.protect:
        if ':' not in p:
            sys.exit('--protect needs app:version, e.g. ride:v15')
        app, ver = p.split(':', 1)
        protected.setdefault(app, set()).add(ver)

    grand = 0
    for app in sorted(os.listdir(rel_root)):
        d = os.path.join(rel_root, app)
        if not os.path.isdir(d):
            continue
        versions = sorted([v for v in os.listdir(d) if vnum(v) is not None], key=vnum)
        keep = set(versions[-max(1, a.keep):]) | {'v1'} | protected.get(app, set())
        drop = [v for v in versions if v not in keep]
        print('%s: %d versions, keeping %s' % (app, len(versions), ', '.join(sorted(keep & set(versions), key=vnum))))
        if not drop:
            print('  nothing to delete')
            continue
        saved = 0
        for v in drop:
            s = size(os.path.join(d, v))
            saved += s
            print('  can delete js/releases/%s/%s  (%.0f KB)' % (app, v, s / 1024.0))
        grand += saved
        print('  frees %.1f MB' % (saved / 1048576.0))
    print('\nTotal %.1f MB. Nothing was deleted: this tool only lists.' % (grand / 1048576.0))
    if not a.protect:
        print('NOTE: give --protect app:version for each app\'s stable, canary and previous-stable version (Admin > Releases) before deleting anything.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
