"""Guards the shop Decline button: markup, server call, all four reasons, styles."""
root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html = read('servicegiver.html')
js = read('js/servicegiver.js')
bad = []
for i in ('decline-overlay', 'decline-reasons', 'decline-note', 'decline-go', 'decline-msg'):
    if 'id="' + i + '"' not in html:
        bad.append('servicegiver.html missing #' + i)
for fn in ('openDecline', 'declinePick', 'closeDecline', 'submitDecline'):
    if 'function ' + fn + '(' not in js:
        bad.append('missing function ' + fn)
if "apiCall('sg_reject_order'" not in js:
    bad.append('the shop app does not call sg_reject_order')
for r in ('out_of_stock', 'too_busy', 'closing', 'other'):
    if "'" + r + "'" not in js:
        bad.append('missing reason ' + r)
if "status === 'pending' || status === 'preparing'" not in js:
    bad.append('Decline must only show for pending/preparing orders')
for c in ('.decline-go', '.decline-keep', '.decline-opt', '.btn-decline'):
    if c not in html:
        bad.append('css missing ' + c)
if bad:
    print('FAIL:\n  ' + '\n  '.join(bad)); raise SystemExit(1)
print('shop decline: ok')
