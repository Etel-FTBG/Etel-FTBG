<?php
// Run: php delivery/tests/gps_check_test.php   (needs MariaDB database fa_test, sql_mode ANSI_QUOTES; it creates the small tables it needs)
// Fake GPS checks: the REAL lib/gps_check.php and the REAL controller file.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); if ($cap === 'owner.only' && empty($u['owner'])) fail('Owner only', 403); return $u; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
function sqlfile($f) { global $PDO; foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../api/migrations/' . $f))))) as $q) $PDO->exec($q); }
function build() {
    global $PDO;
    foreach (array('users', 'ride_drivers', 'driver_gps_flags', 'app_hub_settings', 'driver_doc_reminders') as $t) $PDO->exec('DROP TABLE IF EXISTS `' . $t . '`');
    $PDO->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', role VARCHAR(20) NOT NULL DEFAULT 'driver')");
    $PDO->exec("CREATE TABLE ride_drivers (user_id INT NOT NULL PRIMARY KEY, onboarded TINYINT(1) DEFAULT 1, online TINYINT(1) DEFAULT 0, insurance_expiry_date DATE NULL, technical_inspection_expiry_date DATE NULL, road_fund_expiry_date DATE NULL)");
    sqlfile('112_app_hub_content.sql'); $PDO->exec('TRUNCATE TABLE app_hub_settings'); sqlfile('116_driver_gps_flags.sql'); sqlfile('115_driver_doc_reminders.sql');
    $PDO->exec("INSERT INTO users (id, name, phone) VALUES (1,'Abel','0911'),(2,'Sara','0912'),(3,'Clean','0913')");
    $PDO->exec("INSERT INTO ride_drivers (user_id, online) VALUES (1,1),(2,0),(3,1)");
}
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; $b = $body;
    try { (function () use ($action, $b) { $body = $b; require dirname(__DIR__) . '/api/controllers/TrustController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
require_once __DIR__ . '/../api/lib/gps_check.php';
$OWNER = array('id' => 99, 'is_admin' => 1, 'owner' => 1); $ADMIN = array('id' => 98, 'is_admin' => 1, 'owner' => 0); $CUST = array('id' => 97, 'is_admin' => 0, 'owner' => 0);

// ---- distance and the jump rule (pure)
$d = gps_distance_m(7.0621, 38.4761, 7.0711, 38.4761);
t('distance: 0.009 degrees of latitude is about 1000 m', abs($d - 1000) < 10, (string) $d);
t('distance of the same point is 0', gps_distance_m(7.06, 38.47, 7.06, 38.47) < 0.001);
$lat2 = 7.0621 + 2000 / 111195.0; // 2 km north
t('a 2 km move in 10 s (720 km/h) is a jump, with a readable description', ($j = gps_check_jump(7.0621, 38.4761, 10, $lat2, 38.4761)) !== null && strpos($j, 'moved 2 km in 10 s') === 0 && strpos($j, 'km/h') !== false, (string) $j);
t('a 2 km move in 100 s (72 km/h) is a normal drive', gps_check_jump(7.0621, 38.4761, 100, $lat2, 38.4761) === null);
t('a move shorter than 1.5 km is never a jump, however fast', gps_check_jump(7.0621, 38.4761, 5, 7.0621 + 1400 / 111195.0, 38.4761) === null);
t('after 5 minutes of silence even a long move is normal (301 s)', gps_check_jump(7.0621, 38.4761, 301, 7.0621 + 50000 / 111195.0, 38.4761) === null);
t('5 km in 300 s (60 km/h) is a normal drive; 5 km in 60 s (300 km/h) is a jump', gps_check_jump(7.0621, 38.4761, 300, 7.0621 + 5000 / 111195.0, 38.4761) === null && gps_check_jump(7.0621, 38.4761, 60, 7.0621 + 5000 / 111195.0, 38.4761) !== null);
t('the limit is 55 m/s: 1650 m in 30 s is fine, 1680 m in 30 s is a jump', gps_check_jump(7.0621, 38.4761, 30, 7.0621 + 1650 / 111195.0, 38.4761) === null && gps_check_jump(7.0621, 38.4761, 30, 7.0621 + 1680 / 111195.0, 38.4761) !== null);
t('no last position, no time, or 0 seconds: no verdict and no division by zero', gps_check_jump(null, null, 10, 7, 38) === null && gps_check_jump(7, 38, null, 7.1, 38) === null && gps_check_jump(7, 38, 0, 8, 38) === null && gps_check_jump(7, 38, -5, 8, 38) === null);

// ---- the log
build();
t('the switch is off by default', gps_block_enabled($PDO) === false);
t('an unknown kind is refused', gps_record($PDO, 1, 'teleport', 'x', 7, 38) === false);
t('the first sighting is written', gps_record($PDO, 1, 'mock', 'the phone reported a mock location', 7.06, 38.47) === true);
t('the same kind again within 10 minutes is not written (the app pings every 5 s)', gps_record($PDO, 1, 'mock', 'again', 7.06, 38.47) === false && intval($PDO->query("SELECT COUNT(*) FROM driver_gps_flags")->fetchColumn()) === 1);
t('another kind, or another driver, is written', gps_record($PDO, 1, 'jump', 'moved 2 km in 10 s (720 km/h)', 7.08, 38.47) === true && gps_record($PDO, 2, 'mock', 'x', 7, 38) === true);
$PDO->exec("UPDATE driver_gps_flags SET created_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE driver_user_id = 1 AND kind = 'mock'");
t('after 10 minutes the same kind can be written again', gps_record($PDO, 1, 'mock', 'later', 7.06, 38.47) === true);
t('a long description is cut to 160 letters', (function () use ($PDO) { gps_record($PDO, 3, 'jump', str_repeat('x', 400), 7, 38); return strlen($PDO->query("SELECT detail FROM driver_gps_flags WHERE driver_user_id = 3")->fetchColumn()) === 160; })());

// ---- the 6 hour window
$PDO->exec("DELETE FROM driver_gps_flags"); $PDO->exec("INSERT INTO driver_gps_flags (driver_user_id, kind, detail, created_at) VALUES (1,'mock','a',DATE_SUB(NOW(), INTERVAL 5 HOUR)),(2,'mock','b',DATE_SUB(NOW(), INTERVAL 7 HOUR)),(3,'jump','c',NOW())");
t('a mock sighting 5 hours ago counts, 7 hours ago does not, and a jump never counts', gps_recent_mock($PDO, 1) === true && gps_recent_mock($PDO, 2) === false && gps_recent_mock($PDO, 3) === false && gps_recent_mock($PDO, 999) === false);

// ---- the admin view
$PDO->exec("DELETE FROM driver_gps_flags");
$PDO->exec("INSERT INTO driver_gps_flags (driver_user_id, kind, detail, created_at) VALUES
  (1,'mock','m1',DATE_SUB(NOW(), INTERVAL 3 DAY)),(1,'mock','m2',DATE_SUB(NOW(), INTERVAL 2 HOUR)),(1,'jump','moved 3 km in 20 s (540 km/h)',DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  (2,'jump','j1',DATE_SUB(NOW(), INTERVAL 30 MINUTE)),(3,'mock','old',DATE_SUB(NOW(), INTERVAL 10 DAY))");
$o = gps_overview($PDO, 7); $by = array(); foreach ($o as $x) $by[$x['user_id']] = $x;
t('overview: drivers with sightings in the last 7 days (not the one from 10 days ago), newest first', count($o) === 2 && $o[0]['user_id'] === 2 && $o[1]['user_id'] === 1, json_encode(array_map(function ($x) { return $x['user_id']; }, $o)));
t('overview: counts per kind, last sighting, online flag, name and phone', $by[1]['mocks'] === 2 && $by[1]['jumps'] === 1 && $by[1]['last_kind'] === 'jump' && strpos($by[1]['last_detail'], '540 km/h') !== false && $by[1]['online'] === 1 && $by[2]['online'] === 0 && $by[1]['name'] === 'Abel' && $by[1]['phone'] === '0911', json_encode($by[1]));

// ---- the controller
t('a customer is refused', call('admin_driver_trust', array(), $CUST)['code'] === 403); t('nobody signed in: 401', call('admin_driver_trust', array(), null)['code'] === 401);
$r = call('admin_driver_trust', array(), $ADMIN);
t('an admin gets the gps part: switch state, 6 hour window, the drivers', $r['ok'] !== null && $r['ok']['gps']['block_fake'] === false && $r['ok']['gps']['block_hours'] === 6 && count($r['ok']['gps']['drivers']) === 2 && isset($r['ok']['documents']), json_encode($r['err']));
t('clearing needs the owner (admin 403, customer 403)', call('admin_gps_clear', array('driver_user_id' => 1), $ADMIN)['code'] === 403 && call('admin_gps_clear', array('driver_user_id' => 1), $CUST)['code'] === 403);
t('nothing was removed by the refused calls', intval($PDO->query("SELECT COUNT(*) FROM driver_gps_flags WHERE driver_user_id = 1")->fetchColumn()) === 3);
t('clearing needs a driver', call('admin_gps_clear', array(), $OWNER)['code'] === 422);
$r = call('admin_gps_clear', array('driver_user_id' => 1), $OWNER);
t('the owner clears one driver: 3 rows go, the others stay', $r['ok'] && $r['ok']['cleared'] === 3 && intval($PDO->query("SELECT COUNT(*) FROM driver_gps_flags WHERE driver_user_id = 2")->fetchColumn()) === 1 && gps_recent_mock($PDO, 1) === false);
$PDO->exec("INSERT INTO app_hub_settings (setting_key, setting_value) VALUES ('trust_block_fake_gps', '1')");
t('the switch reads on when set', gps_block_enabled($PDO) === true && call('admin_driver_trust', array(), $ADMIN)['ok']['gps']['block_fake'] === true);

// ---- missing table
$PDO->exec("DROP TABLE driver_gps_flags");
t('no flags table: everything is quiet, nothing throws', gps_record($PDO, 1, 'mock', 'x', 7, 38) === false && gps_recent_mock($PDO, 1) === false && gps_overview($PDO, 7) === array() && gps_clear($PDO, 1) === 0);
$PDO->exec("DROP TABLE app_hub_settings");
t('no settings table: the switch reads as off', gps_block_enabled($PDO) === false);
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);
