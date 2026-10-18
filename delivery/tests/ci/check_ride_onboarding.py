#!/usr/bin/env python3
"""Rider welcome cards and loading skeletons: wired in, translated, accessible, remembered per device."""
import re, sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

html = read('ride/index.html')
js = read('js/ride-onboarding.js')
css = read('css/ride-onboarding.css')
i18n = read('js/ride-i18n.js')

for f in ('ride-onboarding.js', 'ride-onboarding.css'):
    if f not in html:
        bad.append('ride/index.html must load ' + f)
for need in ("role', 'dialog'", "aria-modal", "key === 'Escape'", "etel_ride_welcome_v1", 'liveTrip()', 'prefers-reduced-motion'):
    if need not in js and need not in css:
        bad.append('missing: ' + need)
if 'data-act="skip"' not in js:
    bad.append('the welcome must always offer Skip')

# every sentence on the cards must have an Amharic translation
titles = re.findall(r"title: '((?:[^'\\]|\\.)*)'", js)
bodies = re.findall(r'body: ("(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\')', js)
texts = titles + [b[1:-1] for b in bodies] + ['Next', 'Get started', 'Skip']
for t in texts:
    if ("'" + t + "':" not in i18n) and ('"' + t + '":' not in i18n):
        bad.append('no Amharic for welcome text: ' + t[:50])
if len(titles) < 3 or len(bodies) < 3:
    bad.append('expected three welcome cards')

# the dark theme paints cards transparent unless the card sets its own colours with !important
for sel in ('.etel-wel .etel-wel-card{', '.etel-wel .etel-wel-title{', '.etel-wel .etel-wel-body{'):
    block = css.split(sel, 1)
    if len(block) < 2 or 'important' not in block[1].split('}', 1)[0]:
        bad.append(sel + ' must use !important so app-wide rules cannot make it transparent')

if bad:
    print('check_ride_onboarding FAILED:\n  ' + '\n  '.join(bad[:20]))
    sys.exit(1)
print('check_ride_onboarding OK')
