#!/usr/bin/env python3
"""
CI check: the accessibility helper is loaded on every main app page.

js/etel-a11y.js gives clickable <div>s a button role and keyboard support, names icon-only buttons and unlabeled
fields, and adds landmarks. If a page stops loading it, keyboard and screen-reader users lose all of that without any
visible change, so this fails when a page drops the script tag or the file goes missing.
"""
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
PAGES = ["admin.html", "servicegiver.html", "customer.html", "courier/index.html"]

bad = []
if not os.path.exists(os.path.join(ROOT, "js", "etel-a11y.js")):
    bad.append("js/etel-a11y.js is missing")
for page in PAGES:
    with open(os.path.join(ROOT, page), encoding="utf-8", errors="replace") as fh:
        if not re.search(r'<script[^>]+src="[^"]*js/etel-a11y\.js(\?[^"]*)?"', fh.read()):
            bad.append(page + " does not load js/etel-a11y.js")
if bad:
    print("FAIL:\n  " + "\n  ".join(bad))
    sys.exit(1)
print("OK: etel-a11y.js is loaded on %d pages" % len(PAGES))
