"""Guards the courier page: Refresh jobs / Earnings (.action-bar) keep room below them so the floating buttons (help, job sound, theme) never hide them."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
bad = []
for page in ('courier/index.html', 'courier/courier-index.html'):
    s = open(root + '/' + page, encoding='utf-8').read()
    m = re.search(r'\.action-bar\{margin-bottom:(\d+)px\}', s)
    if not m or int(m.group(1)) < 120:
        bad.append(page + ': .action-bar needs margin-bottom of at least 120px so the floating buttons do not cover Refresh jobs / Earnings')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: courier bottom buttons keep their room')
