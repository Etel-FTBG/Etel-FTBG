"""Guards the customer Cancel order button: markup, server call, both languages for every reason, styles."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html = read('customer.html')
js = read('js/customer.js')
css = read('css/customer-v5.css')
bad = []
if 'id="track-cancel-row"' not in html:
    bad.append('customer.html has no #track-cancel-row')
for fn in ('renderTrackCancel', 'openCancelOrder', 'submitCancelOrder'):
    if 'function ' + fn + '(' not in js:
        bad.append('missing function ' + fn)
if 'renderTrackCancel(o);' not in js:
    bad.append('refreshTrackOrder does not call renderTrackCancel')
if "api('customer_cancel_order'" not in js:
    bad.append('the app does not call customer_cancel_order')
if 'o.can_cancel' not in js:
    bad.append('the button must follow the server can_cancel flag')
keys = ['cancel_btn_order', 'cancel_title', 'cancel_why', 'cancel_note', 'cancel_confirm', 'cancel_keep', 'cancel_done', 'cancel_done_refund', 'cancel_pick', 'cancel_fail',
        'cancel_r_changed_mind', 'cancel_r_wrong_order', 'cancel_r_too_slow', 'cancel_r_other']
for k in keys:
    if len(re.findall(r"\b" + k + r":", js)) != 2:
        bad.append(k + ' must exist in both the English and Amharic tables')
for cls in ('.trk-cancel ', '.trk-cancel-go', '.trk-cancel-keep', '.cancel-opt'):
    if cls not in css:
        bad.append('css missing ' + cls)
if bad:
    print('check_customer_cancel FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_customer_cancel ok')
