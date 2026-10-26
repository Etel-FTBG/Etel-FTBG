"""Guards two things a driver on a phone ran into: (1) the Etel app's hub opens the ETEL Driver app for the same roles the Driver web app lets in (driver, courier,
admin: many bajaj drivers hold a courier-role account and were shown "Other role"), and (2) the Driver web app's top bar fits a narrow phone (its status line, power,
language and theme buttons used to be wider than the screen)."""
import re

root = __file__.rsplit('/tests/', 1)[0]
repo = root.rsplit('/', 1)[0]
bad = []

web = open(root + '/js/driver.js', encoding='utf-8').read()
m = re.search(r"u\.role !== 'driver' && u\.role !== 'courier' && u\.role !== 'admin'", web)
if not m:
    bad.append("js/driver.js no longer lets in driver, courier and admin: update Etel-Driver-App/src/hub/session.ts ROLE_APP and this guard together")

sess = open(repo + '/Etel-Driver-App/src/hub/session.ts', encoding='utf-8').read()
if not re.search(r"driver:\s*\['driver',\s*'courier'\]", sess):
    bad.append("session.ts must open the driver app for the 'driver' and 'courier' roles")
if "role === 'admin') return true" not in sess:
    bad.append('an admin must still open every app')
if not re.search(r"courier:\s*\['courier'\]", sess) or not re.search(r"service:\s*\['service_giver'\]", sess):
    bad.append('the courier and service apps must stay limited to their own role')

html = open(root + '/driver/index.html', encoding='utf-8').read()
if 'id="drv-topbar-fit"' not in html or 'class="drv-titleblock"' not in html:
    bad.append('driver/index.html lost the narrow-phone top bar fit (style#drv-topbar-fit, .drv-titleblock)')
else:
    blk = html[html.index('id="drv-topbar-fit"'):]
    blk = blk[:blk.index('</style>')]
    for must in ('flex-wrap:nowrap', '.drv-online-pill{display:none', 'text-overflow:ellipsis', '@media (max-width:480px)'):
        if must not in blk:
            bad.append('drv-topbar-fit lost: ' + must)

if bad:
    print('check_etel_app_access FAILED:\n  - ' + '\n  - '.join(bad))
    raise SystemExit(1)
print('check_etel_app_access: ok')
