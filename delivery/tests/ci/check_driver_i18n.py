#!/usr/bin/env python3
"""English / Amharic switch of the driver app: wired in, shared engine and key, covers the main screens."""
import re, sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

html = read('driver/index.html')
for f in ('etel-dom-i18n.js', 'driver-i18n.js', 'driver-lang.css'):
    if f not in html:
        bad.append('driver/index.html must load ' + f)
if html.find('etel-dom-i18n.js') > html.find('driver-i18n.js') > -1:
    bad.append('the shared engine must load before driver-i18n.js')

engine = read('js/etel-dom-i18n.js')
drv = read('js/driver-i18n.js')
if "key: 'etel_lang'" not in drv:
    bad.append('driver-i18n.js must use the shared etel_lang key')
if 'toggleDriverLanguage' not in drv:
    bad.append('the old Profile > Language row must be routed to the new switch')

am = re.search(r"var AM = \{(.*?)\n    \};", drv, re.S)
body = am.group(1) if am else ''
pairs = re.findall(r"'((?:[^'\\]|\\.)*)'\s*:\s*'((?:[^'\\]|\\.)*)'", body) + re.findall(r'"([^"]+)"\s*:\s*\'((?:[^\'\\]|\\.)*)\'', body)
if len(pairs) < 150:
    bad.append('Amharic dictionary looks truncated (%d entries)' % len(pairs))
keys = {p[0] for p in pairs}
for en, a in pairs:
    if not re.search(r'[ሀ-፿]', a):
        bad.append('no Ethiopic characters in the Amharic for "%s"' % en)
for needed in ('Requests', 'Earnings', 'Chats', 'Profile', 'Slide to go online', 'Accept', 'Decline', 'Language', 'You are now online'):
    if needed not in keys:
        bad.append('dictionary is missing "%s"' % needed)

# the engine must never write into typed values
if 'INPUT' in engine and 'value' in engine.split('function textNode', 1)[1].split('function attrs', 1)[0]:
    bad.append('engine text translation must not touch input values')

if bad:
    print('check_driver_i18n FAILED:\n  ' + '\n  '.join(bad[:20]))
    sys.exit(1)
print('check_driver_i18n OK (%d entries)' % len(pairs))
