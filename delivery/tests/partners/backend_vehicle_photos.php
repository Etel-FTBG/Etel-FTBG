<?php
// Behavioural test for vehicle photos (3 sides) for couriers and drivers. Runs the REAL lib + controller files against a local MariaDB (ANSI_QUOTES on).
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
function rate_limit_fail($pdo, $id) { $pdo->prepare('INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())')->execute(array($id)); }
require_once $API . '/validate.php';
$FAKE_AGE = array('ok' => true, 'adult' => true, 'reviewed' => false);
function etelid_bridge_service_call($path, $payload, $t = 5) { global $FAKE_AGE; return $FAKE_AGE; }

$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key IN ('program_enabled', 'require_digital_payment')");
function etel_table_columns($pdo, $t) { $o = array(); foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll() as $c) $o[] = $c['Field']; return $o; }
function fail_ex($e, $m, $c = 500) { fail($m, $c); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API;
    $fn = function () use ($action, $body, $API) { require $API . '/controllers/AgentBookingController.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
$PASS = 0; $FAILS = array();
function t($name, $cond, $extra = '') { global $PASS, $FAILS; if ($cond) { $PASS++; } else { $FAILS[] = $name . ($extra !== '' ? '  -> ' . $extra : ''); } }
function mkuser($name, $phone, $etel = null, $role = 'customer') { global $PDO; $PDO->prepare('INSERT INTO users (name, phone, etel_id, role, created_at) VALUES (?,?,?,?,NOW())')->execute(array($name, $phone, $etel, $role)); return array('id' => intval($PDO->lastInsertId()), 'name' => $name, 'phone' => $phone); }
function one($sql, $p = array()) { global $PDO; $q = $PDO->prepare($sql); $q->execute($p); return $q->fetchColumn(); }
function mkagent($name, $phone, $pay, $status = 'approved') {
    global $PDO; $u = mkuser($name, $phone, 'ET' . $phone, 'customer');
    $PDO->prepare("INSERT INTO fa_agents (user_id, etel_id, full_name, phone, venue_name, venue_type, venue_zone, payout_phone, code, status, age_status, tier, decided_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,'manual_verified','bronze',NOW(),NOW(),NOW())")
        ->execute(array($u['id'], 'ET' . $phone, $name, $phone, 'Hilton Hawassa', 'hotel', 'Lake', $pay, strtoupper(substr(md5($name), 0, 6)), $status));
    $aid = intval($PDO->lastInsertId());
    require_once __DIR__ . '/../../api/lib/fa_core.php'; fa_roles_ensure($PDO);
    $PDO->exec("UPDATE fa_agents SET roles = 'ride,courier,driver,shop' WHERE id = $aid");   // these tests are about partners, so the owner has given every role
    return array($u, $aid);
}
require_once $API . '/lib/courier_details.php'; require_once $API . '/lib/vehicle_photos.php';
function callv($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API; $_GET = array();
    $fn = function () use ($action, $body, $API) { require $API . '/controllers/VehiclePhotosController.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
$PDO->exec("TRUNCATE TABLE users");
$PDO->exec("CREATE TABLE IF NOT EXISTS ride_drivers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT UNIQUE)");
$u1 = 'https://ethelmarket.com/delivery/uploads/vehicle_photos/veh_a.jpg'; $u2 = 'https://ethelmarket.com/delivery/uploads/vehicle_photos/veh_b.jpg'; $u3 = 'https://ethelmarket.com/delivery/uploads/vehicle_photos/veh_c.png';

// ---- the link check ----
t('our own vehicle-pictures link is accepted', etel_vp_url_ok($u1));
t('a link from another server is refused', !etel_vp_url_ok('http://evil.example/x/uploads/vehicle_photos/../../a.jpg') && !etel_vp_url_ok('javascript:alert(1)'));
t('a link in another folder is refused', !etel_vp_url_ok('https://ethelmarket.com/delivery/uploads/driver_documents/cid_1.jpg'));
$c = etel_vp_clean(array('front' => $u1, 'vehicle_photo_side' => $u2, 'back' => 'http://evil.example/a.jpg'));
t('clean keeps good sides, drops bad ones, reads both key styles', $c['front'] === $u1 && $c['side'] === $u2 && $c['back'] === '');
t('complete needs all three', !etel_vp_complete($c) && etel_vp_complete(array('front' => $u1, 'side' => $u2, 'back' => $u3)));

// ---- self service (courier) ----
$cu = mkuser('Chala Courier', '0911222001', null, 'courier'); $cu['role'] = 'courier';
$r = callv('vehicle_photos_get', array(), $cu); t('a courier can ask what pictures they have', $r->ok && $r->data['complete'] === false && $r->data['have']['front'] === false, json_encode($r->data));
$r = callv('vehicle_photos_save', array('front' => $u1), $cu); t('one side can be saved first', $r->ok && $r->data['have']['front'] === true && $r->data['complete'] === false);
$r = callv('vehicle_photos_save', array('side' => $u2, 'back' => $u3), $cu); t('the other two complete it, the first stays', $r->ok && $r->data['complete'] === true);
t('the courier row holds all three', one("SELECT COUNT(*) FROM courier_details WHERE user_id = ? AND vehicle_photo_front = ? AND vehicle_photo_side = ? AND vehicle_photo_back = ?", array($cu['id'], $u1, $u2, $u3)) == 1);
$r = callv('vehicle_photos_save', array('front' => 'http://evil.example/x.jpg'), $cu); t('a foreign link saves nothing', !$r->ok && $r->code2 == 400);
t('and the saved front is unchanged', one("SELECT vehicle_photo_front FROM courier_details WHERE user_id = ?", array($cu['id'])) === $u1);

// ---- self service (driver) ----
$du = mkuser('Dawit Driver', '0911222002', null, 'driver'); $du['role'] = 'driver';
$r = callv('vehicle_photos_save', array('front' => $u1, 'side' => $u2, 'back' => $u3), $du); t('a driver can save all three', $r->ok && $r->data['complete'] === true, json_encode($r->data));
t('the driver row holds them', one("SELECT COUNT(*) FROM ride_drivers WHERE user_id = ? AND vehicle_photo_back = ?", array($du['id'], $u3)) == 1);

// ---- others cannot use it ----
$cust = mkuser('Customer', '0911222003', null, 'customer'); $cust['role'] = 'customer';
$r = callv('vehicle_photos_save', array('front' => $u1), $cust); t('a customer cannot', !$r->ok && $r->code2 == 403);

// ---- staff ----
$adm = array('id' => 1, 'role' => 'admin', 'is_admin' => 1);
$r = callv('admin_vehicle_photos_save', array('user_id' => $du['id'], 'kind' => 'driver', 'side' => $u1), $adm); t('staff can replace one side of a driver', $r->ok && $r->data['photos']['side'] === $u1 && $r->data['photos']['front'] === $u1);
$r = callv('admin_vehicle_photos_save', array('user_id' => $du['id'], 'kind' => 'courier', 'side' => $u1), $adm); t('staff cannot mix up the kind', !$r->ok && $r->code2 == 404);
$r = callv('admin_vehicle_photos_save', array('user_id' => $du['id'], 'kind' => 'driver', 'side' => $u1), $cust); t('a non-admin is refused', !$r->ok && $r->code2 == 403);

// ---- the staff-desk helper: a sent picture must be real and on disk ----
$dir = $API . '/../uploads/vehicle_photos'; if (!is_dir($dir)) mkdir($dir, 0755, true);
file_put_contents($dir . '/veh_test_ok.jpg', 'x');
$strict = function ($b) { try { return etel_vp_body_strict($b); } catch (Resp $r) { return $r; } };
$ok = $strict(array('front' => 'https://ethelmarket.com/delivery/uploads/vehicle_photos/veh_test_ok.jpg'));
t('a stored picture passes the staff check', is_array($ok) && $ok['front'] !== '' && $ok['side'] === '');
$bad = $strict(array('front' => 'https://ethelmarket.com/delivery/uploads/vehicle_photos/veh_missing.jpg'));
t('a picture that is not on disk is refused', $bad instanceof Resp && !$bad->ok);
$bad = $strict(array('back' => 'http://evil.example/a.jpg'));
t('a foreign picture is refused', $bad instanceof Resp && !$bad->ok);
t('nothing sent is fine', $strict(array()) === array('front' => '', 'side' => '', 'back' => ''));
unlink($dir . '/veh_test_ok.jpg');

echo "PASSED $PASS FAILED " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
exit(count($FAILS) ? 1 : 0);
