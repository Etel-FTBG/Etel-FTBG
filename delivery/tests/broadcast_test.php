<?php
// Run: php delivery/tests/broadcast_test.php   (needs MariaDB database fa_test, sql_mode ANSI_QUOTES; it creates the small tables it needs)
// Messages to phones from Admin: the REAL lib/broadcast.php and the REAL controller file. Only the actual push (a network call) is replaced by a recorder.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); if ($cap === 'owner.only' && empty($u['owner'])) fail('Owner only', 403); return $u; }
$SENT = array(); $FAILUSERS = array(); $THROWUSERS = array();
function etel_push_to_user($pdo, $uid, $payload) {
    global $SENT, $FAILUSERS, $THROWUSERS;
    if (in_array($uid, $THROWUSERS, true)) throw new Exception('boom');
    $SENT[] = array($uid, $payload);
    return array(array('ok' => !in_array($uid, $FAILUSERS, true)));
}
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
foreach (array('users', 'push_subscriptions', 'push_broadcasts') as $t) $PDO->exec('DROP TABLE IF EXISTS `' . $t . '`');
$PDO->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'customer', active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_phone (phone))");
$PDO->exec("CREATE TABLE push_subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL)");
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../api/migrations/114_push_broadcasts.sql'))))) as $q) $PDO->exec($q);
$PDO->exec("INSERT INTO users (id,name,phone,role,active) VALUES
 (1,'Owner','+251900000000','admin',1),
 (10,'Abel','+251911000010','customer',1),(11,'Sara','+251911000011','customer',1),(12,'NoPhone','+251911000012','customer',1),(13,'Gone','+251911000013','customer',0),
 (20,'Dawit','+251911000020','driver',1),(21,'Meron','+251911000021','courier',1),(22,'Shop','+251911000022','service_giver',1),
 (30,'Twin A','0922000030','customer',1),(31,'Twin B','+251922000030','driver',1)");
foreach (array(1, 10, 11, 13, 20, 20, 21, 22, 30) as $u) $PDO->exec("INSERT INTO push_subscriptions (user_id) VALUES ($u)");   // user 12 and 31 have no phone able to receive; 20 has two devices
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; $b = $body;
    try { (function () use ($action, $b) { $body = $b; require dirname(__DIR__) . '/api/controllers/BroadcastController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
$OWNER = array('id' => 1, 'is_admin' => 1, 'owner' => 1); $ADMIN = array('id' => 2, 'is_admin' => 1, 'owner' => 0); $CUST = array('id' => 10, 'is_admin' => 0, 'owner' => 0);
function sentIds() { global $SENT; $o = array(); foreach ($SENT as $s) $o[] = $s[0]; sort($o); return $o; }
function reset_sent() { global $SENT; $SENT = array(); }
$M = array('audience' => 'customer', 'title' => 'Hello', 'body' => 'A short test message');

// ---- who may use it
foreach (array('admin_broadcast_preview', 'admin_broadcast_send', 'admin_broadcast_list') as $a) {
    t("$a: not signed in = 401", call($a, $M, null)['code'] === 401);
    t("$a: customer = 403", call($a, $M, $CUST)['code'] === 403);
    t("$a: admin who is not the owner = 403", call($a, $M, $ADMIN)['code'] === 403);
}
t('nothing was sent or written by refused calls', count($SENT) === 0 && intval($PDO->query('SELECT COUNT(*) FROM push_broadcasts')->fetchColumn()) === 0);

// ---- preview
$r = call('admin_broadcast_preview', array('audience' => 'customer'), $OWNER);
t('preview customers: 4 active people, 3 can receive (not the one with no device, not the disabled one)', $r['ok'] && $r['ok']['people'] === 4 && $r['ok']['reachable'] === 3, json_encode($r));
$r = call('admin_broadcast_preview', array('audience' => 'all'), $OWNER);
t('preview all: only active accounts with a device (users 1,10,11,20,21,22,30)', $r['ok'] && $r['ok']['reachable'] === 7 && $r['ok']['people'] === 9, json_encode($r));
$r = call('admin_broadcast_preview', array('audience' => 'driver'), $OWNER);
t('a driver with two devices counts once', $r['ok']['reachable'] === 1, json_encode($r));
t('unknown audience refused', call('admin_broadcast_preview', array('audience' => 'everyone'), $OWNER)['code'] === 422);
$r = call('admin_broadcast_preview', array('audience' => 'user', 'phone' => '0911 000 010'), $OWNER);
t('one person found by phone (spaces and a leading 0 are fine)', $r['ok'] && $r['ok']['name'] === 'Abel' && $r['ok']['reachable'] === 1, json_encode($r));
t('one person found with +251', call('admin_broadcast_preview', array('audience' => 'user', 'phone' => '+251911000011'), $OWNER)['ok']['name'] === 'Sara');
t('too short a number refused', call('admin_broadcast_preview', array('audience' => 'user', 'phone' => '0911'), $OWNER)['code'] === 422);
t('unknown number refused', call('admin_broadcast_preview', array('audience' => 'user', 'phone' => '0999999999'), $OWNER)['code'] === 422);
t('a disabled account cannot be found', call('admin_broadcast_preview', array('audience' => 'user', 'phone' => '0911000013'), $OWNER)['code'] === 422);
t('two accounts with the same last 9 digits: refused, asks for the full number', call('admin_broadcast_preview', array('audience' => 'user', 'phone' => '922000030'), $OWNER)['code'] === 422);
$r = call('admin_broadcast_preview', array('audience' => 'user', 'phone' => '0911000012'), $OWNER);
t('a person with no device: found, but 0 can receive', $r['ok'] && $r['ok']['people'] === 1 && $r['ok']['reachable'] === 0, json_encode($r));
t('preview sent nothing', count($SENT) === 0);

// ---- send
t('no title refused', call('admin_broadcast_send', array('audience' => 'customer', 'title' => '', 'body' => 'x'), $OWNER)['code'] === 422);
t('no message refused', call('admin_broadcast_send', array('audience' => 'customer', 'title' => 'x', 'body' => '  '), $OWNER)['code'] === 422);
t('a person with no device: refused with a clear reason', call('admin_broadcast_send', array('audience' => 'user', 'phone' => '0911000012', 'title' => 'x', 'body' => 'y'), $OWNER)['code'] === 422);
t('nothing was sent by the refused sends', count($SENT) === 0 && intval($PDO->query('SELECT COUNT(*) FROM push_broadcasts')->fetchColumn()) === 0);
$FAILUSERS = array(11);
$r = call('admin_broadcast_send', array('audience' => 'customer', 'title' => '<b>Peak</b> hours', 'body' => 'Rain tonight <script>x</script>, expect more rides'), $OWNER);
t('owner sends to customers', $r['ok'] && $r['ok']['recipients'] === 3 && $r['ok']['id'] > 0, json_encode($r));
t('exactly the 3 reachable customers were sent to (no staff, driver, disabled or device-less account)', sentIds() === array(10, 11, 30), json_encode(sentIds()));
$p = $SENT[0][1];
t('the push carries title, text, the broadcast id; tags are removed', $p['title'] === 'Peak hours' && strpos($p['body'], '<') === false && $p['data']['type'] === 'broadcast' && $p['data']['broadcast_id'] === $r['ok']['id'], json_encode($p));
$row = $PDO->query('SELECT * FROM push_broadcasts WHERE id=' . intval($r['ok']['id']))->fetch();
t('history row: 3 people, 2 reached, 1 failed, sent', intval($row['recipients']) === 3 && intval($row['reached']) === 2 && intval($row['failed']) === 1 && $row['status'] === 'sent' && intval($row['created_by']) === 1, json_encode($row));
t('the same message again within 10 minutes is refused (double click)', call('admin_broadcast_send', array('audience' => 'customer', 'title' => 'Peak hours', 'body' => 'Rain tonight x, expect more rides'), $OWNER)['code'] === 422 || call('admin_broadcast_send', array('audience' => 'customer', 'title' => '<b>Peak</b> hours', 'body' => 'Rain tonight <script>x</script>, expect more rides'), $OWNER)['code'] === 422);
reset_sent(); $FAILUSERS = array();
$r = call('admin_broadcast_send', array('audience' => 'driver', 'title' => 'Drivers', 'body' => 'Meeting at 5'), $OWNER);
t('drivers: one person, sent once even with two devices', $r['ok'] && sentIds() === array(20));
reset_sent();
$r = call('admin_broadcast_send', array('audience' => 'user', 'phone' => '+251911000011', 'title' => 'Hi Sara', 'body' => 'Your documents are approved'), $OWNER);
t('one person: only that person gets it', $r['ok'] && sentIds() === array(11), json_encode(sentIds()));
reset_sent(); $THROWUSERS = array(10);
$r = call('admin_broadcast_send', array('audience' => 'customer', 'title' => 'Crash test', 'body' => 'One push throws'), $OWNER);
$row = $PDO->query('SELECT * FROM push_broadcasts WHERE id=' . intval($r['ok']['id']))->fetch();
t('a push that throws does not stop the rest, and is counted as failed', $r['ok'] && sentIds() === array(11, 30) && intval($row['reached']) === 2 && intval($row['failed']) === 1 && $row['status'] === 'sent', json_encode($row));
$THROWUSERS = array();
reset_sent();
$r = call('admin_broadcast_send', array('audience' => 'all', 'title' => 'Everyone', 'body' => 'Happy new year'), $OWNER);
t('all: every reachable account once (admin included)', $r['ok'] && sentIds() === array(1, 10, 11, 20, 21, 22, 30), json_encode(sentIds()));
t('a very long text is cut to 60 and 240 letters', (function () use ($OWNER) { $r = call('admin_broadcast_send', array('audience' => 'courier', 'title' => str_repeat('t', 100), 'body' => str_repeat('b', 500)), $OWNER); $row = $GLOBALS['PDO']->query('SELECT title, body FROM push_broadcasts WHERE id=' . intval($r['ok']['id']))->fetch(); return strlen($row['title']) === 60 && strlen($row['body']) === 240; })());

// ---- history
$r = call('admin_broadcast_list', array(), $OWNER);
t('history lists the newest first with counts and the name for a single person', $r['ok'] && count($r['ok']['broadcasts']) >= 5 && $r['ok']['broadcasts'][0]['title'] !== '' && $r['ok']['broadcasts'][0]['id'] > $r['ok']['broadcasts'][1]['id'], json_encode(array_slice($r['ok']['broadcasts'], 0, 2)));
$one = null; foreach ($r['ok']['broadcasts'] as $b) if ($b['audience'] === 'user') $one = $b;
t('a single-person message shows the person name', $one && $one['target_name'] === 'Sara', json_encode($one));
// the cap
$PDO->exec("INSERT INTO users (name, phone, role, active) SELECT CONCAT('bulk', n), CONCAT('+2519', LPAD(n, 8, '0')), 'courier', 1 FROM (SELECT a.N + b.N*10 + c.N*100 + d.N*1000 + 100 AS n FROM (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d) x");
$PDO->exec("INSERT INTO push_subscriptions (user_id) SELECT id FROM users WHERE name LIKE 'bulk%'");
reset_sent();
$r = call('admin_broadcast_send', array('audience' => 'courier', 'title' => 'Too many', 'body' => 'Over the cap'), $OWNER);
t('more than 2000 people: refused with advice, nothing sent', $r['code'] === 422 && count($SENT) === 0, json_encode($r));
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);
