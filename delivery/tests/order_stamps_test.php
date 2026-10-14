<?php
// Order journey timestamps: picked_up_at is stamped once, only for orders really picked up, and the admin averages
// (courier trip = picked up -> delivered, wait = accepted -> picked up) ignore missing and unbelievable times.
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
// api/lib files stop silently unless the API is 'booted'; the other CLI tests define this the same way.
if (!defined('APP_VER')) define('APP_VER', 'order-stamps-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/courier_offers.php';
require_once __DIR__ . '/../api/lib/order_stamps.php';
require_once __DIR__ . '/../api/lib/platform_pulse.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }
etel_offers_ensure_schema($pdo);   // courier_accepted_at

echo "order timestamps\n";
check('picked_up_at column exists (migration or created on first use)', etel_order_has_pickup_col($pdo));

$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'STAMP-%'");
$ins = $pdo->prepare("INSERT INTO orders (order_code, customer_id, from_zone, to_zone, status, placed_at, courier_accepted_at, picked_up_at, delivered_at)
    VALUES (?, 1, 'Stadium', 'Tabor', ?, '2020-04-01 12:00:00', ?, ?, ?)");
$day = '2020-04-01 ';
function at($m) { return date('Y-m-d H:i:s', strtotime('2020-04-01 12:00:00') + $m * 60); }

// stamping: an assigned order is not stamped; a picked-up order is stamped once and keeps its first time
$ins->execute(array('STAMP-A', 'assigned', at(5), null, null));
check('an order that is only assigned is not stamped', etel_order_stamp_pickup($pdo, (int) $pdo->query("SELECT id FROM orders WHERE order_code='STAMP-A'")->fetchColumn()) === false);
$pdo->exec("UPDATE orders SET status='picked_up' WHERE order_code='STAMP-A'");
$idA = (int) $pdo->query("SELECT id FROM orders WHERE order_code='STAMP-A'")->fetchColumn();
check('a picked-up order gets picked_up_at', etel_order_stamp_pickup($pdo, $idA) === true && $pdo->query("SELECT picked_up_at IS NOT NULL FROM orders WHERE id=$idA")->fetchColumn() == 1);
$pdo->exec("UPDATE orders SET picked_up_at = '2020-04-01 12:30:00' WHERE id=$idA");
etel_order_stamp_pickup($pdo, $idA);
check('the first pickup time is kept (a second call does not overwrite)', $pdo->query("SELECT picked_up_at FROM orders WHERE id=$idA")->fetchColumn() === '2020-04-01 12:30:00');
$pdo->exec("DELETE FROM orders WHERE order_code='STAMP-A'");

// averages. trip = picked up -> delivered (1..180), wait = accepted -> picked up (0..180)
$ins->execute(array('STAMP-1', 'delivered', at(5), at(15), at(35)));    // wait 10, trip 20
$ins->execute(array('STAMP-2', 'delivered', at(5), at(25), at(65)));    // wait 20, trip 40
$ins->execute(array('STAMP-3', 'delivered', at(5), at(15), at(500)));   // trip 485: unbelievable, left out of trip; wait 10 still counts
$ins->execute(array('STAMP-4', 'delivered', at(5), null, at(60)));      // no pickup time (old order / admin close): in neither
$ins->execute(array('STAMP-5', 'delivered', at(5), at(15), at(10)));    // delivered before pickup: left out of trip
$r = etel_pulse_period($pdo, "'2020-04-01'", "'2020-04-02'");
check('courier trip average = (20 + 40) / 2 = 30', $r['avg_trip'] === 30 && $r['avg_trip_n'] === 2);
check('wait for pickup average = (10 + 20 + 10 + 10) / 4 = 12.5 -> 13 over 4 orders', $r['avg_wait'] === 13 && $r['avg_wait_n'] === 4);
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'STAMP-%'");
$r = etel_pulse_period($pdo, "'2020-04-01'", "'2020-04-02'");
check('no timed orders: both averages are empty, not zero', $r['avg_trip'] === null && $r['avg_wait'] === null && $r['avg_trip_n'] === 0);

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
