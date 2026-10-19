"""Guards the promo discount on receipts and trip screens: the receipt has a promo line (and the agreed-price line), the end-of-trip
answer carries the promo, both scripts are loaded, and the Amharic entries exist."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

ctl = read('api/controllers/RideController.php')
for needle in ("'Promo ' . $promo['promo_code']", "'Price agreed with driver'", 'rides_promo_info($pdo, $rid)', "'note'       => $note"):
    if needle not in ctl:
        bad.append('RideController.php is missing ' + needle)
if 'function rides_promo_info' not in read('api/lib/ride_promo.php'):
    bad.append('ride_promo.php is missing rides_promo_info')
if 'js/ride-promo-lines.js' not in read('ride/index.html'):
    bad.append('ride/index.html does not load js/ride-promo-lines.js')
if 'js/driver-promo-note.js' not in read('driver/index.html'):
    bad.append('driver/index.html does not load js/driver-promo-note.js')
rec = read('js/ride-receipt.js')
if "(v < 0 ? '- ' : '')" not in rec or 'r.note' not in rec:
    bad.append('ride-receipt.js must show negative lines and the note')
for text in ('Promo discount', 'The total above is already after your promo.'):
    if text not in read('js/ride-i18n.js'):
        bad.append('ride-i18n.js needs the Amharic entry for ' + text)
if 'The rider used a promo code.' not in read('js/driver-i18n.js'):
    bad.append('driver-i18n.js needs the Amharic entry for the promo notice')

if bad:
    print('check_promo_lines FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_promo_lines OK')
