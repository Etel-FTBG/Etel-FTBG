"""Guards the courier navigation: full-screen navigation must start only after the courier pressed a Navigate button (wantNav), never just because the
app redrew the route when the Active tab refreshed."""
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
js = open(root + '/js/courier-nav.js', encoding='utf-8').read()
bad = []
if 'function wantNav' not in js or 'wanted' not in js:
    bad.append('courier-nav.js lost the "only after a Navigate tap" gate')
if 'else start(this, res)' in js:
    bad.append('courier-nav.js starts navigation on every route redraw again')
if subprocess.call(['node', '--check', root + '/js/courier-nav.js']) != 0:
    bad.append('courier-nav.js does not parse')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: courier navigation autostart guarded')
