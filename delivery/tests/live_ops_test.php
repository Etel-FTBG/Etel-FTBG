<?php
// Run: php delivery/tests/live_ops_test.php   (needs MariaDB database fa_test, sql_mode ANSI_QUOTES; it creates the small tables it needs)
// Live operations numbers: the REAL lib/live_ops.php and the REAL controller file.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); return $u; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
function build() {
    global $PDO;
    foreach (array('users', 'rides', 'ride_drivers', 'orders', 'couriers', 'sos_alerts') as $t) $PDO->exec('DROP TABLE IF EXISTS `' . $t . '`');
    $PDO->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', role VARCHAR(20) NOT NULL DEFAULT 'customer')");
    $PDO->exec("CREATE TABLE rides (id INT AUTO_INCREMENT PRIMARY KEY, passenger_id INT NOT NULL, driver_id INT DEFAULT NULL, pickup_address VARCHAR(255) DEFAULT '', status VARCHAR(20) DEFAULT 'searching', assigned_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)");
    $PDO->exec("CREATE TABLE ride_drivers (user_id INT NOT NULL PRIMARY KEY, online TINYINT(1) DEFAULT 0, last_seen DATETIME DEFAULT NULL)");
    $PDO->exec("CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, order_code VARCHAR(30) NOT NULL, courier_id INT DEFAULT NULL, from_zone VARCHAR(60) DEFAULT '', to_zone VARCHAR(60) DEFAULT '', status VARCHAR(30) DEFAULT 'pending', placed_at DATETIME DEFAULT CURRENT_TIMESTAMP, courier_assigned_at DATETIME DEFAULT NULL, delivered_at DATETIME DEFAULT NULL)");
    $PDO->exec("CREATE TABLE sos_alerts (id INT AUTO_INCREMENT PRIMARY KEY, reporter_id INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL)");
    $PDO->exec("CREATE TABLE couriers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, status VARCHAR(20) DEFAULT 'offline', last_seen DATETIME DEFAULT NULL)");
}
function seed() {
    global $PDO;
    $PDO->exec("INSERT INTO users (id, name, role) VALUES (1,'Owner','admin'),(100,'Pax One','customer'),(101,'Pax Two','customer'),(200,'Driver A','driver'),(201,'Driver B','driver'),(202,'Driver C','driver'),(203,'Driver D','driver')");
    $PDO->exec("INSERT INTO ride_drivers (user_id, online, last_seen) VALUES (200,1,NOW()),(201,1,NOW()),(202,1,DATE_SUB(NOW(), INTERVAL 2 HOUR)),(203,0,NOW())");
    $PDO->exec("INSERT INTO rides (passenger_id, driver_id, pickup_address, status, assigned_at, created_at) VALUES
      (100, NULL, 'Stadium gate', 'searching', NULL, DATE_SUB(NOW(), INTERVAL 6 MINUTE)),
      (101, NULL, 'Piassa', 'searching', NULL, DATE_SUB(NOW(), INTERVAL 1 MINUTE)),
      (100, 200, 'a', 'accepted', DATE_ADD(NOW(), INTERVAL 20 SECOND), NOW()),
      (100, 201, 'b', 'completed', DATE_ADD(NOW(), INTERVAL 40 SECOND), NOW()),
      (101, NULL, 'c', 'cancelled', NULL, NOW()),
      (100, 200, 'd', 'in_progress', DATE_ADD(NOW(), INTERVAL 60 SECOND), NOW()),
      (100, 201, 'old', 'completed', DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 100 SECOND), DATE_SUB(NOW(), INTERVAL 3 DAY))");
    $PDO->exec("INSERT INTO sos_alerts (reporter_id, status, created_at) VALUES (200,'open',DATE_SUB(NOW(), INTERVAL 7 MINUTE)),(201,'open',NOW()),(200,'resolved',DATE_SUB(NOW(), INTERVAL 2 HOUR))");
    $PDO->exec("INSERT INTO couriers (user_id, status, last_seen) VALUES (300,'online',NOW()),(301,'busy',NOW()),(302,'online',DATE_SUB(NOW(), INTERVAL 2 HOUR)),(303,'offline',NOW())");
    $PDO->exec("INSERT INTO orders (order_code, courier_id, from_zone, to_zone, status, placed_at, courier_assigned_at, delivered_at) VALUES
      ('ET-1', NULL, 'Stadium', 'Piassa', 'pending', DATE_SUB(NOW(), INTERVAL 12 MINUTE), NULL, NULL),
      ('ET-2', 300, 'a', 'b', 'assigned', NOW(), DATE_ADD(NOW(), INTERVAL 30 SECOND), NULL),
      ('ET-3', 301, 'a', 'b', 'picked_up', NOW(), DATE_ADD(NOW(), INTERVAL 45 SECOND), NULL),
      ('ET-4', 300, 'a', 'b', 'delivered', NOW(), DATE_ADD(NOW(), INTERVAL 60 SECOND), DATE_ADD(NOW(), INTERVAL 1800 SECOND)),
      ('ET-5', NULL, 'a', 'b', 'cancelled', NOW(), NULL, NULL),
      ('ET-6', 300, 'a', 'b', 'delivered', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 120 SECOND), DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 3000 SECOND))");
}
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($action, $user) {
    $GLOBALS['CUR'] = $user; $body = array();
    try { (function () use ($action, $body) { require dirname(__DIR__) . '/api/controllers/LiveOpsController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
$ADMIN = array('id' => 1, 'is_admin' => 1); $CUST = array('id' => 100, 'is_admin' => 0);

build(); seed();
t('not signed in = 401', call('admin_live_ops', null)['code'] === 401);
t('a customer = 403', call('admin_live_ops', $CUST)['code'] === 403);
$r = call('admin_live_ops', $ADMIN); $d = $r['ok'];
t('an admin gets the numbers', $d !== null && isset($d['now'], $d['speed'], $d['alerts']), json_encode($r));
$n = $d['now'];
t('rides now: 2 searching, 1 on the way, 1 in progress', $n['rides']['searching'] === 2 && $n['rides']['on_the_way'] === 1 && $n['rides']['in_progress'] === 1, json_encode($n['rides']));
t('the oldest waiting request is listed first with its minutes and place', $n['rides']['oldest_wait_min'] >= 6 && $n['rides']['waiting'][0]['where'] === 'Stadium gate' && $n['rides']['waiting'][0]['who'] === 'Pax One' && count($n['rides']['waiting']) === 2, json_encode($n['rides']['waiting']));
t('drivers: 2 online (one seen 2 hours ago and one offline are not counted), 1 busy (counted once although on two rides), 1 free', $n['drivers']['online'] === 2 && $n['drivers']['busy'] === 1 && $n['drivers']['free'] === 1, json_encode($n['drivers']));
t('orders now: 1 waiting, 1 assigned, 1 on the way', $n['orders']['waiting'] === 1 && $n['orders']['assigned'] === 1 && $n['orders']['on_the_way'] === 1, json_encode($n['orders']));
t('the waiting order is listed with code, minutes and zones', $n['orders']['oldest_wait_min'] >= 12 && $n['orders']['waiting_list'][0]['code'] === 'ET-1' && $n['orders']['waiting_list'][0]['where'] === 'Stadium > Piassa', json_encode($n['orders']['waiting_list']));
t('couriers: 2 online (a stale one and an offline one are not counted), 1 free', $n['couriers']['online'] === 2 && $n['couriers']['free'] === 1, json_encode($n['couriers']));
t('open SOS alerts are counted (2 open, the resolved one is not) with the oldest age', $n['sos']['open'] === 2 && $n['sos']['oldest_min'] >= 7, json_encode($n['sos']));
t('an open SOS is the FIRST alert and it is critical', $d['alerts'][0]['level'] === 'critical' && strpos($d['alerts'][0]['text'], '2 SOS alert(s) are open') === 0 && strpos($d['alerts'][0]['text'], 'oldest for 7 min') !== false, json_encode($d['alerts'][0]));
$w = $d['speed']['week']['rides'];
t('week rides: 7 requests, 4 taken (57 percent), 1 gave up, 2 completed', $w['requests'] === 7 && $w['taken'] === 4 && $w['taken_pct'] === 57 && $w['gave_up'] === 1 && $w['completed'] === 2, json_encode($w));
t('week time to take a ride: typical 40 s, slowest 1 in 10 is 100 s', $w['secs_to_take']['n'] === 4 && $w['secs_to_take']['typical'] === 40 && $w['secs_to_take']['slow'] === 100, json_encode($w['secs_to_take']));
$td = $d['speed']['today']['rides'];
t('today rides: 3 taken (20, 40, 60 s): typical 40, slow 60; the 3-day-old ride is not in today', $td['taken'] === 3 && $td['secs_to_take']['typical'] === 40 && $td['secs_to_take']['slow'] === 60 && $td['requests'] >= 4 && $td['requests'] <= 6, json_encode($td));
$wo = $d['speed']['week']['orders'];
t('week orders: 6 placed, 4 taken, 2 delivered, 1 cancelled', $wo['placed'] === 6 && $wo['taken'] === 4 && $wo['delivered'] === 2 && $wo['cancelled'] === 1, json_encode($wo));
t('week orders: time to take typical 45 s, slow 120 s; time to deliver typical 1800 s, slow 3000 s', $wo['secs_to_take']['typical'] === 45 && $wo['secs_to_take']['slow'] === 120 && $wo['secs_to_deliver']['typical'] === 1800 && $wo['secs_to_deliver']['slow'] === 3000, json_encode($wo));
$tdo = $d['speed']['today']['orders'];
t('today orders: the 3-day-old order is not counted', in_array($tdo['placed'], array(4, 5), true), json_encode($tdo));
$texts = array(); foreach ($d['alerts'] as $a) $texts[] = $a['level'] . ':' . $a['text'];
t('alerts: a ride has waited 6 minutes (critical) and an order 12 minutes (warn)', count(array_filter($texts, function ($x) { return strpos($x, 'critical:A ride request has waited') === 0; })) === 1 && count(array_filter($texts, function ($x) { return strpos($x, 'warn:An order has waited') === 0; })) === 1, json_encode($texts));
t('no "nobody online" alert while drivers and couriers are online', count(array_filter($texts, function ($x) { return strpos($x, 'no driver is online') !== false || strpos($x, 'no courier is online') !== false; })) === 0);
t('it did not change anything (still 2 searching, order still pending)', intval($PDO->query("SELECT COUNT(*) FROM rides WHERE status='searching'")->fetchColumn()) === 2 && $PDO->query("SELECT status FROM orders WHERE order_code='ET-1'")->fetchColumn() === 'pending');

// nobody online
$PDO->exec("UPDATE ride_drivers SET online = 0"); $PDO->exec("UPDATE couriers SET status = 'offline'");
$d = call('admin_live_ops', $ADMIN)['ok']; $texts = array(); foreach ($d['alerts'] as $a) $texts[] = $a['text'];
t('no driver and no courier online with waiting requests: critical alerts', count(array_filter($texts, function ($x) { return strpos($x, 'no driver is online') !== false; })) === 1 && count(array_filter($texts, function ($x) { return strpos($x, 'no courier is online') !== false; })) === 1, json_encode($texts));
t('nobody online: free is 0, not negative', $d['now']['drivers']['online'] === 0 && $d['now']['drivers']['free'] === 0);

// an empty system
build();
$d = call('admin_live_ops', $ADMIN)['ok'];
t('an empty system: zeros, no alerts, no SOS, no percent and no times (null), no error', $d['now']['rides']['searching'] === 0 && $d['alerts'] === array() && $d['speed']['week']['rides']['taken_pct'] === null && $d['speed']['week']['rides']['secs_to_take']['typical'] === null && $d['speed']['week']['orders']['secs_to_deliver']['slow'] === null, json_encode($d['speed']['week']));
// a server without the courier_assigned_at column
seed(); $PDO->exec("ALTER TABLE orders DROP COLUMN courier_assigned_at");
$r = call('admin_live_ops', $ADMIN); $d = $r['ok'];
t('without courier_assigned_at: no error, "no data" for the time to take an order, delivery times still there', $d !== null && $d['has_order_assign_time'] === false && $d['speed']['week']['orders']['taken'] === null && $d['speed']['week']['orders']['secs_to_take']['typical'] === null && $d['speed']['week']['orders']['secs_to_deliver']['typical'] === 1800, json_encode($r['err']));
// a server without the couriers table
$PDO->exec("DROP TABLE couriers");
$r = call('admin_live_ops', $ADMIN);
t('without the couriers table: no error, courier numbers are null', $r['ok'] !== null && $r['ok']['now']['couriers']['online'] === null, json_encode($r['err']));
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);
