"""Guards the driver navigation-app choice: the script is loaded, it builds Google Maps, Waze and geo:/Apple Maps links,
keeps the original button as the fallback, asks only on a real tap, and the dialog stays solid on the dark driver page."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

drv = read('driver/index.html')
for needle in ('js/driver-nav-choice.js', 'css/ride-promo.css', 'css/ride-offer-price.css'):
    if needle not in drv:
        bad.append('driver/index.html does not load ' + needle)
js = read('js/driver-nav-choice.js')
for needle in ('waze.com/ul?ll=', 'google.com/maps/dir/?api=1&destination=', "'geo:'", 'maps://?daddr=', 'Object.defineProperty(window, \'dnOpenMaps\'',
               'userActivation', 'if (typeof orig === \'function\') return orig.apply'):
    if needle not in js:
        bad.append('driver-nav-choice.js is missing ' + needle)
css = read('css/ride-offer-price.css')
if 'body.dark-page .rp-dlg .rp-card{background:#14141F!important' not in css:
    bad.append('ride-offer-price.css must keep .rp-card solid on body.dark-page (the driver bundle blanks classes ending in -card)')
for text in ('Navigate with', 'Always use this app', 'Other map app'):
    if "'" + text + "'" not in read('js/driver-i18n.js'):
        bad.append('driver-i18n.js needs the Amharic entry for ' + text)

if bad:
    print('check_driver_nav_choice FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_driver_nav_choice OK')
