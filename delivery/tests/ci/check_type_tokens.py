"""Guards the customer-app type fixes: font tokens exist, body uses the plain body font, lining numerals, solid red badge."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
css = open(root + '/css/customer-v5.css', encoding='utf-8').read()
js = open(root + '/js/customer.js', encoding='utf-8').read()
html = open(root + '/customer.html', encoding='utf-8').read()
bad = []
for tok in ('--font-body:', '--font-display:', '--font-serif:', '--red-solid:'):
    if tok not in css:
        bad.append('missing token ' + tok)
body = re.search(r'\nbody \{(.*?)\}', css, re.S)
if not body or 'var(--font-body)' not in body.group(1):
    bad.append('body must use var(--font-body)')
if body and 'lining-nums' not in body.group(1):
    bad.append('body must set font-variant-numeric: lining-nums (old-style figures made prices and order codes hard to read)')
if len(re.findall(r"font-family:\s*'Cormorant Garamond'", css)) != 0 and not re.search(r'--font-serif:\s*\'Cormorant Garamond\'', css):
    bad.append('Cormorant Garamond may only be set through --font-serif')
if re.findall(r"font-family:\s*'Cormorant Garamond'", css):
    bad.append("raw font-family: 'Cormorant Garamond' found; use var(--font-serif) or var(--font-body)")
if '.svc-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }' not in css:
    bad.append('service tiles must use minmax(0, 1fr) so they never overflow a 320px phone')
if 'background:#EF4444' in js or 'background:var(--red);color:#fff' in js:
    bad.append('SALE badges must use var(--red-solid) (white on the lighter red fails 4.5:1)')
if 'rgba(255,255,255,.4)' in html:
    bad.append('customer.html has a dim inline footer colour that fails contrast; use var(--text-muted)')
if bad:
    print('check_type_tokens FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_type_tokens ok')
