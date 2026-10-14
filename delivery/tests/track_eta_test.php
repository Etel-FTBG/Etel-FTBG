<?php
// Customer arrival estimate: the maths (live vs typical vs none), the typical times from real deliveries, and hiding a stale courier pin.
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'track-eta-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/courier_offers.php';
require_once __DIR__ . '/../api/lib/order_stamps.php';
require_once __DIR__ . '/../api/lib/track_eta.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "the maths\n";
check('delivered and cancelled orders get no estimate', etel_eta_estimate(array('status' => 'delivered')) === null && etel_eta_estimate(array('status' => 'cancelled')) === null);
$r = etel_eta_estimate(array('status' => 'in_transit', 'fresh' => true, 'km' => 3.0));
check('live: 3 km x 1.3 at 20 km/h = 12 min + 2 handover = 14', $r && $r['minutes'] === 14 && $r['basis'] === 'live' && $r['km'] == 3.0);
check('the shown range brackets the estimate and is at least 1 min wide', $r['low'] < $r['minutes'] && $r['high'] > $r['minutes']);
$r = etel_eta_estimate(array('status' => 'in_transit', 'fresh' => true, 'km' => 0.0));
check('courier at the door: still at least 1 min, never 0', $r && $r['minutes'] >= 1 && $r['low'] >= 1 && $r['high'] > $r['low']);
$r = etel_eta_estimate(array('status' => 'in_transit', 'fresh' => false, 'km' => null, 'pickup_age_sec' => 600, 'trip_min' => 20));
check('on the road, courier position paused: typical trip 20 less 10 used = 10 min', $r && $r['minutes'] === 10 && $r['basis'] === 'typical');
$r = etel_eta_estimate(array('status' => 'in_transit', 'fresh' => false, 'pickup_age_sec' => 3000, 'trip_min' => 20));
check('already past the typical trip: floor of 3 min, not zero or negative', $r && $r['minutes'] === 3);
$r = etel_eta_estimate(array('status' => 'accepted', 'age_sec' => 300, 'lead_min' => 15, 'trip_min' => 20));
check('before pickup: (15 - 5 already used) + 20 = 30 min', $r && $r['minutes'] === 30 && $r['basis'] === 'typical');
$r = etel_eta_estimate(array('status' => 'pending', 'age_sec' => 6000, 'lead_min' => 15, 'trip_min' => 20));
check('late before pickup: wait floor of 5 + 20 = 25 min', $r && $r['minutes'] === 25);
check('no history: NO time is invented (null)', etel_eta_estimate(array('status' => 'pending', 'age_sec' => 60)) === null);
check('on the road, no position, no history: null', etel_eta_estimate(array('status' => 'in_transit', 'fresh' => false)) === null);
check('distance: Hawassa 0.01 degree latitude is about 1.1 km', abs(etel_eta_km(7.05, 38.47, 7.06, 38.47) - 1.11) < 0.05);

echo "typical times come from real deliveries\n";
etel_offers_ensure_schema($pdo); etel_order_has_pickup_col($pdo);
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'ETA-%'");
$ins = $pdo->prepare("INSERT INTO orders (order_code, customer_id, from_zone, to_zone, status, placed_at, picked_up_at, delivered_at) VALUES (?, 1, 'A', 'B', 'delivered', '2020-05-01 12:00:00', '2020-05-01 12:10:00', '2020-05-01 12:30:00')");
for ($i = 0; $i < 4; $i++) $ins->execute(array('ETA-' . $i));
// the 200 newest delivered orders decide, so with fewer than 5 of them we cannot prove anything: skip the "not enough" check
// when other tests' rows are present, and fill the window with our own rows to make the numbers exact.
for ($i = 4; $i < 204; $i++) $ins->execute(array('ETA-' . $i));
$t = etel_eta_typical($pdo);
check('typical: 10 min until pickup, 20 min trip, from 200 orders', $t['n'] === 200 && abs($t['lead_min'] - 10) < 0.01 && abs($t['trip_min'] - 20) < 0.01);
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'ETA-%'");

echo "a stale courier pin is hidden\n";
$pdo->exec("INSERT INTO orders (order_code, customer_id, from_zone, to_zone, status, placed_at) VALUES ('ETA-LIVE', 1, 'A', 'B', 'in_transit', NOW())");
$oid = (int) $pdo->query("SELECT id FROM orders WHERE order_code='ETA-LIVE'")->fetchColumn();
$base = array('id' => $oid, 'status' => 'in_transit', 'courier_name' => 'Abebe', 'courier_lat' => 7.05, 'courier_lng' => 38.47, 'delivery_lat' => 7.06, 'delivery_lng' => 38.47);
$o = etel_track_eta_apply($pdo, $base + array('courier_seen_sec' => 30));
check('fresh courier: pin kept, live estimate present', $o['courier_stale'] === false && $o['courier_lat'] == 7.05 && $o['eta'] && $o['eta']['basis'] === 'live');
$o = etel_track_eta_apply($pdo, $base + array('courier_seen_sec' => 900));
check('courier silent for 15 min: pin hidden and flagged, no live estimate', $o['courier_stale'] === true && $o['courier_lat'] === null && (!$o['eta'] || $o['eta']['basis'] !== 'live'));
$o = etel_track_eta_apply($pdo, array_merge($base, array('status' => 'delivered')) + array('courier_seen_sec' => 99999));
check('delivered order: never flagged stale, no estimate', $o['courier_stale'] === false && $o['eta'] === null);
$o = etel_track_eta_apply($pdo, array('id' => $oid, 'status' => 'pending', 'courier_name' => null, 'courier_lat' => null, 'courier_lng' => null));
check('no courier yet: nothing breaks', $o['courier_stale'] === false);
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'ETA-%'");

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
