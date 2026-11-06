<?php
// Run: php delivery/tests/agents/backend_phase4.php   (needs a local MariaDB database fa_test with ANSI_QUOTES, see README.md). Behavioural test for Field Agents (Phase 4). Runs the REAL lib + controller files against a local MariaDB (ANSI_QUOTES on).
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
foreach (array('fa_agents', 'fa_referrals', 'fa_earnings', 'fa_payout_batches', 'fa_payout_items', 'fa_audit_log', 'fa_notifications', 'login_attempts', 'users', 'rides') as $t) $PDO->exec("TRUNCATE TABLE $t");
$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");

function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API, $PDO;
    $fn = function () use ($action, $body, $API) {
        $f = in_array($action, array('admin_fa_insights','admin_fa_nudge','admin_fa_digest_list','admin_fa_digest_build','admin_fa_tiers_run')) ? 'FieldAgentInsightsController.php' : ((strpos($action, 'admin_fa_') === 0) ? 'FieldAgentAdminController.php' : 'FieldAgentController.php');
        require $API . '/controllers/' . $f;
        throw new Exception('no response for ' . $action);
    };
    try { $fn(); } catch (Resp $r) { return $r; }
}
$PASS = 0; $FAILS = array();
function t($name, $cond, $extra = '') { global $PASS, $FAILS; if ($cond) { $PASS++; } else { $FAILS[] = $name . ($extra !== '' ? '  -> ' . $extra : ''); } }
function mkuser($name, $phone, $etel = null) { global $PDO; $PDO->prepare('INSERT INTO users (name, phone, etel_id, created_at) VALUES (?,?,?,NOW())')->execute(array($name, $phone, $etel)); return array('id' => intval($PDO->lastInsertId()), 'name' => $name, 'phone' => $phone, 'etel_id' => $etel); }
function ride($rider, $driver, $km, $mins, $fare, $daysAgo, $status = 'completed', $hour = 14, $pm = 'cash') {
    global $PDO;
    $end = date('Y-m-d', time() - 86400 * $daysAgo) . sprintf(' %02d:30:00', $hour);
    $start = date('Y-m-d H:i:s', strtotime($end) - $mins * 60);
    $PDO->prepare('INSERT INTO rides (passenger_id, driver_id, distance_km, traveled_km, fare_final, fare_paid, payment_method, status, started_at, arrived_at, completed_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array($rider['id'], $driver, $km, $km, $fare, $status === 'completed' ? $fare : 0, $pm, $status, $start, $end, $end, $start));
    return intval($PDO->lastInsertId());
}
function setclaim($riderId, $daysAgo) { global $PDO; $PDO->prepare('UPDATE fa_referrals SET claimed_at = ? WHERE rider_user_id = ?')->execute(array(date('Y-m-d H:i:s', time() - 86400 * $daysAgo - 3 * 3600 * ($riderId % 40)), $riderId)); }
function one($sql, $p = array()) { global $PDO; $q = $PDO->prepare($sql); $q->execute($p); return $q->fetchColumn(); }

require_once $API . '/lib/fa_earnings.php'; require_once $API . '/lib/fa_payouts.php';
$ADMIN = array('id' => 9000, 'name' => 'Admin', 'phone' => '0900000000', 'is_admin' => true);
require_once $API . '/lib/fa_insights.php'; require_once $API . '/lib/fa_risk.php';
foreach (array('fa_digests') as $t) $PDO->exec("TRUNCATE TABLE `$t`");
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
function mkagent($name, $phone, $pay, $dev = null, $zone = 'Lake', $venue = 'V', $status = 'approved', $lastRef = null) {
    global $PDO; $u = mkuser($name, $phone, 'ET' . $phone);
    $PDO->prepare("INSERT INTO fa_agents (user_id, etel_id, full_name, phone, venue_name, venue_type, venue_zone, payout_phone, code, status, age_status, tier, device_hash, last_referral_at, decided_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,'manual_verified','bronze',?,?,NOW() - INTERVAL 60 DAY,NOW() - INTERVAL 60 DAY,NOW())")
        ->execute(array($u['id'], 'ET' . $phone, $name, $phone, $venue, 'hotel', $zone, $pay, strtoupper(substr(md5($name), 0, 6)), $status, $dev, $lastRef));
    return intval($PDO->lastInsertId());
}
function mkref($agent, $status, $daysAgo, $repeat = false) {
    global $PDO; $u = mkuser('R' . mt_rand(), '09' . mt_rand(10000000, 99999999));
    $PDO->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, claimed_at, status, first_ride_at, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())")
        ->execute(array($agent, $u['id'], date('Y-m-d H:i:s', time() - 86400 * $daysAgo - 3600), $status, $status === 'qualified' ? date('Y-m-d H:i:s', time() - 86400 * $daysAgo) : null));
    $rid = intval($PDO->lastInsertId());
    if ($repeat) $PDO->prepare("INSERT INTO fa_earnings (referral_id, agent_id, kind, parent_id, amount, status, earned_at, created_at, updated_at) VALUES (?,?,'repeat3',?,30,'payable',NOW(),NOW(),NOW())")->execute(array($rid, $agent, $rid));
    return $rid;
}
$ADMIN = array('id' => 9000, 'name' => 'Admin', 'phone' => '0900000000', 'is_admin' => true);
$S = fa_settings($PDO);

// ---- settings validation (Phase 4 keys)
$r = call('admin_fa_settings_save', array('settings' => array('tier_gold_min_riders' => 3, 'tier_silver_min_riders' => 5)), $ADMIN); t('settings: gold riders < silver rejected', !$r->ok);
$r = call('admin_fa_settings_save', array('settings' => array('tier_gold_min_repeat_pct' => 10, 'tier_silver_min_repeat_pct' => 20)), $ADMIN); t('settings: gold repeat < silver rejected', !$r->ok);
$r = call('admin_fa_settings_save', array('settings' => array('ai_summary_enabled' => 1)), $ADMIN); t('settings: ai summary cannot be switched on', !$r->ok || empty($r->data['settings']['ai_summary_enabled']), $r->ok ? json_encode($r->data['settings']['ai_summary_enabled']) : '');
$r = call('admin_fa_settings_save', array('settings' => array('hold_days' => 7, 'hold_days_silver' => 5, 'hold_days_gold' => 3)), $ADMIN); t('settings: tier holds saved', $r->ok);
$S = fa_settings($PDO);

// ---- tiers
$bronze = mkagent('Bro', '0911000001', '0911000001'); for ($i = 0; $i < 2; $i++) mkref($bronze, 'qualified', 5, false);
$silver = mkagent('Sil', '0911000002', '0911000002'); for ($i = 0; $i < 6; $i++) mkref($silver, 'qualified', 5, $i < 2);          // 6 riders, 33% repeat
$gold   = mkagent('Gol', '0911000003', '0911000003'); for ($i = 0; $i < 16; $i++) mkref($gold, 'qualified', 5, $i < 8);          // 16 riders, 50% repeat
$dirty  = mkagent('Drt', '0911000004', '0911000004'); for ($i = 0; $i < 16; $i++) mkref($dirty, 'qualified', 5, $i < 8); for ($i = 0; $i < 10; $i++) mkref($dirty, 'rejected', 5);  // many rejected
$risky  = mkagent('Rsk', '0911000005', '0911000005'); for ($i = 0; $i < 16; $i++) mkref($risky, 'qualified', 5, $i < 8); $PDO->exec("UPDATE fa_agents SET risk_score = 70 WHERE id = $risky");
$r = call('admin_fa_tiers_run', array(), $ADMIN); t('tiers: run ok', $r->ok, $r->ok ? '' : $r->getMessage());
$tier = function ($id) { return one('SELECT tier FROM fa_agents WHERE id = ?', array($id)); };
t('tiers: 2 riders -> bronze', $tier($bronze) === 'bronze');
t('tiers: 6 riders, 33% repeat -> silver', $tier($silver) === 'silver', $tier($silver));
t('tiers: 16 riders, 50% repeat -> gold', $tier($gold) === 'gold', $tier($gold));
t('tiers: high rejection rate blocks a tier', $tier($dirty) === 'bronze', $tier($dirty));
t('tiers: high risk blocks a tier', $tier($risky) === 'bronze', $tier($risky));
t('tiers: change audited', intval(one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'tier_changed' AND entity_id = ?", array($gold))) === 1);
t('tiers: agent told in-portal', intval(one("SELECT COUNT(*) FROM fa_notifications WHERE agent_id = ? AND kind = 'tier_up'", array($gold))) === 1);
$r = call('admin_fa_tiers_run', array(), $ADMIN); t('tiers: second run changes nothing (idempotent)', $r->ok && count($r->data['changed']) === 0);
t('tiers: perks never raise a bonus - hold only', fa_hold_days($S, 'gold') <= fa_hold_days($S, 'silver') && fa_hold_days($S, 'silver') <= fa_hold_days($S, 'bronze') && fa_hold_days($S, 'gold') >= 3);
$p = fa_tier_progress($PDO, array('id' => $silver, 'tier' => 'silver'));
t('tier progress: next=gold, needs more riders, no fraud limits leaked', $p['next'] === 'gold' && $p['need_riders'] === 9 && strpos(json_encode($p), 'risk') === false && strpos(json_encode($p), 'reject') === false, json_encode($p));
$p = fa_tier_progress($PDO, array('id' => $gold, 'tier' => 'gold')); t('tier progress: gold has no next', $p['next'] === '');
$PDO->exec("UPDATE fa_agents SET status = 'suspended' WHERE id = $silver"); fa_recompute_tiers($PDO); t('tiers: suspended agent not changed by the job', $tier($silver) === 'silver');
$PDO->exec("UPDATE fa_agents SET status = 'approved' WHERE id = $silver");

// ---- insights
$dormant = mkagent('Dor', '0911000006', '0911000006', null, 'Piassa', 'Q', 'approved', date('Y-m-d H:i:s', time() - 86400 * 20));
$active  = mkagent('Act', '0911000007', '0911000007', null, 'Piassa', 'Q', 'approved', date('Y-m-d H:i:s', time() - 86400 * 2));
$r = call('admin_fa_insights', array('days' => 30), $ADMIN); t('insights: ok', $r->ok, $r->ok ? '' : $r->getMessage());
if ($r->ok) {
    $I = $r->data['insights']; $ids = array_map(function ($d) { return $d['id']; }, $I['dormant']);
    t('insights: dormant agent listed', in_array($dormant, $ids));
    t('insights: recent agent not dormant', !in_array($active, $ids));
    t('insights: best agent is the high-repeat one', count($I['best_agents']) > 0 && $I['best_agents'][0]['id'] === $gold || $I['best_agents'][0]['id'] === $risky || $I['best_agents'][0]['id'] === $dirty, json_encode(array_map(function ($x) { return $x['id']; }, $I['best_agents'])));
    t('insights: tier counts add up', array_sum($I['tiers']) === intval(one("SELECT COUNT(*) FROM fa_agents WHERE status = 'approved'")));
    t('insights: channels - only portal enabled', $r->data['channels']['portal'] === true && $r->data['channels']['telegram'] === false && $r->data['channels']['sms'] === false);
    t('insights: AI hook disabled and totals only', $r->data['ai']['enabled'] === false && strpos(json_encode($r->data['ai']['would_send']), 'Gol') === false && strpos(json_encode($r->data['ai']['would_send']), '0911') === false);
    t('insights: no phone or device in payload', strpos(json_encode($r->data), '0911000') === false && strpos(json_encode($r->data), 'device') === false);
}
$r = call('admin_fa_insights', array(), array('id' => 1, 'is_admin' => false)); t('insights: non-admin refused', !$r->ok && $r->code2 == 403);

// ---- near cap + budget alerts
$PDO->exec("UPDATE fa_settings SET setting_value = '100' WHERE setting_key = 'daily_cap_etb'");
$rf = mkref($active, 'qualified', 1); $PDO->prepare("INSERT INTO fa_earnings (referral_id, agent_id, kind, parent_id, amount, status, earned_at, created_at, updated_at) VALUES (?,?,'first_trip',?,90,'payable',NOW(),NOW(),NOW())")->execute(array($rf, $active, $rf));
$PDO->exec("UPDATE fa_settings SET setting_value = '500' WHERE setting_key = 'monthly_budget_etb'");
fa_settings($PDO, true);
$r = call('admin_fa_insights', array(), $ADMIN); $I = $r->data['insights'];
$nc = array_filter($I['near_cap'], function ($x) use ($active) { return $x['id'] === $active; }); t('near-cap: agent at 90% of daily cap flagged', count($nc) === 1, json_encode($I['near_cap']));
$codes = array_map(function ($a) { return $a['code']; }, $I['alerts']); t('alerts: NEAR_CAP present', in_array('NEAR_CAP', $codes));
$PDO->exec("UPDATE fa_settings SET setting_value = '50' WHERE setting_key = 'monthly_budget_etb'");
fa_settings($PDO, true);
$r = call('admin_fa_insights', array(), $ADMIN); $codes = array_map(function ($a) { return $a['code']; }, $r->data['insights']['alerts']); t('alerts: budget alert fires when spend passes budget', in_array('BUDGET_PROJECTED', $codes) || in_array('BUDGET_NEAR', $codes), json_encode($codes));
$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'monthly_budget_etb'");

// ---- nudge
$r = call('admin_fa_nudge', array('agent_ids' => array($dormant)), $ADMIN); t('nudge: sent to dormant agent', $r->ok && $r->data['sent'] === array($dormant), $r->ok ? json_encode($r->data) : $r->getMessage());
t('nudge: in-portal notification only', intval(one("SELECT COUNT(*) FROM fa_notifications WHERE agent_id = ? AND kind = 'nudge' AND channel = 'portal'", array($dormant))) === 1);
t('nudge: audited', intval(one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'nudge_sent' AND entity_id = ?", array($dormant))) === 1);
$r = call('admin_fa_nudge', array('agent_ids' => array($dormant)), $ADMIN); t('nudge: cooldown blocks a second nudge', $r->ok && $r->data['sent'] === array() && count($r->data['skipped']) === 1);
$pend = mkagent('Pen', '0911000008', '0911000008', null, 'Z', 'Z', 'pending');
$r = call('admin_fa_nudge', array('agent_ids' => array($pend)), $ADMIN); t('nudge: pending agent not nudged', $r->ok && $r->data['sent'] === array());
$r = call('admin_fa_nudge', array('agent_ids' => array()), $ADMIN); t('nudge: empty list refused', !$r->ok);
$r = call('admin_fa_nudge', array('agent_ids' => array($dormant)), array('id' => 1, 'is_admin' => false)); t('nudge: non-admin refused', !$r->ok);
$r = call('admin_fa_insights', array(), $ADMIN); $d = array_filter($r->data['insights']['dormant'], function ($x) use ($dormant) { return $x['id'] === $dormant; }); $d = array_values($d); t('nudge: dormant row shows can_nudge=false after nudge', count($d) === 1 && $d[0]['can_nudge'] === false);

// ---- shared identity risk signal
$a1 = mkagent('Sh1', '0911000011', '0911000099', 'DEVSAME'); $a2 = mkagent('Sh2', '0911000012', '0911000099', 'DEVOTHER');
$ag = $PDO->query("SELECT * FROM fa_agents WHERE id = $a1")->fetch();
$sh = fa_agent_shared_identity($PDO, $ag); t('shared identity: same payout number found', count($sh) === 1 && $sh[0]['id'] === $a2 && strpos($sh[0]['why'], 'payout') !== false, json_encode($sh));
$rider = mkuser('Rd', '0944000001'); $PDO->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, claimed_at, status, created_at, updated_at) VALUES (?,?,NOW(),'claimed',NOW(),NOW())")->execute(array($a1, $rider['id']));
$ref = $PDO->query('SELECT * FROM fa_referrals WHERE id = ' . intval($PDO->lastInsertId()))->fetch();
$ev = fa_risk_evaluate($PDO, $ag, $ref, $rider, null); $cs = array_map(function ($x) { return $x['code']; }, $ev['reasons']); t('risk: SHARED_IDENTITY raises points with a reason', in_array('SHARED_IDENTITY', $cs), json_encode($cs));
$r = call('admin_fa_agent_get', array('id' => $a1), $ADMIN); t('agent detail: shared_with returned, no device hash', $r->ok && count($r->data['shared_with']) === 1 && strpos(json_encode($r->data), 'DEVSAME') === false);

// ---- digest
$r = call('admin_fa_digest_build', array(), $ADMIN); t('digest: build ok and stored', $r->ok && $r->data['created'] === true, $r->ok ? '' : $r->getMessage());
t('digest: english + amharic text', $r->ok && strlen($r->data['preview']['text_en']) > 40 && preg_match('/[\x{1200}-\x{137F}]/u', $r->data['preview']['text_am']) === 1);
t('digest: amharic text complete (all 9 lines)', $r->ok && substr_count($r->data['preview']['text_am'], "\n") === 8, $r->ok ? substr_count($r->data['preview']['text_am'], "\n") : '');
t('digest: no garbage bytes in amharic', $r->ok && mb_check_encoding($r->data['preview']['text_am'], 'UTF-8'));
$r = call('admin_fa_digest_build', array(), $ADMIN); t('digest: second build same week is a no-op (idempotent)', $r->ok && $r->data['created'] === false);
t('digest: exactly one row', intval(one('SELECT COUNT(*) FROM fa_digests')) === 1);
$r = call('admin_fa_digest_list', array(), $ADMIN); t('digest: list returns payload', $r->ok && count($r->data['digests']) === 1 && isset($r->data['digests'][0]['payload']['text_en']));
$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'digest_enabled'"); $PDO->exec('TRUNCATE TABLE fa_digests');
fa_settings($PDO, true); t('digest: disabled setting stops storing', fa_digest_store($PDO) === null && intval(one('SELECT COUNT(*) FROM fa_digests')) === 0);
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'digest_enabled'");
$ai = fa_ai_summary($PDO, fa_digest_build($PDO)); t('EtelAI hook: always disabled', $ai['enabled'] === false);

// ---- agent self view
$au = mkuser('AgU', '0955000001'); $PDO->exec("UPDATE fa_agents SET user_id = " . intval($au['id']) . " WHERE id = $silver");
$r = call('fa_agent_me', array(), $au); t('agent me: tier_progress present', $r->ok && isset($r->data['tier_progress']['next']), $r->ok ? '' : $r->getMessage());
t('agent me: tier_progress hides risk/fraud', $r->ok && strpos(json_encode($r->data['tier_progress']), 'risk') === false);

// ---- earnings job hooks tiers and keeps the chain valid
$res = fa_run_earnings($PDO); t('job: runs and reports tier changes', is_array($res) && array_key_exists('tiers_changed', $res), json_encode($res));
$v = fa_audit_verify($PDO); t('audit: chain still valid after phase 4 actions', $v['ok'], json_encode($v));

$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'monthly_budget_etb'"); $PDO->exec("DELETE FROM fa_settings WHERE setting_key IN ('hold_days_silver','hold_days_gold','hold_days','daily_cap_etb')");
echo "PASSED: $PASS\n";
echo "FAILED: " . count($FAILS) . "\n";
foreach ($FAILS as $f) echo "  X $f\n";
