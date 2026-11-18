<?php
// Run: php delivery/tests/hub_apps_test.php   (needs MariaDB database fa_test with migration 111 loaded; see tests/agents/README.md)
// ETEL app (hub) visibility: the REAL lib/hub_apps.php and the REAL controller file.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); if ($cap === 'owner.only' && empty($u['owner'])) fail('Owner only', 403); return $u; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
$PDO->exec('TRUNCATE TABLE app_hub_apps');
$_SERVER['HTTP_HOST'] = 'ethelmarket.com';
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; $b = $body;
    try { (function () use ($action, $b) { $body = $b; require dirname(__DIR__) . '/api/controllers/HubAppsController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
$OWNER = array('id' => 1, 'is_admin' => 1, 'owner' => 1); $ADMIN = array('id' => 2, 'is_admin' => 1, 'owner' => 0); $CUST = array('id' => 9, 'is_admin' => 0, 'owner' => 0);

$r = call('get_hub_apps', array(), $CUST);
t('a signed-in customer gets the list (empty by default)', $r['ok'] && $r['ok']['apps'] === array(), json_encode($r));
$r = call('get_hub_apps', array(), null);
t('not signed in: 401', $r['code'] === 401);
$r = call('admin_hub_apps_get', array(), $OWNER);
t('owner sees all 13 apps and 3 feature switches with defaults', $r['ok'] && count($r['ok']['apps']) === 16, json_encode($r['err']));
$by = array(); if ($r['ok']) foreach ($r['ok']['apps'] as $a) $by[$a['key']] = $a;
t('defaults: ride on, pay off, admin off', $by['ride']['mode'] === 'on' && $by['pay']['mode'] === 'off' && $by['admin']['mode'] === 'off');
t('pay has no built-in address (needs one)', $by['pay']['needs_url'] === 1 && $by['ride']['needs_url'] === 0);
t('an admin who is not the owner is refused (view)', call('admin_hub_apps_get', array(), $ADMIN)['code'] === 403);
t('a customer is refused (view)', call('admin_hub_apps_get', array(), $CUST)['code'] === 403);
t('an admin who is not the owner cannot save', call('admin_hub_apps_save', array('key' => 'agent', 'mode' => 'off'), $ADMIN)['code'] === 403);
t('a customer cannot save', call('admin_hub_apps_save', array('key' => 'agent', 'mode' => 'off'), $CUST)['code'] === 403);
t('nothing was written by the refused calls', intval($PDO->query('SELECT COUNT(*) FROM app_hub_apps')->fetchColumn()) === 0);

t('feature switches default to on and have no address', $by['light']['mode'] === 'on' && $by['light']['kind'] === 'feature' && $by['light']['needs_url'] === 0 && $by['light_alert']['mode'] === 'on' && $by['force_permissions']['mode'] === 'on' && $by['force_permissions']['kind'] === 'feature' && $by['ride']['kind'] === 'app');
// saving
$r = call('admin_hub_apps_save', array('key' => 'agent', 'mode' => 'off'), $OWNER); t('owner hides the Agent app', $r['ok'] && $r['ok']['saved'], json_encode($r));
$r = call('get_hub_apps', array(), $CUST); t('phones now get agent=off', $r['ok'] && $r['ok']['apps']['agent']['mode'] === 'off' && count($r['ok']['apps']) === 1, json_encode($r['ok']));
$r = call('admin_hub_apps_save', array('key' => 'admin', 'mode' => 'admins'), $OWNER); t('admin app: admins only', $r['ok']);
$r = call('admin_hub_apps_save', array('key' => 'nope', 'mode' => 'on'), $OWNER); t('unknown app refused', $r['code'] === 422);
$r = call('admin_hub_apps_save', array('key' => 'agent', 'mode' => 'sideways'), $OWNER); t('unknown mode refused', $r['code'] === 422);
$r = call('admin_hub_apps_save', array('key' => 'pay', 'mode' => 'on'), $OWNER); t('coming-soon app cannot go on without an address', $r['code'] === 422 && strpos($r['err'], 'address') !== false, json_encode($r));
$r = call('admin_hub_apps_save', array('key' => 'pay', 'mode' => 'off'), $OWNER); t('coming-soon app may be saved as hidden without an address', $r['ok']);
foreach (array('http://ethelmarket.com/Etel.Pay/wallet/' => 'plain http', 'https://evil.example.com/x/' => 'other site', 'https://ethelmarket.com.evil.com/x/' => 'look-alike host', 'https://user@ethelmarket.com/x/' => 'user name', 'https://ethelmarket.com' => 'no path',
               'javascript:alert(1)' => 'javascript', 'https://ethelmarket.com/x y/' => 'space', "https://ethelmarket.com/x\"onload=/" => 'quote') as $u => $why) {
    $r = call('admin_hub_apps_save', array('key' => 'pay', 'mode' => 'on', 'url' => $u), $OWNER); t('address refused: ' . $why, $r['code'] === 422, json_encode($r));
}
$r = call('admin_hub_apps_save', array('key' => 'pay', 'mode' => 'on', 'url' => 'https://www.ethelmarket.com/Etel.Pay/wallet/'), $OWNER); t('www. of the same host is accepted', $r['ok'] && $r['ok']['saved']);
$r = call('admin_hub_apps_save', array('key' => 'pay', 'mode' => 'on', 'url' => 'https://ethelmarket.com/Etel.Pay/wallet/'), $OWNER); t('EtelPay goes live with its address', $r['ok']);
$r = call('get_hub_apps', array(), $CUST); t('phones get pay with its address', $r['ok']['apps']['pay']['mode'] === 'on' && $r['ok']['apps']['pay']['url'] === 'https://ethelmarket.com/Etel.Pay/wallet/', json_encode($r['ok']));
$r = call('admin_hub_apps_get', array(), $OWNER); $by = array(); foreach ($r['ok']['apps'] as $a) $by[$a['key']] = $a;
t('owner view shows the changes', $by['agent']['mode'] === 'off' && $by['agent']['changed'] === 1 && $by['admin']['mode'] === 'admins' && $by['pay']['url'] !== '');
$r = call('admin_hub_apps_save', array('key' => 'agent', 'mode' => 'on'), $OWNER); t('showing it again works', $r['ok']);
t('one row per app (upsert, no duplicates)', intval($PDO->query("SELECT COUNT(*) FROM app_hub_apps WHERE app_key = 'agent'")->fetchColumn()) === 1);
t('updated_by is recorded', intval($PDO->query("SELECT updated_by FROM app_hub_apps WHERE app_key = 'agent'")->fetchColumn()) === 1);

$r = call('admin_hub_apps_save', array('key' => 'light', 'mode' => 'admins'), $OWNER); t('owner sets the floating light to admins only', $r['ok']);
$r = call('admin_hub_apps_save', array('key' => 'light_alert', 'mode' => 'off', 'url' => 'https://evil.example.com/x/'), $OWNER); t('a feature ignores any address (saved, address dropped)', $r['ok'] && $PDO->query("SELECT url FROM app_hub_apps WHERE app_key = 'light_alert'")->fetchColumn() === '');
$r = call('get_hub_apps', array(), $CUST); t('phones get the feature switches too', $r['ok']['apps']['light']['mode'] === 'admins' && $r['ok']['apps']['light_alert']['mode'] === 'off');
$r = call('admin_hub_apps_save', array('key' => 'light', 'mode' => 'on'), $OWNER);
// table missing: everything still answers
$PDO->exec('RENAME TABLE app_hub_apps TO app_hub_apps_x');
$r = call('get_hub_apps', array(), $CUST); t('missing table: phones get an empty list, no error', $r['ok'] && $r['ok']['apps'] === array(), json_encode($r));
$r = call('admin_hub_apps_get', array(), $OWNER); t('missing table: owner view shows defaults', $r['ok'] && count($r['ok']['apps']) === 16);
$r = call('admin_hub_apps_save', array('key' => 'agent', 'mode' => 'off'), $OWNER); t('missing table: save says run the update', $r['code'] === 500 && strpos($r['err'], 'migration 111') !== false, json_encode($r));
$PDO->exec('RENAME TABLE app_hub_apps_x TO app_hub_apps');
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit($FAILS ? 1 : 0);
