<?php
// Run: php delivery/tests/app_status_test.php   (needs MariaDB database fa_test; it loads migration 112 itself)
// Maintenance mode and minimum app version: the REAL lib/hub_content.php and the REAL controller file.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); if ($cap === 'owner.only' && empty($u['owner'])) fail('Owner only', 403); return $u; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../api/migrations/112_app_hub_content.sql'))))) as $q) $PDO->exec($q);
foreach (array('app_hub_content', 'app_hub_settings') as $t) $PDO->exec('TRUNCATE TABLE ' . $t);
$_SERVER['HTTP_HOST'] = 'ethelmarket.com';
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; $b = $body;
    try { (function () use ($action, $b) { $body = $b; require dirname(__DIR__) . '/api/controllers/HubAppsController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
$OWNER = array('id' => 1, 'is_admin' => 1, 'owner' => 1); $ADMIN = array('id' => 2, 'is_admin' => 1, 'owner' => 0); $CUST = array('id' => 9, 'is_admin' => 0, 'owner' => 0);
function save($s, $u) { return call('admin_hub_settings_save', array('settings' => $s), $u); }
function status($v) { return call('app_status', array('version' => $v), null); }

$r = status('1.7.0');
t('public: answers without sign-in', $r['ok'] !== null, json_encode($r));
t('default: no maintenance, no update needed', $r['ok']['maintenance']['on'] === false && $r['ok']['update']['required'] === false && $r['ok']['update']['min'] === '', json_encode($r['ok']));
t('works with no version sent', status('')['ok']['update']['required'] === false);

t('admin who is not the owner cannot switch maintenance on', save(array('maint_enabled' => '1'), $ADMIN)['code'] === 403);
t('customer cannot switch maintenance on', save(array('maint_enabled' => '1'), $CUST)['code'] === 403);
t('nothing changed by the refused calls', status('1.7.0')['ok']['maintenance']['on'] === false);
$r = save(array('maint_enabled' => '1', 'maint_text_en' => 'We are updating. Back at 3 PM.', 'maint_text_am' => 'ማሻሻል'), $OWNER);
t('owner switches maintenance on with texts', $r['ok'] && $r['ok']['saved'] === true, json_encode($r));
$m = status('1.7.0')['ok']['maintenance'];
t('phones now see maintenance with the texts', $m['on'] === true && $m['text_en'] === 'We are updating. Back at 3 PM.' && $m['text_am'] === 'ማሻሻል', json_encode($m));
save(array('maint_enabled' => '0'), $OWNER);
t('switching it off works', status('1.7.0')['ok']['maintenance']['on'] === false);

t('a bad version is refused', save(array('min_version_android' => 'abc'), $OWNER)['code'] === 422);
t('a version with letters is refused', save(array('min_version_android' => '1.7.0-beta'), $OWNER)['code'] === 422);
t('a one-number version is refused', save(array('min_version_android' => '7'), $OWNER)['code'] === 422);
foreach (array('https://evil.example.com/app.apk', 'http://play.google.com/store/apps/details?id=x', 'javascript:alert(1)', 'https://user:pw@play.google.com/x', 'https://play.google.com.evil.com/x', "https://play.google.com/a b", 'https://notexpo.dev/x') as $bad)
    t('update address refused: ' . $bad, save(array('update_url_android' => $bad), $OWNER)['code'] === 422);
foreach (array('https://play.google.com/store/apps/details?id=com.etelgroup.driver', 'https://expo.dev/accounts/etel-ftbg/projects/etel/builds/abc', 'https://build.expo.dev/x.apk', 'https://ethelmarket.com/delivery/install.html') as $good)
    t('update address accepted: ' . $good, save(array('update_url_android' => $good), $OWNER)['code'] === 0 || save(array('update_url_android' => $good), $OWNER)['ok'] !== null);
t('nothing bad was kept', strpos(status('1.0.0')['ok']['update']['url'], 'evil') === false);

$r = save(array('min_version_android' => '1.7.2', 'update_text_en' => 'Please update ETEL.', 'update_url_android' => 'https://play.google.com/store/apps/details?id=com.etelgroup.driver'), $OWNER);
t('owner sets a minimum version', $r['ok'] !== null, json_encode($r));
foreach (array(array('1.7.1', true), array('1.7.2', false), array('1.7.3', false), array('1.6.9', true), array('1.10.0', false), array('2.0.0', false), array('1.7', true), array('0.9.9', true)) as $c)
    t('version ' . $c[0] . ($c[1] ? ' must update' : ' is fine'), status($c[0])['ok']['update']['required'] === $c[1]);
t('no version sent: not blocked', status('')['ok']['update']['required'] === false);
t('junk version sent: not blocked', status('banana')['ok']['update']['required'] === false && status('1.2.3.4.5')['ok']['update']['required'] === false);
$u = status('1.7.0')['ok']['update'];
t('the update answer carries the text, the minimum and the address', $u['min'] === '1.7.2' && $u['text_en'] === 'Please update ETEL.' && strpos($u['url'], 'play.google.com') !== false, json_encode($u));
save(array('min_version_android' => ''), $OWNER);
t('clearing the minimum lets every version in', status('0.0.1')['ok']['update']['required'] === false);
t('the settings do not leak into the home texts', !isset(call('get_hub_apps', array(), $CUST)['ok']['texts']['maint_text_en']));
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);
