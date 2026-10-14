#!/usr/bin/env python3
"""
CI check: no invented statistics in the page source.

A dashboard card must never ship with a written-in trend such as "12% from yesterday".
Those numbers do not come from the shop's orders, so they look wrong next to "0 orders".
Trends have to be computed in JavaScript from real data (see loadDashboardData in
js/servicegiver.js). This check fails if an HTML page contains an arrow or a +/- followed by
a percentage and a time word, written straight into the markup.
"""
import glob
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
PATTERN = re.compile(r"[↑↓▲▼+\-]\s*\d+(\.\d+)?\s*%\s*(from|vs|this|last|since|than)", re.I)

pages = [p for p in glob.glob(os.path.join(ROOT, "*.html")) + glob.glob(os.path.join(ROOT, "*", "*.html"))
         if "backup" not in p and "investor" not in p and "/docs/" not in p]
bad = []
for path in sorted(pages):
    with open(path, encoding="utf-8", errors="replace") as fh:
        for n, line in enumerate(fh, 1):
            if PATTERN.search(line):
                bad.append("%s:%d: %s" % (os.path.relpath(path, ROOT), n, line.strip()[:120]))
if bad:
    print("FAIL: hard-coded trend numbers found (compute them from real data instead):")
    print("\n".join(bad))
    sys.exit(1)
print("OK: no hard-coded trend numbers in %d pages" % len(pages))
