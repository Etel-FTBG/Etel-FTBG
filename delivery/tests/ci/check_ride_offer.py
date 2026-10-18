#!/usr/bin/env python3
"""Offer waves: the nearest drivers see a new ride request first, the rest later; wired into poll, stream, push."""
import subprocess, sys, tempfile, os

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

ride = read('api/controllers/RideController.php')
boot = read('api/boot.php')
offer = read('api/lib/ride_offer.php')
if 'rides_offer_filter(' not in ride.split("case 'driver_get_nearby_requests'", 1)[1].split("case 'driver_get_goal_tiers'", 1)[0]:
    bad.append('driver_get_nearby_requests must filter by offer wave')
if 'rides_offer_filter(' not in boot.split("case 'sse_driver_stream'", 1)[1].split("case 'sse_ride_status'", 1)[0] and \
   'rides_offer_filter(' not in boot.split("case 'sse_driver_stream'", 1)[1][:9000]:
    bad.append('the driver request stream must filter by offer wave')
if 'rides_offer_first_push_targets(' not in ride:
    bad.append('book_ride must push only the first waves')
if 'rides_offer_escalate(' not in ride or 'rides_offer_escalate(' not in boot:
    bad.append('the wider wake-up push (rides_offer_escalate) must be triggered from ride_status and the status stream')
mig = read('api/migrations/087_ride_offer_waves.sql')
for k in ('ride_offer_wave1_size', 'ride_offer_wave2_size', 'ride_offer_wave_secs', 'ride_offer_log'):
    if k not in mig:
        bad.append('migration 087 is missing ' + k)

# run the real ranking functions
php = r'''<?php
define('APP_VER', 't');
require_once %r . '/api/lib/ride_fare.php';
require_once %r . '/api/lib/ride_offer.php';
$cfg = array('w1' => 3, 'w2' => 8, 'secs' => 20);
$fail = array();
function t($ok, $m) { global $fail; if (!$ok) $fail[] = $m; }
// pickup at 0,0; competitors at increasing distance east
$comp = array();
for ($i = 1; $i <= 10; $i++) $comp[] = array('user_id' => 100 + $i, 'lat' => 0.0, 'lng' => 0.001 * $i);
$d = rides_haversine_km(0, 0, 0.0, 0.0055);   // me: between competitor 5 and 6
$rank = rides_offer_rank($d, 999, 0, 0, $comp, array());
t($rank === 6, 'rank should be 6, got ' . $rank);
t(!rides_offer_allows($cfg, $rank, 5),  'rank 6 must not see a 5 s old ride');
t(!rides_offer_allows($cfg, $rank, 19), 'rank 6 must not see a 19 s old ride');
t(rides_offer_allows($cfg, $rank, 20),  'rank 6 sees the ride after one wave');
t(!rides_offer_allows($cfg, 9, 25),     'rank 9 must not see a 25 s old ride');
t(rides_offer_allows($cfg, 9, 40),      'everyone sees the ride after two waves');
t(rides_offer_allows($cfg, 1, 0),       'the nearest driver sees it at once');
t(rides_offer_allows($cfg, 3, 0) && !rides_offer_allows($cfg, 4, 0), 'wave 1 is exactly 3 drivers');
// a driver who declined does not take a place in line
$rank2 = rides_offer_rank($d, 999, 0, 0, $comp, array(101 => 1, 102 => 1));
t($rank2 === 4, 'declined drivers are not counted, got ' . $rank2);
// ties: lower id first, never two firsts
$same = rides_haversine_km(0, 0, 0.0, 0.01);
$a = rides_offer_rank($same, 5, 0, 0, array(array('user_id' => 7, 'lat' => 0.0, 'lng' => 0.01)), array());
$b = rides_offer_rank($same, 7, 0, 0, array(array('user_id' => 5, 'lat' => 0.0, 'lng' => 0.01)), array());
t($a === 1 && $b === 2, 'ties go to the lower user id');
echo $fail ? implode("\n", $fail) : 'OK';
''' % (root, root)
with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False) as f:
    f.write(php); path = f.name
try:
    r = subprocess.run(['php', path], capture_output=True, text=True)
finally:
    os.unlink(path)
out = (r.stdout + r.stderr).strip()
if out != 'OK':
    bad.append('ranking functions: ' + out[:400])

if bad:
    print('check_ride_offer FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_ride_offer OK')
