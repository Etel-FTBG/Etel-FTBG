#!/usr/bin/env python3
"""Native driver app (Etel-Driver-App): English / Amharic is wired through the shared Text / TextInput / Alert."""
import glob, os, re, sys

root = __file__.rsplit('/delivery/tests/', 1)[0]
app = os.path.join(root, 'Etel-Driver-App')
read = lambda p: open(os.path.join(app, p), encoding='utf-8').read()
bad = []

# 1. every screen uses the translating Text / TextInput / Alert, never react-native's own
files = glob.glob(os.path.join(app, 'src/screens/*.tsx')) + glob.glob(os.path.join(app, 'src/components/*.tsx')) + [os.path.join(app, 'src/security/AppLockGate.tsx'), os.path.join(app, 'App.tsx')]
for f in files:
    s = open(f, encoding='utf-8').read()
    for m in re.finditer(r"import \{([^}]*)\} from 'react-native';", s):
        names = {n.strip() for n in m.group(1).split(',')}
        for n in ('Text', 'TextInput', 'Alert'):
            if n in names:
                bad.append('%s imports %s from react-native; import it from the i18n module so it can be translated' % (os.path.relpath(f, app), n))

# 2. the provider and the switches
if 'LanguageProvider' not in read('App.tsx'):
    bad.append('App.tsx must wrap the app in LanguageProvider')
if 'toggleLang' not in read('src/screens/ProfileScreen.tsx') or 'toggleLang' not in read('src/screens/LoginScreen.tsx'):
    bad.append('Profile and Login must offer the language switch')
if "'etel_lang'" not in read('src/i18n/index.tsx'):
    bad.append('the language choice must use the shared etel_lang key')

# 3. the dictionary
am = read('src/i18n/am.ts')
pairs = re.findall(r"'((?:[^'\\]|\\.)*)'\s*:\s*'((?:[^'\\]|\\.)*)'", am) + re.findall(r'"([^"]+)"\s*:\s*\'((?:[^\'\\]|\\.)*)\'', am)
keys = {k.replace("\\'", "'") for k, _ in pairs}
if len(pairs) < 120:
    bad.append('Amharic dictionary looks truncated (%d entries)' % len(pairs))
for k, v in pairs:
    if not re.search(r'[ሀ-፿]', v):
        bad.append('no Ethiopic characters in the Amharic for "%s"' % k[:50])

# 4. most of the text the screens show has an entry (new text may lag a little; a big drop means something broke)
edge = re.compile(r'^[^A-Za-z0-9\'"~·]*([\s\S]*?)[^A-Za-z0-9\'"…).?]*$')
shown = set()
for f in files:
    s = open(f, encoding='utf-8').read()
    for m in re.finditer(r'>\s*([^<>{}\n][^<>{}]*?)\s*</Text>', s):
        shown.add(re.sub(r'\s+', ' ', m.group(1)).strip())
    for m in re.finditer(r"placeholder=['\"]([^'\"]+)['\"]", s):
        shown.add(m.group(1))
shown = {t for t in shown if re.search(r'[A-Za-z]{3}', t)}
missing = [t for t in shown if t not in keys and edge.match(t).group(1) not in keys]
if shown and (len(shown) - len(missing)) / float(len(shown)) < 0.85:
    bad.append('only %d of %d screen texts have an Amharic entry, e.g. %s' % (len(shown) - len(missing), len(shown), ', '.join(sorted(missing)[:5])))

if bad:
    print('check_native_driver_i18n FAILED:\n  ' + '\n  '.join(bad[:20]))
    sys.exit(1)
print('check_native_driver_i18n OK (%d entries, %d of %d screen texts covered)' % (len(pairs), len(shown) - len(missing), len(shown)))
