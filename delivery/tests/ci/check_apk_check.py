"""Guards the APK checker page and the build's install check: page + script exist, nothing is uploaded, the build workflow verifies signature, APK type, minSdk <= 24 and arm64."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
html, js = read('apk-check.html'), read('js/apk-check.js')
if 'js/apk-check.js' not in html:
    bad.append('apk-check.html does not load js/apk-check.js')
if re.search(r'\beval\(|document\.write|XMLHttpRequest|fetch\(|FormData|sendBeacon', js):
    bad.append('apk-check.js must stay local: no network calls, eval or document.write')
if subprocess.call(['node', '--check', root + '/js/apk-check.js']) != 0:
    bad.append('apk-check.js does not parse')
for need in ('BundleConfig.pb', 'APK Sig Block 42', 'arm64-v8a', 'AndroidManifest.xml'):
    if need not in js:
        bad.append('apk-check.js lost the check for ' + need)
wf = open(root + '/../.github/workflows/build-android-apk.yml', encoding='utf-8').read()
for need in ('apksigner" verify', 'BundleConfig.pb', '-le 24', 'arm64-v8a'):
    if need not in wf:
        bad.append('build-android-apk.yml lost the install check: ' + need)
if wf.find('apksigner" verify') > wf.find('gh release create'):
    bad.append('the install check must run BEFORE the release is created')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: APK checker and build install check guarded')
