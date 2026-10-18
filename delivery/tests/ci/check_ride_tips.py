"""Guards ride tips: the rider and admin screens are wired in, every action is routed and handled, and the money rules are
where they belong (a tip waits for an admin; approving credits the driver once and in full; one tip per trip and per
transfer reference)."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

page = read('ride/index.html')
if 'js/ride-tip.js' not in page:
    bad.append('ride/index.html does not load js/ride-tip.js')
if 'js/admin-ride-tips.js' not in read('admin.html'):
    bad.append('admin.html does not load js/admin-ride-tips.js')

boot = read('api/boot.php')
ctl = read('api/controllers/RideController.php')
for action in ('ride_tip_info', 'ride_tip_give', 'driver_get_tips', 'admin_ride_tip_list', 'admin_ride_tip_review'):
    if "case '%s'" % action not in boot:
        bad.append('boot.php does not route ' + action)
    if "case '%s': {" % action not in ctl:
        bad.append('RideController.php does not handle ' + action)

review = ctl.split("case 'admin_ride_tip_review': {", 1)[1].split("\ncase '", 1)[0]
if "'rides.manage'" not in review:
    bad.append('admin_ride_tip_review must require rides.manage')
if "status='pending'" not in review or 'rowCount() < 1' not in review:
    bad.append('approving must claim the pending status first so a double click pays the driver once')
if 'total_earnings = total_earnings + ?' not in review or 'commission' in review.lower():
    bad.append('an approved tip must be added to total_earnings in full (no commission)')
give = ctl.split("case 'ride_tip_give': {", 1)[1].split("\ncase '", 1)[0]
if "'pending'" not in give or "'approved'" in give:
    bad.append('a new tip must start as pending')
if 'total_earnings' in give:
    bad.append('giving a tip must not credit the driver')

lib = read('api/lib/ride_tip.php')
if 'uq_ride_tip_ride' not in lib or 'uq_ride_tip_ref' not in lib:
    bad.append('one tip per trip and one transfer reference per tip must be enforced by unique keys')
mig = read('api/migrations/089_ride_tips.sql')
if 'ride_tips' not in mig or 'uq_ride_tip_ref' not in mig:
    bad.append('migration 089 is missing the ride_tips table')

if bad:
    print('check_ride_tips FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_ride_tips OK')
