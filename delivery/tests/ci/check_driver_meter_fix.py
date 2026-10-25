"""Guards the trip-meter / waiting-clock fix: the driver add-on (js/driver-meter-fix.js) parses and is the tail of release v26 (v26 = v25 + the add-on, nothing else
changed), the server keeps its drift guards (GPS-speed stand-still rule, accuracy-scaled move floor) and sends the waiting charge, rate and cap with every meter answer,
and the native shell keeps the web driver app and the background task from reporting positions at the same time and fits the web apps to the phone."""
import filecmp
import os
import re
import subprocess

root = __file__.rsplit('/tests/', 1)[0]
repo = os.path.dirname(root)
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

addon = read('js/driver-meter-fix.js')
if subprocess.call(['node', '--check', root + '/js/driver-meter-fix.js']) != 0:
    bad.append('js/driver-meter-fix.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", addon):
    bad.append('no inline handlers in the add-on')
for must in ('window._updateWaitBox', 'window.drvApi', 'driver_update_location', 'driver_toggle_wait', 'wait_rate_per_min', 'wait_cap_minutes', 'watchPosition', 'clearWatch', 'params.speed'):
    if must not in addon:
        bad.append('driver-meter-fix.js lost: ' + must)

v25, v26 = root + '/js/releases/driver/v25', root + '/js/releases/driver/v26'
if not os.path.isdir(v26):
    bad.append('release v26 is missing')
else:
    for f in os.listdir(v25):
        if f == 'driver-bundle.js':
            continue
        if not os.path.exists(v26 + '/' + f) or not filecmp.cmp(v25 + '/' + f, v26 + '/' + f, shallow=False):
            bad.append('v26/' + f + ' must be identical to v25/' + f)
    b25 = open(v25 + '/driver-bundle.js', encoding='utf-8').read().rstrip('\n')
    b26 = open(v26 + '/driver-bundle.js', encoding='utf-8').read()
    if not b26.startswith(b25) or not b26.rstrip('\n').endswith(addon.rstrip('\n')):
        bad.append('v26/driver-bundle.js must be v25 + js/driver-meter-fix.js exactly (rebuild with tools/build_driver_release.py)')

php = read('api/controllers/RideController.php')
blk = php[php.index("case 'driver_update_location'"):php.index("case 'driver_beacon_ping'")]
for must in ("$gpsSpeed < 0.8 && $d < 0.05", "max(0.02, 2.0 * ($accuracy !== null ? $accuracy : 0) / 1000.0)", "'wait_etb' => $liveFareCalc['breakdown']['wait_etb']",
             "'wait_rate_per_min' =>", "'wait_cap_minutes' => rides_wait_cap_minutes($pdo)"):
    if must not in blk:
        bad.append('driver_update_location lost: ' + must)
if blk.count("'wait_etb' => $liveFareCalc['breakdown']['wait_etb']") != 2:
    bad.append('both meter answers (good and weak fix) must carry wait_etb')
tog = php[php.index("case 'driver_toggle_wait'"):php.index("case 'driver_arrived'")]
if "'wait_rate_per_min'" not in tog or "'wait_cap_minutes'" not in tog:
    bad.append('driver_toggle_wait must send wait_rate_per_min and wait_cap_minutes')

app = repo + '/Etel-Driver-App/src'
task = open(app + '/location/backgroundLocationTask.ts', encoding='utf-8').read()
screen = open(app + '/screens/WebAppScreen.tsx', encoding='utf-8').read()
bridge = open(app + '/hub/webBridge.ts', encoding='utf-8').read()
if 'webDriverForeground' not in task or 'if (!webDriverForeground)' not in task or 'export function setWebDriverForeground' not in task:
    bad.append('the native location task must stay quiet while the web driver app is on screen')
for must in ('setWebDriverForeground', 'useSafeAreaInsets', 'FIT_JS', 'pullToRefreshEnabled={false}', 'setBuiltInZoomControls={false}', 'onRenderProcessGone'):
    if must not in screen:
        bad.append('WebAppScreen lost: ' + must)
if 'export const FIT_JS' not in bridge or 'width=device-width' not in bridge:
    bad.append('webBridge lost FIT_JS')

if bad:
    print('check_driver_meter_fix FAILED:\n  - ' + '\n  - '.join(bad))
    raise SystemExit(1)
print('check_driver_meter_fix: ok')
