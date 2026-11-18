<?php
// Run: php delivery/tests/legal_deletion_test.php   (needs MariaDB database fa_test, sql_mode ANSI_QUOTES; it creates the small tables it needs)
// Legal texts the owner edits, and the "Delete my account" queue: the REAL libs and the REAL controller file.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; throw $r; }
function db() { global $PDO; return $PDO; }
function require_auth($pdo) { if (empty($GLOBALS['CUR'])) fail('Unauthorized', 401); return $GLOBALS['CUR']; }
function require_admin($pdo, $cap = null) { $u = require_auth($pdo); if (empty($u['is_admin'])) fail('Admin access required', 403); if ($cap === 'owner.only' && empty($u['owner'])) fail('Owner only', 403); return $u; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
foreach (array('users', 'customer_messages', 'push_subscriptions', 'customer_addresses', 'customer_favorites', 'ride_favorite_routes') as $t) $PDO->exec('DROP TABLE IF EXISTS `' . $t . '`');
$PDO->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL, email VARCHAR(160) DEFAULT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'customer', active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_phone (phone))");
$PDO->exec("CREATE TABLE customer_messages (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, sender ENUM('customer','system','support') NOT NULL DEFAULT 'customer', subject VARCHAR(150) DEFAULT NULL, body TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
foreach (array('push_subscriptions', 'customer_addresses', 'customer_favorites') as $t) $PDO->exec('CREATE TABLE `' . $t . '` (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL)');   // ride_favorite_routes is left out on purpose: a missing table must be skipped
$mig = file_get_contents(__DIR__ . '/../api/migrations/113_legal_and_deletion.sql');
$PDO->exec('DROP TABLE IF EXISTS legal_docs'); $PDO->exec('DROP TABLE IF EXISTS account_deletion_requests');
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $mig)))) as $q) $PDO->exec($q);
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; $b = $body;
    try { (function () use ($action, $b) { $body = $b; require dirname(__DIR__) . '/api/controllers/LegalController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
$OWNER = array('id' => 1, 'is_admin' => 1, 'owner' => 1); $ADMIN = array('id' => 2, 'is_admin' => 1, 'owner' => 0); $CUST = array('id' => 9, 'is_admin' => 0, 'owner' => 0);
$LONG = str_repeat('We keep only what the apps need to work. ', 8);

// ---- legal texts
$r = call('get_legal_doc', array('doc' => 'privacy'), null);
t('public read works without sign-in; nothing written yet = not custom', $r['ok'] && $r['ok']['custom'] === false, json_encode($r));
t('unknown document is not custom', call('get_legal_doc', array('doc' => 'x'), null)['ok']['custom'] === false);
t('admin who is not the owner cannot save', call('admin_legal_save', array('doc' => 'privacy', 'title' => 'P', 'body' => $LONG), $ADMIN)['code'] === 403);
t('customer cannot save', call('admin_legal_save', array('doc' => 'privacy', 'title' => 'P', 'body' => $LONG), $CUST)['code'] === 403);
t('not signed in cannot save', call('admin_legal_save', array('doc' => 'privacy', 'title' => 'P', 'body' => $LONG), null)['code'] === 401);
t('customer cannot read the editor', call('admin_legal_get', array(), $CUST)['code'] === 403);
t('nothing was written by refused calls', intval($PDO->query('SELECT COUNT(*) FROM legal_docs')->fetchColumn()) === 0);
t('owner: unknown document refused', call('admin_legal_save', array('doc' => 'cookies', 'title' => 'x', 'body' => $LONG), $OWNER)['code'] === 422);
t('owner: too short refused', call('admin_legal_save', array('doc' => 'privacy', 'title' => 'x', 'body' => 'short'), $OWNER)['code'] === 422);
t('owner: too long refused', call('admin_legal_save', array('doc' => 'privacy', 'title' => 'x', 'body' => str_repeat('a', 60001)), $OWNER)['code'] === 422);
t('owner: long title refused', call('admin_legal_save', array('doc' => 'privacy', 'title' => str_repeat('t', 81), 'body' => $LONG), $OWNER)['code'] === 422);
$r = call('admin_legal_save', array('doc' => 'privacy', 'title' => '<b>Privacy</b>', 'body' => "# Data\n<script>alert(1)</script>" . $LONG . "\r\n\r\n\r\n\r\nMore"), $OWNER);
t('owner saves a privacy text', $r['ok'] && $r['ok']['saved'] === true, json_encode($r));
$r = call('get_legal_doc', array('doc' => 'privacy'), null);
t('public read now returns it', $r['ok']['custom'] === true && $r['ok']['title'] === 'Privacy', json_encode($r['ok']));
t('tags are removed (the words stay as plain text)', strpos($r['ok']['body'], '<') === false && strpos($r['ok']['body'], '# Data') === 0);
t('many blank lines collapse', strpos($r['ok']['body'], "\n\n\n") === false && strpos($r['ok']['body'], "\r") === false);
t('terms are still built-in', call('get_legal_doc', array('doc' => 'terms'), null)['ok']['custom'] === false);
$r = call('admin_legal_get', array(), $OWNER);
t('editor returns both documents', $r['ok'] && isset($r['ok']['docs']['privacy'], $r['ok']['docs']['terms']) && $r['ok']['docs']['terms']['body'] === '');
call('admin_legal_save', array('doc' => 'privacy', 'title' => 'P2', 'body' => $LONG), $OWNER);
t('saving again replaces (one row)', intval($PDO->query('SELECT COUNT(*) FROM legal_docs')->fetchColumn()) === 1 && call('get_legal_doc', array('doc' => 'privacy'), null)['ok']['title'] === 'P2');
call('admin_legal_save', array('doc' => 'privacy', 'title' => '', 'body' => ''), $OWNER);
t('empty text = back to the built-in page', call('get_legal_doc', array('doc' => 'privacy'), null)['ok']['custom'] === false && intval($PDO->query('SELECT COUNT(*) FROM legal_docs')->fetchColumn()) === 0);

// ---- deletion queue
$PDO->exec("INSERT INTO users (id,name,phone,email,password_hash,role) VALUES (10,'Abel','0911000010','a@x.et','hash','customer'),(11,'Sara','0911000011',NULL,'hash','driver'),(12,'Boss','0911000012',NULL,'hash','admin'),(13,'Keep','0911000013',NULL,'hash','customer')");
foreach (array(10, 11, 12, 13) as $u) foreach (array('push_subscriptions', 'customer_addresses', 'customer_favorites') as $tb) $PDO->exec("INSERT INTO $tb (user_id) VALUES ($u)");
$PDO->exec("INSERT INTO customer_messages (id,user_id,sender,subject,body) VALUES (1,10,'customer','Account deletion request','delete me'),(2,11,'customer','Account deletion request','driver delete'),(3,12,'customer','Account deletion request','admin delete'),(4,13,'customer','Hello','not a deletion'),(5,13,'support','Account deletion request','staff reply with same subject')");
t('customer cannot list requests', call('admin_deletion_list', array(), $CUST)['code'] === 403);
t('admin who is not the owner cannot list', call('admin_deletion_list', array(), $ADMIN)['code'] === 403);
$r = call('admin_deletion_list', array(), $OWNER);
t('list has the 3 customer requests only, all open', $r['ok'] && count($r['ok']['requests']) === 3 && $r['ok']['requests'][0]['status'] === 'open', json_encode($r));
t('list shows the person (name, phone, role)', $r['ok']['requests'][2]['name'] === 'Abel' && $r['ok']['requests'][2]['phone'] === '0911000010' && $r['ok']['requests'][1]['role'] === 'driver');
t('customer cannot decide', call('admin_deletion_decide', array('message_id' => 1, 'decision' => 'delete'), $CUST)['code'] === 403);
t('admin who is not the owner cannot decide', call('admin_deletion_decide', array('message_id' => 1, 'decision' => 'delete'), $ADMIN)['code'] === 403);
t('the account is untouched after refused calls', $PDO->query('SELECT name FROM users WHERE id=10')->fetchColumn() === 'Abel');
t('unknown decision refused', call('admin_deletion_decide', array('message_id' => 1, 'decision' => 'maybe'), $OWNER)['code'] === 422);
t('a message that is not a deletion request is refused', call('admin_deletion_decide', array('message_id' => 4, 'decision' => 'delete'), $OWNER)['code'] === 422);
t('a staff reply with the same subject is refused', call('admin_deletion_decide', array('message_id' => 5, 'decision' => 'delete'), $OWNER)['code'] === 422);
t('an admin account is not deleted here', call('admin_deletion_decide', array('message_id' => 3, 'decision' => 'delete'), $OWNER)['code'] === 422 && $PDO->query('SELECT active FROM users WHERE id=12')->fetchColumn() == 1);
$r = call('admin_deletion_decide', array('message_id' => 1, 'decision' => 'delete', 'note' => 'Called him <b>ok</b>'), $OWNER);
t('owner deletes the customer account', $r['ok'] && $r['ok']['saved'] === true, json_encode($r));
$u = $PDO->query('SELECT * FROM users WHERE id=10')->fetch();
t('name, phone, email, password replaced and sign-in disabled', $u['name'] === 'Deleted user' && $u['phone'] === 'deleted-10' && $u['email'] === null && $u['password_hash'] === '!' && intval($u['active']) === 0, json_encode($u));
foreach (array('push_subscriptions', 'customer_addresses', 'customer_favorites') as $tb) t("$tb of that user removed", intval($PDO->query("SELECT COUNT(*) FROM $tb WHERE user_id=10")->fetchColumn()) === 0);
t('other people lists are untouched', intval($PDO->query("SELECT COUNT(*) FROM customer_addresses WHERE user_id IN (11,12,13)")->fetchColumn()) === 3);
t('other accounts untouched', $PDO->query('SELECT name FROM users WHERE id=11')->fetchColumn() === 'Sara' && intval($PDO->query('SELECT active FROM users WHERE id=11')->fetchColumn()) === 1);
t('the request is marked done, note has no tags', $PDO->query('SELECT status FROM account_deletion_requests WHERE message_id=1')->fetchColumn() === 'done' && strpos($PDO->query('SELECT note FROM account_deletion_requests WHERE message_id=1')->fetchColumn(), '<') === false);
t('handling it twice is refused', call('admin_deletion_decide', array('message_id' => 1, 'decision' => 'delete'), $OWNER)['code'] === 422);
$r = call('admin_deletion_decide', array('message_id' => 2, 'decision' => 'reject', 'note' => 'Has an open trip'), $OWNER);
t('reject only marks it, the account stays', $r['ok'] && $PDO->query('SELECT name FROM users WHERE id=11')->fetchColumn() === 'Sara' && $PDO->query('SELECT status FROM account_deletion_requests WHERE message_id=2')->fetchColumn() === 'rejected');
$r = call('admin_deletion_list', array(), $OWNER);
$st = array(); foreach ($r['ok']['requests'] as $q) $st[$q['message_id']] = $q['status'];
t('list now shows done, rejected, open', $st[1] === 'done' && $st[2] === 'rejected' && $st[3] === 'open', json_encode($st));
// the same phone can sign up again: the old number is free
$PDO->exec("INSERT INTO users (name,phone,password_hash) VALUES ('Abel new','0911000010','h')");
t('the person can register again with the same phone', intval($PDO->query("SELECT COUNT(*) FROM users WHERE phone='0911000010'")->fetchColumn()) === 1);
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);
