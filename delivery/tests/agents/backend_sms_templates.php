<?php
// Behavioural test for the editable customer text messages (English / Amharic / both). Runs the REAL lib + controller files against a local MariaDB (ANSI_QUOTES on).
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
foreach (array('fa_agents', 'fa_referrals', 'fa_earnings', 'fa_bookings', 'fa_notifications', 'fa_audit_log', 'login_attempts', 'users', 'rides', 'ride_drivers') as $t) $PDO->exec("TRUNCATE TABLE `$t`");
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
$ADMIN = array('id' => 9000, 'name' => 'Admin', 'phone' => '0900000000', 'is_admin' => true);

function calla($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API;
    $fn = function () use ($action, $body, $API) { require $API . '/controllers/FieldAgentAdminController.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
require_once $API . '/lib/fa_sms_templates.php';
$PDO->exec('DROP TABLE IF EXISTS fa_sms_templates');
list($AU, $AID) = mkagent('Agent Sms', '0911000071', '0911000079');

// ---- cost estimate (the admin screen shows the same numbers; tests/agents/sms_cost_parity.js checks the browser copy)
$c = fa_sms_cost(str_repeat('a', 160)); t('cost: 160 plain characters = 1 credit', $c['segments'] === 1 && $c['encoding'] === 'GSM-7' && $c['chars'] === 160);
$c = fa_sms_cost(str_repeat('a', 161)); t('cost: 161 plain characters = 2 credits (153 per part)', $c['segments'] === 2 && $c['per_part'] === 153);
$c = fa_sms_cost(str_repeat('a', 307)); t('cost: 307 plain characters = 3 credits', $c['segments'] === 3);
$c = fa_sms_cost('{[]}'); t('cost: { [ ] } count twice in plain text', $c['chars'] === 8, json_encode($c));
$am = json_decode('"አአ"'); $c = fa_sms_cost(str_repeat($am, 35)); t('cost: 70 Amharic characters = 1 credit, Unicode', $c['segments'] === 1 && $c['encoding'] === 'Unicode' && $c['chars'] === 70, json_encode($c));
$c = fa_sms_cost(str_repeat($am, 35) . 'a'); t('cost: 71 characters with Amharic = 2 credits (67 per part)', $c['segments'] === 2 && $c['per_part'] === 67, json_encode($c));
$c = fa_sms_cost('hello `world`'); t('cost: a backtick makes it Unicode', $c['encoding'] === 'Unicode');
$c = fa_sms_cost(''); t('cost: empty = 0 credits', $c['segments'] === 0);

// ---- built-in wording: every kind has all three variants, with a sign-in link, and costs at most 2 credits
$d = fa_sms_defaults();
foreach (array('welcome', 'reset', 'verify', 'test') as $k) foreach (fa_sms_langs() as $l) {
    t("default $k/$l exists", isset($d[$k][$l]) && $d[$k][$l] !== '');
    $txt = str_replace('{link}', fa_sms_link(), $d[$k][$l]); $cc = fa_sms_cost($txt);
    t("default $k/$l costs at most 2 credits", $cc['segments'] >= 1 && $cc['segments'] <= 2, $cc['segments'] . ' credits, ' . $cc['chars'] . ' chars');
    if ($k !== 'test') t("default $k/$l carries {link}", strpos($d[$k][$l], '{link}') !== false);
}
t('default English welcome fits ONE credit', fa_sms_cost(str_replace('{link}', fa_sms_link(), $d['welcome']['en']))['segments'] === 1);
t('the "both" welcome has Amharic letters and English words', preg_match('/[\x{1200}-\x{137F}]/u', $d['welcome']['both']) === 1 && stripos($d['welcome']['both'], 'ride booked') !== false);
t('the brand is spelled with the owner\'s spelling in Amharic and both', strpos($d['welcome']['am'], json_decode('"ኢትኤል"')) !== false && strpos($d['welcome']['both'], json_decode('"ኢትኤል"')) !== false);

// ---- language choice: 0 English, 1 Amharic, 2 both; the old on/off Amharic setting still counts
t('mode: default is English', fa_sms_mode(array()) === 'en');
t('mode: 1 is Amharic, 2 is both', fa_sms_mode(array('agent_booking_sms_lang' => 1)) === 'am' && fa_sms_mode(array('agent_booking_sms_lang' => 2)) === 'both');
t('mode: the older Amharic switch still works when the new setting was never set', fa_sms_mode(array('agent_booking_sms_lang' => 0, 'agent_booking_sms_am' => 1)) === 'am');

// ---- admin actions
$r = calla('admin_fa_sms_templates_get', array(), $AU); t('templates get: a non-admin is refused', !$r->ok && $r->code2 == 403);
$r = calla('admin_fa_sms_templates_get', array(), $ADMIN);
t('templates get: four messages, three variants each, mode english', $r->ok && count($r->data['kinds']) === 4 && count($r->data['kinds'][0]['variants']) === 3 && $r->data['mode'] === 'en', $r->ok ? '' : $r->getMessage());
t('templates get: reports whether the automatic text is on', $r->ok && array_key_exists('auto_on', $r->data) && array_key_exists('provider', $r->data));
t('templates get: nothing is custom yet and each variant carries its cost', $r->ok && !$r->data['kinds'][0]['variants']['en']['custom'] && $r->data['kinds'][0]['variants']['am']['cost']['segments'] === 2);

$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'en', 'body' => 'x'), $AU); t('save: a non-admin is refused', !$r->ok && $r->code2 == 403);
$r = calla('admin_fa_sms_template_save', array('kind' => 'nope', 'lang' => 'en', 'body' => 'x {link}'), $ADMIN); t('save: unknown message refused', !$r->ok);
$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'fr', 'body' => 'x {link}'), $ADMIN); t('save: unknown language refused', !$r->ok);
$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'en', 'body' => '   '), $ADMIN); t('save: an empty text is refused', !$r->ok);
$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'en', 'body' => 'Hello, no link here'), $ADMIN); t('save: a text without {link} is refused (except the test text)', !$r->ok && strpos($r->getMessage(), '{link}') !== false, $r->getMessage());
$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'en', 'body' => str_repeat('a', 500) . ' {link}'), $ADMIN); t('save: a text over 480 characters is refused', !$r->ok);
$r = calla('admin_fa_sms_template_save', array('kind' => 'test', 'lang' => 'en', 'body' => 'Just a test'), $ADMIN); t('save: the test text needs no link', $r->ok, $r->ok ? '' : $r->getMessage());

$mine = 'Etel Ride: your driver will call you soon. Follow the ride at {link}';
$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'en', 'body' => $mine), $ADMIN);
t('save: edited wording is stored and reported as custom', $r->ok && $r->data['kinds'][0]['variants']['en']['custom'] === true && $r->data['kinds'][0]['variants']['en']['body'] === $mine, $r->ok ? '' : $r->getMessage());
t('resolve: the edited wording is what gets sent, with the link filled in', fa_sms_resolve($PDO, 'welcome', 'en') === 'Etel Ride: your driver will call you soon. Follow the ride at ' . fa_sms_link());
t('resolve: the other variants still use the built-in wording', fa_sms_resolve($PDO, 'welcome', 'am') === str_replace('{link}', fa_sms_link(), $d['welcome']['am']));
$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'en', 'body' => $mine . ' again'), $ADMIN);
t('save: saving again updates the same row', $r->ok && intval(one("SELECT COUNT(*) FROM fa_sms_templates WHERE kind='welcome' AND lang='en'")) === 1);
$r = calla('admin_fa_sms_template_save', array('kind' => 'welcome', 'lang' => 'en', 'reset' => true), $ADMIN);
t('reset: the built-in wording is back and nothing is custom', $r->ok && !$r->data['kinds'][0]['variants']['en']['custom'] && intval(one('SELECT COUNT(*) FROM fa_sms_templates WHERE kind = ?', array('welcome'))) === 0);
$r = calla('admin_fa_sms_template_save', array('kind' => 'verify', 'lang' => 'am', 'body' => $d['verify']['am']), $ADMIN);
t('saving the built-in wording again does not store a copy', $r->ok && intval(one("SELECT COUNT(*) FROM fa_sms_templates WHERE kind='verify'")) === 0);
t('audit: saves and resets are written to the audit log', intval(one("SELECT COUNT(*) FROM fa_audit_log WHERE action IN ('sms_template_saved','sms_template_reset')")) >= 3);

$r = calla('admin_fa_sms_mode_save', array('mode' => 'both'), $AU); t('mode: a non-admin is refused', !$r->ok && $r->code2 == 403);
$r = calla('admin_fa_sms_mode_save', array('mode' => 'klingon'), $ADMIN); t('mode: an unknown choice is refused', !$r->ok);
$r = calla('admin_fa_sms_mode_save', array('mode' => 'both'), $ADMIN); t('mode: both is saved', $r->ok && $r->data['mode'] === 'both', $r->ok ? '' : $r->getMessage());
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'agent_booking_sms_am'"); fa_settings($PDO, true);
$r = calla('admin_fa_sms_mode_save', array('mode' => 'en'), $ADMIN); t('mode: choosing English clears the older Amharic switch too', $r->ok && $r->data['mode'] === 'en' && fa_sms_mode(fa_settings($PDO, true)) === 'en');
$r = calla('admin_fa_sms_mode_save', array('mode' => 'am'), $ADMIN); t('mode: Amharic is saved', $r->ok && $r->data['mode'] === 'am');

// ---- test sending (no SMS service in the test environment, so the plain notice comes back)
$r = calla('admin_fa_sms_test', array('phone' => '0911234567', 'kind' => 'welcome', 'lang' => 'both'), $ADMIN); t('test send: no SMS service -> plain SMS_OFF notice', !$r->ok && $r->data['reason'] === 'SMS_OFF', $r->ok ? 'sent?' : $r->getMessage());
$PDO->exec("DELETE FROM fa_audit_log WHERE action = 'sms_test'");
$r = calla('admin_fa_sms_test', array('phone' => '0911234567', 'kind' => 'welcome', 'lang' => 'en', 'body' => 'no link'), $ADMIN); t('test send: a bad draft is refused before anything is sent', !$r->ok && !isset($r->data['reason']), $r->getMessage());
$PDO->exec("DELETE FROM fa_audit_log WHERE action = 'sms_test'");
$r = calla('admin_fa_sms_test', array('phone' => '0911234567', 'kind' => 'welcome', 'lang' => 'xx'), $ADMIN); t('test send: unknown language refused', !$r->ok);
$r = calla('admin_fa_sms_test', array('phone' => '12', 'kind' => 'test', 'lang' => 'en'), $ADMIN); t('test send: a bad number is refused', !$r->ok);

$PDO->exec('DROP TABLE IF EXISTS fa_sms_templates');
echo "PASSED: $PASS\n"; echo "FAILED: " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
