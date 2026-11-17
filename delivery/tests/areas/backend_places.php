<?php
// Behavioural test for quick zones per city (lib/service_area_places.php + ServiceAreaController.php). Runs the REAL files against a local MariaDB (ANSI_QUOTES on, database fa_test).
// Not part of CI (needs a database). Run: php delivery/tests/areas/backend_places.php
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test'); define('TOKEN_SECRET_FINAL', 'test-secret');
date_default_timezone_set('Africa/Addis_Ababa');
$API = dirname(__DIR__, 2) . '/api';

class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; $r->data = $x; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { if (empty($GLOBALS['CUR']['is_admin'])) fail('Admin access required', 403); return $GLOBALS['CUR']; }
function rate_limit_check($pdo, $id, $max = 5, $w = 600, $l = 900) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ?'); $q->execute(array($id));
    if ($q->fetchColumn() >= $max) fail('Too many failed attempts.', 429);
}
require_once $API . '/validate.php';
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
function etel_table_columns($pdo, $t) { $o = array(); foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll() as $c) $o[] = $c['Field']; return $o; }
function fail_ex($e, $m, $c = 500) { fail($m, $c); }
$PASS = 0; $FAILS = array();
function t($name, $cond, $extra = '') { global $PASS, $FAILS; if ($cond) { $PASS++; } else { $FAILS[] = $name . ($extra !== '' ? '  -> ' . $extra : ''); } }
function calla($action, $body, $user, $get = array()) {
    $GLOBALS['CUR'] = $user; global $API; $_GET = $get;
    $fn = function () use ($action, $body, $API) { require $API . '/controllers/ServiceAreaController.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
require_once $API . '/lib/service_areas.php';
$PDO->exec('DROP TABLE IF EXISTS service_area_places'); $PDO->exec('DROP TABLE IF EXISTS service_areas'); $PDO->exec('DROP TABLE IF EXISTS service_area_settings');
require_once $API . '/lib/service_area_places.php';
function one($sql, $p = array()) { global $PDO; $q = $PDO->prepare($sql); $q->execute($p); return $q->fetchColumn(); }
$ADM = array('id' => 1, 'name' => 'A', 'phone' => '0900000000', 'is_admin' => true);
$NOADM = array('id' => 2, 'name' => 'B', 'phone' => '0900000001', 'is_admin' => false);

t('ensure creates the table', etel_area_places_ensure($PDO));
$H = etel_area_places_list($PDO, 'hawassa');
t('Hawassa is seeded with its twelve zones', count($H) === 12, count($H));
t('seed keeps the order and the Amharic name', $H[0]['name'] === 'Mehal Ketema' && $H[1]['name'] === 'Tabor' && $H[1]['name_am'] === json_decode('"ታቦር"'));
t('seeded flag is written once', one("SELECT v FROM service_area_settings WHERE k = 'places_seeded'") === '1');
t('Shashemene has no places', count(etel_area_places_list($PDO, 'shashemene')) === 0);

// public lookup
$r = calla('get_area_places', array(), null, array('area' => 'hawassa'));
t('get_area_places hawassa = 12', $r->ok && count($r->data['places']) === 12 && $r->data['area'] === 'hawassa', json_encode($r->data));
$r = calla('get_area_places', array(), null, array('area' => 'shashemene'));
t('get_area_places shashemene = none', $r->ok && count($r->data['places']) === 0);
$r = calla('get_area_places', array(), null, array());
t('get_area_places without a city = none', $r->ok && count($r->data['places']) === 0);
$r = calla('get_area_places', array(), null, array('area' => "hawassa' OR 1=1 --"));
t('get_area_places strips odd characters', $r->ok && count($r->data['places']) === 0, json_encode($r->data));

// admin: add a city, then its places
$r = calla('admin_area_save', array('name' => 'Addis Ababa', 'name_am' => '', 'lat' => 9.03, 'lng' => 38.74, 'radius_km' => 25, 'active' => 1), $ADM);
t('add the city Addis Ababa', $r->ok === true, $r->getMessage());
$AID = intval(one("SELECT id FROM service_areas WHERE code = 'addis-ababa'"));
$HID = intval(one("SELECT id FROM service_areas WHERE code = 'hawassa'"));
$r = calla('admin_area_place_save', array('area_id' => $AID, 'name' => 'Bole', 'name_am' => '', 'lat' => 8.9806, 'lng' => 38.7578, 'active' => 1), $ADM);
t('add Bole inside Addis Ababa', $r->ok === true, $r->getMessage());
$r = calla('admin_area_place_save', array('area_id' => $AID, 'name' => 'Piassa', 'lat' => 9.0348, 'lng' => 38.7525, 'active' => 1), $ADM);
t('add Piassa (sort goes last)', $r->ok === true);
t('sort order is 1 then 2', one("SELECT sort_order FROM service_area_places WHERE name = 'Bole'") == 1 && one("SELECT sort_order FROM service_area_places WHERE name = 'Piassa'") == 2);
$r = calla('admin_area_place_save', array('area_id' => $AID, 'name' => 'Hawassa Tabor', 'lat' => 7.0395, 'lng' => 38.4895, 'active' => 1), $ADM);
t('a point inside Hawassa is refused for Addis Ababa', $r->ok === false && strpos($r->getMessage(), 'outside Addis Ababa') !== false, $r->getMessage());
$r = calla('admin_area_place_save', array('area_id' => $AID, 'name' => 'X', 'lat' => 9.0, 'lng' => 38.7), $ADM);
t('short name refused', $r->ok === false);
$r = calla('admin_area_place_save', array('area_id' => $AID, 'name' => 'Moon', 'lat' => 60, 'lng' => 38.7), $ADM);
t('point outside Ethiopia refused', $r->ok === false);
$r = calla('admin_area_place_save', array('area_id' => 99999, 'name' => 'Nowhere', 'lat' => 9.0, 'lng' => 38.7), $ADM);
t('unknown city refused (404)', $r->ok === false && $r->code2 === 404);
$r = calla('admin_area_place_save', array('area_id' => $AID, 'name' => 'Bole', 'lat' => 8.98, 'lng' => 38.75, 'active' => 1), $NOADM);
t('a non-admin cannot save', $r->ok === false && $r->code2 === 403);

$r = calla('get_area_places', array(), null, array('area' => 'addis-ababa'));
t('riders in Addis Ababa see Bole and Piassa, not Hawassa places', $r->ok && count($r->data['places']) === 2 && $r->data['places'][0]['name'] === 'Bole', json_encode($r->data));
$r = calla('get_area_places', array(), null, array('area' => 'hawassa'));
t('Hawassa riders still see only their own twelve', $r->ok && count($r->data['places']) === 12);

// edit, hide, list, delete
$BID = intval(one("SELECT id FROM service_area_places WHERE name = 'Bole'"));
$r = calla('admin_area_place_save', array('area_id' => $AID, 'id' => $BID, 'name' => 'Bole Airport', 'name_am' => '', 'lat' => 8.9806, 'lng' => 38.7578, 'active' => 0, 'sort_order' => 5), $ADM);
t('edit and hide Bole', $r->ok === true, $r->getMessage());
$r = calla('get_area_places', array(), null, array('area' => 'addis-ababa'));
t('a hidden place is not shown to riders', $r->ok && count($r->data['places']) === 1 && $r->data['places'][0]['name'] === 'Piassa');
$r = calla('admin_area_places', array('area_id' => $AID), $ADM);
t('admin sees hidden places too', $r->ok && count($r->data['places']) === 2 && $r->data['area']['code'] === 'addis-ababa', json_encode($r->data));
$r = calla('admin_area_places', array('area_id' => $AID), $NOADM);
t('a non-admin cannot list', $r->ok === false && $r->code2 === 403);
$r = calla('admin_area_place_save', array('area_id' => $HID, 'id' => $BID, 'name' => 'Stolen', 'lat' => 7.06, 'lng' => 38.47), $ADM);
t('a place cannot be edited through another city', $r->ok === false && $r->code2 === 404);
$r = calla('admin_area_place_delete', array('id' => $BID), $ADM);
t('delete Bole', $r->ok === true && intval(one("SELECT COUNT(*) FROM service_area_places WHERE id = ?", array($BID))) === 0);

// limit of 100 per city
$PDO->exec("DELETE FROM service_area_places WHERE area_code = 'addis-ababa'");
$ins = $PDO->prepare("INSERT INTO service_area_places (area_code, name, name_am, lat, lng, active, sort_order, created_at, updated_at) VALUES ('addis-ababa', ?, '', 9.03, 38.74, 1, ?, NOW(), NOW())");
for ($i = 1; $i <= 100; $i++) $ins->execute(array('P' . $i, $i));
$r = calla('admin_area_place_save', array('area_id' => $AID, 'name' => 'One too many', 'lat' => 9.03, 'lng' => 38.74, 'active' => 1), $ADM);
t('the 101st place is refused', $r->ok === false && strpos($r->getMessage(), '100') !== false, $r->getMessage());

// the city list and places survive each other
$r = calla('admin_areas_list', array(), $ADM);
t('the city list still loads', $r->ok && count($r->data['areas']) === 3, json_encode($r->data));

echo "PASSED $PASS FAILED " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
exit(count($FAILS) ? 1 : 0);
