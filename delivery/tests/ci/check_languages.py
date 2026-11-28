"""Guards the language switch of the Delivery apps (28 Nov 2026): English, Amharic, Oromo (om), Tigrinya (ti), Somali (so), Afar (aa).
- tools/i18n/strings.tsv and delivery/i18n/*.js are in step (build_langs.py --check);
- om / ti / so have a real number of texts, each non-empty, written in the right script (Tigrinya in Ethiopic letters, Oromo and Somali in Latin letters);
- Afar is listed but marked soon (not selectable) while its dictionary is empty, and becomes selectable only when it has texts;
- js/etel-langs.js is ASCII, lists the six codes, and every page loads it; the server accepts the same six codes."""
import os
import re
import subprocess
import sys

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
bad = []

def rd(p):
    return open(os.path.join(root, p), encoding='utf-8', errors='replace').read()

if subprocess.call([sys.executable, os.path.join(root, 'tools', 'i18n', 'build_langs.py'), '--check']) != 0:
    bad.append('delivery/i18n/*.js is out of date with tools/i18n/strings.tsv (run tools/i18n/build_langs.py)')

rows = [l.split('\t') for l in rd('tools/i18n/strings.tsv').split('\n') if l.strip()]
head, rows = rows[0], rows[1:]
if head != ['English', 'am', 'om', 'ti', 'so', 'aa']:
    bad.append('strings.tsv header is %r' % head)
keys = [r[0] for r in rows]
if len(keys) != len(set(keys)):
    bad.append('strings.tsv has an English text twice')
eth = re.compile('[ሀ-፿]')
counts = {}
for ci, code in enumerate(['am', 'om', 'ti', 'so', 'aa'], 1):
    vals = [(r[0], r[ci] if len(r) > ci else '') for r in rows]
    filled = [(k, v) for k, v in vals if v.strip()]
    counts[code] = len(filled)
    if code == 'am' and len(filled) < 600:
        bad.append('am has only %d texts (expected 600 or more)' % len(filled))
    if code not in ('aa', 'am'):
        if len(filled) < 350:
            bad.append('%s has only %d texts (expected 350 or more)' % (code, len(filled)))
        for k, v in filled:
            if code == 'ti' and not eth.search(v) and not re.fullmatch(r'[A-Za-z0-9 .:/\-+%()?!…{}~#·,]*', v):
                bad.append('ti text without Ethiopic letters: %r -> %r' % (k, v))
            if code in ('om', 'so') and eth.search(v):
                bad.append('%s text with Ethiopic letters: %r -> %r' % (code, k, v))
            if k.count('{') != v.count('{') or k.count('%') != v.count('%'):
                bad.append('%s placeholder or percent sign differs: %r -> %r' % (code, k, v))
    path = os.path.join(D, 'i18n', code + '.js')
    if not os.path.exists(path):
        bad.append('missing delivery/i18n/%s.js' % code)
    elif any(b > 127 for b in open(path, 'rb').read()):
        bad.append('delivery/i18n/%s.js must be ASCII only' % code)

js = rd('delivery/js/etel-langs.js')
if any(ord(c) > 127 for c in js):
    bad.append('delivery/js/etel-langs.js must be ASCII only')
for code in ('en', 'am', 'om', 'ti', 'so', 'aa'):
    if "c: '%s'" % code not in js:
        bad.append('etel-langs.js does not list ' + code)
aa_soon = re.search(r"c: 'aa'[^}]*soon: 1", js) is not None
if counts.get('aa', 0) == 0 and not aa_soon:
    bad.append("Afar has no texts but is not marked soon: 1 in etel-langs.js")
if counts.get('aa', 0) >= 150 and aa_soon:
    bad.append("Afar has texts now: remove soon: 1 from etel-langs.js")
if len(js) > 30000:
    bad.append('etel-langs.js is over 30 KB')

for page in ('customer.html', 'login.html', 'servicegiver.html', 'ride/index.html', 'driver/index.html', 'courier/index.html', 'courier/courier-index.html', 'admin.html', 'admin-login.html', 'investor/index.html', 'equb/index.html', 'index.html', 'install.html', 'claim.html', 'status.html'):
    if not re.search(r'<script src="(?:\.\./)?js/etel-langs\.js\?v=[0-9.]+"[^>]* defer></script>', rd('delivery/' + page)):
        bad.append(page + ' does not load js/etel-langs.js')

adm = rd('delivery/admin.html')
if 'js/admin-languages.js' not in adm or 'data-walk-am' not in adm or 'data-etel-lang-host' not in adm:
    bad.append('admin.html does not load the Languages control (js/admin-languages.js)')
for code in ('en', 'am', 'om', 'ti', 'so', 'aa'):
    if not re.search(r"\b%s: '<(rect|path|circle)" % code, js):
        bad.append('etel-langs.js has no flag for ' + code)
lc = rd('delivery/api/lib/lang_config.php')
if "'beta', 'soon', 'off'" not in lc or 'lang_state_' not in lc:
    bad.append('api/lib/lang_config.php does not keep the language states')


# the other ETEL systems load the same picker from /delivery/js/etel-langs.js (same site, so the choice is shared)
OTHERS = ['Etel.Pay/wallet/login.html', 'Etel.Pay/wallet/wallet.html', 'Etel.Pay/wallet/send.html', 'Etel.Pay/wallet/profile.html', 'EtelAi/login.html', 'EtelAi/admin.html', 'EtelAi/customer.html', 'EtelAi/index.html',
          'EtelERP/index.html', 'EtelTaxi/index.html', 'EtelTaxi/driver.html', 'EtelTaxi/admin.html', 'EtelTaxi/verify.html', 'EtelGroup/index.html', 'EtelGroup/admin.html', 'EtelGroup/investors/portal.html',
          'EtelGroup/investors/admin.html', 'EtelGebeya/admin.html', 'EtelGebeya/app/Views/_layouts/dark.php', 'EtelGebeya/app/Views/_layouts/public.php', 'EtelMadeya/index.php', 'EtelMadeya/admin/index.php',
          'EtelId/templates/portal.php', 'EtelId/templates/auth-page.php', 'EtelId/templates/admin-console.php', 'EtelId/templates/apply-form.php', 'EtelId/templates/profile-update.php',
          'EtelId/templates/reset-password.php', 'EtelId/templates/verify.php']
for f in OTHERS:
    if not re.search(r'<script src="/delivery/js/etel-langs\.js\?v=[0-9.]+"', rd(f)):
        bad.append(f + ' does not load /delivery/js/etel-langs.js')
for f in OTHERS:
    if f.startswith('Etel.Pay') and 'data-lang-key="ep_lang"' not in rd(f):
        bad.append(f + ' (Pay) does not keep its own language in step (data-lang-key="ep_lang")')

boot = rd('delivery/api/boot.php')
if "array('en', 'am', 'om', 'ti', 'so', 'aa')" not in boot:
    bad.append("api/boot.php set_user_language does not accept the six codes")
if bad:
    print('FAIL check_languages:')
    for b in bad[:40]:
        print(' -', b)
    raise SystemExit(1)
print('ok check_languages (%s)' % ', '.join('%s %d' % kv for kv in counts.items()))
