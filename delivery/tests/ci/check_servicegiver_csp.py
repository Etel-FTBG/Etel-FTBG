"""Service Giver page: no JavaScript inside HTML (inline handlers, inline <script> blocks) and every data-act name is allowed.

Same idea as check_inline_handlers.py (the Customer page): a strict Content-Security-Policy only works once the page has none.
js/core.js is shared with the other apps, so it keeps ONE sanctioned fallback (_etelAct) that writes an inline handler on pages
that do not load js/etel-actions.js; everything else must use data-act.
"""
import json
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

html = read('servicegiver.html')
handler = re.compile(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""")
if handler.search(html):
    bad.append('servicegiver.html has inline handlers: ' + ', '.join(sorted(set(m.group(0).strip() for m in handler.finditer(html))))[:200])
for m in re.finditer(r'<script\b([^>]*)>', html):
    if 'src=' not in m.group(1) and 'type="application/json"' not in m.group(1):
        bad.append('servicegiver.html has an inline <script> block')
if 'javascript:' in html.lower():
    bad.append('servicegiver.html has a javascript: link')

allowed_src = read('js/servicegiver-actions.js')
m = re.search(r'EtelActions\.expose\((\[.*?\])\)', allowed_src, re.S)
allowed = set(json.loads(m.group(1))) if m else set()
if not m:
    bad.append('js/servicegiver-actions.js has no EtelActions.expose([...]) list')

used = set()
for mm in re.finditer(r'data-act(?:-[a-z]+)?="([^"]+)"', html):
    used.add(mm.group(1))
for mm in re.finditer(r"data-acts(?:-[a-z]+)?='(\[.*?\])'", html):
    for call in json.loads(mm.group(1)):
        used.add(call[0])
sg = read('js/servicegiver.js')
for mm in re.finditer(r"_ea\('([A-Za-z_$][\w$.]*)'", sg):
    used.add(mm.group(1))
# core.js: modal buttons, diagnostics panel, retry button of UI.error (the retry name comes from the caller)
core = read('js/core.js')
for mm in re.finditer(r"_etelAct\('[^']*', '([A-Za-z_$][\w$.]*)'\)", core):
    used.add(mm.group(1))
for mm in re.finditer(r"UI\.error\([^)]*?,\s*'([A-Za-z_$][\w$]*)\(", sg):
    used.add(mm.group(1))
for n in sorted(used - allowed):
    bad.append('action "%s" is used in markup but not exposed in js/servicegiver-actions.js' % n)

order = [html.find(x) for x in ('js/etel-actions.js', 'js/servicegiver-actions.js', 'js/servicegiver.js')]
if min(order) < 0 or order != sorted(order):
    bad.append('servicegiver.html must load etel-actions.js, then servicegiver-actions.js, then servicegiver.js')

# Scripts the page loads must not build markup with inline handlers. core.js: exactly the one sanctioned fallback line.
pat = re.compile(r"""[\s'"`>]on(?:click|change|input|error|load|mouseover|mouseout|keydown|keyup|submit|focus|blur|keypress|touchstart|touchend)\s*=\s*\\?["']""")
for f in re.findall(r'src="(js/[^"?]+)', html):
    if f in ('js/jsQR.js', 'js/core.js'):
        continue
    n = len(pat.findall(read(f)))
    if n:
        bad.append('%s has %d inline handlers. Use EtelActions.attrs(...) / data-act.' % (f, n))
core_hits = [l for l in core.split('\n') if pat.search(l)]
if len(core_hits) != 1 or "' onclick=\"' + call" not in core_hits[0]:
    bad.append('js/core.js may only write an inline handler in the _etelAct fallback (found %d lines)' % len(core_hits))

if bad:
    print('check_servicegiver_csp FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_servicegiver_csp ok (%d actions used, %d allowed)' % (len(used), len(allowed)))
