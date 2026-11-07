<?php
// Behavioural test for agent-assisted booking (Phase 6). Runs the REAL lib + controller files against a local MariaDB (ANSI_QUOTES on).
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
$PDO->exec('DELETE FROM service_areas'); $PDO->exec("DELETE FROM service_area_settings");
$GLOBALS['__reset'] = 1;
t('tables ensure', etel_areas_ensure($PDO));
$HAW = array(7.0621, 38.4763); $SHA = array(7.2, 38.59); $ADDIS = array(9.03, 38.74);
$A = etel_areas_list($PDO);
t('two seeded cities', count($A) === 2, count($A));
t('hawassa point -> hawassa', ($a = etel_area_for_point($PDO, $HAW[0], $HAW[1])) && $a['code'] === 'hawassa');
t('shashemene point -> shashemene', ($a = etel_area_for_point($PDO, $SHA[0], $SHA[1])) && $a['code'] === 'shashemene');
t('addis is outside', etel_area_for_point($PDO, $ADDIS[0], $ADDIS[1]) === null);
t('nearest for addis exists', etel_area_nearest($PDO, $ADDIS[0], $ADDIS[1]) !== null);
$c = etel_area_check_trip($PDO, $HAW[0], $HAW[1], $HAW[0] + 0.01, $HAW[1]);
t('hawassa->hawassa ok', $c['ok']);
$c = etel_area_check_trip($PDO, $HAW[0], $HAW[1], $SHA[0], $SHA[1]);
t('hawassa->shashemene refused', !$c['ok'] && $c['reason'] === 'CROSS_AREA' && strpos($c['message'], 'Hawassa') !== false && $c['message_am'] !== '', json_encode($c));
$c = etel_area_check_trip($PDO, $ADDIS[0], $ADDIS[1], $ADDIS[0], $ADDIS[1]);
t('addis pickup refused OUTSIDE_AREA', !$c['ok'] && $c['reason'] === 'OUTSIDE_AREA');
$c = etel_area_check_trip($PDO, $HAW[0], $HAW[1], $ADDIS[0], $ADDIS[1]);
t('drop outside refused', !$c['ok'] && $c['reason'] === 'DROPOFF_OUTSIDE');
$c = etel_area_check_trip($PDO, $HAW[0], $HAW[1], 0, 0);
t('no drop coords = ok', $c['ok']);
$c = etel_area_check_trip($PDO, 0, 0, 0, 0);
t('no coords at all = ok (fail open)', $c['ok']);

// shop filter
$sc = array('lat', 'lng', 'city');
$PDO->exec("CREATE TEMPORARY TABLE sgt (id INT, lat DOUBLE NULL, lng DOUBLE NULL, city VARCHAR(40))");
$PDO->exec("INSERT INTO sgt VALUES (1,7.0621,38.4763,'Hawassa'),(2,7.2,38.59,'Shashemene'),(3,NULL,NULL,'Hawassa'),(4,NULL,NULL,'shashemene'),(5,9.03,38.74,'Addis'),(6,7.07,38.48,'Shashemene')");
list($sql, $par) = etel_area_shop_where(etel_area_by_code($PDO, 'hawassa'), $sc, 'sgt');
$q = $PDO->prepare("SELECT GROUP_CONCAT(id ORDER BY id) FROM sgt sgt WHERE 1=1 $sql"); $q->execute($par);
t('hawassa shops = 1,3,6 (coords beat city text)', $q->fetchColumn() === '1,3,6');
list($sql, $par) = etel_area_shop_where(etel_area_by_code($PDO, 'shashemene'), $sc, 'sgt');
$q = $PDO->prepare("SELECT GROUP_CONCAT(id ORDER BY id) FROM sgt sgt WHERE 1=1 $sql"); $q->execute($par);
t('shashemene shops = 2,4', $q->fetchColumn() === '2,4', $q->fetchColumn());
t('shop area by coords', etel_area_of_shop($PDO, 7.2, 38.59, 'Hawassa')['code'] === 'shashemene');
t('shop area by city text', etel_area_of_shop($PDO, 0, 0, 'Hawassa')['code'] === 'hawassa');

// overlapping circles (radius 50 km each, as on the live site): the nearest centre wins; shop coordinates beat a wrong city text
$PDO->exec("UPDATE service_areas SET radius_km = 50");
$PDO->exec("INSERT INTO sgt VALUES (7,7.0397553,38.4828616,'Addis Ababa')");
foreach (array('hawassa' => '1,3,6,7', 'shashemene' => '2,4') as $code => $want) {
    list($sql, $par) = etel_area_shop_where(etel_area_by_code($PDO, $code), $sc, 'sgt', etel_areas_list($PDO, true));
    $q = $PDO->prepare("SELECT GROUP_CONCAT(id ORDER BY id) FROM sgt sgt WHERE 1=1 $sql"); $q->execute($par); $got = $q->fetchColumn();
    t("overlap: $code shops = $want", $got === $want, $got);
}
$c = etel_area_check_trip($PDO, $HAW[0], $HAW[1], $SHA[0], $SHA[1]);
t('overlap: hawassa->shashemene still refused', !$c['ok'] && $c['reason'] === 'CROSS_AREA');
$PDO->exec("UPDATE service_areas SET radius_km = 12 WHERE code='hawassa'"); $PDO->exec("UPDATE service_areas SET radius_km = 10 WHERE code='shashemene'");

// enforcement switch
$ADM = array('id' => 1, 'name' => 'A', 'phone' => '0900000000', 'is_admin' => true);
$r = calla('admin_areas_settings_save', array('enforce' => false), $ADM);
t('switch off saved', $r->ok === true);
t('switch off lets addis pass', etel_area_check_trip($PDO, $ADDIS[0], $ADDIS[1], $ADDIS[0], $ADDIS[1])['ok']);
calla('admin_areas_settings_save', array('enforce' => true), $ADM);
t('switch on again refuses', !etel_area_check_trip($PDO, $ADDIS[0], $ADDIS[1], $ADDIS[0], $ADDIS[1])['ok']);

// public lookup
$r = calla('get_service_areas', array(), null, array('lat' => $SHA[0], 'lng' => $SHA[1]));
t('get_service_areas detects shashemene', $r->ok && $r->data['current']['code'] === 'shashemene', json_encode($r->data));
$r = calla('get_service_areas', array(), null, array('lat' => $ADDIS[0], 'lng' => $ADDIS[1]));
t('get_service_areas outside + nearest', $r->ok && $r->data['outside'] === true && $r->data['nearest'], json_encode($r->data));

// admin CRUD validation
$r = calla('admin_area_save', array('name' => 'Adama', 'lat' => 8.54, 'lng' => 39.27, 'radius_km' => 12, 'active' => 1), $ADM);
t('add Adama', $r->ok === true, $r->getMessage());
$r = calla('admin_area_save', array('name' => 'X', 'lat' => 8.54, 'lng' => 39.27, 'radius_km' => 12), $ADM);
t('short name refused', $r->ok === false);
$r = calla('admin_area_save', array('name' => 'Moon', 'lat' => 60, 'lng' => 39.27, 'radius_km' => 12), $ADM);
t('outside Ethiopia refused', $r->ok === false);
$r = calla('admin_area_save', array('name' => 'Big', 'lat' => 8.5, 'lng' => 39.2, 'radius_km' => 500), $ADM);
t('radius too big refused', $r->ok === false);
$r = calla('admin_area_save', array('name' => 'Adama', 'lat' => 8.54, 'lng' => 39.27, 'radius_km' => 12), $ADM);
t('duplicate name refused', $r->ok === false && $r->code2 === 409);
$r = calla('admin_areas_list', array(), $ADM);
t('admin list has 3', $r->ok && count($r->data['areas']) === 3, json_encode($r->data));
$id = intval(one("SELECT id FROM service_areas WHERE code='adama'"));
$r = calla('admin_area_delete', array('id' => $id), $ADM);
t('delete Adama', $r->ok === true);
$r = calla('admin_area_save', array('name' => 'Hawassa', 'name_am' => '', 'lat' => 7.06, 'lng' => 38.47, 'radius_km' => 12, 'active' => 1, 'id' => intval(one("SELECT id FROM service_areas WHERE code='hawassa'"))), $ADM);
t('edit Hawassa', $r->ok === true);
function one($sql, $p = array()) { global $PDO; $q = $PDO->prepare($sql); $q->execute($p); return $q->fetchColumn(); }

// package quote
require_once $API . '/lib/package_orders.php';
$cases = array(array('Hawassa->Shashemene', array('lat' => 7.06, 'lng' => 38.47), array('lat' => 7.2, 'lng' => 38.59), false), array('Hawassa->Hawassa', array('lat' => 7.06, 'lng' => 38.47), array('lat' => 7.07, 'lng' => 38.49), true));
foreach ($cases as $k) {
    $q = etel_package_quote($PDO, $k[1] + array('address' => 'A'), $k[2] + array('address' => 'B'), array('size' => 'small', 'weight_kg' => 1));
    t('package ' . $k[0] . ($k[3] ? ' quoted' : ' refused'), $k[3] ? ($q['ok'] === true || strpos($q['message'], 'Etel is not') === false && strpos($q['message'], 'so the drop') === false) : ($q['ok'] === false && isset($q['reason']) && $q['reason'] === 'CROSS_AREA'), json_encode($q));
}
echo "PASSED $PASS FAILED " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
exit(count($FAILS) ? 1 : 0);
