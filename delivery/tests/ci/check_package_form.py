"""Guards the 'Send a package' booking form: every string it uses exists in English and Amharic, the language switch and the
map picker are wired (actions exposed on the customer page), no inline handlers, and the styles ship with a bumped version."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
js, acts, html, css, sw = read('js/customer-package.js'), read('js/customer-actions.js'), read('customer.html'), read('css/customer-v5.css'), read('sw.js')
bad = []
en = re.search(r"\ben: \{(.*?)\n        \},\n        am: \{", js, re.S)
am = re.search(r"\bam: \{(.*?)\n        \}\n    \}\);", js, re.S)
keys = lambda m: set(re.findall(r"\b(pkg_[a-z0-9_]+):", m.group(1))) if m else set()
ke, ka = keys(en), keys(am)
if ke != ka:
    bad.append('English and Amharic strings differ: ' + ', '.join(sorted(ke ^ ka)))
used = set(re.findall(r"T\('(pkg_[a-z0-9_]+)'\)", js))
dyn = {'pkg_size_', 'pkg_q_', 'pkg_err_addr_', 'pkg_err_area_'}
for k in sorted(used):
    if k not in ke:
        bad.append('string used but not defined: ' + k)
for k in ('pkg_q_docs', 'pkg_q_clothes', 'pkg_q_food', 'pkg_q_electronics', 'pkg_q_gift', 'pkg_size_document', 'pkg_size_small', 'pkg_size_medium', 'pkg_size_large'):
    if k not in ke:
        bad.append('missing string: ' + k)
for n in set(re.findall(r"\ba\('(pkg[A-Za-z]+)'", js)) | {'pkgLang', 'pkgMapOpen', 'pkgMapGps', 'pkgMapOk', 'pkgMapCancel', 'pkgWeightStep', 'pkgDesc'}:
    if '"%s"' % n not in acts:
        bad.append('action not exposed in customer-actions.js: ' + n)
    if ('window.%s = ' % n) not in js:
        bad.append('action not defined: ' + n)
for must in ('google.maps.Map', 'google.maps.Geocoder', 'pkg-mapview', 'toggleCustomerLanguage', 'ERR_FIELD'):
    if must not in js:
        bad.append('customer-package.js lost: ' + must)
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('customer-package.js must not use inline handlers')
if 'pkg-mapview' not in css or 'pkg-lang' not in css:
    bad.append('the form styles are missing from customer-v5.css')
m = re.search(r'customer-v5\.css\?v=(\d+)\.(\d+)', html)
if not m or (int(m.group(1)), int(m.group(2))) < (7, 19):
    bad.append('customer.html must load customer-v5.css?v=7.19 or newer')
if not re.search(r"customer-package\.js\?v=(\d+)", html) or int(re.search(r"customer-package\.js\?v=(\d+)", html).group(1)) < 7:
    bad.append('customer.html must load customer-package.js?v=7 or newer')
if int(re.search(r"etel-v(\d+)", sw).group(1)) < 166:
    bad.append('sw.js cache version must be bumped (etel-v166+)')
if bad:
    print('check_package_form FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_package_form ok')
