"""Guards the driver Money tab tips card: the script is loaded, it reads driver_get_tips, and the API side exists."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

if 'js/driver-tips.js' not in read('driver/index.html'):
    bad.append('driver/index.html does not load js/driver-tips.js')
js = read('js/driver-tips.js')
for needle in ('driver_get_tips', 'money-balance', 'drv-tips-card'):
    if needle not in js:
        bad.append('driver-tips.js is missing ' + needle)
if "case 'driver_get_tips'" not in read('api/boot.php'):
    bad.append('boot.php does not route driver_get_tips')
if 'Tips from riders' not in read('js/driver-i18n.js'):
    bad.append('driver-i18n.js needs the Amharic entries for the tips card')

if bad:
    print('check_driver_tips FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_driver_tips OK')
