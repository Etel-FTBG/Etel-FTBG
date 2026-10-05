#!/usr/bin/env python3
"""
CI check (ratchet): don't add NEW places where user-controlled text is put into
innerHTML without escaping.

Finds lines that build HTML from a field that other users can set (names, notes,
addresses, messages ...) without escapeHtml()/esc()/escUrl()/encodeURIComponent() on that
line. It is a heuristic with some false positives (e.g. admin-only labels), so it is a
RATCHET: the build fails only if a file has MORE candidates than ci/xss_baseline.json.
When you fix some, run with --update to lower the baseline. Never raise it to hide a new one.
"""
import json
import os
import re
import sys

here = os.path.dirname(os.path.abspath(__file__))
js = os.path.join(here, "..", "..", "js")
base_path = os.path.join(here, "xss_baseline.json")

risky = r"(name|full_name|driver_name|customer_name|recipient_name|sender_name|courier_name|notes?|delivery_notes|address|pickup_addr(?:ess)?|dropoff_addr(?:ess)?|recipient_address|message|msg|body|text|comment|reason|description|title|label|shop_name|item_name|product_name|issue|details|plate|vehicle_make|vehicle_color|bio|subject)"
dyn = re.compile(r"(\+\s*\(?\s*[A-Za-z_\$][\w\$]*(?:\[[^\]]+\])?\.\s*" + risky + r"\b(?!\s*\())|(\$\{\s*[A-Za-z_\$][\w\$.]*\." + risky + r"\b)")
esc = re.compile(r"(esc\(|escapeHtml\(|escHtml\(|escUrl\(|escAttr\(|encodeURIComponent\(|textContent|\.toFixed\(|parseInt\(|parseFloat\(|Number\(|fmt[A-Z]\w*\()")
htmlish = re.compile(r"<[a-zA-Z/]|innerHTML|insertAdjacentHTML|outerHTML|html\(")
FILES = ["customer.js", "ride.js", "driver.js", "courier.js", "servicegiver.js", "admin.js", "order-chat.js",
         "ride_chat.js", "core.js", "ride-receipt.js", "driver-demand.js", "admin-rides.js"]

counts = {}
for f in FILES:
    p = os.path.join(js, f)
    if not os.path.exists(p):
        continue
    n = 0
    for line in open(p, encoding="utf-8", errors="replace"):
        if len(line) > 600:      # minified blob
            continue
        if dyn.search(line) and not esc.search(line) and htmlish.search(line):
            n += 1
    counts[f] = n

if "--update" in sys.argv:
    json.dump(counts, open(base_path, "w"), indent=1, sort_keys=True)
    print("baseline written:", counts)
    sys.exit(0)

baseline = json.load(open(base_path))
worse = {f: (baseline.get(f, 0), n) for f, n in counts.items() if n > baseline.get(f, 0)}
better = {f: (baseline.get(f, 0), n) for f, n in counts.items() if n < baseline.get(f, 0)}
print("XSS-sink candidates per file:", counts)
if better:
    print("Improved (run `python3 tests/ci/check_xss_sinks.py --update` to lock it in):", better)
if worse:
    print("FAIL: new unescaped user-text-into-HTML candidates (was, now):", worse)
    print("Wrap the field in escapeHtml(...) on that line, or if it is truly admin-only/safe, explain in the PR.")
    sys.exit(1)
print("OK: no new unescaped sinks")
