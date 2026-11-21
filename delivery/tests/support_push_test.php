<?php
// Run: php delivery/tests/support_push_test.php   (needs MariaDB database fa_test; it loads migration 103 for the support tables)
// The staff reply of the Support desk now pushes "you have a reply" to the person's phone: the REAL lib/sup_notify.php. Only the push (a network call) is replaced by a recorder.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
$PUSHES = array(); $THROW = false;
function etel_push_send_async($pdo, $uid, $title, $body, $data = array()) { global $PUSHES, $THROW; if ($THROW) throw new Exception('boom'); $PUSHES[] = array('uid' => $uid, 'title' => $title, 'body' => $body, 'data' => $data); return true; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
$PDO->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', role VARCHAR(20) NOT NULL DEFAULT 'customer')");
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../api/migrations/103_support_desk.sql'))))) as $q) { try { $PDO->exec($q); } catch (Exception $e) { /* already there */ } }
$PDO->exec('DELETE FROM sup_settings');
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
require_once __DIR__ . '/../api/lib/sup_notify.php';
$T = array('id' => 41, 'user_id' => 7, 'ref' => 'ET-0041', 'lang' => 'en', 'subject' => 'My secret subject 0911223344');

t('a staff reply pushes to the person who asked', sup_push_user_reply($PDO, $T) === true && count($PUSHES) === 1 && $PUSHES[0]['uid'] === 7, json_encode($PUSHES));
$p = $PUSHES[0];
t('the push names the request and says to open Help', strpos($p['body'], 'ET-0041') !== false && strpos($p['body'], 'Open Help') !== false && $p['title'] === 'Reply from ETEL support');
t('it never carries the subject, the message or a phone number', strpos($p['title'] . $p['body'], 'secret') === false && strpos($p['title'] . $p['body'], '0911') === false);
t('the data tells the app which request: type support_reply and the ticket id', $p['data']['type'] === 'support_reply' && $p['data']['ticket_id'] === 41);
$PUSHES = array();
$r = sup_notify_user_reply($PDO, $T);
t('the notice function sends the push even when Telegram is off (the default)', count($PUSHES) === 1 && $r === false, json_encode(array(count($PUSHES), $r)));
$THROW = true; $PUSHES = array();
t('a failing push never breaks the reply: false, no exception', sup_push_user_reply($PDO, $T) === false && sup_notify_user_reply($PDO, $T) === false);
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);
