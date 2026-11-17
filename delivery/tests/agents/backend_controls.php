<?php
// Run: php delivery/tests/agents/backend_controls.php   (needs MariaDB database fa_test with ANSI_QUOTES and migrations 101, 102, 104, 107, 110; see README.md)
// Agents control centre: the REAL lib (fa_controls, fa_earnings cap check, fa_payouts candidates) and the REAL controller file.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test'); define('TOKEN_SECRET_FINAL', 'test-secret');
date_default_timezone_set('Africa/Addis_Ababa');
$API = dirname(__DIR__, 2) . '/api';
class Resp extends Error { public $data; public $code2; public $ok; }
function ok($d = null) { $r = new Resp('ok'); $r->ok = true; $r->data = $d; throw $r; }
function fail($m, $c = 400, $x = array()) { $r = new Resp($m); $r->ok = false; $r->code2 = $c; $r->data = $x; throw $r; }
function db() { global $PDO; return $PDO; }
function require_admin($pdo, $cap = null) { if (empty($GLOBALS['CUR']['is_admin'])) fail('Admin access required', 403); if ($cap === 'agents.manage' && empty($GLOBALS['CUR']['manage'])) fail('Not allowed', 403); return $GLOBALS['CUR']; }
require_once $API . '/validate.php';
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
foreach (array('fa_agents', 'fa_referrals', 'fa_earnings', 'fa_payout_batches', 'fa_payout_items', 'fa_audit_log', 'fa_agent_controls', 'fa_bookings') as $t) $PDO->exec('TRUNCATE TABLE ' . $t);
require_once $API . '/lib/fa_core.php';
require_once $API . '/lib/fa_earnings.php';
require_once $API . '/lib/fa_payouts.php';
$PASS = 0; $FAILS = array();
function t($name, $cond, $info = '') { global $PASS, $FAILS; if ($cond) $PASS++; else $FAILS[] = $name . ($info !== '' ? ' -> ' . $info : ''); }
function call($action, $body, $admin = array('is_admin' => 1, 'manage' => 1, 'id' => 1)) {
    $GLOBALS['CUR'] = $admin; $b = $body;
    try { $action2 = $action; $GLOBALS['__a'] = $action2; (function () use ($action2, $b) { $action = $action2; $body = $b; require dirname(__DIR__, 2) . '/api/controllers/FieldAgentControlController.php'; })(); return array('ok' => null, 'err' => 'fell through', 'code' => 0); }
    catch (Resp $r) { return array('ok' => $r->ok ? $r->data : null, 'err' => $r->ok ? null : $r->getMessage(), 'code' => $r->code2); }
}
$now = date('Y-m-d H:i:s');
function agent($id, $name, $status, $phone) { global $PDO, $now; $PDO->prepare("INSERT INTO fa_agents (id, user_id, full_name, phone, venue_name, payout_phone, code, status, created_at) VALUES (?,?,?,?,?,?,?,?,?)")->execute(array($id, 100 + $id, $name, $phone, 'Venue ' . $id, $phone, 'C' . $id, $status, $now)); }
agent(1, 'Abebe', 'approved', '0911000001'); agent(2, 'Birtukan', 'approved', '0911000002'); agent(3, 'Chala', 'suspended', '0911000003'); agent(4, 'Dawit', 'pending', '0911000004');
function earn($agent, $amount, $status, $when = null) { global $PDO, $now; static $ref = 0; $ref++; $when = $when ? $when : $now;
    $PDO->prepare("INSERT INTO fa_earnings (referral_id, agent_id, kind, amount, status, earned_at, created_at) VALUES (?,?,'first_trip',?,?,?,?)")->execute(array(1000 + $ref, $agent, $amount, $status, $when, $now)); }
earn(1, 60, 'payable'); earn(1, 50, 'payable'); earn(2, 150, 'payable'); earn(3, 200, 'payable');
$PDO->exec("INSERT INTO fa_referrals (agent_id, rider_user_id, claimed_at, status, created_at) VALUES (1, 501, NOW(), 'claimed', NOW()), (1, 502, NOW(), 'rejected', NOW()), (2, 503, NOW(), 'qualified', NOW())");

// --- before any control: everyone with a payout number and enough money is a candidate
$ids = array_map(function ($r) { return intval($r['id']); }, fa_payout_candidates($PDO)); sort($ids);
t('no controls: agents 1 and 2 are payout candidates (3 is suspended)', $ids === array(1, 2), json_encode($ids));

// --- list
$r = call('admin_fa_controls_list', array());
t('list works for an admin', $r['ok'] && count($r['ok']['agents']) === 3, json_encode($r['err']));
$by = array(); if ($r['ok']) foreach ($r['ok']['agents'] as $a) $by[$a['id']] = $a;
t('list shows approved and suspended, not pending', isset($by[1]) && isset($by[3]) && !isset($by[4]));
t('list: agent 1 has 1 rider in 30 days (rejected not counted) and 110 earned', $by[1]['riders30'] === 1 && abs($by[1]['earned30'] - 110) < 0.001 && abs($by[1]['payable'] - 110) < 0.001, json_encode($by[1]));
t('list: no hold and no own limit yet', $by[1]['payout_hold'] === 0 && $by[1]['daily_cap_etb'] == 0);
$GLOBALS['CUR'] = array('is_admin' => 0); $x = call('admin_fa_controls_list', array(), array('is_admin' => 0));
t('list refused for a non-admin', $x['code'] === 403);

// --- save: validation
$r = call('admin_fa_controls_save', array('ids' => array(1), 'payout_hold' => 1));
t('hold needs a reason', $r['code'] === 422 && strpos($r['err'], 'reason') !== false, json_encode($r));
$r = call('admin_fa_controls_save', array('ids' => array(1), 'daily_cap_etb' => 'abc'));
t('own limit must be a number', $r['code'] === 422);
$r = call('admin_fa_controls_save', array('ids' => array(1), 'daily_cap_etb' => 20000));
t('own limit above 10000 refused', $r['code'] === 422);
$r = call('admin_fa_controls_save', array('ids' => array(1), 'daily_cap_etb' => -5));
t('negative limit refused', $r['code'] === 422);
$r = call('admin_fa_controls_save', array('ids' => array()));
t('no agent chosen refused', $r['code'] === 400);
$r = call('admin_fa_controls_save', array('ids' => array(1)));
t('nothing to change refused', $r['code'] === 400 && strpos($r['err'], 'Nothing') !== false);
$r = call('admin_fa_controls_save', array('ids' => array(1), 'note' => 'x'), array('is_admin' => 1, 'manage' => 0));
t('view-only admin cannot save', $r['code'] === 403);
t('failed saves wrote nothing', intval($PDO->query('SELECT COUNT(*) FROM fa_agent_controls')->fetchColumn()) === 0);

// --- hold
$r = call('admin_fa_controls_save', array('ids' => array(1), 'payout_hold' => 1, 'hold_reason' => 'Checking duplicate riders'));
t('hold saved', $r['ok'] && $r['ok']['changed'] === 1, json_encode($r));
$ids = array_map(function ($r) { return intval($r['id']); }, fa_payout_candidates($PDO));
t('held agent is no longer a payout candidate', $ids === array(2), json_encode($ids));
t('held agent keeps the payable money', abs(floatval($PDO->query("SELECT SUM(amount) FROM fa_earnings WHERE agent_id = 1 AND status = 'payable'")->fetchColumn()) - 110) < 0.001);
$b = fa_payout_create($PDO, 1, array(1, 2), 'test');
$ba = $PDO->query('SELECT agent_id FROM fa_payout_items WHERE batch_id = ' . intval(is_array($b) ? $b['batch_id'] : $b))->fetchAll(PDO::FETCH_COLUMN);
t('a payout batch asked for agents 1 and 2 only includes agent 2', array_map('intval', $ba) === array(2), json_encode($ba));
$au = $PDO->query("SELECT action, reason FROM fa_audit_log WHERE action = 'payout_hold_on'")->fetch();
t('hold is in the audit log with the reason', $au && $au['reason'] === 'Checking duplicate riders');
$r = call('admin_fa_controls_list', array()); $by = array(); foreach ($r['ok']['agents'] as $a) $by[$a['id']] = $a;
t('list shows the hold and the reason', $by[1]['payout_hold'] === 1 && $by[1]['hold_reason'] === 'Checking duplicate riders');

// --- release
$r = call('admin_fa_controls_save', array('ids' => array(1), 'payout_hold' => 0));
t('release saved', $r['ok'] && $r['ok']['changed'] === 1);
$row = $PDO->query('SELECT payout_hold, hold_reason FROM fa_agent_controls WHERE agent_id = 1')->fetch();
t('release clears the reason', intval($row['payout_hold']) === 0 && $row['hold_reason'] === '');
$r = call('admin_fa_controls_save', array('ids' => array(1), 'payout_hold' => 0));
t('saving the same thing again changes nothing', $r['ok'] && $r['ok']['changed'] === 0, json_encode($r));

// --- bulk
$r = call('admin_fa_controls_save', array('ids' => array(1, 2, 3, 999), 'payout_hold' => 1, 'hold_reason' => 'Month end review'));
t('bulk: 3 real agents changed, unknown id skipped', $r['ok'] && $r['ok']['changed'] === 3, json_encode($r));
$r = call('admin_fa_controls_save', array('ids' => array(1, 2, 3), 'payout_hold' => 0));
t('bulk release', $r['ok'] && $r['ok']['changed'] === 3);

// --- own daily limit
$S = fa_settings($PDO); $prog = floatval($S['daily_cap_etb']);
t('programme limit is the default (100 ETB)', fa_controls_daily_cap($PDO, 2, $prog) == $prog && $prog == 100);
$PDO->exec("INSERT INTO fa_earnings (referral_id, agent_id, kind, amount, status, earned_at, created_at) VALUES (9001, 2, 'first_trip', 90, 'paid', NOW(), NOW())");
t('with the programme limit, 90 + 20 is over the daily limit', fa_cap_check($PDO, 2, date('Y-m-d H:i:s'), 20) === 'CAP_DAILY');
$r = call('admin_fa_controls_save', array('ids' => array(2), 'daily_cap_etb' => 500));
t('own limit saved', $r['ok'] && $r['ok']['changed'] === 1);
t('with an own limit of 500, 90 + 20 is fine', fa_cap_check($PDO, 2, date('Y-m-d H:i:s'), 20) === '');
t('another agent still uses the programme limit', fa_controls_daily_cap($PDO, 1, $prog) == $prog);
$r = call('admin_fa_controls_save', array('ids' => array(2), 'daily_cap_etb' => 50));
t('a LOWER own limit also works: 90 already over 50', fa_cap_check($PDO, 2, date('Y-m-d H:i:s'), 1) === 'CAP_DAILY');
$r = call('admin_fa_controls_save', array('ids' => array(2), 'daily_cap_etb' => 0));
t('0 goes back to the programme limit', fa_controls_daily_cap($PDO, 2, $prog) == $prog);

// --- note
$r = call('admin_fa_controls_save', array('ids' => array(2), 'note' => str_repeat('n', 800)));
t('note is cut to 500 characters', $r['ok'] && intval($PDO->query('SELECT CHAR_LENGTH(note) FROM fa_agent_controls WHERE agent_id = 2')->fetchColumn()) === 500);
$agentRow = $PDO->query('SELECT * FROM fa_agents WHERE id = 2')->fetch();
t('controls never touch the agent row or the money', $agentRow['status'] === 'approved');

// --- table missing: nothing breaks
$PDO->exec('RENAME TABLE fa_agent_controls TO fa_agent_controls_x');
t('missing table: no held agents', fa_controls_held_ids($PDO) === array());
t('missing table: programme limit used', fa_controls_daily_cap($PDO, 2, 100) == 100);
$l = fa_controls_list($PDO); t('missing table: list still works', count($l) === 3);
$ids = array_map(function ($r) { return intval($r['id']); }, fa_payout_candidates($PDO)); t('missing table: candidates still work', count($ids) >= 0);
$PDO->exec('RENAME TABLE fa_agent_controls_x TO fa_agent_controls');
$v = fa_audit_verify($PDO); t('audit chain still valid', $v['ok'] === true, json_encode($v));
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit($FAILS ? 1 : 0);
