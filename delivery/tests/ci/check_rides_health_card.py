"""Guards the Rides "System health" / "Live trip watch" cards: js/admin-rides-desk.js must decide "already built" by looking at child ELEMENTS, never at firstChild
(admin.html leaves an HTML comment inside the box, which is a child node too: that made the cards never appear since 24 Oct), and admin.html must still hold the box."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
js = read('js/admin-rides-desk.js')
html = read('admin.html')
bad = []
if re.search(r'\.firstChild\b', js):
    bad.append('admin-rides-desk.js uses firstChild: a comment node counts, so the cards are never built. Use children.length')
if 'el.children.length' not in js:
    bad.append('admin-rides-desk.js no longer checks child elements before building the cards')
if 'id="rides-ops-pro"' not in html:
    bad.append('admin.html lost the box (#rides-ops-pro) the System health card is built into')
if re.search(r'admin-rides-desk\.js\?v=1"', html):
    bad.append('admin.html still loads the old admin-rides-desk.js?v=1 (bump the version when the file changes)')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: Rides health card guarded')
