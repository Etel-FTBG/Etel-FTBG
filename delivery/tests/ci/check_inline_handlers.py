"""Customer page: no JavaScript inside HTML (inline handlers, inline <script> blocks) and every data-act name is allowed.

A strict Content-Security-Policy (script-src without 'unsafe-inline') only works once the page has none of those.
Pages are listed with the number of inline handlers still allowed in the scripts they load; the number may only go down.
"""
import json
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

html = read('customer.html')
handler = re.compile(r'\son[a-z]+\s*=\s*["\']')
if handler.search(html):
    bad.append('customer.html has inline handlers: ' + ', '.join(sorted(set(m.group(0).strip() for m in handler.finditer(html))))[:200])
for m in re.finditer(r'<script\b([^>]*)>', html):
    if 'src=' not in m.group(1) and 'type="application/json"' not in m.group(1):
        bad.append('customer.html has an inline <script> block')
if 'javascript:' in html.lower():
    bad.append('customer.html has a javascript: link')

# Every action named in markup must be exposed, in the page or in the scripts that build markup.
actions = read('js/customer-actions.js')
m = re.search(r'EtelActions\.expose\((\[.*?\])\)', actions, re.S)
allowed = set(json.loads(m.group(1))) if m else set()
if not m:
    bad.append('js/customer-actions.js has no EtelActions.expose([...]) list')
used = set()
for src in ('customer.html',):
    t = read(src)
    for mm in re.finditer(r'data-act(?:-[a-z]+)?="([^"]+)"', t):
        used.add(mm.group(1))
    for mm in re.finditer(r"data-acts(?:-[a-z]+)?='(\[.*?\])'", t):
        for call in json.loads(mm.group(1)):
            used.add(call[0])
# Actions that scripts build into markup with EtelActions.attrs (the package screens): every name must be exposed too.
for src in ('js/customer-package.js',):
    for mm in re.finditer(r"\ba\('([A-Za-z]+)'", read(src)):
        used.add(mm.group(1))
for n in sorted(used - allowed):
    bad.append('action "%s" is used in markup but not exposed in js/customer-actions.js' % n)
for n in sorted(allowed - used):
    pass  # scripts that build markup may use more names than the static page does

# Load order: the runtime and the allow-list come before the scripts that use them.
order = [html.find(x) for x in ('js/etel-actions.js', 'js/customer-actions.js', 'js/customer-bundle.js')]
if min(order) < 0 or order != sorted(order):
    bad.append('customer.html must load etel-actions.js, then customer-actions.js, then customer-bundle.js')

# Scripts the page loads that still build markup with inline handlers. These numbers may only go down: write new
# markup with EtelActions.attrs(...) or data-act attributes instead. When one reaches 0, remove it from this list.
BASELINE = {'js/customer.js': 79, 'js/customer-bundle.js': 11, 'js/customer-package.js': 0}
pat = re.compile(r"""\son(?:click|change|input|error|load|mouseover|mouseout|keydown|keyup|submit|focus|blur|keypress)\s*=\s*\\?["']""")
for f, limit in sorted(BASELINE.items()):
    n = len(pat.findall(read(f)))
    if n > limit:
        bad.append('%s has %d inline handlers, up from %d. Use EtelActions.attrs(...) / data-act for new markup.' % (f, n, limit))
    elif n < limit:
        bad.append('%s now has only %d inline handlers: lower its BASELINE entry in this file from %d to %d.' % (f, n, limit, n))

if bad:
    print('FAIL:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('inline handlers: ok (%d actions used by the page markup, %d allowed)' % (len(used), len(allowed)))
