"""Guards the home 'How was your order?' card: the hook, both languages, the styles."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
js = open(root + '/js/customer.js', encoding='utf-8').read()
css = open(root + '/css/customer-v5.css', encoding='utf-8').read()
bad = []

for fn in ('renderHomeRateCard', 'startHomeRating', 'skipHomeRating'):
    if 'function ' + fn + '(' not in js:
        bad.append('missing function ' + fn)
if 'renderHomeRateCard(res.data.orders' not in js:
    bad.append('loadHomeTracker does not call renderHomeRateCard')
if 'loadHomeTracker()' not in js.split('async function submitRating', 1)[1].split('// ', 1)[0]:
    bad.append('submitRating does not refresh the home card')
for key in ('rate_card_q', 'rate_card_sub', 'rate_card_later'):
    if len(re.findall(key + ':', js)) != 2:
        bad.append(key + ' must exist in both the English and Amharic tables')
for sel in ('.rate-card ', '.rate-card-star', 'body:not(.dark-theme) .rate-card .rate-card-star', 'body.dark-theme .rate-card'):
    if sel not in css:
        bad.append('css missing ' + sel)
if '#C8960C !important' in css.split('Home rate prompt', 1)[1]:
    bad.append('star colour #C8960C fails the contrast guard on white; use #946A00')

if bad:
    print('check_rate_card FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_rate_card ok')
