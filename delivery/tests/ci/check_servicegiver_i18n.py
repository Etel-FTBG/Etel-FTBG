"""Guards the shop (service giver) app's English / Amharic switch: both languages have the same keys, every key used in the page
(data-i18n) or in script (sgT) exists, and the changed script files carry a new version so phones do not keep old copies."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
i18n, html, sg = read('js/servicegiver-i18n.js'), read('servicegiver.html'), read('js/servicegiver.js')
bad = []
import json
import subprocess
# run the dictionary file in node with a stub ETEL_I18N and read back both languages (it registers more than one block)
js = ("var d={en:{},am:{}};global.ETEL_I18N={register:function(a,x){Object.assign(d.en,x.en||{});Object.assign(d.am,x.am||{});},current:function(){return 'en'},apply:function(){},init:function(){},t:function(k){return k}};"
      "global.document={addEventListener:function(){},querySelectorAll:function(){return []},getElementById:function(){return null}};"
      "eval(require('fs').readFileSync(process.argv[1],'utf8'));console.log(JSON.stringify(d));")
out = subprocess.run(['node', '-e', js, root + '/js/servicegiver-i18n.js'], capture_output=True, text=True)
if out.returncode != 0:
    print('check_servicegiver_i18n FAILED: could not read the dictionary:', out.stderr[:300])
    sys.exit(1)
dd = json.loads(out.stdout)
ke, ka = set(dd['en']), set(dd['am'])
if not ke:
    bad.append('no strings found')
if ke != ka:
    bad.append('English and Amharic differ: ' + ', '.join(sorted(ke ^ ka)))
for lang in ('en', 'am'):
    for k, v in dd[lang].items():
        if not str(v).strip():
            bad.append('empty string: %s (%s)' % (k, lang))
used = set(re.findall(r'data-i18n(?:-ph)?="(sg_[a-z0-9_]+)"', html)) | set(re.findall(r"sgT\('(sg_[a-z0-9_]+)'\)", sg))
used |= {'sg_d_ty_' + k for k in ('restaurant','pharmacy','grocery','electronics','retail','cafe','fastfood','laundry','fuel','custom')} | {'sg_dec_' + k for k in re.findall(r"\['(out_of_stock|too_busy|closing|other)',", sg)} | {'sg_st_' + k for k in re.findall(r"sgT\('sg_st_([a-z_]+)'\)", sg)}
for k in sorted(used):
    if k not in ke:
        bad.append('used but not defined: ' + k)
for must in ('sgSwitchLang', 'sg-lang-b', 'servicegiver-i18n.js'):
    if must not in html:
        bad.append('servicegiver.html lost: ' + must)
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", i18n):
    bad.append('servicegiver-i18n.js must not use inline handlers')
if '"sgSwitchLang"' not in read('js/servicegiver-actions.js'):
    bad.append('sgSwitchLang is not allowed in servicegiver-actions.js')
v = lambda name: re.search(re.escape(name) + r'\?v=([0-9.]+)', html)
if not v('servicegiver-actions.js') or float(v('servicegiver-actions.js').group(1)) < 2:
    bad.append('servicegiver-actions.js must be loaded as ?v=2 or newer')
if not v('servicegiver.js') or float(v('servicegiver.js').group(1)) < 4.9:
    bad.append('servicegiver.js must be loaded as ?v=4.9 or newer')
if not v('servicegiver-i18n.js') or float(v('servicegiver-i18n.js').group(1)) < 3:
    bad.append('servicegiver-i18n.js must be loaded as ?v=3 or newer')
if bad:
    print('check_servicegiver_i18n FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_servicegiver_i18n ok (%d strings in each language)' % len(ke))
