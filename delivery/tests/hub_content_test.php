<?php
// Run: php delivery/tests/hub_content_test.php   (needs MariaDB database fa_test with migrations 111 and 112 loaded; see tests/agents/README.md)
// ETEL app look: names, descriptions, icons, home texts and banner. The REAL lib/hub_content.php and the REAL controller file.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); if ($cap === 'owner.only' && empty($u['owner'])) fail('Owner only', 403); return $u; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
foreach (array('app_hub_content', 'app_hub_settings', 'app_hub_apps') as $t) $PDO->exec('TRUNCATE TABLE ' . $t);
$_SERVER['HTTP_HOST'] = 'ethelmarket.com';
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; $b = $body;
    try { (function () use ($action, $b) { $body = $b; require dirname(__DIR__) . '/api/controllers/HubAppsController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
$OWNER = array('id' => 1, 'is_admin' => 1, 'owner' => 1, 'role' => 'admin'); $ADMIN = array('id' => 2, 'is_admin' => 1, 'owner' => 0, 'role' => 'admin');
$CUST = array('id' => 9, 'is_admin' => 0, 'owner' => 0, 'role' => 'customer'); $DRV = array('id' => 5, 'is_admin' => 0, 'owner' => 0, 'role' => 'driver');
$ICON = 'https://ethelmarket.com/delivery/uploads/hub/icon_1_1700000000_ab12cd34.png';

// ---- defaults
$r = call('get_hub_apps', array(), $CUST);
t('default: no content, no texts, no banner', $r['ok'] && $r['ok']['content'] === array() && $r['ok']['texts'] === array() && $r['ok']['banner'] === null, json_encode($r));
$r = call('admin_hub_content_get', array(), $OWNER); t('owner can read', $r['ok'] && $r['ok']['content'] === array() && $r['ok']['settings'] === array());
t('non-owner admin cannot read', call('admin_hub_content_get', array(), $ADMIN)['code'] === 403);
t('customer cannot read', call('admin_hub_content_get', array(), $CUST)['code'] === 403);

// ---- app content
$r = call('admin_hub_content_save', array('key' => 'ride', 'title_en' => 'ETEL Ride', 'title_am' => 'ኢቴል ራይድ', 'desc_en' => 'Book a ride in Hawassa', 'desc_am' => 'ጉዞ ይዘዙ', 'badge' => 'New', 'sort_order' => 3, 'icon_url' => $ICON), $OWNER);
t('owner saves the look of one app', $r['ok'] && $r['ok']['saved'], json_encode($r));
$r = call('get_hub_apps', array(), $CUST); $c = $r['ok']['content']['ride'];
t('phones get it: names, descriptions, label, order, icon', $c['title_en'] === 'ETEL Ride' && $c['title_am'] === 'ኢቴል ራይድ' && $c['desc_am'] === 'ጉዞ ይዘዙ' && $c['badge'] === 'New' && $c['sort_order'] === 3 && $c['icon_url'] === $ICON, json_encode($c));
$r = call('admin_hub_content_save', array('key' => 'ride', 'badge' => 'Beta'), $OWNER);
$r = call('get_hub_apps', array(), $CUST); $c = $r['ok']['content']['ride'];
t('a partial save changes only what was sent', $c['badge'] === 'Beta' && $c['title_en'] === 'ETEL Ride' && $c['icon_url'] === $ICON, json_encode($c));
$r = call('admin_hub_content_save', array('key' => 'ride', 'title_en' => '<b>Bold</b> <script>alert(1)</script>Ride'), $OWNER);
$c = call('get_hub_apps', array(), $CUST)['ok']['content']['ride'];
t('tags are removed from texts', $r['ok'] && strpos($c['title_en'], '<') === false && strpos($c['title_en'], '>') === false, $c['title_en']);
call('admin_hub_content_save', array('key' => 'ride', 'title_en' => str_repeat('a', 100)), $OWNER);
t('a long name is cut to 40', strlen(call('get_hub_apps', array(), $CUST)['ok']['content']['ride']['title_en']) === 40);
call('admin_hub_content_save', array('key' => 'ride', 'icon_url' => ''), $OWNER);
t('an empty icon removes the picture (built-in logo again)', call('get_hub_apps', array(), $CUST)['ok']['content']['ride']['icon_url'] === '');
t('unknown app refused', call('admin_hub_content_save', array('key' => 'nope', 'badge' => 'x'), $OWNER)['code'] === 422);
t('a feature switch has no look: refused', call('admin_hub_content_save', array('key' => 'light', 'badge' => 'x'), $OWNER)['code'] === 422);
t('nothing sent: refused', call('admin_hub_content_save', array('key' => 'ride'), $OWNER)['code'] === 422);
t('order out of range refused', call('admin_hub_content_save', array('key' => 'ride', 'sort_order' => 500), $OWNER)['code'] === 422 && call('admin_hub_content_save', array('key' => 'ride', 'sort_order' => -1), $OWNER)['code'] === 422 && call('admin_hub_content_save', array('key' => 'ride', 'sort_order' => 'x'), $OWNER)['code'] === 422);
foreach (array('http://ethelmarket.com/delivery/uploads/hub/a.png' => 'plain http', 'https://evil.example.com/delivery/uploads/hub/a.png' => 'other site', 'https://ethelmarket.com/delivery/uploads/items/a.png' => 'not the hub folder',
               'https://ethelmarket.com/delivery/uploads/hub/a.png?x=1' => 'query', 'https://ethelmarket.com/delivery/uploads/hub/../../a.png' => 'path climb', 'https://ethelmarket.com/delivery/uploads/hub/a.php' => 'not a picture',
               'https://ethelmarket.com.evil.com/delivery/uploads/hub/a.png' => 'look-alike host', 'javascript:alert(1)' => 'javascript') as $u => $why) {
    $r = call('admin_hub_content_save', array('key' => 'ride', 'icon_url' => $u), $OWNER); t('icon refused: ' . $why, $r['code'] === 422, json_encode($r));
}
t('non-owner cannot save', call('admin_hub_content_save', array('key' => 'ride', 'badge' => 'x'), $ADMIN)['code'] === 403 && call('admin_hub_content_save', array('key' => 'ride', 'badge' => 'x'), $CUST)['code'] === 403);

// ---- settings
$r = call('admin_hub_settings_save', array('settings' => array('welcome_title_en' => 'Welcome to ETEL', 'tagline_en' => 'One account. One ecosystem.', 'tagline_am' => 'አንድ መለያ', 'support_phone' => '+251 911 234 567', 'support_telegram' => '@etelsupport', 'support_email' => 'help@ethelmarket.com')), $OWNER);
t('owner saves home texts and support contacts', $r['ok'], json_encode($r));
$t = call('get_hub_apps', array(), $CUST)['ok']['texts'];
t('phones get them (Telegram becomes a link)', $t['welcome_title_en'] === 'Welcome to ETEL' && $t['support_phone'] === '+251 911 234 567' && $t['support_telegram'] === 'https://t.me/etelsupport' && $t['support_email'] === 'help@ethelmarket.com', json_encode($t));
foreach (array('support_phone' => 'call me', 'support_email' => 'not-an-email', 'support_telegram' => 'ab', 'banner_audience' => 'everyone', 'banner_starts_at' => 'not a date', 'banner_link_app' => 'nope', 'banner_image_url' => 'https://evil.example.com/delivery/uploads/hub/a.png') as $k => $v) {
    $r = call('admin_hub_settings_save', array('settings' => array($k => $v)), $OWNER); t('setting refused: ' . $k, $r['code'] === 422, json_encode($r));
}
t('unknown setting refused', call('admin_hub_settings_save', array('settings' => array('evil' => 'x')), $OWNER)['code'] === 422);
t('one bad value saves nothing at all', call('admin_hub_settings_save', array('settings' => array('support_email' => 'ok@ethelmarket.com', 'support_phone' => 'bad')), $OWNER)['code'] === 422 && call('get_hub_apps', array(), $CUST)['ok']['texts']['support_email'] === 'help@ethelmarket.com');
call('admin_hub_settings_save', array('settings' => array('tagline_am' => '')), $OWNER);
t('an empty value removes the setting', !isset(call('get_hub_apps', array(), $CUST)['ok']['texts']['tagline_am']));
t('non-owner cannot save settings', call('admin_hub_settings_save', array('settings' => array('support_phone' => '+251911000000')), $ADMIN)['code'] === 403);

// ---- banner
call('admin_hub_settings_save', array('settings' => array('banner_enabled' => '1', 'banner_title_en' => 'Peak hours', 'banner_text_en' => 'Earn more tonight', 'banner_link_app' => 'driver', 'banner_audience' => 'driver')), $OWNER);
$b = call('get_hub_apps', array(), $DRV)['ok']['banner'];
t('a driver sees the banner meant for drivers', $b && $b['title_en'] === 'Peak hours' && $b['link_app'] === 'driver' && strlen($b['id']) === 12, json_encode($b));
t('a customer does not see a drivers-only banner', call('get_hub_apps', array(), $CUST)['ok']['banner'] === null);
call('admin_hub_settings_save', array('settings' => array('banner_audience' => 'all', 'banner_ends_at' => date('Y-m-d H:i', time() - 3600))), $OWNER);
t('an expired banner is not shown', call('get_hub_apps', array(), $CUST)['ok']['banner'] === null);
call('admin_hub_settings_save', array('settings' => array('banner_ends_at' => date('Y-m-d H:i', time() + 3600), 'banner_starts_at' => date('Y-m-d H:i', time() + 1800))), $OWNER);
t('a banner not started yet is not shown', call('get_hub_apps', array(), $CUST)['ok']['banner'] === null);
call('admin_hub_settings_save', array('settings' => array('banner_starts_at' => date('Y-m-d H:i', time() - 1800))), $OWNER);
t('inside its dates it is shown to everybody', call('get_hub_apps', array(), $CUST)['ok']['banner'] !== null);
call('admin_hub_settings_save', array('settings' => array('banner_enabled' => '0')), $OWNER);
t('switched off: not shown', call('get_hub_apps', array(), $CUST)['ok']['banner'] === null);
call('admin_hub_settings_save', array('settings' => array('banner_enabled' => '1', 'banner_title_en' => '', 'banner_text_en' => '', 'banner_title_am' => '', 'banner_text_am' => '', 'banner_image_url' => '')), $OWNER);
t('an empty banner is never shown', call('get_hub_apps', array(), $CUST)['ok']['banner'] === null);
$s1 = array('banner_enabled' => '1', 'banner_title_en' => 'A'); $s2 = array('banner_enabled' => '1', 'banner_title_en' => 'B');
t('a changed banner has a different id (people who closed the old one see it)', hub_banner_active($s1, 'customer', time())['id'] !== hub_banner_active($s2, 'customer', time())['id']);

// ---- tables missing
$PDO->exec('RENAME TABLE app_hub_content TO app_hub_content_x'); $PDO->exec('RENAME TABLE app_hub_settings TO app_hub_settings_x');
$r = call('get_hub_apps', array(), $CUST); t('missing tables: the phone still gets an answer', $r['ok'] && $r['ok']['content'] === array() && $r['ok']['banner'] === null, json_encode($r));
$r = call('admin_hub_content_save', array('key' => 'ride', 'badge' => 'x'), $OWNER); t('missing table: save says run migration 112', $r['code'] === 500 && strpos($r['err'], 'migration 112') !== false, json_encode($r));
$r = call('admin_hub_settings_save', array('settings' => array('support_phone' => '+251911000000')), $OWNER); t('missing table: settings save says run migration 112', $r['code'] === 500 && strpos($r['err'], 'migration 112') !== false);
$PDO->exec('RENAME TABLE app_hub_content_x TO app_hub_content'); $PDO->exec('RENAME TABLE app_hub_settings_x TO app_hub_settings');
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit($FAILS ? 1 : 0);
