"""Guards saved places at checkout: functions, both languages, server calls, styles, per-order reset."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
js = read('js/customer.js')
css = read('css/customer-v5.css')
bad = []
for fn in ('loadSavedPlaces', 'applySavedPlace', 'useSavedPlace', 'savedPlacesRow', 'saveThisAddressRow', 'saveCurrentAddress'):
    if 'function ' + fn + '(' not in js:
        bad.append('missing function ' + fn)
for call in ("apiGet('customer_get_addresses'", "api('customer_add_address'"):
    if call not in js:
        bad.append('missing server call ' + call)
for k in ('saved_places', 'save_this_addr', 'saved_addr_ok', 'saved_addr_fail'):
    if len(re.findall(r"\b" + k + r":", js)) != 2:
        bad.append(k + ' must exist in both the English and Amharic tables')
if 'savedPlacesRow() +' not in js or 'saveThisAddressRow() +' not in js:
    bad.append('checkout does not render the saved places row or the save link')
if "if (box) box.value = _deliveryAddr;" not in js:
    bad.append('useSavedPlace must write the address into the box before redrawing (the redraw keeps typed text)')
if js.count("_deliveryAddr = ''; _savedAutoApplied = false;") < 5:
    bad.append('every order path must reset _savedAutoApplied together with _deliveryAddr')
for cls in ('.sp-title', '.sp-row', '.sp-chip', '.sp-save'):
    if cls not in css:
        bad.append('css missing ' + cls)
if bad:
    print('FAIL:\n  ' + '\n  '.join(bad)); sys.exit(1)
print('saved places: ok')
