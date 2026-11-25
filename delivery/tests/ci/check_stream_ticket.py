"""Guards the login token being kept out of web addresses: the apps that open live streams or send the beacon load js/etel-stream-ticket.js BEFORE their own scripts,
no app code builds a stream address with the token except through that wrapper's reach (a new one would have to be reviewed here), boot.php reads tickets and
honours url_token_mode, and the admin CSV download no longer puts the token in the address."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for page in ('driver/index.html', 'ride/index.html', 'customer.html', 'admin.html'):
    h = read(page)
    i = h.find('etel-stream-ticket.js')
    if i < 0:
        bad.append(page + ' does not load js/etel-stream-ticket.js')
        continue
    first_other = min([m.start() for m in re.finditer(r'<script[^>]+src="[^"]*/(?:core|driver|driver-bundle|ride|ride-bundle|customer|admin)\.js|<script[^>]+src="(?:core|driver|driver-bundle|ride|ride-bundle|customer)\.js|<script[^>]+src="[^"]*/(?:driver|ride)-bundle\.js', h)] or [10 ** 9])
    if i > first_other:
        bad.append(page + ' loads etel-stream-ticket.js after the app scripts')
js = read('js/etel-stream-ticket.js')
if re.search(r'\beval\(|document\.write', js):
    bad.append('etel-stream-ticket.js uses eval / document.write')
# the only places allowed to build a stream or beacon address with a token (all reach the wrapper); a new one must be added here on purpose
# (admin.html: the system-test stream, and the legacy one-time fare backfill tool link, which is a separate page and is documented to be deleted after use)
allowed = {'admin.html': 2, 'js/driver.js': 2, 'js/ride.js': 1, 'js/customer.js': 1}
for f, n in allowed.items():
    c = len(re.findall(r"[?&]token=' \+", read(f)))
    if c != n:
        bad.append('%s builds %d web addresses with a token, expected %d (use stream tickets, see lib/stream_ticket.php)' % (f, c, n))
boot = read('api/boot.php')
if 'etel_ticket_open(' not in boot or 'etel_url_token_note(' not in boot or "case 'stream_ticket'" not in boot:
    bad.append('boot.php lost the ticket handling, the url_token_mode note or the stream_ticket route')
if 'equity_export_investors_csv&token' in read('js/admin-equity.js'):
    bad.append('admin-equity.js puts the login token in the address again')
if 'url_token_mode' not in read('api/lib/shield_store.php'):
    bad.append('url_token_mode is no longer a shield setting')
for f in ('api/lib/stream_ticket.php',):
    t = read(f)
    if re.search(r'[^\x00-\x7f]', t):
        bad.append(f + ' is not ASCII only')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: login token kept out of web addresses (guarded)')
