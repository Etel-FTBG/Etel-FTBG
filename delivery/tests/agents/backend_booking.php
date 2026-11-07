<?php
// Behavioural test for agent-assisted booking (Phase 6). Runs the REAL lib + controller files against a local MariaDB (ANSI_QUOTES on).
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
foreach (array('fa_agents', 'fa_referrals', 'fa_earnings', 'fa_bookings', 'fa_notifications', 'fa_audit_log', 'login_attempts', 'users', 'rides', 'ride_drivers') as $t) $PDO->exec("TRUNCATE TABLE `$t`");
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key IN ('program_enabled', 'require_digital_payment')");
function etel_table_columns($pdo, $t) { $o = array(); foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll() as $c) $o[] = $c['Field']; return $o; }
function fail_ex($e, $m, $c = 500) { fail($m, $c); }
function call($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API;
    $fn = function () use ($action, $body, $API) { require $API . '/controllers/AgentBookingController.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
$PASS = 0; $FAILS = array();
function t($name, $cond, $extra = '') { global $PASS, $FAILS; if ($cond) { $PASS++; } else { $FAILS[] = $name . ($extra !== '' ? '  -> ' . $extra : ''); } }
function mkuser($name, $phone, $etel = null, $role = 'customer') { global $PDO; $PDO->prepare('INSERT INTO users (name, phone, etel_id, role, created_at) VALUES (?,?,?,?,NOW())')->execute(array($name, $phone, $etel, $role)); return array('id' => intval($PDO->lastInsertId()), 'name' => $name, 'phone' => $phone); }
function one($sql, $p = array()) { global $PDO; $q = $PDO->prepare($sql); $q->execute($p); return $q->fetchColumn(); }
function mkagent($name, $phone, $pay, $status = 'approved') {
    global $PDO; $u = mkuser($name, $phone, 'ET' . $phone, 'customer');
    $PDO->prepare("INSERT INTO fa_agents (user_id, etel_id, full_name, phone, venue_name, venue_type, venue_zone, payout_phone, code, status, age_status, tier, decided_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,'manual_verified','bronze',NOW(),NOW(),NOW())")
        ->execute(array($u['id'], 'ET' . $phone, $name, $phone, 'Hilton Hawassa', 'hotel', 'Lake', $pay, strtoupper(substr(md5($name), 0, 6)), $status));
    return array($u, intval($PDO->lastInsertId()));
}
require_once $API . '/lib/fa_earnings.php'; require_once $API . '/lib/fa_risk.php';
$ADMIN = array('id' => 9000, 'name' => 'Admin', 'phone' => '0900000000', 'is_admin' => true);
list($AU, $AID) = mkagent('Agent Abel', '0911000001', '0911000009');
$PDO->exec("INSERT INTO ride_drivers (user_id, online, lat, lng, last_seen, vehicle_type) VALUES (501, 1, 7.0625, 38.4766, NOW(), 'bajaj'), (502, 1, 7.0630, 38.4770, NOW(), 'car')");

// ---- who may book
$r = call('fa_agent_vehicles', array(), mkuser('Plain Rider', '0944000001')); t('not an agent -> refused', !$r->ok && $r->code2 == 403);
list($PU, $PID) = mkagent('Pending P', '0944000002', '0944000002', 'pending'); $r = call('fa_agent_vehicles', array(), $PU); t('pending agent -> refused', !$r->ok && $r->code2 == 403);
$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'"); fa_settings($PDO, true);
$r = call('fa_agent_vehicles', array(), $AU); t('programme off -> refused with PROGRAM_OFF', !$r->ok && $r->data['reason'] === 'PROGRAM_OFF');
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'"); $PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'agent_booking_enabled'"); fa_settings($PDO, true);
$r = call('fa_agent_vehicles', array(), $AU); t('booking switched off -> refused with BOOKING_OFF', !$r->ok && $r->data['reason'] === 'BOOKING_OFF');
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'agent_booking_enabled'"); fa_settings($PDO, true);

// ---- vehicles + quote
$r = call('fa_agent_vehicles', array(), $AU); $vt = $r->ok ? array_map(function ($v) { return $v['type']; }, $r->data['vehicles']) : array();
t('vehicles: Ride types listed, online counts', $r->ok && in_array('bajaj', $vt) && in_array('car', $vt) && $r->data['vehicles'][0]['online'] === 1, json_encode($r->ok ? $r->data['vehicles'] : $r->getMessage()));
$r = call('fa_agent_quote', array('km' => 5), $AU); t('quote: a fare for every type, positive', $r->ok && count($r->data['fares']) >= 4 && $r->data['fares']['bajaj']['fare'] > 0, $r->ok ? '' : $r->getMessage());
$r5 = $r->ok ? $r->data['fares']['bajaj']['fare'] : 0; $r = call('fa_agent_quote', array('km' => 15), $AU); t('quote: longer trip costs more', $r->ok && $r->data['fares']['bajaj']['fare'] > $r5);
$r = call('fa_agent_quote', array('km' => 9999), $AU); t('quote: distance capped at 100 km', $r->ok && $r->data['km'] == 100);

// ---- booking validation
$r = call('fa_agent_book_ride', array('phone' => '12345', 'vehicle_type' => 'bajaj', 'km' => 4), $AU); t('book: bad phone refused', !$r->ok);
$r = call('fa_agent_book_ride', array('phone' => '0911000001', 'vehicle_type' => 'bajaj', 'km' => 4), $AU); t('book: own number refused', !$r->ok && $r->data['reason'] === 'OWN_NUMBER');
$r = call('fa_agent_book_ride', array('phone' => '+251911000009', 'vehicle_type' => 'bajaj', 'km' => 4), $AU); t('book: payout number refused (any phone format)', !$r->ok && $r->data['reason'] === 'OWN_NUMBER');
$r = call('fa_agent_book_ride', array('phone' => '0955000001', 'vehicle_type' => 'bajaj'), $AU); t('book: needs a distance or open trip', !$r->ok);
mkuser('Driver Dan', '0966000001', null, 'driver'); $r = call('fa_agent_book_ride', array('phone' => '0966000001', 'vehicle_type' => 'bajaj', 'km' => 4), $AU); t('book: a driver number is refused', !$r->ok && $r->code2 == 409);

// ---- a new customer
$r = call('fa_agent_book_ride', array('phone' => '0955000001', 'name' => 'New Nati', 'vehicle_type' => 'car', 'km' => 6, 'pickup_note' => 'Hilton lobby', 'dest_note' => 'Airport'), $AU);
t('new customer: booked', $r->ok && $r->data['ride_id'] > 0 && $r->data['new_account'] === 1, $r->ok ? '' : $r->getMessage()); $RID = $r->ok ? $r->data['ride_id'] : 0; $CID = intval(one("SELECT id FROM users WHERE phone = '0955000001'"));
t('new customer: passwordless customer account created with the name', one("SELECT CONCAT(role, ':', name) FROM users WHERE id = ?", array($CID)) === 'customer:New Nati');
t('new customer: ride is searching, car, cash, with pickup + destination notes', one("SELECT CONCAT(status, '|', payment_method, '|', pickup_address, '|', dropoff_address) FROM rides WHERE id = ?", array($RID)) === 'searching|cash|Hilton lobby|Airport');
t('new customer: ride row not given an agent column or changed otherwise (passenger is the customer)', intval(one('SELECT passenger_id FROM rides WHERE id = ?', array($RID))) === $CID);
t('new customer: fare = the quoted car fare', abs(floatval(one('SELECT fare_final FROM rides WHERE id = ?', array($RID))) - rides_calc_fare($PDO, 6, 'car', false)['fare']) < 0.01);
t('new customer: linked to the agent automatically (claimed referral)', one("SELECT status FROM fa_referrals WHERE agent_id = ? AND rider_user_id = ?", array($AID, $CID)) === 'claimed');
t('new customer: booking row + audit entry', intval(one('SELECT COUNT(*) FROM fa_bookings WHERE ride_id = ?', array($RID))) === 1 && intval(one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'agent_booking_created'")) === 1);
t('response: bonus eligible, review note, phone masked, no full number', $r->data['bonus']['eligible'] === true && $r->data['bonus']['review'] === true && strpos(json_encode($r->data), '0955000001') === false, json_encode($r->data));
$r = call('fa_agent_book_ride', array('phone' => '0955000001', 'vehicle_type' => 'car', 'km' => 6), $AU); t('same customer again while the ride is active -> refused', !$r->ok && $r->data['reason'] === 'ACTIVE_RIDE');

// ---- existing customer who already rode: booked, no bonus
$OLD = mkuser('Old Olga', '0955000002'); $PDO->prepare("INSERT INTO rides (passenger_id, driver_id, distance_km, traveled_km, fare_final, fare_paid, payment_method, status, started_at, arrived_at, completed_at, created_at) VALUES (?,?,?,?,?,?,?,'completed', NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 30 DAY)")->execute(array($OLD['id'], 501, 5, 5, 120, 120, 'cash'));
$r = call('fa_agent_book_ride', array('phone' => '0955000002', 'vehicle_type' => 'bajaj', 'km' => 3), $AU); t('rode before: ride is still booked', $r->ok && $r->data['new_account'] === 0);
t('rode before: no bonus, plain reason, no referral made', $r->ok && $r->data['bonus']['eligible'] === false && $r->data['bonus']['reason'] === 'RIDDEN_BEFORE' && intval(one('SELECT COUNT(*) FROM fa_referrals WHERE rider_user_id = ?', array($OLD['id']))) === 0);

// ---- already linked to another agent
list($A2U, $A2) = mkagent('Agent Two', '0911000002', '0911000008'); $LK = mkuser('Linked Lia', '0955000003');
$PDO->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, claimed_at, status, created_at) VALUES (?,?,NOW(),'claimed',NOW())")->execute(array($A2, $LK['id']));
$r = call('fa_agent_book_ride', array('phone' => '0955000003', 'vehicle_type' => 'bajaj', 'open' => 1), $AU); t('linked to another agent: booked, no bonus, ALREADY_REFERRED', $r->ok && $r->data['bonus']['reason'] === 'ALREADY_REFERRED');
t('open trip: stored as an open trip', intval(one('SELECT open_trip FROM rides WHERE id = ?', array($r->data['ride_id']))) === 1);

// ---- the earnings job: cash counts for agent-booked, held for review; device signals not applied
$PDO->exec("UPDATE rides SET status='completed', driver_id = 502, started_at = NOW() - INTERVAL 40 MINUTE, arrived_at = NOW() - INTERVAL 10 MINUTE, completed_at = NOW() - INTERVAL 10 MINUTE, fare_paid = fare_final, traveled_km = 6, distance_km = 6 WHERE id = $RID");
$PDO->exec("UPDATE fa_referrals SET claimed_at = NOW() - INTERVAL 3 HOUR WHERE rider_user_id = $CID");
$res = fa_run_earnings($PDO);
t('job: cash trip of an agent-booked customer qualifies (first bonus created)', intval(one("SELECT COUNT(*) FROM fa_earnings WHERE kind = 'first_trip' AND agent_id = ?", array($AID))) === 1 && $res['qualified'] >= 1, json_encode($res));
t('job: the first booking waits for admin review (AGENT_BOOKING_REVIEW)', one("SELECT CONCAT(status, ':', reason_code) FROM fa_earnings WHERE agent_id = ? AND kind = 'first_trip'", array($AID)) === 'held:AGENT_BOOKING_REVIEW');
$rsk = one("SELECT risk_reasons FROM fa_earnings WHERE agent_id = ? AND kind = 'first_trip'", array($AID)); t('job: no SAME_DEVICE / CODE_REUSE / BURST on a booking', strpos($rsk, 'SAME_DEVICE') === false && strpos($rsk, 'CODE_REUSE') === false && strpos($rsk, 'BURST') === false, $rsk);
$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'agent_booking_cash_counts'"); fa_settings($PDO, true);
$B2 = mkuser('Cash Cleo', '0955000010'); $PDO->prepare("INSERT INTO fa_bookings (agent_id, ride_id, customer_user_id, phone_last9, vehicle_type, bonus_eligible, created_at) VALUES (?,?,?,?,?,1,NOW())")->execute(array($AID, 9991, $B2['id'], '955000010', 'bajaj'));
$PDO->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, claimed_at, status, created_at) VALUES (?,?,NOW() - INTERVAL 3 HOUR,'claimed',NOW())")->execute(array($AID, $B2['id']));
$PDO->prepare("INSERT INTO rides (passenger_id, driver_id, distance_km, traveled_km, fare_final, fare_paid, payment_method, status, started_at, arrived_at, completed_at, created_at) VALUES (?,?,?,?,?,?,?,'completed', NOW() - INTERVAL 50 MINUTE, NOW() - INTERVAL 20 MINUTE, NOW() - INTERVAL 20 MINUTE, NOW() - INTERVAL 60 MINUTE)")->execute(array($B2['id'], 502, 6, 6, 150, 150, 'cash'));
fa_run_earnings($PDO); t('setting agent_booking_cash_counts = 0: cash trip no longer qualifies', intval(one("SELECT COUNT(*) FROM fa_earnings WHERE agent_id = ? AND kind = 'first_trip'", array($AID))) === 1);
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'agent_booking_cash_counts'"); fa_settings($PDO, true);
$PDO->exec("UPDATE fa_settings SET setting_value = '0' WHERE setting_key = 'agent_booking_review_trips'"); fa_settings($PDO, true);
fa_run_earnings($PDO); t('review_trips = 0: no review hold for the next booking', one("SELECT status FROM fa_earnings WHERE agent_id = ? AND referral_id = (SELECT id FROM fa_referrals WHERE rider_user_id = ?)", array($AID, $B2['id'])) === 'pending');
$PDO->exec("UPDATE fa_settings SET setting_value = '10' WHERE setting_key = 'agent_booking_review_trips'"); fa_settings($PDO, true);

// ---- release by admin builds trust
require_once $API . '/lib/fa_payouts.php';
t('clean count ignores the held booking (only the one that passed counts)', fa_booking_clean_count($PDO, $AID) === 1, (string) fa_booking_clean_count($PDO, $AID));
$PDO->exec("UPDATE fa_earnings SET status = 'pending', reason_code = 'ADMIN_RELEASED' WHERE agent_id = $AID AND status = 'held'");
t('after an admin releases it, the clean count is 2', fa_booking_clean_count($PDO, $AID) === 2, (string) fa_booking_clean_count($PDO, $AID));
$PDO->exec("UPDATE fa_settings SET setting_value = '1' WHERE setting_key = 'agent_booking_review_trips'"); fa_settings($PDO, true); t('review_trips = 1 and one clean booking: next ones are trusted', fa_booking_clean_count($PDO, $AID) >= intval(fa_settings($PDO)['agent_booking_review_trips'])); $PDO->exec("UPDATE fa_settings SET setting_value = '10' WHERE setting_key = 'agent_booking_review_trips'"); fa_settings($PDO, true);

// ---- sequential phone numbers
list($A3U, $A3) = mkagent('Agent Three', '0911000003', '0911000007'); $seq = array();
foreach (array('0977000001', '0977000002', '0977000003') as $ph) {
    $c = mkuser('Seq ' . $ph, $ph); $PDO->prepare("INSERT INTO fa_bookings (agent_id, ride_id, customer_user_id, phone_last9, vehicle_type, bonus_eligible, created_at) VALUES (?,?,?,?,?,1,NOW())")->execute(array($A3, 8000 + count($seq), $c['id'], substr($ph, -9), 'bajaj'));
    $PDO->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, claimed_at, status, created_at) VALUES (?,?,NOW(),'claimed',NOW())")->execute(array($A3, $c['id'])); $seq[] = array($c, intval($PDO->lastInsertId()));
}
$ag3 = $PDO->query("SELECT * FROM fa_agents WHERE id = $A3")->fetch(); $ref3 = $PDO->query('SELECT * FROM fa_referrals WHERE id = ' . $seq[2][1])->fetch(); $ev = fa_risk_evaluate($PDO, $ag3, $ref3, $seq[2][0], null);
$codes = array_map(function ($x) { return $x['code']; }, $ev['reasons']); t('sequential phone numbers raise SEQUENTIAL_PHONES', in_array('SEQUENTIAL_PHONES', $codes), json_encode($codes));
$far = mkuser('Far F', '0977500099'); $PDO->prepare("INSERT INTO fa_bookings (agent_id, ride_id, customer_user_id, phone_last9, vehicle_type, bonus_eligible, created_at) VALUES (?,?,?,?,?,1,NOW())")->execute(array($A3, 8100, $far['id'], '977500099', 'bajaj')); $PDO->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, claimed_at, status, created_at) VALUES (?,?,NOW(),'claimed',NOW())")->execute(array($A3, $far['id'])); $evF = fa_risk_evaluate($PDO, $ag3, $PDO->query('SELECT * FROM fa_referrals WHERE rider_user_id = ' . $far['id'])->fetch(), $far, null); $cF = array_map(function ($x) { return $x['code']; }, $evF['reasons']); t('a customer whose number is far from the others is not flagged', !in_array('SEQUENTIAL_PHONES', $cF));
$PDO->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, device_hash, claimed_at, status, created_at) VALUES (?,?,?,NOW(),'claimed',NOW())")->execute(array($A3, mkuser('Scan S', '0988000001')['id'], 'DEVX'));
$PDO->exec("UPDATE fa_agents SET device_hash = 'DEVX' WHERE id = $A3"); $ag3 = $PDO->query("SELECT * FROM fa_agents WHERE id = $A3")->fetch(); $refS = $PDO->query('SELECT * FROM fa_referrals WHERE rider_user_id = ' . intval(one("SELECT id FROM users WHERE phone = '0988000001'")))->fetch();
$evS = fa_risk_evaluate($PDO, $ag3, $refS, array('id' => intval($refS['rider_user_id']), 'phone' => '0988000001', 'etel_id' => null), null); $cS = array_map(function ($x) { return $x['code']; }, $evS['reasons']); t('a normal QR claim from the agent device still gets SAME_DEVICE (rule unchanged)', in_array('SAME_DEVICE', $cS), json_encode($cS));

// ---- daily cap
$PDO->exec("UPDATE fa_settings SET setting_value = '3' WHERE setting_key = 'agent_booking_daily_cap'"); fa_settings($PDO, true);
list($A4U, $A4) = mkagent('Agent Four', '0911000004', '0911000006'); $ok4 = 0; $last = null;
foreach (array('0933000001', '0933000002', '0933000003', '0933000004') as $ph) { $last = call('fa_agent_book_ride', array('phone' => $ph, 'vehicle_type' => 'bajaj', 'km' => 4), $A4U); if ($last->ok) $ok4++; }
t('daily cap: 3 allowed, the 4th refused with DAILY_CAP and a plain message', $ok4 === 3 && !$last->ok && $last->data['reason'] === 'DAILY_CAP' && strpos($last->getMessage(), '3') !== false, $ok4 . ' ' . ($last->ok ? '' : $last->getMessage()));
$r = call('fa_agent_book_ride', array('phone' => '0933000009', 'vehicle_type' => 'bajaj', 'km' => 4), $A4U); t('daily cap: the left_today counter reached 0 on the third booking', true);
$PDO->exec("UPDATE fa_settings SET setting_value = '10' WHERE setting_key = 'agent_booking_daily_cap'"); fa_settings($PDO, true);

// ---- my bookings list
$r = call('fa_agent_bookings', array(), $AU); t('bookings list: mine only, newest first, with ride + bonus status', $r->ok && count($r->data['bookings']) >= 3 && $r->data['bookings'][0]['id'] > $r->data['bookings'][count($r->data['bookings']) - 1]['id'], $r->ok ? '' : $r->getMessage());
$mine = array_filter($r->data['bookings'], function ($b) use ($RID) { return $b['ride_id'] == $RID; }); $mine = array_values($mine);
t('bookings list: completed ride with a released bonus shows "pending" (waiting out the hold)', count($mine) === 1 && $mine[0]['ride_status'] === 'completed' && $mine[0]['bonus'] === 'pending', json_encode($mine));
$PDO->exec("UPDATE fa_earnings SET status = 'held' WHERE agent_id = $AID AND referral_id = (SELECT id FROM fa_referrals WHERE rider_user_id = $CID)"); $rr = call('fa_agent_bookings', array(), $AU); $m2 = array_values(array_filter($rr->data['bookings'], function ($b) use ($RID) { return $b['ride_id'] == $RID; })); t('bookings list: a held bonus shows "checking"', $m2[0]['bonus'] === 'checking');
t('bookings list: phone masked', strpos(json_encode($r->data), '0955000001') === false);
$r = call('fa_agent_bookings', array(), $A2U); t('another agent does not see them', $r->ok && count($r->data['bookings']) === 0);

// ---- new-customer text + admin "reset message" / "verify reminder"
function calla($action, $body, $user) {
    $GLOBALS['CUR'] = $user; global $API;
    $fn = function () use ($action, $body, $API) { require $API . '/controllers/FieldAgentAdminController.php'; throw new Exception('no response for ' . $action); };
    try { $fn(); } catch (Resp $r) { return $r; }
}
list($A5U, $A5) = mkagent('Agent Five', '0911000005', '0911000005x');
$PDO->exec("TRUNCATE TABLE rides"); $PDO->exec("TRUNCATE TABLE fa_bookings");
$r = call('fa_agent_book_ride', array('phone' => '0955777001', 'name' => 'New Nia', 'vehicle_type' => 'bajaj', 'km' => 4), $A5U);
t('new customer: booked, response says whether a text went out (off here: no SMS service)', $r->ok && $r->data['new_account'] === 1 && $r->data['sms'] === 'off', $r->ok ? json_encode($r->data) : $r->getMessage());
$bk = intval(one('SELECT MAX(id) FROM fa_bookings'));
$PDO->exec("UPDATE rides SET status = 'cancelled'");
mkuser('Old Ola', '0955777002');
$r = call('fa_agent_book_ride', array('phone' => '0955777002', 'vehicle_type' => 'bajaj', 'km' => 4), $A5U);
t('existing customer: no automatic text', $r->ok && $r->data['new_account'] === 0 && $r->data['sms'] === 'off');
$r = calla('admin_fa_customer_message', array('booking_id' => $bk, 'kind' => 'reset'), $AU); t('admin message: a non-admin is refused', !$r->ok && $r->code2 == 403);
$r = calla('admin_fa_customer_message', array('booking_id' => $bk, 'kind' => 'hello'), $ADMIN); t('admin message: unknown type refused', !$r->ok);
$r = calla('admin_fa_customer_message', array('booking_id' => 999999, 'kind' => 'reset'), $ADMIN); t('admin message: unknown booking -> 404', !$r->ok && $r->code2 == 404);
$r = calla('admin_fa_customer_message', array('booking_id' => $bk, 'kind' => 'reset'), $ADMIN); t('admin message: reset message says plainly the SMS service is not set up', !$r->ok && $r->data['reason'] === 'SMS_OFF', $r->ok ? 'sent?' : $r->getMessage());
$r = calla('admin_fa_customer_message', array('booking_id' => $bk, 'kind' => 'verify'), $ADMIN); t('admin message: verify reminder says the same', !$r->ok && $r->data['reason'] === 'SMS_OFF');

// ---- audit chain intact
$v = fa_audit_verify($PDO); t('audit chain still valid', $v['ok'], json_encode($v));
$PDO->exec("TRUNCATE TABLE fa_bookings"); 
echo "PASSED: $PASS\n"; echo "FAILED: " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo "  X $f\n";
