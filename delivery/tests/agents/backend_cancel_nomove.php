<?php
// Behavioural test: a customer who ends a started trip (cancel_ride on an in_progress ride) pays the base fare if they did not move,
// and only the distance actually travelled otherwise. Runs the REAL RideController against a local MariaDB (ANSI_QUOTES on).
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); ini_set('display_errors', '1');
define('APP_VER', 'test'); define('TOKEN_SECRET_FINAL', 'test-secret');
date_default_timezone_set('Africa/Addis_Ababa');
$API = realpath(__DIR__ . '/../../api');
class Resp extends Error { public $data; public $ok; }
function ok($d = null) { if (!isset($GLOBALS['LAST'])) $GLOBALS['LAST'] = array('ok' => true, 'data' => $d); $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { if (!isset($GLOBALS['LAST'])) $GLOBALS['LAST'] = array('ok' => false, 'data' => $m); $r = new Resp($m); $r->ok = false; $r->data = $x; throw $r; }
function db() { global $PDO; return $PDO; }
function fail_ex($e, $m = 'error', $c = 500) { fail($m . ': ' . $e->getMessage(), $c); }
function require_auth($pdo) { return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { return $GLOBALS['CUR']; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
foreach (array('vehicle_type VARCHAR(20) NULL', 'open_trip TINYINT(1) NOT NULL DEFAULT 0', 'traveled_km DECIMAL(8,2) NOT NULL DEFAULT 0', 'started_at DATETIME NULL', 'arrived_at DATETIME NULL', 'distance_km DECIMAL(8,2) NULL',
    'wait_active TINYINT(1) NOT NULL DEFAULT 0', 'wait_started_at DATETIME NULL', 'wait_seconds_total INT NOT NULL DEFAULT 0', 'surge_applied TINYINT(1) NOT NULL DEFAULT 0', 'fare_breakdown_json TEXT NULL', 'fare_final DECIMAL(10,2) DEFAULT 0', 'fare_estimate DECIMAL(10,2) DEFAULT 0', 'driver_id INT NULL', 'cancelled_at DATETIME NULL', 'cancel_reason VARCHAR(255) NULL') as $c) {
    try { $PDO->exec('ALTER TABLE rides ADD COLUMN IF NOT EXISTS ' . $c); } catch (Exception $e) { /* already there */ }
}
if (!isset($argv[1])) $PDO->exec('TRUNCATE TABLE rides');
$PDO->exec("INSERT INTO users (name, phone, role, created_at) VALUES ('Cancel Cust', '0955888001', 'customer', NOW()) ON DUPLICATE KEY UPDATE name = name");
$cust = intval($PDO->query("SELECT id FROM users WHERE phone = '0955888001'")->fetchColumn());
if (isset($argv[1]) && $argv[1] === 'child') {
    $rid = intval($argv[2]); $GLOBALS['CUR'] = array('id' => intval($argv[3]), 'name' => 'Cancel Cust', 'phone' => '0955888001', 'role' => 'customer');
    $action = 'cancel_ride'; $body = array('ride_id' => $rid); $u = $GLOBALS['CUR']; $pdo = db();
    try { require $API . '/controllers/RideController.php'; } catch (Throwable $e) { /* first ok()/fail() already recorded */ }
    echo json_encode($GLOBALS['LAST']); exit;
}
$PASS = 0; $FAILS = array();
function t($n, $c, $x = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($x !== '' ? ' -> ' . $x : ''); }
function cancel($rid) {
    global $cust; $o = shell_exec('php ' . escapeshellarg(__FILE__) . ' child ' . intval($rid) . ' ' . intval($cust) . ' 2>/dev/null'); if (getenv("DBG")) fwrite(STDERR, "RAW: " . $o . "\n");
    $lines = array_values(array_filter(array_map('trim', explode("\n", $o)))); $j = json_decode(end($lines), true);
    $r = new Resp('x'); $r->ok = !empty($j['ok']); $r->data = isset($j['data']) ? $j['data'] : null; return $r;
}
function mkride($km) {
    global $PDO, $cust;
    $PDO->prepare("INSERT INTO rides (passenger_id, pickup_lat, pickup_lng, pickup_address, dropoff_address, distance_km, fare_estimate, fare_final, payment_method, status, open_trip, vehicle_type, traveled_km, started_at, created_at) VALUES (?,7.06,38.47,'A','B',4,150,150,'cash','in_progress',1,'bajaj',?,NOW(),NOW())")->execute(array($cust, $km));
    return intval($PDO->lastInsertId());
}
require_once $API . '/lib/ride_fare.php';
$base = round(floatval(rides_fare_rule($PDO, 'bajaj')['base_fare']) * rides_calc_fare_no_move($PDO, 'bajaj', true, 0)['breakdown']['surge_mult'], 2);

$rid = mkride(0.0); $r = cancel($rid);
$row = $PDO->query("SELECT status, fare_final, distance_km FROM rides WHERE id = $rid")->fetch();
t('no movement: the trip ends and is billed (not a free cancel)', $r && $r->ok && !empty($r->data['ended_early']) && $row['status'] === 'arrived', json_encode($r ? $r->data : null) . json_encode($row));
t('no movement: the fare is the base fare only', abs(floatval($row['fare_final']) - $base) < 0.011 && floatval($row['distance_km']) == 0.0, json_encode($row) . ' base ' . $base);

$rid = mkride(1.5); $r = cancel($rid);
$row = $PDO->query("SELECT status, fare_final, distance_km FROM rides WHERE id = $rid")->fetch();
$full = rides_calc_fare($PDO, 1.5, 'bajaj', true, 0)['fare'];
t('moved 1.5 km: billed for 1.5 km only, not the booked 4 km', $row['status'] === 'arrived' && abs(floatval($row['fare_final']) - $full) < 0.011 && floatval($row['distance_km']) == 1.5, json_encode($row) . ' expected ' . $full);
t('moved 1.5 km: more than the base fare', floatval($row['fare_final']) > $base);
echo 'PASSED: ' . $PASS . "\nFAILED: " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo '  X ' . $f . "\n";
