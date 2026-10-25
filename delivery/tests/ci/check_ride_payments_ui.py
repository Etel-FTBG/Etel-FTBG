"""Guards the Ride Payments console (js/admin-ride-payments.js): it parses, is built on AdminKit and loaded after it, uses the kit's dialogs (never window.prompt/confirm/alert),
asks for a reason before rejecting a top-up, escapes server values, has no inline handlers, never uses a class name containing "-card" (the admin theme paints a glare over those),
guards CSV cells against spreadsheet formulas, and the backend keeps its window whitelist."""
import re
import subprocess

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

js = read('js/admin-ride-payments.js')
if subprocess.call(['node', '--check', root + '/js/admin-ride-payments.js']) != 0:
    bad.append('js/admin-ride-payments.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('no inline handlers in admin-ride-payments.js')
if re.search(r'window\.(prompt|confirm|alert)\(|[^.\w](prompt|confirm|alert)\(', js):
    bad.append('use AdminKit dialogs, not window.prompt/confirm/alert')
if re.search(r'class="[^"]*-card', js) or re.search(r"'[^']*-card[ '\"]", js):
    bad.append('a class name containing "-card" gets the admin glare overlay; rename it')
for must in ('window.AdminKit', 'K.confirm', "reason: 'Reason for rejecting'", 'required: true', 'admin_ride_payments', 'admin_approve_etel_topup', 'admin_reject_etel_topup',
             'window.ridePaymentsLoad', "csvCell", "rel=\"noopener\"", 'safeUrl'):
    if must not in js:
        bad.append('admin-ride-payments.js lost: ' + must)
if 'innerHTML' in js and 'esc(' not in js:
    bad.append('server values must go through AdminKit.esc')
if "x = \"'\" + x" not in js:
    bad.append('CSV cells starting with = + - @ must be neutralised')

html = read('admin.html')
m = re.search(r'src="js/admin-ride-payments\.js[^"]*"', html)
k = re.search(r'src="js/admin-kit\.js[^"]*"', html)
if not m or not k or m.start() < k.start():
    bad.append('admin.html must load admin-ride-payments.js after admin-kit.js')
if 'id="ridepay-body"' not in html:
    bad.append('admin.html lost #ridepay-body')
if re.search(r'\.rp-tile|\.rp-t\{', html):
    bad.append('the old inline .rp-* styles should be gone')

ctl = read('api/controllers/AdminController.php')
blk = ctl[ctl.index("case 'admin_ride_payments'"):ctl.index("case 'admin_get_pending_etel_topups'")]
if 'in_array($days, array(7, 30, 90, 365), true)' not in blk:
    bad.append('admin_ride_payments must whitelist the window (7/30/90/365)')
if re.search(r'\$body\[.days.\]\s*[.\]]\s*"', blk) or '. $body' in blk:
    bad.append('admin_ride_payments must not put request values into SQL')
if "etel_require_ride_pay($pdo, 'finance.view', 'rides.view')" not in blk:
    bad.append('admin_ride_payments lost its permission gate')

if bad:
    print('check_ride_payments_ui FAILED:\n  - ' + '\n  - '.join(bad))
    raise SystemExit(1)
print('check_ride_payments_ui: ok')
