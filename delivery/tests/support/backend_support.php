<?php
// Behavioural test for the Support desk. Runs the REAL lib + controller files against a local MariaDB (ANSI_QUOTES on).
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
foreach (array('sup_tickets', 'sup_messages', 'login_attempts', 'users') as $t) $PDO->exec("TRUNCATE TABLE `$t`");
$PDO->exec("TRUNCATE TABLE sup_canned"); $PDO->exec("DELETE FROM sup_settings"); 
$PDO->exec("INSERT INTO sup_settings (setting_key, setting_value, updated_by, updated_at) VALUES ('desk_enabled','1',0,NOW()),('tg_enabled','0',0,NOW())");
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API;
    $fn = function () use ($action, $body, $API) {
        $f = (strpos($action, 'admin_sup_') === 0) ? 'SupportAdminController.php' : 'SupportController.php';
        require $API . '/controllers/' . $f; throw new Exception('no response for ' . $action);
    };
    try { $fn(); } catch (Resp $r) { return $r; }
}
$PASS = 0; $FAILS = array();
function t($name, $cond, $extra = '') { global $PASS, $FAILS; if ($cond) { $PASS++; } else { $FAILS[] = $name . ($extra !== '' ? '  -> ' . $extra : ''); } }
function mkuser($name, $phone) { global $PDO; $PDO->prepare('INSERT INTO users (name, phone, created_at) VALUES (?,?,NOW())')->execute(array($name, $phone)); return array('id' => intval($PDO->lastInsertId()), 'name' => $name, 'phone' => $phone); }
function one($sql, $p = array()) { global $PDO; $q = $PDO->prepare($sql); $q->execute($p); return $q->fetchColumn(); }
require_once $API . '/lib/sup_core.php'; require_once $API . '/lib/sup_notify.php';
$A = array('id' => 9000, 'name' => 'Admin Abel', 'phone' => '0900000000', 'is_admin' => true);
$U1 = mkuser('Rider One', '0911111111'); $U2 = mkuser('Rider Two', '0922222222');

// ---- settings
$r = call('admin_sup_settings_save', array('settings' => array('sla_first_hours' => 0)), $A); t('settings: out of range rejected', !$r->ok);
$r = call('admin_sup_settings_save', array('settings' => array('sla_first_hours' => 4, 'sla_urgent_hours' => 6)), $A); t('settings: urgent longer than normal rejected', !$r->ok);
$r = call('admin_sup_settings_save', array('settings' => array('tg_staff_chat_id' => 'abc')), $A); t('settings: bad telegram id rejected', !$r->ok);
$r = call('admin_sup_settings_save', array('settings' => array('tg_enabled' => 1)), $A); t('settings: telegram on without any target rejected', !$r->ok);
$r = call('admin_sup_settings_save', array('settings' => array('sla_first_hours' => 8, 'sla_urgent_hours' => 2, 'max_open_per_user' => 3)), $A); t('settings: valid save', $r->ok && $r->data['settings']['max_open_per_user'] == 3);
$r = call('admin_sup_settings_get', array(), $U1 + array('is_admin' => false)); t('settings: non-admin refused', !$r->ok && $r->code2 == 403);
$r = call('admin_sup_settings_get', array(), $A); t('settings: telegram is OFF by default', $r->ok && $r->data['settings']['tg_enabled'] == 0 && $r->data['settings']['tg_notify_user'] == 0);

// ---- create
$r = call('sup_meta', array(), $U1); t('meta: apps + categories', $r->ok && isset($r->data['apps']['pay']) && in_array('safety', $r->data['categories']));
$r = call('sup_ticket_create', array('app' => 'pay', 'category' => 'payment', 'subject' => 'x', 'body' => 'Money missing from wallet'), $U1); t('create: short title rejected', !$r->ok);
$r = call('sup_ticket_create', array('app' => 'pay', 'category' => 'payment', 'subject' => 'Money missing', 'body' => 'hi'), $U1); t('create: short body rejected', !$r->ok);
$r = call('sup_ticket_create', array('app' => 'pay', 'category' => 'payment', 'subject' => 'Money missing', 'body' => 'I topped up 200 birr and it is not in my wallet', 'lang' => 'en'), $U1);
t('create: ok, ref like ET-000001', $r->ok && preg_match('/^ET-0000\d\d$/', $r->data['ticket']['ref']) === 1, $r->ok ? $r->data['ticket']['ref'] : $r->getMessage()); $T1 = $r->ok ? $r->data['ticket']['id'] : 0;
t('create: payment is high priority', one('SELECT priority FROM sup_tickets WHERE id = ?', array($T1)) === 'high');
t('create: due date set (SLA)', one('SELECT due_at FROM sup_tickets WHERE id = ?', array($T1)) !== null);
$r = call('sup_ticket_create', array('app' => 'pay', 'category' => 'payment', 'subject' => 'Money missing', 'body' => 'I topped up 200 birr and it is not in my wallet'), $U1); t('create: same title within 10 minutes returns the same request', $r->ok && $r->data['ticket']['id'] == $T1);
$r = call('sup_ticket_create', array('app' => 'ride', 'category' => 'safety', 'subject' => 'Driver was unsafe', 'body' => 'The driver drove very fast and I felt unsafe'), $U1); $T2 = $r->data['ticket']['id'];
t('create: safety is urgent', one('SELECT priority FROM sup_tickets WHERE id = ?', array($T2)) === 'urgent');
$r = call('sup_ticket_create', array('app' => 'nonsense', 'category' => 'weird', 'subject' => 'Something odd', 'body' => 'Unknown app and category'), $U1); $T3 = $r->data['ticket']['id'];
t('create: unknown app/category fall back to other', one('SELECT CONCAT(app, \'/\', category) FROM sup_tickets WHERE id = ?', array($T3)) === 'other/other');
$r = call('sup_ticket_create', array('app' => 'ride', 'category' => 'trip', 'subject' => 'A fourth one', 'body' => 'This should hit the open limit'), $U1); t('create: open-request limit enforced', !$r->ok && strpos($r->getMessage(), 'open requests') !== false, $r->getMessage());
$inj = "<script>alert(1)</script> " . str_repeat('A', 200);
$r = call('sup_ticket_create', array('app' => 'gebeya', 'category' => 'order', 'subject' => $inj, 'body' => "Body with \x01 control char and <b>html</b>"), $U2); $T4 = $r->data['ticket']['id'];
t('create: subject cut to 160 chars', sup_len(one('SELECT subject FROM sup_tickets WHERE id = ?', array($T4))) <= 160);
t('create: control characters stripped', strpos(one('SELECT body FROM sup_messages WHERE ticket_id = ? LIMIT 1', array($T4)), "\x01") === false);

// ---- privacy between users
$r = call('sup_ticket_get', array('id' => $T1), $U2); t('privacy: another user cannot open my request', !$r->ok && $r->code2 == 404);
$r = call('sup_ticket_reply', array('id' => $T1, 'body' => 'hacking in'), $U2); t('privacy: another user cannot reply', !$r->ok);
$r = call('sup_my_tickets', array(), $U2); t('privacy: my list shows only mine', $r->ok && count($r->data['tickets']) === 1 && $r->data['tickets'][0]['id'] == $T4);

// ---- staff side
$r = call('admin_sup_list', array(), $A); t('list: active queue ordered urgent first', $r->ok && $r->data['tickets'][0]['priority'] === 'urgent', $r->ok ? $r->data['tickets'][0]['priority'] : '');
t('list: counts', $r->ok && $r->data['counts']['open'] == 4 && $r->data['counts']['unread'] == 4);
$r = call('admin_sup_list', array('app' => 'pay'), $A); t('list: filter by app', $r->ok && count($r->data['tickets']) === 1);
$r = call('admin_sup_list', array('q' => 'Rider Two'), $A); t('list: search by name', $r->ok && count($r->data['tickets']) === 1);
$r = call('admin_sup_list', array('q' => '%'), $A); t('list: percent sign searched literally', $r->ok && count($r->data['tickets']) === 0);
$r = call('admin_sup_list', array(), $U1 + array('is_admin' => false)); t('list: non-admin refused', !$r->ok && $r->code2 == 403);
$PDO->exec("UPDATE sup_tickets SET due_at = NOW() - INTERVAL 1 HOUR WHERE id = $T3");
$r = call('admin_sup_list', array('overdue' => 1), $A); t('list: overdue filter finds the late one', $r->ok && count($r->data['tickets']) === 1 && $r->data['tickets'][0]['id'] == $T3 && $r->data['counts']['overdue'] == 1);
$r = call('admin_sup_get', array('id' => $T1), $A); t('get: thread + user info + clears unread', $r->ok && count($r->data['messages']) === 1 && $r->data['user']['phone'] === '0911111111' && one('SELECT staff_unread FROM sup_tickets WHERE id = ?', array($T1)) == 0);
$r = call('admin_sup_reply', array('id' => $T1, 'body' => 'Internal: check Telebirr log', 'note' => true), $A);
$r = call('sup_ticket_get', array('id' => $T1), $U1); t('privacy: customer never sees internal notes', $r->ok && count($r->data['messages']) === 1 && strpos(json_encode($r->data), 'Telebirr log') === false);
$r = call('admin_sup_reply', array('id' => $T1, 'body' => 'We found your top-up and added it. Sorry for the delay!'), $A);
t('reply: status becomes pending, user_unread, first response recorded', $r->ok && $r->data['ticket']['status'] === 'pending' && one('SELECT user_unread FROM sup_tickets WHERE id = ?', array($T1)) == 1 && one('SELECT first_response_at FROM sup_tickets WHERE id = ?', array($T1)) !== null);
$r = call('admin_sup_reply', array('id' => $T1, 'body' => ''), $A); t('reply: empty refused', !$r->ok);
$r = call('sup_my_tickets', array(), $U1); t('customer: unread count shows', $r->ok && $r->data['unread'] >= 1);
$r = call('sup_ticket_get', array('id' => $T1), $U1); t('customer: sees staff reply as "support", no staff name or id', $r->ok && $r->data['messages'][1]['from'] === 'support' && strpos(json_encode($r->data), 'Abel') === false && strpos(json_encode($r->data), '9000') === false);
t('customer: opening clears unread', one('SELECT user_unread FROM sup_tickets WHERE id = ?', array($T1)) == 0);
$r = call('sup_ticket_reply', array('id' => $T1, 'body' => 'Thank you, I see it now'), $U1); t('customer reply: reopens to open', $r->ok && $r->data['ticket']['status'] === 'open');
$r = call('admin_sup_update', array('id' => $T1, 'assign' => 'me', 'priority' => 'urgent', 'status' => 'open'), $A); t('update: assign + priority', $r->ok && $r->data['ticket']['priority'] === 'urgent' && intval($r->data['ticket']['assigned_to']) === 9000);
$r = call('admin_sup_get', array('id' => $T1), $A); t('update: recorded as system lines in the history', substr_count(json_encode($r->data['messages']), '"system"') >= 2);
$r = call('admin_sup_update', array('id' => $T1, 'status' => 'banana'), $A); t('update: unknown status refused', !$r->ok);
$r = call('admin_sup_list', array('assigned' => 'me'), $A); t('list: assigned to me', $r->ok && count($r->data['tickets']) === 1);

// ---- solve, rate, close
$r = call('sup_ticket_rate', array('id' => $T1, 'rating' => 5), $U1); t('rate: not before solved', !$r->ok);
$r = call('admin_sup_reply', array('id' => $T1, 'body' => 'Glad it is fixed. Closing this one.', 'status' => 'solved'), $A); t('reply with status solved', $r->ok && $r->data['ticket']['status'] === 'solved');
$r = call('sup_ticket_rate', array('id' => $T1, 'rating' => 9), $U1); t('rate: out of range refused', !$r->ok);
$r = call('sup_ticket_rate', array('id' => $T1, 'rating' => 5, 'note' => 'Fast and kind'), $U1); t('rate: ok once solved', $r->ok && $r->data['ticket']['rating'] === 5);
$r = call('sup_ticket_rate', array('id' => $T1, 'rating' => 1), $U1); t('rate: only once', !$r->ok);
$r = call('sup_ticket_close', array('id' => $T2), $U1); t('customer can mark solved', $r->ok && $r->data['ticket']['status'] === 'solved');
$PDO->exec("UPDATE sup_tickets SET solved_at = NOW() - INTERVAL 10 DAY WHERE id = $T1");
$r = call('admin_sup_list', array('status' => 'closed'), $A); t('housekeeping: old solved requests are closed automatically', $r->ok && count($r->data['tickets']) === 1 && $r->data['tickets'][0]['id'] == $T1);
$r = call('sup_ticket_reply', array('id' => $T1, 'body' => 'one more thing'), $U1); t('closed: customer cannot reply, told to open a new one', !$r->ok && strpos($r->getMessage(), 'closed') !== false);
$r = call('sup_ticket_get', array('id' => $T1), $U1); t('closed: get says can_reply false, can_rate false', $r->ok && $r->data['can_reply'] === false && $r->data['can_rate'] === false);

// ---- canned replies
$r = call('admin_sup_canned_save', array('title' => '', 'body_en' => ''), $A); t('canned: empty refused', !$r->ok);
$r = call('admin_sup_canned_save', array('title' => 'Top-up delay', 'body_en' => 'Top-ups can take up to 10 minutes.', 'body_am' => 'x'), $A); $cid = $r->data['id']; t('canned: saved', $r->ok && $cid > 0);
$r = call('admin_sup_canned_list', array(), $A); t('canned: listed', $r->ok && count($r->data['canned']) === 1);
$r = call('admin_sup_canned_delete', array('id' => $cid), $A); $r = call('admin_sup_canned_list', array(), $A); t('canned: deleted', count($r->data['canned']) === 0);

// ---- stats
$r = call('admin_sup_stats', array('days' => 30), $A); t('stats: totals', $r->ok && $r->data['created'] == 4 && $r->data['rated'] == 1 && $r->data['csat'] == 5 && $r->data['avg_first_response_hours'] !== null, $r->ok ? json_encode(array($r->data['created'], $r->data['rated'], $r->data['csat'])) : '');
t('stats: by app has the apps used', $r->ok && count($r->data['by_app']) >= 3);

// ---- Telegram: off by default, then on with a fake sender (nothing real is ever contacted)
$sent = array(); $GLOBALS['SUP_TG_SENDER'] = function ($chat, $text) use (&$sent) { $sent[] = array($chat, $text); return true; }; $GLOBALS['SUP_TG_CHAT_LOOKUP'] = function ($phone) { return $phone === '0911111111' ? '555111222' : ''; };
$r = call('sup_ticket_create', array('app' => 'taxi', 'category' => 'app', 'subject' => 'App crash on start', 'body' => 'My secret details 0911111111 are here'), $U1); $T5 = $r->data['ticket']['id'];
call('admin_sup_reply', array('id' => $T5, 'body' => 'Private reply text'), $A); t('telegram: nothing sent while switched off', count($sent) === 0);
$PDO->exec("INSERT INTO sup_settings (setting_key, setting_value, updated_by, updated_at) VALUES ('tg_staff_chat_id','-100123456',0,NOW()),('tg_notify_user','1',0,NOW()),('tg_enabled','1',0,NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"); sup_settings($PDO, true);
$r = call('sup_ticket_create', array('app' => 'madeya', 'category' => 'account', 'subject' => 'Cannot log in', 'body' => 'My phone number is 0911111111 and my password fails'), $U2); 
t('telegram on: staff chat gets a new-request notice', count($sent) === 1 && $sent[0][0] === '-100123456' && strpos($sent[0][1], 'ET-') !== false);
t('telegram: notice never contains the message text or a phone number', strpos($sent[0][1], '0911111111') === false && strpos($sent[0][1], 'password') === false);
$T6 = $r->data['ticket']['id'];
call('admin_sup_reply', array('id' => $T6, 'body' => 'Private reply text'), $A); t('telegram: customer without a linked chat is skipped silently', count($sent) === 1);
call('admin_sup_reply', array('id' => $T5, 'body' => 'Private reply text two'), $A);
t('telegram: customer with a linked chat gets a notice', count($sent) === 2 && $sent[1][0] === '555111222');
t('telegram: user notice has no reply text, has link + ref', strpos($sent[1][1], 'Private') === false && strpos($sent[1][1], '/delivery/help/') !== false && strpos($sent[1][1], 'ET-') !== false);
$PDO->exec("DELETE FROM sup_settings WHERE setting_key IN ('tg_enabled','tg_staff_chat_id','tg_notify_user')"); sup_settings($PDO, true); unset($GLOBALS['SUP_TG_SENDER']);

// ---- desk paused
$PDO->exec("UPDATE sup_settings SET setting_value = '0' WHERE setting_key = 'desk_enabled'"); sup_settings($PDO, true);
$r = call('sup_ticket_create', array('app' => 'ride', 'category' => 'trip', 'subject' => 'While paused', 'body' => 'Should be refused politely'), mkuser('Late', '0933333333')); t('desk paused: new requests refused with a plain message', !$r->ok && strpos($r->getMessage(), 'paused') !== false);
$r = call('sup_meta', array(), $U1); t('desk paused: meta says enabled=false', $r->ok && $r->data['enabled'] === false);
$PDO->exec("UPDATE sup_settings SET setting_value = '1' WHERE setting_key = 'desk_enabled'");

echo "PASSED: $PASS\n"; echo "FAILED: " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
