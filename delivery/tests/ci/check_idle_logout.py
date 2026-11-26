"""Guards the automatic sign-out: every signed-in app loads js/etel-idle-logout.js, the sign-in pages load the notice, the server enforces the limit in get_auth_user,
session_ping is registered, and an app that never reported activity is never expired (the safety rule)."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for page in ('admin.html', 'customer.html', 'servicegiver.html', 'courier/index.html', 'courier/courier-index.html', 'driver/index.html', 'ride/index.html', 'equb/index.html'):
    if 'etel-idle-logout.js' not in read(page):
        bad.append(page + ' does not load etel-idle-logout.js')
for page in ('login.html', 'admin-login.html'):
    if 'etel-idle-notice.js' not in read(page):
        bad.append(page + ' does not load etel-idle-notice.js')
for f in ('js/etel-idle-logout.js', 'js/etel-idle-notice.js'):
    js = read(f)
    if re.search(r'\beval\(|document\.write|\bonclick=', js):
        bad.append(f + ' uses inline handlers, eval or document.write')
    if subprocess.call(['node', '--check', root + '/' + f]) != 0:
        bad.append(f + ' does not parse')
js = read('js/etel-idle-logout.js')
if "KEY + '_tok'" not in js or 'forgetLast();' not in js:
    bad.append('etel-idle-logout.js lost the rule that a last-use time belongs to one sign-in and is forgotten on sign-out (the sign-out loop bug)')
if "etel_last_active_tok" not in read('js/etel-idle-notice.js'):
    bad.append('etel-idle-notice.js no longer clears the leftover last-use time on the sign-in pages')
boot = read('api/boot.php')
if 'etel_idle_expired(' not in boot:
    bad.append('boot.php get_auth_user no longer enforces the idle limit')
if "case 'session_ping'" not in boot:
    bad.append('session_ping is not registered in boot.php')
lib = read('api/lib/idle_session.php')
if "empty($r['last_active'])) return false" not in lib:
    bad.append('idle_session.php lost the safety rule: a session that never reported activity must never expire')
if 'IDLE_TIMEOUT_OFF' not in lib:
    bad.append('idle_session.php lost the emergency switch IDLE_TIMEOUT_OFF')
if "X-Etel-App" not in js or 'RIDE' not in js or 'startRide' not in js:
    bad.append('etel-idle-logout.js lost the rule that the Ride pages (/ride/, /driver/) have no timer and mark their requests with X-Etel-App: ride')
if 'etel_idle_ride_request()' not in lib or 'HTTP_X_ETEL_APP' not in lib:
    bad.append('idle_session.php lost the rule that a Ride request is never ended for being idle')
if 'X-Etel-App' not in boot:
    bad.append('boot.php no longer allows the X-Etel-App header')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: idle sign-out guarded')
