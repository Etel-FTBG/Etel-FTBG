"""Guards the courier dark-mode colours: the vehicle marker undoes the dark map filter, the small location map is darkened, "Open in Maps" stays white,
and the secondary buttons have no blue glow."""
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
js = read('js/courier-vehicle-marker.js')
if 'body.dark-theme .cvm-pic{filter:hue-rotate(180deg) invert(1) contrast(1.25)}' not in js:
    bad.append('courier-vehicle-marker.js lost the counter-filter that keeps the vehicle picture in its real colours in dark mode')
for page in ('courier/index.html', 'courier/courier-index.html'):
    s = read(page)
    for need in ('body.dark-theme img[alt$="location map"]{filter:invert(90%) hue-rotate(180deg)}',
                 'a[href*="/maps/dir/"] > div[style*="position:absolute"]{color:#fff!important',
                 '.btn.btn-secondary,.btn-secondary{box-shadow:0 8px 18px -10px rgba(212,175,55,.45)!important}'):
        if need not in s:
            bad.append(page + ' is missing the dark-mode colour rule: ' + need[:60])
if subprocess.call(['node', '--check', root + '/js/courier-vehicle-marker.js']) != 0:
    bad.append('courier-vehicle-marker.js does not parse')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: courier dark-mode colours guarded')
