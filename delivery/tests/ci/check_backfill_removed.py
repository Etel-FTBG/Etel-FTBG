"""The one-time fare backfill script is gone (deleted on the live server by the owner on 28 Nov 2026, removed from the repo the same day):
the file does not exist, and nothing in the admin screen or the code still points at it."""
import os
import re

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
bad = []
if os.path.exists(os.path.join(D, 'api', 'backfill_open_trip_fares.php')):
    bad.append('api/backfill_open_trip_fares.php is back')
me = os.path.abspath(__file__)
for base, dirs, files in os.walk(D):
    dirs[:] = [d for d in dirs if d not in ('releases', 'node_modules', 'uploads', 'backups')]
    for f in files:
        p = os.path.join(base, f)
        if os.path.abspath(p) == me or not re.search(r'\.(php|js|html|py|json|md)$', f):
            continue
        if 'backfill_open_trip_fares' in open(p, encoding='utf-8', errors='replace').read():
            bad.append('%s still mentions backfill_open_trip_fares' % os.path.relpath(p, root))
if bad:
    print('FAIL check_backfill_removed:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_backfill_removed')
