"""Guards the courier page layout: the page must scroll (html must not be overflow:hidden), the footer script is loaded so Refresh jobs / Earnings are never hidden behind
the floating buttons, and the one-button map toggle is loaded. (Replaces the old .action-bar margin rule.)"""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for page in ('courier/index.html', 'courier/courier-index.html'):
    s = read(page)
    if not re.search(r'html\{overflow-y:auto!important', s):
        bad.append(page + ': html{overflow-y:auto!important} is missing: css/courier-bundle.css sets html{overflow:hidden}, which locks the whole page')
    for js in ('courier-footer.js', 'courier-map-toggle.js'):
        if js not in s:
            bad.append(page + ' does not load ' + js)
foot = read('js/courier-footer.js')
if 'calc(104px' not in foot:
    bad.append('courier-footer.js lost the room under the footer for the floating buttons')
for f in ('js/courier-footer.js', 'js/courier-map-toggle.js'):
    js = read(f)
    if re.search(r'\beval\(|document\.write|\bonclick=|\bonerror=', js):
        bad.append(f + ' uses inline handlers, eval or document.write')
    if subprocess.call(['node', '--check', root + '/' + f]) != 0:
        bad.append(f + ' does not parse')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: courier page scrolls, footer and map button loaded')
