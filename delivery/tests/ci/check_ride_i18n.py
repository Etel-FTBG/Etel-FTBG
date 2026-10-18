#!/usr/bin/env python3
"""English / Amharic switch of the rider app: wired in, shares the ETEL language key, covers the main screens."""
import re, sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

js = read('js/ride-i18n.js')
html = read('ride/index.html')
if 'ride-i18n.js' not in html:
    bad.append('ride/index.html must load ride-i18n.js')
if "var KEY = 'etel_lang'" not in js:
    bad.append('ride-i18n.js must use the shared etel_lang key')

am = re.search(r"var AM = \{(.*?)\n    \};", js, re.S)
pairs = re.findall(r"'((?:[^'\\]|\\.)*)'\s*:\s*'((?:[^'\\]|\\.)*)'", am.group(1)) if am else []
pairs += re.findall(r'"([^"]+)"\s*:\s*\'((?:[^\'\\]|\\.)*)\'', am.group(1)) if am else []
if len(pairs) < 100:
    bad.append('Amharic dictionary looks truncated (%d entries)' % len(pairs))
for en, a in pairs:
    if not a.strip():
        bad.append('empty Amharic text for ' + en)
    elif not re.search(r'[ሀ-፿]', a):
        bad.append('no Ethiopic characters in the Amharic for "%s"' % en)

# the words the add-on scripts draw must be translatable
keys = {p[0] for p in pairs}
for needed in ('Book', 'Street Hail', 'Choose vehicle', 'Slide to request ride', 'Estimated Fare', 'Cheapest', 'Fastest', 'No drivers online', 'Where are you going?'):
    if needed not in keys:
        bad.append('dictionary is missing "%s"' % needed)
for s in ('Cheapest', 'Fastest', 'No drivers online'):
    if s not in read('js/ride-vehicle-list.js'):
        bad.append('ride-vehicle-list.js no longer draws "%s" - dictionary is stale' % s)

# every visible tab / header label in the page should be translated
for lab in ('Book', 'Street Hail', 'Active', 'History'):
    if '>' + lab + '<' not in html and lab not in keys:
        bad.append('tab label not translatable: ' + lab)

if bad:
    print('check_ride_i18n FAILED:\n  ' + '\n  '.join(bad[:20]))
    sys.exit(1)
print('check_ride_i18n OK (%d entries)' % len(pairs))
