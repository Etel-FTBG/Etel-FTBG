"""Guards the customer arrival estimate: server lib wired into track_order, client shows it, both languages."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
ctl = open(root + '/api/controllers/OrderController.php', encoding='utf-8').read()
js = open(root + '/js/customer.js', encoding='utf-8').read()
bad = []
if 'lib/track_eta.php' not in ctl or 'etel_track_eta_apply($pdo, $order)' not in ctl:
    bad.append('track_order does not call etel_track_eta_apply')
if 'courier_seen_sec' not in ctl:
    bad.append('track_order does not select courier_seen_sec')
if 'o.eta && o.eta.minutes' not in js:
    bad.append('customer.js does not render the server estimate')
for key in ('eta_arrive', 'eta_live', 'eta_typical', 'eta_paused'):
    if len(re.findall(key + ':', js)) != 2:
        bad.append(key + ' must exist in both the English and Amharic tables')
if bad:
    print('check_track_eta FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_track_eta ok')
