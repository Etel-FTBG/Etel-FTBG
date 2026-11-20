<?php
// Run: php delivery/tests/driver_docs_test.php   (needs MariaDB database fa_test, sql_mode ANSI_QUOTES; it creates the small tables it needs)
// Driver documents: the REAL lib/driver_docs.php and the REAL controller file. Only the push (a network call) is replaced by a recorder.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); return $u; }
$PUSHES = array();
function etel_push_send_async($pdo, $uid, $title, $body, $data = array()) { global $PUSHES; $PUSHES[] = array('uid' => $uid, 'title' => $title, 'body' => $body, 'data' => $data); return true; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
function sqlfile($f) { global $PDO; foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../api/migrations/' . $f))))) as $q) $PDO->exec($q); }
function build($withCols = true) {
    global $PDO;
    foreach (array('users', 'ride_drivers', 'driver_doc_reminders', 'app_hub_settings') as $t) $PDO->exec('DROP TABLE IF EXISTS `' . $t . '`');
    $PDO->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', role VARCHAR(20) NOT NULL DEFAULT 'driver')");
    $PDO->exec("CREATE TABLE ride_drivers (user_id INT NOT NULL PRIMARY KEY, onboarded TINYINT(1) DEFAULT 0, online TINYINT(1) DEFAULT 0" . ($withCols ? ", insurance_expiry_date DATE NULL, technical_inspection_expiry_date DATE NULL, road_fund_expiry_date DATE NULL" : "") . ")");
    sqlfile('112_app_hub_content.sql'); $PDO->exec('TRUNCATE TABLE app_hub_settings'); sqlfile('115_driver_doc_reminders.sql');
}
function seed() {
    global $PDO;
    $PDO->exec("INSERT INTO users (id, name, phone) VALUES (1,'Expired Ins','0911'),(2,'Soon Road','0912'),(3,'Today Tech','0913'),(4,'Fine','0914'),(5,'Not Onboarded','0915'),(6,'No Dates','0916'),(7,'Multiple','0917')");
    $d = function ($n) { return $n === null ? 'NULL' : "DATE_ADD(CURDATE(), INTERVAL $n DAY)"; };
    $rows = array(array(1, 1, 1, $d(-3), $d(null), $d(null)), array(2, 1, 0, $d(null), $d(60), $d(5)), array(3, 1, 0, $d(null), $d(0), $d(null)), array(4, 1, 0, $d(90), $d(90), $d(90)),
                  array(5, 0, 0, $d(-10), $d(null), $d(null)), array(6, 1, 0, $d(null), $d(null), $d(null)), array(7, 1, 1, $d(1), $d(null), $d(-1)));
    foreach ($rows as $r) $PDO->exec("INSERT INTO ride_drivers (user_id, onboarded, online, insurance_expiry_date, technical_inspection_expiry_date, road_fund_expiry_date) VALUES ($r[0], $r[1], $r[2], $r[3], $r[4], $r[5])");
}
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($user) {
    $GLOBALS['CUR'] = $user; $action = 'admin_driver_trust';
    try { (function () use ($action) { require dirname(__DIR__) . '/api/controllers/TrustController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
require_once __DIR__ . '/../api/lib/driver_docs.php';
$ADMIN = array('id' => 99, 'is_admin' => 1); $CUST = array('id' => 98, 'is_admin' => 0);

build(); seed();
$list = dd_list($PDO, 14); $by = array(); foreach ($list as $x) $by[$x['user_id']] = $x;
t('listed: the expired one (1), the one expiring in 5 days (2), the one expiring today (3), the one with two problems (7); not the fine one, the not-onboarded one, or the one with no dates', array_keys($by) == array(1, 2, 3, 7) || (function () use ($by) { $k = array_keys($by); sort($k); return $k === array(1, 2, 3, 7); })(), json_encode(array_keys($by)));
t('states and days: expired -3, soon 5, today 0', $by[1]['docs']['insurance']['state'] === 'expired' && $by[1]['docs']['insurance']['days_left'] === -3 && $by[2]['docs']['road_fund']['state'] === 'soon' && $by[2]['docs']['road_fund']['days_left'] === 5 && $by[3]['docs']['technical_inspection']['state'] === 'today' && $by[3]['docs']['technical_inspection']['days_left'] === 0, json_encode($by));
t('only the documents inside the window are listed (the road fund in 60 days is not)', !isset($by[2]['docs']['technical_inspection']) && count($by[7]['docs']) === 2 && $by[7]['docs']['insurance']['days_left'] === 1 && $by[7]['docs']['road_fund']['days_left'] === -1);
t('the phone, name and online flag are included', $by[1]['name'] === 'Expired Ins' && $by[1]['phone'] === '0911' && $by[1]['online'] === 1 && $by[2]['online'] === 0);

// the switch
t('the block switch is off by default: nobody is blocked', dd_block_enabled($PDO) === false && dd_blocked_reason($PDO, 1) === array() && dd_blocked_reason($PDO, 7) === array());
$PDO->exec("INSERT INTO app_hub_settings (setting_key, setting_value) VALUES ('trust_block_expired_docs', '1')");
t('switched on: the driver with an expired insurance is blocked, with the document named', dd_block_enabled($PDO) === true && dd_blocked_reason($PDO, 1) === array('insurance'), json_encode(dd_blocked_reason($PDO, 1)));
t('switched on: a driver with an expired road fund (7) is blocked; a document that expires today (3) is not expired yet; fine, no dates and unknown drivers are not blocked', dd_blocked_reason($PDO, 7) === array('road fund') && dd_blocked_reason($PDO, 3) === array() && dd_blocked_reason($PDO, 4) === array() && dd_blocked_reason($PDO, 6) === array() && dd_blocked_reason($PDO, 999) === array());
$PDO->exec("UPDATE app_hub_settings SET setting_value = '0'");
t('switched off again: nobody is blocked', dd_blocked_reason($PDO, 1) === array());

// reminders
$PUSHES = array();
t('at night nothing is sent and nothing is written down', dd_send_reminders($PDO, 22) === 0 && count($PUSHES) === 0 && intval($PDO->query('SELECT COUNT(*) FROM driver_doc_reminders')->fetchColumn()) === 0);
$n = dd_send_reminders($PDO, 10);
$titles = array(); foreach ($PUSHES as $p) $titles[$p['uid'] . ':' . $p['title']] = $p;
t('5 reminders: expired insurance, road fund in 5 days (stage 7), technical inspection today, insurance in 1 day, expired road fund', $n === 5 && count($PUSHES) === 5, json_encode(array_keys($titles)));
t('texts: expired / expires in 7 days / expires today / expires in 1 day (singular)', isset($titles['1:Your insurance has expired']) && isset($titles['2:Road fund expires in 7 days']) && isset($titles['3:Your technical inspection expires today']) && isset($titles['7:Insurance expires in 1 day']) && isset($titles['7:Your road fund has expired']), json_encode(array_keys($titles)));
t('each goes to the driver and tells what to do (Documents)', $titles['1:Your insurance has expired']['uid'] === 1 && strpos($titles['1:Your insurance has expired']['body'], 'Documents') !== false && $titles['1:Your insurance has expired']['data']['type'] === 'doc_expiry' && strpos($titles['1:Your insurance has expired']['body'], 'cannot go online') === false);
$PUSHES = array(); t('running again sends nothing (each reminder once)', dd_send_reminders($PDO, 10) === 0 && count($PUSHES) === 0);
// stage change: 5 days left becomes 2 days left (a new stage, so one more reminder), a stage is never repeated
$PDO->exec("UPDATE ride_drivers SET road_fund_expiry_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY) WHERE user_id = 2");
$PUSHES = array(); $n = dd_send_reminders($PDO, 10);
t('when it gets closer (2 days left) the next stage is sent once: expires in 3 days', $n === 1 && $PUSHES[0]['title'] === 'Road fund expires in 3 days', json_encode($PUSHES));
// renewal: a new expiry date starts again
$PDO->exec("UPDATE ride_drivers SET insurance_expiry_date = DATE_ADD(CURDATE(), INTERVAL 100 DAY) WHERE user_id = 1");
t('after a renewal far in the future the driver is no longer listed', !in_array(1, array_map(function ($x) { return $x['user_id']; }, dd_list($PDO, 14))));
$PDO->exec("UPDATE ride_drivers SET insurance_expiry_date = DATE_ADD(CURDATE(), INTERVAL 6 DAY) WHERE user_id = 1");
$PUSHES = array(); $n = dd_send_reminders($PDO, 10);
t('a new expiry date has new reminders (6 days left: stage 7)', $n === 1 && $PUSHES[0]['title'] === 'Insurance expires in 7 days', json_encode($PUSHES));
// with the switch on the text says what happens
$PDO->exec("UPDATE app_hub_settings SET setting_value = '1'");
$PDO->exec("UPDATE ride_drivers SET technical_inspection_expiry_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id = 3");
$PUSHES = array(); dd_send_reminders($PDO, 10);
t('with the switch on, the expired reminder says the driver cannot go online', count($PUSHES) === 1 && strpos($PUSHES[0]['body'], 'cannot go online') !== false, json_encode($PUSHES));

// the admin answer
t('a customer is refused', call($CUST)['code'] === 403); t('nobody signed in: 401', call(null)['code'] === 401);
$r = call($ADMIN); $d = $r['ok']['documents'];
t('an admin gets the list, the switch state and counts, soonest first', $r['ok'] !== null && $d['block_expired'] === true && $d['columns_ready'] === true && $d['expired'] >= 2 && count($d['drivers']) >= 4, json_encode($r['err']));
$first = $d['drivers'][0]; $min = 999; foreach ($first['docs'] as $x) $min = min($min, $x['days_left']);
t('the most urgent driver is first (an expired one)', $min < 0, json_encode($first));

// older servers
$PDO->exec("ALTER TABLE ride_drivers DROP COLUMN road_fund_expiry_date");
t('without the date columns: nothing to report, no error', dd_columns_ready($PDO) === false && dd_list($PDO, 14) === array() && dd_blocked_reason($PDO, 1) === array() && dd_send_reminders($PDO, 10) === 0 && call($ADMIN)['ok']['documents']['columns_ready'] === false);
$PDO->exec("DROP TABLE app_hub_settings");
t('without the settings table: the switch reads as off, no error', dd_block_enabled($PDO) === false);
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);
