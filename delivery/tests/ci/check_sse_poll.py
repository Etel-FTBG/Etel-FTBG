"""Guards the polling replacement for the live (SSE) streams: both apps load it before the bundles, and both streams
in boot.php answer once and exit when asked with once=1."""
import re, sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

for page in ('ride/index.html', 'driver/index.html'):
    h = read(page)
    a, b = h.find('js/etel-sse-poll.js'), h.find('js/release-loader.js')
    if a < 0:
        bad.append(page + ' does not load js/etel-sse-poll.js')
    elif b >= 0 and a > b:
        bad.append(page + ' must load etel-sse-poll.js BEFORE release-loader.js (the bundles create their EventSource at run time)')
js = read('js/etel-sse-poll.js')
for needle in ('sse_(ride_status|driver_stream)', 'once=1', 'CLOSED', "localStorage.getItem('etel_sse')"):
    if needle not in js:
        bad.append('etel-sse-poll.js is missing ' + needle)
boot = read('api/boot.php')
for name in ('sse_ride_status', 'sse_driver_stream'):
    i = boot.find("case '" + name + "'")
    j = boot.find("\n    case '", i + 10)
    body = boot[i:j]
    if not re.search(r"\$once\s*=\s*!empty\(\$_GET\['once'\]\)", body) or 'if ($once)' not in body:
        bad.append(name + ' in boot.php does not answer once and exit when once=1')
if "'ride_driver_ghost_minutes', 10)" not in read('api/lib/ride_sweeper.php'):
    bad.append('ride_sweeper.php ghost-driver default should be 10 minutes')

if bad:
    print('check_sse_poll FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_sse_poll OK')
