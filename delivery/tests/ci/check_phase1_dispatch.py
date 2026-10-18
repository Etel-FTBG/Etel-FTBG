"""Guards Phase 1 dispatch fixes: Decline reaches the server (API, driver PWA, native app), request lists are nearest-first, dispatch indexes exist."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

ride = read('api/controllers/RideController.php')
boot = read('api/boot.php')
if "case 'driver_decline_ride'" not in ride:
    bad.append('RideController.php has no driver_decline_ride handler')
elif "INSERT IGNORE INTO ride_driver_exclusions" not in ride.split("case 'driver_decline_ride'", 1)[1].split("case 'driver_accept_ride'", 1)[0]:
    bad.append('driver_decline_ride must record the decline in ride_driver_exclusions')
if "case 'driver_decline_ride'" not in boot:
    bad.append('boot.php does not route driver_decline_ride')
if boot.count('usort($nearby') < 1 or ride.count("usort($out") < 1:
    bad.append('driver request lists (poll and stream) must be sorted nearest first')

sync = read('js/driver-decline-sync.js')
if 'driver_decline_ride' not in sync or 'declineRequest(' not in sync:
    bad.append('js/driver-decline-sync.js must send driver_decline_ride when Decline is tapped')
if 'driver-decline-sync.js' not in read('driver/index.html'):
    bad.append('driver/index.html does not load driver-decline-sync.js')

native = read('../Etel-Driver-App/src/screens/RequestsScreen.tsx')
if 'declineRide(' not in native or 'driver_decline_ride' not in read('../Etel-Driver-App/src/api/ride.ts'):
    bad.append('the native driver app must call driver_decline_ride on Decline')

mig = read('api/migrations/086_ride_dispatch_indexes.sql')
for idx in ('idx_rides_status_expires', 'idx_ride_drivers_online_seen', 'idx_ride_path_ride_time'):
    if idx not in mig:
        bad.append('migration 086 is missing ' + idx)

if bad:
    print('check_phase1_dispatch FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_phase1_dispatch OK')
