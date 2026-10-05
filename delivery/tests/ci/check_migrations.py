#!/usr/bin/env python3
"""
CI check: the migration files are well-formed and every version number is unique.

The migration runner records applied migrations BY VERSION NUMBER. Two files sharing a
number (040_equb_system.sql and 040_rides_cancel_reason.sql did) means only one ever
runs - the other is silently skipped forever. This fails the build if that happens again.
"""
import collections
import os
import re
import sys

root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "api", "migrations")
pattern = re.compile(r"^(\d{3,})_(.+)\.sql$")   # same rule api/migrate.php uses
files = sorted(f for f in os.listdir(root) if f.endswith(".sql"))
if not files:
    print("FAIL: no migration files found in %s" % root)
    sys.exit(1)

problems = []
by_version = collections.defaultdict(list)
for f in files:
    m = pattern.match(f)
    if not m:
        problems.append("file name does not match NNN_name.sql (runner would ignore it): " + f)
        continue
    by_version[m.group(1)].append(f)

for version, names in sorted(by_version.items()):
    if len(names) > 1:
        problems.append("version %s is used by %d files (only one would ever run): %s" % (version, len(names), ", ".join(names)))

if problems:
    print("Migration check FAILED:")
    for p in problems:
        print("  - " + p)
    sys.exit(1)
print("Migration check OK: %d files, %d unique versions (%s .. %s)" % (
    len(files), len(by_version), min(by_version), max(by_version)))
