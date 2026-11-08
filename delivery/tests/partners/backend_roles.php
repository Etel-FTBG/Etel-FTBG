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
function callc($file, $action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API; $_GET = array();
    $fn = function () use ($action, $body, $API, $file) { require $API . '/controllers/' . $file . '.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
foreach (array('fa_agents', 'fa_partners', 'fa_earnings', 'fa_audit_log', 'fa_notifications', 'fa_referrals', 'users') as $tt) $PDO->exec("TRUNCATE TABLE `$tt`");
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key IN ('program_enabled')");
$PDO->exec("DELETE FROM fa_settings WHERE setting_key LIKE 'partner%'");
$PDO->exec("INSERT INTO fa_settings (setting_key, setting_value) VALUES ('partners_enabled','1')");
$PDO->exec("CREATE TABLE IF NOT EXISTS ride_fare_rules (id INT AUTO_INCREMENT PRIMARY KEY, vehicle_type VARCHAR(24), active TINYINT(1) DEFAULT 1, base_fare DECIMAL(10,2) DEFAULT 50, per_km DECIMAL(10,2) DEFAULT 10, min_fare DECIMAL(10,2) DEFAULT 60)");
require_once $API . '/lib/fa_core.php'; require_once $API . '/lib/fa_partners.php';
function roles_of($agentId) { global $PDO; fa_roles_ensure($PDO); return fa_agent_roles($PDO->query("SELECT * FROM fa_agents WHERE id = $agentId")->fetch()); }
$ADMIN = array('id' => 9000, 'name' => 'Admin', 'phone' => '0900000000', 'is_admin' => true);
fa_roles_ensure($PDO);

list($U1, $A1) = mkagent('Role Agent One', '0911100001', '0922100001');
t('a new or old agent with no roles saved works as ride only (how it was before roles)', roles_of($A1) === array('ride'), json_encode(roles_of($A1)));

// ---- ride only: booking works, partner registration is closed ----
$r = callc('AgentBookingController', 'fa_agent_vehicles', array(), $U1); t('ride role: booking opens', $r->ok === true, $r->getMessage());
$r = callc('FieldAgentPartnerController', 'fa_partner_list', array(), $U1); t('ride only: partner registration is closed (ROLE_NONE)', !$r->ok && $r->code2 == 403 && $r->data['reason'] === 'ROLE_NONE', $r->getMessage());
$r = callc('FieldAgentController', 'fa_agent_me', array(), $U1); t('the agent sees its own roles', $r->ok && $r->data['agent']['roles'] === array('ride') && $r->data['agent']['code'] !== null, json_encode($r->data['agent'] ?? array()));

// ---- admin gives courier only ----
$r = callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array('courier')), $U1); t('an agent cannot change roles', !$r->ok && $r->code2 == 403);
$r = callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => 'courier'), $ADMIN); t('roles must be a list', !$r->ok);
$r = callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => 999999, 'roles' => array('courier')), $ADMIN); t('unknown agent', !$r->ok && $r->code2 == 404);
$r = callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array('courier', 'hacker', 'courier')), $ADMIN); t('the admin gives the courier role (unknown words dropped)', $r->ok && $r->data['roles'] === array('courier'), json_encode($r->data));
t('stored', roles_of($A1) === array('courier'));
t('the agent got an in-app notice', intval(one("SELECT COUNT(*) FROM fa_notifications WHERE agent_id = ? AND kind = 'roles_changed'", array($A1))) === 1);
t('the change is in the audit log', intval(one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'agent_roles_set'")) === 1);
$r = callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array('courier')), $ADMIN);
t('saving the same roles again sends no second notice', intval(one("SELECT COUNT(*) FROM fa_notifications WHERE agent_id = ? AND kind = 'roles_changed'", array($A1))) === 1);
$r = callc('AgentBookingController', 'fa_agent_vehicles', array(), $U1); t('courier only: booking a ride is closed (ROLE_RIDE)', !$r->ok && $r->code2 == 403 && $r->data['reason'] === 'ROLE_RIDE', $r->getMessage());
$r = callc('FieldAgentController', 'fa_agent_me', array(), $U1); t('courier only: no referral code is shown', $r->ok && $r->data['agent']['code'] === null && $r->data['agent']['roles'] === array('courier'));
$img = 'http://127.0.0.1/delivery/uploads/x.jpg';
$cd = array('vehicle_type' => 'motorcycle', 'id_photo_url' => $img, 'selfie_url' => $img, 'emergency_phone' => '0944000001');
$r = callc('FieldAgentPartnerController', 'fa_partner_list', array(), $U1); t('courier only: the partner list shows only courier as allowed', $r->ok && $r->data['allowed_kinds'] === array('courier'), json_encode($r->data['allowed_kinds'] ?? null));
$r = callc('FieldAgentPartnerController', 'fa_partner_save', array('kind' => 'courier', 'name' => 'Role Courier', 'phone' => '0955100001', 'data' => $cd), $U1); t('courier only: can register a courier', $r->ok === true, $r->getMessage()); $pc = $r->data['partner']['id'] ?? 0;
$r = callc('FieldAgentPartnerController', 'fa_partner_save', array('kind' => 'shop', 'name' => 'Role Shop', 'phone' => '0955100002', 'data' => $cd), $U1); t('courier only: cannot register a shop (ROLE_SHOP)', !$r->ok && $r->code2 == 403 && $r->data['reason'] === 'ROLE_SHOP', $r->getMessage());
$r = callc('FieldAgentPartnerController', 'fa_partner_save', array('kind' => 'driver', 'name' => 'Role Driver', 'phone' => '0955100003', 'data' => $cd), $U1); t('courier only: cannot register a driver (ROLE_DRIVER)', !$r->ok && $r->data['reason'] === 'ROLE_DRIVER');
$r = callc('FieldAgentPartnerController', 'fa_partner_check', array('kind' => 'shop', 'phone' => '0955100009'), $U1); t('courier only: cannot even run the live check for a shop', !$r->ok && $r->data['reason'] === 'ROLE_SHOP');
$r = callc('FieldAgentPartnerController', 'fa_partner_check', array('kind' => 'courier', 'phone' => '0955100009'), $U1); t('courier only: the live check for a courier works', $r->ok === true);

// ---- the role is taken away after a draft was started: the draft cannot be sent ----
callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array('shop')), $ADMIN);
$r = callc('FieldAgentPartnerController', 'fa_partner_submit', array('id' => $pc), $U1); t('a draft of a kind the agent no longer has cannot be sent', !$r->ok && $r->code2 == 403 && $r->data['reason'] === 'ROLE_COURIER', $r->getMessage());
$r = callc('FieldAgentPartnerController', 'fa_partner_get', array('id' => $pc), $U1); t('but the agent can still read their own history', $r->ok === true);
$r = callc('FieldAgentPartnerController', 'fa_partner_save', array('id' => $pc, 'kind' => 'courier', 'name' => 'Role Courier', 'phone' => '0955100001', 'data' => $cd), $U1); t('or edit it', !$r->ok && $r->code2 == 403);

// ---- none, and all ----
$r = callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array()), $ADMIN); t('the admin can give no role at all', $r->ok && $r->data['roles'] === array());
t('none is stored as none, not as the ride default', roles_of($A1) === array(), json_encode(roles_of($A1)));
$r = callc('AgentBookingController', 'fa_agent_vehicles', array(), $U1); t('none: no booking', !$r->ok && $r->data['reason'] === 'ROLE_RIDE');
$r = callc('FieldAgentPartnerController', 'fa_partner_list', array(), $U1); t('none: no partners', !$r->ok && $r->data['reason'] === 'ROLE_NONE');
$r = callc('FieldAgentController', 'fa_agent_me', array(), $U1); t('none: the agent still gets the basic app (its roles are empty)', $r->ok && $r->data['agent']['roles'] === array());
$r = callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array('ride', 'courier', 'driver', 'shop')), $ADMIN); t('the admin can give all four', $r->ok && $r->data['roles'] === array('ride', 'courier', 'driver', 'shop'));
$r = callc('AgentBookingController', 'fa_agent_vehicles', array(), $U1); t('all: booking opens again', $r->ok === true);
foreach (array('courier', 'driver', 'shop') as $k) { $r = callc('FieldAgentPartnerController', 'fa_partner_save', array('kind' => $k, 'name' => 'All ' . $k, 'phone' => '09551' . rand(10000, 99999), 'data' => $cd), $U1); t("all: can register a $k", $r->ok === true, $r->getMessage()); }
$r = callc('FieldAgentPartnerController', 'fa_partner_list', array(), $U1); t('all: three kinds are allowed', $r->ok && $r->data['allowed_kinds'] === array('courier', 'driver', 'shop'));

// ---- a referral code only works while the agent has the ride role ----
$code = one("SELECT code FROM fa_agents WHERE id = ?", array($A1));
$rider = mkuser('New Rider', '0966100001');
callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array('shop')), $ADMIN);
$r = callc('FieldAgentController', 'fa_claim_ref', array('code' => $code, 'device' => 'dev1'), $rider); t('without the ride role the referral code is refused like a wrong code', $r->ok && $r->data['applied'] === false && $r->data['reason'] === 'BAD_CODE', json_encode($r->data));
callc('FieldAgentAdminController', 'admin_fa_agent_set_roles', array('id' => $A1, 'roles' => array('ride')), $ADMIN);
$rider2 = mkuser('New Rider Two', '0966100002');
$r = callc('FieldAgentController', 'fa_claim_ref', array('code' => $code, 'device' => 'dev2'), $rider2); t('with the ride role the code is accepted again', $r->ok && ($r->data['reason'] ?? '') !== 'BAD_CODE', json_encode($r->data));

// ---- the admin lists and approval ----
$r = callc('FieldAgentAdminController', 'admin_fa_agents_list', array(), $ADMIN); $row = null; foreach ($r->data['agents'] as $x) if (intval($x['id']) === $A1) $row = $x;
t('the agent list shows each agent\'s roles and the role names', $r->ok && $row && $row['roles'] === array('ride') && isset($r->data['all_roles']['shop']), json_encode($row ? $row['roles'] : null));
$r = callc('FieldAgentAdminController', 'admin_fa_agent_get', array('id' => $A1), $ADMIN); t('the agent detail has roles too', $r->ok && $r->data['agent']['roles'] === array('ride') && count($r->data['all_roles']) === 4);
list($UP, $AP) = mkagent('Pending Role Agent', '0911100003', '0922100003', 'pending');
$r = callc('FieldAgentAdminController', 'admin_fa_agent_decide', array('id' => $AP, 'decision' => 'approve', 'roles' => array('shop', 'driver')), $ADMIN); t('the admin can choose the roles while approving', $r->ok && roles_of($AP) === array('driver', 'shop'), $r->getMessage() . json_encode(roles_of($AP)));
list($UQ, $AQ) = mkagent('Pending Plain Agent', '0911100004', '0922100004', 'pending');
$r = callc('FieldAgentAdminController', 'admin_fa_agent_decide', array('id' => $AQ, 'decision' => 'approve'), $ADMIN); t('approving without choosing keeps the default (ride only)', $r->ok && roles_of($AQ) === array('ride'));

foreach (array('fa_agents', 'fa_partners', 'fa_earnings', 'fa_audit_log', 'fa_notifications', 'fa_referrals', 'users') as $tt) { try { $PDO->exec("TRUNCATE TABLE `$tt`"); } catch (Exception $e) {} }
$PDO->exec("DELETE FROM fa_settings WHERE setting_key LIKE 'partner%'");
echo "PASSED $PASS FAILED " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
exit(count($FAILS) ? 1 : 0);
