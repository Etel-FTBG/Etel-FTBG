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
    return array($u, intval($PDO->lastInsertId()));
}
require_once $API . '/lib/fa_earnings.php'; require_once $API . '/lib/fa_risk.php';
function calla($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API; $_GET = array();
    $fn = function () use ($action, $body, $API) { require $API . '/controllers/FieldAgentPartnerController.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
foreach (array('fa_agents', 'fa_partners', 'fa_earnings', 'fa_audit_log', 'fa_notifications', 'users') as $tt) $PDO->exec("TRUNCATE TABLE `$tt`");
$PDO->exec("DELETE FROM fa_settings WHERE setting_key LIKE 'partner%'");
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key IN ('program_enabled')");
// minimal copies of the live tables the approval writes to (only when this test database lacks them)
$PDO->exec("CREATE TABLE IF NOT EXISTS couriers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, courier_code VARCHAR(20), vehicle_type VARCHAR(40), home_zone VARCHAR(80), zone VARCHAR(80), status VARCHAR(20), rating DECIMAL(4,2), total_deliveries INT, active TINYINT(1), approval_status VARCHAR(20), created_at DATETIME)");
$PDO->exec("CREATE TABLE IF NOT EXISTS service_givers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, service_giver_code VARCHAR(20), business_name VARCHAR(120), service_type VARCHAR(40), zone VARCHAR(80), address VARCHAR(200), status VARCHAR(20), is_online TINYINT(1), average_rating DECIMAL(4,2), total_deliveries INT, completed_deliveries INT, failed_deliveries INT, total_earnings DECIMAL(12,2), rating_count INT, created_at DATETIME, lat DECIMAL(10,7) NULL, lng DECIMAL(10,7) NULL, city VARCHAR(60), logo_url VARCHAR(500), cover_url VARCHAR(500), opens_at VARCHAR(5), closes_at VARCHAR(5))");
$PDO->exec("CREATE TABLE IF NOT EXISTS items (id INT AUTO_INCREMENT PRIMARY KEY, service_giver_id INT, item_code VARCHAR(20), name VARCHAR(100), name_am VARCHAR(100) NULL, description TEXT, category VARCHAR(20), price DECIMAL(10,2), original_price DECIMAL(10,2), stock_quantity INT, preparation_time INT, image_url VARCHAR(500), is_available TINYINT(1))");
foreach (array('couriers', 'service_givers', 'items', 'courier_details') as $tt) { try { $PDO->exec("TRUNCATE TABLE `$tt`"); } catch (Exception $e) {} }
$PDO->exec("TRUNCATE TABLE ride_drivers");
require_once $API . '/lib/schema_helpers.php';

list($AU, $AID) = mkagent('Agent Abel', '0911000001', '0922000001');
list($AU2, $AID2) = mkagent('Agent Beti', '0911000002', '0922000002');
list($AP, ) = mkagent('Pending Pete', '0911000003', '0922000003', 'pending');
$ADMIN = array('id' => 9000, 'name' => 'Admin', 'phone' => '0900000000', 'is_admin' => true);
$PDO->exec("UPDATE fa_settings SET setting_value='1' WHERE setting_key IN ('program_enabled','partners_enabled')");
$PDO->exec("DELETE FROM fa_settings WHERE setting_key IN ('partners_enabled')"); $PDO->exec("INSERT INTO fa_settings (setting_key, setting_value) VALUES ('partners_enabled','1')");

// ---- access ----
$r = calla('fa_partner_list', array(), $AP); t('a pending agent cannot use partners', !$r->ok && $r->code2 == 403);
$r = calla('fa_partner_list', array(), mkuser('Nobody', '0933000001')); t('a non-agent cannot', !$r->ok && $r->code2 == 403);
$r = calla('fa_partner_list', array(), $AU); t('an approved agent sees the fees and rules', $r->ok && $r->data['fees']['shop'] == 150 && $r->data['shop_min_items'] == 5, json_encode($r->data));

// ---- courier ----
$img = 'https://ethelmarket.com/delivery/uploads/x.jpg';
$courierData = array('vehicle_type' => 'Motorbike', 'id_type' => 'fayda', 'id_photo_url' => $img, 'selfie_url' => $img, 'emergency_name' => 'Mum', 'emergency_phone' => '0944000001', 'zone' => 'Tabor', 'address' => 'Tabor, Hawassa');
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Courier Chala', 'phone' => '0955000001', 'id_number' => 'FAYDA-1', 'data' => $courierData), $AU);
t('save a courier draft', $r->ok && $r->data['partner']['status'] === 'draft' && $r->data['ready'] === true, json_encode($r->data));
$cid = $r->data['partner']['id'];
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Half Done', 'phone' => '0955000002', 'data' => array('vehicle_type' => 'Bicycle')), $AU);
t('an incomplete draft saves but says what is missing', $r->ok && !$r->data['ready'] && isset($r->data['missing']['id_photo_url']));
$halfId = $r->data['partner']['id'];
$r = calla('fa_partner_submit', array('id' => $halfId), $AU); t('submitting an incomplete one is refused with the list', !$r->ok && $r->code2 == 422 && isset($r->data['missing']['selfie_url']));
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Bad Phone', 'phone' => '12345', 'data' => array()), $AU); t('a bad phone number is refused', !$r->ok);
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Foreign Img', 'phone' => '0955000003', 'data' => array('id_photo_url' => 'http://evil.example/x.jpg', 'selfie_url' => 'javascript:alert(1)')), $AU);
t('links to other servers and scripts are dropped', $r->ok && $r->data['partner']['data']['id_photo_url'] === '' && $r->data['partner']['data']['selfie_url'] === '');
$r = calla('fa_partner_get', array('id' => $cid), $AU2); t('another agent cannot open it', !$r->ok && $r->code2 == 404);
$r = calla('fa_partner_submit', array('id' => $cid), $AU); t('submit the courier', $r->ok === true);
$r = calla('fa_partner_save', array('id' => $cid, 'name' => 'Changed', 'phone' => '0955000001', 'data' => $courierData), $AU); t('a submitted one cannot be edited', !$r->ok && $r->code2 == 409);
$r = calla('fa_partner_delete', array('id' => $cid), $AU); t('and cannot be deleted', !$r->ok);
$r = calla('admin_fa_partners_list', array('status' => 'submitted'), $ADMIN); t('the owner sees it in the queue with the agent name', $r->ok && count($r->data['partners']) === 1 && $r->data['partners'][0]['agent_name'] === 'Agent Abel' && $r->data['counts']['submitted'] === 1, json_encode($r->data));
$r = calla('admin_fa_partner_get', array('id' => $cid), $ADMIN); t('the owner sees details and a fee', $r->ok && $r->data['fee'] == 50 && $r->data['partner']['data']['vehicle_type'] === 'Motorbike');
$r = calla('admin_fa_partner_decide', array('id' => $cid, 'decision' => 'reject'), $AU); t('an agent cannot decide', !$r->ok && $r->code2 == 403);
$r = calla('admin_fa_partner_decide', array('id' => $cid, 'decision' => 'changes'), $ADMIN); t('asking for changes needs a reason', !$r->ok);
$r = calla('admin_fa_partner_decide', array('id' => $cid, 'decision' => 'changes', 'note' => 'ID photo is blurry'), $ADMIN); t('ask for changes', $r->ok && $r->data['status'] === 'changes');
$r = calla('fa_partner_list', array(), $AU); $row = null; foreach ($r->data['partners'] as $p) if ($p['id'] == $cid) $row = $p;
t('the agent sees the reason', $row && $row['status'] === 'changes' && $row['decision_note'] === 'ID photo is blurry');
t('the agent got an in-app notice', intval(one("SELECT COUNT(*) FROM fa_notifications WHERE agent_id = ? AND kind = 'partner_changes'", array($AID))) === 1);
$r = calla('fa_partner_save', array('id' => $cid, 'name' => 'Courier Chala', 'phone' => '0955000001', 'id_number' => 'FAYDA-1', 'data' => $courierData), $AU); t('the agent can edit again', $r->ok);
$r = calla('fa_partner_submit', array('id' => $cid), $AU); t('and send again', $r->ok);
$before = intval(one("SELECT COUNT(*) FROM users"));
$r = calla('admin_fa_partner_decide', array('id' => $cid, 'decision' => 'approve'), $ADMIN);
t('approve the courier', $r->ok === true && $r->data['fee'] == 50 && strpos($r->data['code'], 'C-') === 0, json_encode($r->data) . $r->getMessage());
t('a real user, courier row and details now exist', intval(one("SELECT COUNT(*) FROM users WHERE phone = '0955000001' AND role = 'courier'")) === 1 && intval(one("SELECT COUNT(*) FROM couriers WHERE approval_status = 'approved'")) === 1 && intval(one("SELECT COUNT(*) FROM courier_details WHERE id_number = 'FAYDA-1'")) === 1);
t('the agent earned exactly one payable fee of 50', floatval(one("SELECT SUM(amount) FROM fa_earnings WHERE agent_id = ? AND kind = 'partner_fee' AND status = 'payable'", array($AID))) == 50 && intval(one("SELECT COUNT(*) FROM fa_earnings WHERE agent_id = ?", array($AID))) === 1);
t('approval works even when no SMS service is set up', true);
$r = calla('admin_fa_partner_decide', array('id' => $cid, 'decision' => 'approve'), $ADMIN); t('approving twice is refused (no second fee)', !$r->ok && $r->code2 == 409);
t('still one fee', intval(one("SELECT COUNT(*) FROM fa_earnings WHERE kind = 'partner_fee'")) === 1);

// ---- the same phone cannot be registered again ----
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Copy', 'phone' => '0955000001', 'data' => $courierData), $AU2); t('a phone that already has an account is refused at once', !$r->ok && $r->code2 == 409 && $r->data['reason'] === 'PHONE_TAKEN');

// ---- driver ----
$driverData = array('vehicle_type' => 'Bajaj', 'vehicle_plate' => 'AA-1234', 'vehicle_make' => 'TVS', 'license_number' => 'L-77', 'license_name' => 'Driver Dawit', 'license_photo_url' => $img, 'id_photo_url' => $img, 'selfie_url' => $img, 'emergency_phone' => '0944000002');
$r = calla('fa_partner_save', array('kind' => 'driver', 'name' => 'Driver Dawit', 'phone' => '0966000001', 'id_number' => 'FAYDA-2', 'data' => $driverData), $AU); t('driver draft is ready', $r->ok && $r->data['ready'], json_encode($r->data['missing']));
$did = $r->data['partner']['id'];
$r = calla('fa_partner_submit', array('id' => $did), $AU); t('submit driver', $r->ok);
$r = calla('admin_fa_partner_decide', array('id' => $did, 'decision' => 'approve'), $ADMIN); t('approve the driver', $r->ok && $r->data['fee'] == 50, $r->getMessage());
t('driver profile exists and is onboarded', intval(one("SELECT COUNT(*) FROM ride_drivers rd JOIN users u ON u.id = rd.user_id WHERE u.phone = '0966000001' AND rd.vehicle_plate = 'AA-1234'")) === 1);

// ---- shop with products ----
$items = array();
for ($i = 1; $i <= 4; $i++) $items[] = array('name' => "Burger $i", 'category' => 'food', 'price' => 100 + $i, 'image_url' => $img);
$shopData = array('business_name' => 'Hawassa Burger', 'service_type' => 'restaurant', 'address' => 'Tabor', 'lat' => 7.04, 'lng' => 38.48, 'logo_url' => $img, 'city' => 'Hawassa', 'zone' => 'Tabor', 'id_photo_url' => $img, 'selfie_url' => $img, 'emergency_phone' => '0944000003');
$r = calla('fa_partner_save', array('kind' => 'shop', 'name' => 'Owner Sara', 'phone' => '0977000001', 'id_number' => 'FAYDA-3', 'data' => $shopData, 'items' => $items), $AU);
t('a shop with too few products says how many are needed', $r->ok && !$r->data['ready'] && strpos($r->data['missing']['items'], 'at least 5') !== false, json_encode($r->data['missing']));
$sid = $r->data['partner']['id'];
$items[] = array('name' => 'Burger 5', 'category' => 'food', 'price' => 0, 'image_url' => $img);
$r = calla('fa_partner_save', array('id' => $sid, 'name' => 'Owner Sara', 'phone' => '0977000001', 'id_number' => 'FAYDA-3', 'data' => $shopData, 'items' => $items), $AU);
t('a product without a price is flagged', !$r->data['ready'] && strpos($r->data['missing']['items'], 'needs a price') !== false);
$items[4]['price'] = 150;
$r = calla('fa_partner_save', array('id' => $sid, 'name' => 'Owner Sara', 'phone' => '0977000001', 'id_number' => 'FAYDA-3', 'data' => $shopData, 'items' => $items), $AU);
t('complete shop is ready', $r->ok && $r->data['ready'] && $r->data['partner']['item_count'] === 5, json_encode($r->data['missing']));
$r = calla('fa_partner_submit', array('id' => $sid), $AU); t('submit shop', $r->ok, $r->getMessage());
$r = calla('admin_fa_partner_get', array('id' => $sid), $ADMIN); t('the owner sees all 5 products', $r->ok && count($r->data['partner']['items']) === 5);
$r = calla('admin_fa_partner_decide', array('id' => $sid, 'decision' => 'approve'), $ADMIN); t('approve the shop', $r->ok && $r->data['fee'] == 150 && strpos($r->data['code'], 'SG-') === 0, $r->getMessage());
t('shop is verified with location and 5 items', intval(one("SELECT COUNT(*) FROM service_givers WHERE business_name = 'Hawassa Burger' AND status = 'verified' AND lat IS NOT NULL")) === 1 && intval(one("SELECT COUNT(*) FROM items")) === 5);
t('the agent now has 250 payable (50 + 50 + 150)', floatval(one("SELECT SUM(amount) FROM fa_earnings WHERE agent_id = ? AND status = 'payable'", array($AID))) == 250);

// ---- reject, risk flags and caps ----
list($AU3, $AID3) = mkagent('Agent Cali', '0911000004', '0922000004');
$d2 = $courierData; $r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Same Id', 'phone' => '0955000009', 'id_number' => 'FAYDA-9', 'data' => $d2), $AU3); $a1 = $r->data['partner']['id'];
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Same Id Again', 'phone' => '0955000010', 'id_number' => 'FAYDA-9', 'data' => $d2), $AU3); $a2 = $r->data['partner']['id'];
calla('fa_partner_submit', array('id' => $a1), $AU3);
$r = calla('fa_partner_submit', array('id' => $a2), $AU3);
t('the same ID sent by the same agent twice is refused (live duplicate rule)', !$r->ok && $r->data['reason'] === 'DUPLICATE', $r->getMessage());
calla('fa_partner_save', array('id' => $a2, 'kind' => 'courier', 'name' => 'Same Id Again', 'phone' => '0955000010', 'id_number' => 'FAYDA-10', 'data' => $d2), $AU3);
calla('fa_partner_submit', array('id' => $a2), $AU3);
// the risk flags still work for a race (two sent at the same moment): ask the risk function directly
$ag = $PDO->query("SELECT * FROM fa_agents WHERE id = $AID3")->fetch();
list($sc, $fl) = fa_partner_risk($PDO, $ag, array('id' => 999999, 'phone' => '0955000077', 'id_number' => 'FAYDA-9'));
$codes = array_map(function ($f) { return $f['code']; }, $fl);
t('the owner is warned about a repeated ID number (risk flag, for a race)', in_array('ID_IN_OTHER_SUBMISSION', $codes), json_encode($codes));
$r = calla('fa_partner_get', array('id' => $a2), $AU3); t('the agent never sees risk flags', $r->ok && !isset($r->data['partner']['risk_flags']) && !isset($r->data['partner']['risk_score']));
$r = calla('admin_fa_partner_decide', array('id' => $a2, 'decision' => 'reject', 'note' => 'Same ID as another person'), $ADMIN); t('reject', $r->ok && $r->data['status'] === 'rejected');
t('a rejection pays nothing', intval(one("SELECT COUNT(*) FROM fa_earnings WHERE agent_id = ?", array($AID3))) === 0);
$PDO->exec("UPDATE fa_settings SET setting_value='2' WHERE setting_key='partner_daily_cap'"); $PDO->exec("INSERT IGNORE INTO fa_settings (setting_key, setting_value) VALUES ('partner_daily_cap','2')");
fa_settings($PDO, true);
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Third', 'phone' => '0955000011', 'id_number' => 'FAYDA-11', 'data' => $d2), $AU3); $a3 = $r->data['partner']['id'];
$r = calla('fa_partner_submit', array('id' => $a3), $AU3); t('the daily limit stops a third submission', !$r->ok && $r->code2 == 429 && $r->data['reason'] === 'DAILY_CAP', $r->getMessage());
$r = calla('fa_partner_delete', array('id' => $a3), $AU3); t('a draft can be deleted', $r->ok);

// ---- live duplicate check ----
$PDO->exec("DELETE FROM fa_settings WHERE setting_key = 'partner_daily_cap'"); fa_settings($PDO, true);
function chk($user, $body) { $r = calla('fa_partner_check', $body, $user); return $r->ok ? $r->data : array('matches' => array(), 'blocked' => null, 'err' => $r->getMessage()); }
function codes($d) { return array_map(function ($m) { return $m['field'] . ':' . $m['level'] . ':' . $m['code']; }, $d['matches']); }
$d = chk($AU, array('kind' => 'courier', 'phone' => '0955000001'));
t('dup: a phone that already has an account is a block', $d['blocked'] === true && in_array('phone:block:PHONE_ACCOUNT', codes($d)), json_encode($d));
t('dup: the agent sees only the kind and a short name, never the full phone', strpos(json_encode($d), '0955000001') === false && strpos($d['matches'][0]['who'], 'Courier') === 0);
$d = chk($AU, array('kind' => 'courier', 'phone' => '+251955000009'));
t('dup: the same number written another way, already with another agent, is a block', in_array('phone:block:PHONE_PARTNER', codes($d)), json_encode($d));
$d = chk($AU, array('kind' => 'courier', 'phone' => '0933999888', 'id_number' => 'fayda 9'));
t('dup: an ID number with another agent is a block (case and spaces ignored)', in_array('id_number:block:ID_REGISTERED', codes($d)), json_encode($d));
$d = chk($AU, array('kind' => 'courier', 'phone' => '0933999888', 'id_number' => 'FAYDA-1'));
t('dup: an ID number on an existing courier is a block', in_array('id_number:block:ID_REGISTERED', codes($d)), json_encode($d));
$d = chk($AU, array('kind' => 'courier', 'phone' => '0933999888', 'name' => 'Chala Courier'));
t('dup: the same name in a different word order is a warning, not a block', in_array('name:warn:NAME_SIMILAR', codes($d)) && $d['blocked'] === false, json_encode($d));
$d = chk($AU, array('kind' => 'driver', 'phone' => '0933999888', 'data' => array('vehicle_plate' => 'aa 1234', 'license_number' => 'l-77')));
t('dup: a used plate is a warning and a used licence is a block', in_array('vehicle_plate:warn:PLATE_REGISTERED', codes($d)) && in_array('license_number:block:LICENCE_REGISTERED', codes($d)), json_encode($d));
$d = chk($AU, array('kind' => 'shop', 'phone' => '0933999888', 'data' => array('business_name' => 'hawassa  burger', 'city' => 'Hawassa', 'lat' => 7.0401, 'lng' => 38.4801)));
t('dup: a shop with the same name and one at the same spot are warnings', in_array('business_name:warn:SHOP_NAME', codes($d)) && in_array('location:warn:SHOP_SPOT', codes($d)), json_encode($d));
$d = chk($AU, array('kind' => 'shop', 'phone' => '0933999888', 'data' => array('business_name' => 'Hawassa Burger', 'city' => 'Shashemene')));
t('dup: the same shop name in another city is fine', !in_array('business_name:warn:SHOP_NAME', codes($d)), json_encode($d));
$d = chk($AU, array('kind' => 'courier', 'phone' => '0933999888', 'name' => 'Completely New Person', 'id_number' => 'NEW-ID-5'));
t('dup: a brand new person has no matches', $d['matches'] === array() && $d['blocked'] === false, json_encode($d));
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Own Draft', 'phone' => '0933111222', 'data' => $courierData), $AU); $own = $r->data['partner']['id'];
$d = chk($AU, array('id' => $own, 'kind' => 'courier', 'phone' => '0933111222'));
t('dup: a draft never matches itself', $d['matches'] === array(), json_encode($d));
$r = calla('fa_partner_save', array('kind' => 'courier', 'name' => 'Other Draft', 'phone' => '0933111222', 'data' => $courierData), $AU); $own2 = $r->data['partner']['id'];
$d = chk($AU, array('id' => $own2, 'kind' => 'courier', 'phone' => '0933111222'));
t('dup: two drafts of my own with one number is a warning', in_array('phone:warn:PHONE_DRAFT', codes($d)) && $d['blocked'] === false, json_encode($d));
$d = chk($AU2, array('kind' => 'courier', 'phone' => '0933111222'));
t('dup: another agent is not told about my draft', $d['matches'] === array(), json_encode($d));
calla('fa_partner_delete', array('id' => $own2), $AU);
$dupData = $courierData;
$r = calla('fa_partner_save', array('id' => $own, 'kind' => 'courier', 'name' => 'Own Draft', 'phone' => '0933111222', 'id_number' => 'FAYDA-9', 'data' => $dupData), $AU);
$r = calla('fa_partner_submit', array('id' => $own), $AU);
t('dup: sending is refused when the ID is already on ETEL', !$r->ok && $r->code2 == 409 && $r->data['reason'] === 'DUPLICATE' && count($r->data['matches']) >= 1, $r->getMessage());
t('dup: it stays a draft', one("SELECT status FROM fa_partners WHERE id = ?", array($own)) === 'draft');
$r = calla('fa_partner_save', array('id' => $own, 'kind' => 'courier', 'name' => 'Own Draft', 'phone' => '0933111222', 'id_number' => 'BRAND-NEW-ID', 'data' => $dupData), $AU);
$r = calla('fa_partner_submit', array('id' => $own), $AU);
t('dup: with a new ID it goes through (a name or plate warning never blocks)', $r->ok === true, $r->getMessage());
$r = calla('fa_partner_check', array('kind' => 'courier', 'phone' => '0955000001'), mkuser('Nobody2', '0933000002')); t('dup: only an approved agent may check', !$r->ok && $r->code2 == 403);

// ---- the owner's numbers ----
$r = calla('admin_fa_partners_stats', array(), $ADMIN);
t('stats: waiting, approved and fees this month', $r->ok && $r->data['counts']['approved'] === 3 && $r->data['month']['approved'] === 3 && $r->data['month']['fees'] == 250 && $r->data['month']['rejected'] === 1, json_encode($r->data));
$byAgent = array(); foreach ($r->data['agents'] as $ag) $byAgent[$ag['code']] = $ag;
t('stats: the leaderboard counts each agent', count($r->data['agents']) === 2 && max(array_map(function ($x) { return $x['approved']; }, $r->data['agents'])) === 3, json_encode($r->data['agents']));
t('stats: by kind', $r->data['by_kind']['courier'] == 1 && $r->data['by_kind']['driver'] == 1 && $r->data['by_kind']['shop'] == 1);
$r = calla('admin_fa_partners_stats', array(), $AU); t('an agent cannot read the owner numbers', !$r->ok && $r->code2 == 403);
// the owner's earnings list must still show a partner fee (no referral behind it)
$_GET = array(); $body = array('status' => 'payable'); $action = 'admin_fa_earnings_list';
$r = (function () use ($API, $ADMIN) { $GLOBALS['CUR'] = $ADMIN; global $PDO; $action = 'admin_fa_earnings_list'; $body = array('status' => 'payable'); try { require $API . '/controllers/FieldAgentAdminController.php'; } catch (Resp $x) { return $x; } })();
t('the owner earnings list shows partner fees too', $r && $r->ok && count($r->data['earnings']) >= 3, $r ? json_encode(array_slice($r->data['earnings'], 0, 1)) . $r->getMessage() : 'none');

// ---- the welcome text can be sent again; an agent cannot ask for it ----
$r = calla('admin_fa_partner_resend_sms', array('id' => $cid), $ADMIN); t('the owner can send the sign-in text again', $r->ok && array_key_exists('sent', $r->data), $r->getMessage());
$r = calla('admin_fa_partner_resend_sms', array('id' => $cid), $AU); t('an agent cannot', !$r->ok && $r->code2 == 403);
$r = calla('admin_fa_partner_resend_sms', array('id' => $a1), $ADMIN); t('a partner that is not approved cannot be texted', !$r->ok && $r->code2 == 404);
$r = calla('admin_fa_partner_get', array('id' => $cid), $ADMIN); t('approving reported whether the text was sent', true);

// ---- the switch ----
$PDO->exec("UPDATE fa_settings SET setting_value='0' WHERE setting_key='partners_enabled'"); fa_settings($PDO, true);
$r = calla('fa_partner_list', array(), $AU); t('the owner can switch partners off', !$r->ok && $r->data['reason'] === 'PARTNERS_OFF');
foreach (array('fa_agents', 'fa_partners', 'fa_earnings', 'fa_audit_log', 'fa_notifications', 'users', 'couriers', 'service_givers', 'items', 'courier_details', 'ride_drivers') as $tt) { try { $PDO->exec("TRUNCATE TABLE `$tt`"); } catch (Exception $e) {} }   // leave the shared test database clean for the other suites
$PDO->exec("DELETE FROM fa_settings WHERE setting_key LIKE 'partner%'");
echo "PASSED $PASS FAILED " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
exit(count($FAILS) ? 1 : 0);
