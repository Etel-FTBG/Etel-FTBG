<?php
// Run: php delivery/tests/agents/backend_phase1_3.php   (needs a local MariaDB database fa_test with ANSI_QUOTES, see README.md). Behavioural test for Field Agents (Phase 1). Runs the REAL lib + controller files against a local MariaDB (ANSI_QUOTES on).
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
        $f = (strpos($action, 'admin_fa_') === 0) ? 'FieldAgentAdminController.php' : 'FieldAgentController.php';
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

// ---------------- settings ----------------
$r = call('admin_fa_settings_save', array('settings' => array('bonus_first_etb' => 9999)), $ADMIN); t('settings: out-of-range rejected', !$r->ok);
$r = call('admin_fa_settings_save', array('settings' => array('risk_hold_threshold' => 90, 'risk_high_threshold' => 80)), $ADMIN); t('settings: hold > high rejected', !$r->ok);
$r = call('admin_fa_settings_save', array('settings' => array('daily_cap_etb' => 500, 'weekly_cap_etb' => 400)), $ADMIN); t('settings: daily > weekly rejected', !$r->ok);
$r = call('admin_fa_settings_save', array('settings' => array('program_enabled' => 1, 'bonus_first_etb' => 20)), $ADMIN); t('settings: valid save', $r->ok && $r->data['settings']['program_enabled'] == 1, json_encode(array($r->ok, $r->ok ? '' : $r->getMessage())));
$r = call('admin_fa_settings_save', array('settings' => array('bonus_first_etb' => 'abc')), $ADMIN); t('settings: non-numeric rejected', !$r->ok);
$r = call('admin_fa_settings_get', array(), array('id' => 1, 'is_admin' => false)); t('settings: non-admin refused', !$r->ok && $r->code2 == 403);

// ---------------- agent apply / age gate / approval ----------------
$agentU = mkuser('Abebe Kebede', '0911000001', 'EG-2026-00001');
$r = call('fa_agent_apply', array('venue_name' => 'Hilton', 'venue_type' => 'hotel', 'payout_phone' => '12345', 'device' => 'AGENTDEV1'), $agentU); t('apply: bad payout phone rejected', !$r->ok);
$agentU2 = mkuser('Abebe Kebede', '0911000001'); // same phone but this is a duplicate user row only for test of hard-block later
$r = call('fa_agent_apply', array('venue_name' => 'Hilton Hawassa', 'venue_type' => 'hotel', 'venue_zone' => 'Lake', 'payout_phone' => '0922000002', 'device' => 'AGENTDEV1'), $agentU);
t('apply: ok pending, age unchecked (self-declared dob)', $r->ok && $r->data['status'] === 'pending' && $r->data['age'] === 'unchecked', json_encode($r->data));
$aid = intval(one('SELECT id FROM fa_agents WHERE user_id = ?', array($agentU['id'])));
$r = call('admin_fa_agent_decide', array('id' => $aid, 'decision' => 'approve'), $ADMIN); t('approve: blocked without manual age check', !$r->ok && $r->code2 == 409);
$r = call('admin_fa_agent_decide', array('id' => $aid, 'decision' => 'approve', 'manual_age_ok' => true, 'age_note' => ''), $ADMIN); t('approve: manual check needs a note', !$r->ok);
$r = call('admin_fa_agent_decide', array('id' => $aid, 'decision' => 'approve', 'manual_age_ok' => true, 'age_note' => 'Fayda card seen, born 1990'), $ADMIN);
t('approve: manual age ok -> code issued', $r->ok && strlen($r->data['code']) === 7, json_encode($r->data));
$CODE = $r->data['code'];
t('approve: age_status manual_verified', one('SELECT age_status FROM fa_agents WHERE id = ?', array($aid)) === 'manual_verified');
// auto verified path + under 18
$a2 = mkuser('Sara M', '0933000003'); $FAKE_AGE = array('ok' => true, 'adult' => true, 'reviewed' => true);
$r = call('fa_agent_apply', array('venue_name' => 'Lake Parking', 'payout_phone' => '0933000003'), $a2); t('apply: adult+reviewed -> auto_verified', $r->ok && $r->data['age'] === 'auto_verified');
$a2id = intval(one('SELECT id FROM fa_agents WHERE user_id = ?', array($a2['id'])));
$r = call('admin_fa_agent_decide', array('id' => $a2id, 'decision' => 'approve'), $ADMIN); t('approve: auto verified needs no manual check', $r->ok);
$a3 = mkuser('Minor X', '0944000004'); $FAKE_AGE = array('ok' => true, 'adult' => false, 'reviewed' => true);
$r = call('fa_agent_apply', array('venue_name' => 'Shop', 'payout_phone' => '0944000004'), $a3); t('apply: under 18 -> rejected', $r->ok && $r->data['status'] === 'rejected');
$FAKE_AGE = array('ok' => true, 'adult' => true, 'reviewed' => false);
$FAKE_AGE = null; $a4 = mkuser('Offline', '0955000005'); $r = call('fa_agent_apply', array('venue_name' => 'Shop', 'payout_phone' => '0955000005'), $a4); t('apply: EtelID unreachable -> stays pending/unchecked (fail safe)', $r->ok && $r->data['status'] === 'pending' && $r->data['age'] === 'unchecked');
$FAKE_AGE = array('ok' => true, 'adult' => true, 'reviewed' => false);
$r = call('fa_agent_apply', array('venue_name' => 'x', 'payout_phone' => '0922000002'), $agentU); t('apply: second apply is idempotent', $r->ok && !empty($r->data['already']));

// ---------------- lookup + claim ----------------
$anon = array('id' => 0);
$r = call('fa_ref_lookup', array('code' => $CODE), $anon); t('lookup: valid code', $r->ok && $r->data['valid'] === true && $r->data['venue'] === 'Hilton Hawassa');
$r = call('fa_ref_lookup', array('code' => 'ZZZZZZZ'), $anon); t('lookup: invalid code', $r->ok && $r->data['valid'] === false);
for ($i = 0; $i < 16; $i++) call('fa_ref_lookup', array('code' => 'BAD' . $i . 'XXX'), $anon);
$r = call('fa_ref_lookup', array('code' => 'ABCDEFG'), $anon); t('lookup: brute force locked out (429)', !$r->ok && $r->code2 == 429);
$PDO->exec("TRUNCATE TABLE login_attempts");

$R = array(); foreach (range(1, 12) as $i) $R[$i] = mkuser('Rider ' . $i, sprintf('09%08d', 70000000 + $i));
$r = call('fa_claim_ref', array('code' => $CODE, 'device' => 'RIDERDEV1'), $R[1]); t('claim: new rider applied', $r->ok && $r->data['applied'] === true, json_encode($r->data));
$r = call('fa_claim_ref', array('code' => $CODE, 'device' => 'RIDERDEV1'), $R[1]); t('claim: duplicate -> ALREADY_CLAIMED', $r->ok && $r->data['reason'] === 'ALREADY_CLAIMED');
$r = call('fa_claim_ref', array('code' => $CODE), $agentU); t('claim: self (same account) answers like a wrong code', $r->ok && $r->data['applied'] === false && $r->data['reason'] === 'BAD_CODE');
t('claim: self attempt is audited', intval(one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'claim_blocked_self'")) === 1);
$selfPhone = mkuser('Same Phone Other Account', '0911000001'); $r = call('fa_claim_ref', array('code' => $CODE), $selfPhone); t('claim: self (same phone, other account) blocked', $r->data['reason'] === 'BAD_CODE');
$old = mkuser('Old Rider', '0966000006'); ride($old, 501, 5, 10, 100, 40); $r = call('fa_claim_ref', array('code' => $CODE), $old); t('claim: rider with earlier completed trip -> NOT_NEW', $r->data['reason'] === 'NOT_NEW');
$r = call('fa_claim_ref', array('code' => 'NOPE123'), $R[2]); t('claim: bad code', $r->data['reason'] === 'BAD_CODE');
$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'"); fa_settings($PDO, true);
$r = call('fa_claim_ref', array('code' => $CODE), $R[2]); t('claim: programme off -> PROGRAM_OFF', $r->data['reason'] === 'PROGRAM_OFF');
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'"); fa_settings($PDO, true);

// ---------------- earnings job: first trip ----------------
// R1: claim 9 days ago, qualifying cash trip 8 days ago (5 km, 12 min, ETB 120), driver 501
setclaim($R[1]['id'], 9); ride($R[1], 501, 5, 12, 120, 8);
// R2: claims, a too-short trip (0.5 km) then a real one
call('fa_claim_ref', array('code' => $CODE, 'device' => 'D2'), $R[2]); setclaim($R[2]['id'], 9); ride($R[2], 502, 0.5, 2, 40, 8); ride($R[2], 502, 4, 9, 100, 7);
// R3: rider phone equals the agent's payout number -> hard block
$R3 = mkuser('Payout Twin', '0922000002'); call('fa_claim_ref', array('code' => $CODE, 'device' => 'D3'), $R3); setclaim($R3['id'], 9); ride($R3, 503, 6, 12, 130, 8);
// R4: same device as agent + 1.2 km trip -> 45 + 15 = 60 -> HELD
call('fa_claim_ref', array('code' => $CODE, 'device' => 'AGENTDEV1'), $R[4]); setclaim($R[4]['id'], 9); ride($R[4], 504, 1.2, 4, 60, 8);
// R5: trip but no qualifying (cancelled) -> nothing
call('fa_claim_ref', array('code' => $CODE, 'device' => 'D5'), $R[5]); setclaim($R[5]['id'], 9); ride($R[5], 505, 5, 12, 120, 8, 'cancelled');
// R6: claim 40 days ago, never rode -> expires
call('fa_claim_ref', array('code' => $CODE, 'device' => 'D6'), $R[6]); setclaim($R[6]['id'], 40);
// R7: had a completed trip BEFORE the claim (claimed after being created) -> NOT_NEW_RIDER in the job
call('fa_claim_ref', array('code' => $CODE, 'device' => 'D7'), $R[7]); ride($R[7], 507, 5, 12, 120, 20); setclaim($R[7]['id'], 9);
// R8: odd hours 03:30 trip + 1.2km -> 10 + 15 = 25 < 60 pending
call('fa_claim_ref', array('code' => $CODE, 'device' => 'D8'), $R[8]); setclaim($R[8]['id'], 9); ride($R[8], 508, 1.2, 4, 60, 8, 'completed', 3);

$res = fa_run_earnings($PDO);
t('job: no errors', $res['errors'] === 0, json_encode($res));
$e1 = $PDO->query("SELECT * FROM fa_earnings WHERE referral_id = (SELECT id FROM fa_referrals WHERE rider_user_id = {$R[1]['id']}) AND kind = 'first_trip'")->fetch();
t('R1: first_trip ETB 20, hold elapsed -> payable same run', $e1 && floatval($e1['amount']) == 20 && $e1['status'] === 'payable', json_encode($e1));
$e2 = $PDO->query("SELECT e.*, r.first_ride_id FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id = {$R[2]['id']} AND e.kind = 'first_trip'")->fetch();
t('R2: short trip skipped, second trip qualified', $e2 && intval($e2['ride_id']) === intval($e2['first_ride_id']) && floatval(one('SELECT distance_km FROM rides WHERE id = ?', array($e2['ride_id']))) == 4);
$e3 = $PDO->query("SELECT e.*, r.status AS rs, r.reject_code FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id = {$R3['id']}")->fetch();
t('R3: payout number == rider phone -> auto-rejected PAYOUT_IS_RIDER, recorded', $e3 && $e3['status'] === 'rejected' && $e3['reason_code'] === 'PAYOUT_IS_RIDER' && $e3['rs'] === 'rejected' && $e3['risk_score'] == 100, json_encode($e3));
$e4 = $PDO->query("SELECT e.* FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id = {$R[4]['id']}")->fetch();
t('R4: same device + short trip -> HELD for admin (score 60), reasons listed', $e4 && $e4['status'] === 'held' && $e4['reason_code'] === 'RISK_HOLD' && $e4['risk_score'] == 60 && strpos($e4['risk_reasons'], 'SAME_DEVICE') !== false && strpos($e4['risk_reasons'], 'SHORT_TRIP') !== false, json_encode($e4));
t('R5: cancelled trip -> no earning', intval(one('SELECT COUNT(*) FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id = ?', array($R[5]['id']))) === 0);
t('R6: unclaimed 40 days -> expired', one('SELECT status FROM fa_referrals WHERE rider_user_id = ?', array($R[6]['id'])) === 'expired');
t('R7: had a trip before claiming -> rejected NOT_NEW_RIDER', one('SELECT reject_code FROM fa_referrals WHERE rider_user_id = ?', array($R[7]['id'])) === 'NOT_NEW_RIDER');
$e8 = $PDO->query("SELECT e.* FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id = {$R[8]['id']}")->fetch();
t('R8: odd hours + short = 25 -> pending (below hold threshold), not auto-rejected', $e8 && in_array($e8['status'], array('pending', 'payable'), true) && $e8['risk_score'] == 25, json_encode($e8));

// ---------------- idempotency ----------------
$before = intval(one('SELECT COUNT(*) FROM fa_earnings')); $res2 = fa_run_earnings($PDO);
t('job: second run creates nothing (idempotent)', intval(one('SELECT COUNT(*) FROM fa_earnings')) === $before && $res2['qualified'] === 0 && $res2['repeat'] === 0, json_encode($res2));

// ---------------- repeat3 ----------------
ride($R[1], 501, 4, 10, 100, 5); ride($R[1], 501, 4, 10, 100, 3);
$res3 = fa_run_earnings($PDO);
$rep = $PDO->query("SELECT * FROM fa_earnings WHERE kind = 'repeat3'")->fetchAll();
t('repeat3: R1 with 3 qualifying trips in 30 days -> ETB 30 once', count($rep) === 1 && floatval($rep[0]['amount']) == 30, json_encode($rep));
$res3b = fa_run_earnings($PDO); t('repeat3: not duplicated on re-run', intval(one("SELECT COUNT(*) FROM fa_earnings WHERE kind = 'repeat3'")) === 1);
t('repeat3: only counted trips with min distance (R2 two short trips never reach it)', intval(one("SELECT COUNT(*) FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id = ? AND e.kind = 'repeat3'", array($R[2]['id']))) === 0);

// ---------------- daily cap ----------------
// settings: daily 100 -> already R1 20 + R2 20 + R8 20 on different days (8/7/8 days ago). Make 6 riders finish the same day (6 days ago), distinct drivers.
$capIds = array();
for ($i = 1; $i <= 6; $i++) { $u = mkuser('Cap ' . $i, sprintf('09%08d', 80000000 + $i)); call('fa_claim_ref', array('code' => $CODE, 'device' => 'CAPDEV' . $i), $u); setclaim($u['id'], 7); ride($u, 600 + $i, 5, 12, 100, 6, 'completed', 10 + $i % 3); $capIds[] = $u['id']; }
fa_run_earnings($PDO);
$ph = implode(',', $capIds);
$capHeld = $PDO->query("SELECT e.reason_code, e.status FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id IN ($ph) AND e.status = 'held'")->fetchAll();
$capOk = intval(one("SELECT COUNT(*) FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id WHERE r.rider_user_id IN ($ph) AND e.status IN ('pending','payable')"));
t('cap: daily cap ETB 100 -> only 5x20 accrue, rest HELD with CAP_DAILY (not dropped)', $capOk === 5 && count($capHeld) === 1 && $capHeld[0]['reason_code'] === 'CAP_DAILY', json_encode(array($capOk, $capHeld)));

// ---------------- admin review: release / reject / bulk ----------------
$held = $PDO->query("SELECT id FROM fa_earnings WHERE status = 'held' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$r = call('admin_fa_earning_decide', array('ids' => $held, 'decision' => 'reject'), $ADMIN); t('review: reject needs a reason', !$r->ok);
$r = call('admin_fa_earning_decide', array('ids' => array($held[0]), 'decision' => 'release', 'reason' => 'Checked with hotel, genuine guest'), $ADMIN);
t('review: release one held item', $r->ok && $r->data['done'] === 1 && one('SELECT status FROM fa_earnings WHERE id = ?', array($held[0])) === 'pending');
$r = call('admin_fa_earning_decide', array('ids' => $held, 'decision' => 'release', 'reason' => 'again'), $ADMIN); t('review: releasing a non-held item is skipped, never double-applied', $r->data['done'] === count($held) - 1 && $r->data['skipped'] === 1, json_encode($r->data));
$r = call('admin_fa_earning_decide', array('ids' => array($held[count($held) - 1]), 'decision' => 'reject', 'reason' => 'Same device as agent'), $ADMIN); t('review: reject pending/held with reason', $r->ok && $r->data['done'] === 1);

// ---------------- clawback ----------------
$payRide = intval(one("SELECT ride_id FROM fa_earnings WHERE referral_id = (SELECT id FROM fa_referrals WHERE rider_user_id = {$R[1]['id']}) AND kind = 'first_trip'"));
$PDO->exec("UPDATE rides SET status = 'cancelled', fare_paid = 0 WHERE id = $payRide");
$resC = fa_run_earnings($PDO);
t('clawback: trip reversed while payable -> first_trip clawed_back RIDE_REVERSED', one("SELECT status FROM fa_earnings WHERE ride_id = ? AND kind = 'first_trip'", array($payRide)) === 'clawed_back');
t('clawback: repeat3 (which includes that trip) also reversed', one("SELECT status FROM fa_earnings WHERE kind = 'repeat3'") === 'clawed_back');

// ---------------- payouts ----------------
$cand0 = fa_payout_candidates($PDO); t('payout: below minimum payout (ETB 50) -> nobody ready yet', count($cand0) === 0);
$r = call('admin_fa_settings_save', array('settings' => array('min_payout_etb' => 20)), $ADMIN); t('settings: min payout lowered', $r->ok);
$cand = fa_payout_candidates($PDO);
t('payout: agent ready (net 40 >= min payout 20)', count($cand) === 1 && floatval($cand[0]['net']) >= 20, json_encode($cand));
$PDO->exec("UPDATE fa_agents SET full_name = '=HYPERLINK(\"x\")' WHERE id = $aid");
$r = call('admin_fa_payout_create', array('note' => 'Week 1'), $ADMIN); t('payout: batch created', $r->ok, $r->ok ? '' : $r->getMessage()); $bid = $r->data['batch_id'];
t('payout: earnings locked into the batch (cannot be batched twice)', count(fa_payout_candidates($PDO)) === 0);
$r = call('admin_fa_payout_create', array(), $ADMIN); t('payout: second batch has nothing to pay', !$r->ok);
$r = call('admin_fa_payout_export', array('batch_id' => $bid), $ADMIN); t('payout: CSV exported, formula neutralised', $r->ok && strpos($r->data['csv'], "\"'=HYPERLINK") !== false && strpos($r->data['csv'], 'telebirr_phone') !== false, $r->ok ? $r->data['csv'] : '');
$itemId = intval(one('SELECT id FROM fa_payout_items WHERE batch_id = ?', array($bid)));
$r = call('admin_fa_payout_mark_paid', array('batch_id' => $bid, 'refs' => array($itemId => 'TB123')), $ADMIN); t('payout: mark paid', $r->ok);
$r = call('admin_fa_payout_mark_paid', array('batch_id' => $bid), $ADMIN); t('payout: marking paid twice refused (no double pay)', !$r->ok && $r->code2 == 409);
$r = call('admin_fa_payout_items', array('batch_id' => $bid), $ADMIN); t('payout items: lists the batch items with agent name', $r->ok && count($r->data['items']) === 1 && $r->data['items'][0]['full_name'] !== '' && intval($r->data['items'][0]['earnings']) >= 1, $r->ok ? json_encode($r->data['items']) : $r->getMessage());
t('payout: earnings flipped to paid', intval(one("SELECT COUNT(*) FROM fa_earnings WHERE payout_item_id = ? AND status = 'paid'", array($itemId))) > 0);
t('payout: Telebirr reference saved', one('SELECT tx_ref FROM fa_payout_items WHERE id = ?', array($itemId)) === 'TB123');
// clawback on a PAID earning creates a negative adjustment
$paidRideEarn = $PDO->query("SELECT * FROM fa_earnings WHERE status = 'paid' AND kind <> 'clawback' ORDER BY id LIMIT 1")->fetch();
$PDO->exec("UPDATE rides SET status = 'cancelled', fare_paid = 0 WHERE id = " . intval($paidRideEarn['ride_id']));
fa_run_earnings($PDO);
$neg = $PDO->query("SELECT * FROM fa_earnings WHERE kind = 'clawback'")->fetchAll();
t('clawback: PAID earning reversed -> one negative adjustment, payable for next batch', count($neg) === 1 && floatval($neg[0]['amount']) == -1 * floatval($paidRideEarn['amount']) && $neg[0]['status'] === 'payable', json_encode($neg));
fa_run_earnings($PDO); t('clawback: adjustment not duplicated', intval(one("SELECT COUNT(*) FROM fa_earnings WHERE kind = 'clawback'")) === 1);

// ---------------- agent-facing privacy ----------------
$r = call('fa_agent_referrals', array(), $agentU); $js = json_encode($r->data);
t('agent view: riders MASKED, never a full phone', $r->ok && strpos($js, '70000001') === false && strpos($js, '09** *** *') !== false, substr($js, 0, 200));
t('agent view: no risk scores / device / hold reasons leaked', strpos($js, 'risk') === false && strpos($js, 'SAME_DEVICE') === false && strpos($js, 'RISK_HOLD') === false && strpos($js, 'CAP_DAILY') === false);
$r = call('fa_agent_me', array(), $agentU); $js = json_encode($r->data);
t('agent me: balances present, code shown when approved', $r->ok && $r->data['agent']['code'] === $CODE && isset($r->data['balances']['available']));
t('agent me: no risk_score in payload', strpos($js, 'risk') === false);
$r = call('fa_agent_notifications', array(), $agentU); $js = json_encode($r->data);
t('agent notifications: held shown as pending, no RISK_HOLD/CAP words', strpos($js, 'bonus_held') === false && strpos($js, 'RISK_HOLD') === false && strpos($js, 'CAP_') === false);
$notAgent = mkuser('Plain Rider', '0977000007'); $r = call('fa_agent_referrals', array(), $notAgent); t('agent view: non-agent refused', !$r->ok && $r->code2 == 403);
$r = call('fa_agent_update_profile', array('payout_phone' => '0988000008'), $agentU); t('profile: payout phone change saved + audited', $r->ok && intval(one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'agent_profile_updated'")) === 1);

// ---------------- suspended agent ----------------
$r = call('admin_fa_agent_decide', array('id' => $aid, 'decision' => 'suspend', 'reason' => 'Investigating'), $ADMIN); t('suspend: needs reason + works', $r->ok);
$r = call('fa_claim_ref', array('code' => $CODE), $R[9]); t('suspended agent code no longer accepts claims', $r->data['reason'] === 'BAD_CODE');
$r = call('admin_fa_agent_decide', array('id' => $aid, 'decision' => 'reinstate', 'reason' => 'Cleared'), $ADMIN); t('reinstate works', $r->ok);

// ---------------- stats / budget / lists ----------------
$r = call('admin_fa_stats', array(), $ADMIN); t('stats: returns KPIs', $r->ok && isset($r->data['budget']['projected']) && $r->data['agents_active'] >= 2, json_encode($r->data));
$r = call('admin_fa_earnings_list', array('status' => 'held'), $ADMIN); t('earnings list: filter + masked rider + reason text', $r->ok && (count($r->data['earnings']) === 0 || strpos($r->data['earnings'][0]['rider_phone'], '**') !== false));
$r = call('admin_fa_agents_list', array('status' => 'approved'), $ADMIN); t('agents list ok', $r->ok && count($r->data['agents']) >= 2);
$r = call('admin_fa_agent_get', array('id' => $aid), $ADMIN); t('agent detail: no device hash exposed', $r->ok && !isset($r->data['agent']['device_hash']));

// ---------------- reports (Phase 2) ----------------
$r = call('admin_fa_reports', array('days' => 30), $ADMIN); t('reports: ok', $r->ok, $r->ok ? '' : $r->getMessage());
if ($r->ok) {
    $d = $r->data; $since = $d['since'];
    $acq = intval(one("SELECT COUNT(*) FROM fa_referrals WHERE status = 'qualified' AND first_ride_at >= ?", array($since)));
    t('reports: totals.acquired matches SQL', $d['totals']['acquired'] === $acq, json_encode(array($d['totals']['acquired'], $acq)));
    t('reports: daily series zero-filled (31 days)', count($d['daily']) === 31);
    $sumNew = 0; $sumCost = 0; foreach ($d['daily'] as $x) { $sumNew += $x['new_riders']; $sumCost += $x['cost']; }
    t('reports: daily new_riders sums to acquired', $sumNew === $acq);
    $cost = floatval(one("SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE kind <> 'clawback' AND status IN ('pending','payable','held','paid') AND earned_at >= ?", array($since)));
    t('reports: totals.cost matches SQL', abs($d['totals']['cost'] - $cost) < 0.01, json_encode(array($d['totals']['cost'], $cost)));
    t('reports: per-agent cost sums to total', abs(array_sum(array_map(function ($a) { return $a['cost']; }, $d['agents'])) - $d['totals']['cost']) < 0.01);
    t('reports: cost_per_rider = cost / acquired', $acq === 0 || abs($d['totals']['cost_per_rider'] - round($d['totals']['cost'] / $acq, 2)) < 0.01);
    t('reports: venue-type breakdown present', count($d['by_venue_type']) >= 1);
    t('reports: no device hash / full phone in payload', strpos(json_encode($d), 'device') === false && strpos(json_encode($d), '70000001') === false);
}
$r = call('admin_fa_reports', array(), array('id' => 1, 'is_admin' => false)); t('reports: non-admin refused', !$r->ok && $r->code2 == 403);

// ---------------- audit chain ----------------
$v = fa_audit_verify($PDO); t('audit: chain valid after all actions', $v['ok'] && $v['rows'] > 30, json_encode($v));
$PDO->exec("UPDATE fa_audit_log SET reason = 'tampered' WHERE id = (SELECT x.id FROM (SELECT id FROM fa_audit_log ORDER BY id LIMIT 1 OFFSET 5) x)");
$v2 = fa_audit_verify($PDO); t('audit: edit of a past row is DETECTED', !$v2['ok'] && $v2['first_bad_id'] > 0, json_encode($v2));

echo "PASSED: $PASS\n";
echo "FAILED: " . count($FAILS) . "\n";
foreach ($FAILS as $f) echo "  X $f\n";
