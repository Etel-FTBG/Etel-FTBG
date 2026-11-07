<?php
// ============================================================
// ETEL Ride - Field Agents: an approved agent books a ride FOR a customer (customer phone + vehicle type).
//   fa_agent_vehicles   {}                                     -> vehicle types the agent can pick, with drivers online now
//   fa_agent_quote      { km?, open? }                         -> estimated fare for every vehicle type
//   fa_agent_book_ride  { phone, name?, vehicle_type, km?, open?, pickup_lat?, pickup_lng?, pickup_note?, dest_note? }
//   fa_agent_bookings   {}                                     -> the agent's own bookings with ride and bonus status
// New file on purpose: the Ride trip code is NOT edited. The ride row is created exactly like Admin's phone booking (admin_phone_book_ride),
// reusing the same libraries (fare, validate, push). A customer who never rode before is linked to the agent automatically (no QR scan needed);
// the bonus then follows the normal rules (digital or - when allowed - cash payment, 7-day hold, caps) plus an admin review of the first bookings.
// Limits: agent_booking_daily_cap per 24 hours, never the agent's own or payout number, one active ride per customer. ASCII only.
// ============================================================
require_once __DIR__ . '/../lib/fa_core.php';
require_once __DIR__ . '/../lib/fa_earnings.php';
require_once __DIR__ . '/../push.php';
require_once __DIR__ . '/../lib/ride_fare.php';
require_once __DIR__ . '/../lib/ride_offer.php';

if (!function_exists('fa_bk_agent')) {

/* Text the customer after an agent books a ride. Returns 'sent', 'failed' or 'off' (no SMS service set up). Never throws. */
function fa_bk_sms_customer($phone, $newAcct) {
    try {
        $key = function_exists('etel_env') ? etel_env('AT_API_KEY', 'CFG_AT_API_KEY', '') : '';
        if (!$key || $key === 'your_at_api_key' || !function_exists('curl_init')) return 'off';
        $user = etel_env('AT_USERNAME', 'CFG_AT_USERNAME', 'sandbox');
        $d = preg_replace('/\D/', '', (string)$phone);
        if (strlen($d) < 9) return 'failed';
        $to = '+251' . substr($d, -9);
        $msg = 'ETEL Ride: a ride was booked for you by an Etel agent. The driver will call you; you can pay the driver in cash.'
             . ($newAcct ? ' To follow your ride or pay with EtelPay, open ethelmarket.com/delivery/ride/ and tap Forgot password to choose your password.' : ' Follow it in the Etel Ride app.');
        $ch = curl_init('https://api.africastalking.com/version1/messaging');
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => array('apiKey: ' . $key, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_POSTFIELDS => http_build_query(array('username' => $user, 'to' => $to, 'message' => $msg, 'from' => 'ETEL'))));
        $resp = curl_exec($ch); curl_close($ch);
        $j = $resp ? @json_decode($resp, true) : null;
        return ($j && isset($j['SMSMessageData']['Recipients'][0]['status']) && $j['SMSMessageData']['Recipients'][0]['status'] === 'Success') ? 'sent' : 'failed';
    } catch (Exception $e) { return 'failed'; }
}

/* The signed-in user's APPROVED agent row, or a plain refusal. */
function fa_bk_agent($pdo, $u) {
    $S = fa_settings($pdo);
    if (empty($S['program_enabled'])) fail('The agent programme is not open yet.', 403, array('reason' => 'PROGRAM_OFF'));
    if (empty($S['agent_booking_enabled'])) fail('Booking rides for customers is switched off right now.', 403, array('reason' => 'BOOKING_OFF'));
    $q = $pdo->prepare("SELECT * FROM fa_agents WHERE user_id = ? AND status = 'approved' LIMIT 1");
    $q->execute(array(intval($u['id'])));
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) fail('Only approved agents can book rides.', 403, array('reason' => 'NOT_AGENT'));
    return $a;
}
function fa_bk_has_col($pdo, $col) {
    static $cols = null;
    if ($cols === null) { $cols = array(); try { foreach ($pdo->query('SHOW COLUMNS FROM `rides`')->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[strtolower($c['Field'])] = true; } catch (Exception $e) {} }
    return isset($cols[$col]);
}
/* Active vehicle types (same source as the Ride booking screen) with how many drivers of that type are online. */
function fa_bk_vehicles($pdo) {
    $types = array();
    try { foreach ($pdo->query('SELECT LOWER(vehicle_type) FROM ride_fare_rules WHERE active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) as $t) if ($t !== '') $types[] = $t; } catch (Exception $e) {}
    if (!$types) $types = array('bajaj', 'moto', 'car', 'minibus');
    $on = array();
    try { foreach ($pdo->query('SELECT LOWER(vehicle_type) AS t, COUNT(*) AS n FROM ride_drivers WHERE online = 1 GROUP BY LOWER(vehicle_type)')->fetchAll(PDO::FETCH_ASSOC) as $r) $on[$r['t']] = intval($r['n']); } catch (Exception $e) {}
    $out = array();
    foreach ($types as $t) $out[] = array('type' => $t, 'online' => isset($on[$t]) ? $on[$t] : 0);
    return $out;
}
function fa_bk_km($v) { return min(100, max(0, floatval($v))); }
function fa_bk_text($v, $max) { $s = trim(preg_replace('/\s+/u', ' ', (string) $v)); return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max); }

}

switch ($action) {

case 'fa_agent_vehicles': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db(); $u = require_auth($pdo); fa_bk_agent($pdo, $u);
    $S = fa_settings($pdo);
    ok(array('vehicles' => fa_bk_vehicles($pdo), 'daily_cap' => intval($S['agent_booking_daily_cap']), 'cash_counts' => !empty($S['agent_booking_cash_counts'])));
    break;
}

case 'fa_agent_quote': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db(); $u = require_auth($pdo); fa_bk_agent($pdo, $u);
    $open = !empty($body['open']); $km = fa_bk_km(isset($body['km']) ? $body['km'] : 0);
    $fares = array();
    foreach (fa_bk_vehicles($pdo) as $v) { $r = rides_calc_fare($pdo, $km, $v['type'], $open); $fares[$v['type']] = array('fare' => $r['fare'], 'surge' => !empty($r['surge'])); }
    ok(array('km' => $km, 'open' => $open ? 1 : 0, 'fares' => $fares));
    break;
}

case 'fa_agent_book_ride': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db(); $u = require_auth($pdo); $agent = fa_bk_agent($pdo, $u);
    $S = fa_settings($pdo);
    rate_limit_check($pdo, 'fa_book_u' . intval($u['id']), 30, 3600, 1800);
    $phone = etel_validate_phone(isset($body['phone']) ? trim($body['phone']) : '');
    if (!$phone) fail('Enter a valid Ethiopian mobile number, for example 0911234567.', 400);
    $last9 = fa_phone_last9($phone);
    if ($last9 === fa_phone_last9($agent['phone']) || ($agent['payout_phone'] !== '' && $last9 === fa_phone_last9($agent['payout_phone']))) fail('You cannot book a ride for your own number or your payout number.', 409, array('reason' => 'OWN_NUMBER'));
    $open = !empty($body['open']); $km = fa_bk_km(isset($body['km']) ? $body['km'] : 0);
    if (!$open && $km <= 0) fail('Enter the distance in km, or choose Open trip.', 400);
    $vtype = rides_normalize_vehicle_type($pdo, isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : 'bajaj');
    $cname = fa_bk_text(isset($body['name']) ? $body['name'] : '', 100);
    $pnote = fa_bk_text(isset($body['pickup_note']) ? $body['pickup_note'] : '', 200); $dnote = fa_bk_text(isset($body['dest_note']) ? $body['dest_note'] : '', 200);
    $plat = isset($body['pickup_lat']) ? floatval($body['pickup_lat']) : 0; $plng = isset($body['pickup_lng']) ? floatval($body['pickup_lng']) : 0;
    if (!$plat || !$plng || abs($plat) > 90 || abs($plng) > 180) { $plat = 7.0621; $plng = 38.4763; }          // Hawassa centre when the agent did not share a location
    $paddr = $pnote !== '' ? $pnote : ($agent['venue_name'] !== '' ? $agent['venue_name'] : 'Agent booking');
    // Optional destination picked on the map. The distance is the route the agent's phone measured, but never trusted blindly:
    // it must sit between the straight-line distance and three times that, otherwise 1.3 x straight line is used.
    $dlat = isset($body['dropoff_lat']) ? floatval($body['dropoff_lat']) : 0; $dlng = isset($body['dropoff_lng']) ? floatval($body['dropoff_lng']) : 0;
    $hasDrop = $dlat && $dlng && abs($dlat) <= 90 && abs($dlng) <= 180;
    if ($hasDrop && !$open) {
        $hav = rides_haversine_km($plat, $plng, $dlat, $dlng);
        $km = ($km > 0 && $km >= $hav * 0.95 && $km <= $hav * 3) ? $km : round($hav * 1.3, 2);
        $km = max(0.5, min(100, $km));
    }

    $cap = intval($S['agent_booking_daily_cap']);
    $q = $pdo->prepare('SELECT COUNT(*) FROM fa_bookings WHERE agent_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'); $q->execute(array($agent['id']));
    if (intval($q->fetchColumn()) >= $cap) fail('You have reached today\'s limit of ' . $cap . ' bookings. Please try again tomorrow.', 429, array('reason' => 'DAILY_CAP'));

    if (!etel_phone_lock($pdo, $phone)) fail('Please try again in a moment.', 429);
    $cust = etel_find_user_by_phone($pdo, $phone, 'id,phone,name,role');
    if ($cust && $cust['role'] !== 'customer' && $cust['role'] !== 'passenger') { etel_phone_unlock($pdo, $phone); fail('That number belongs to a ' . $cust['role'] . ' account, not a customer.', 409); }
    $newAcct = false;
    if (!$cust) {
        $newAcct = true;
        $userCols = etel_table_columns($pdo, 'users');
        $f = array('name', 'phone', 'password_hash', 'role'); $m = array('?', '?', '?', '?');
        $v = array($cname !== '' ? $cname : 'Customer', $phone, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), 'customer');
        if (in_array('active', $userCols, true)) { $f[] = 'active'; $m[] = '1'; }
        $f[] = 'created_at'; $m[] = 'NOW()';
        try { $pdo->prepare('INSERT INTO users (' . implode(',', $f) . ') VALUES (' . implode(',', $m) . ')')->execute($v); }
        catch (Exception $e) { etel_phone_unlock($pdo, $phone); fail_ex($e, 'Could not create the customer account', 500); }
        $cust = array('id' => intval($pdo->lastInsertId()), 'phone' => $phone, 'name' => $cname, 'role' => 'customer');
    }
    etel_phone_unlock($pdo, $phone);
    $cid = intval($cust['id']);
    if (intval($cid) === intval($u['id'])) fail('You cannot book a ride for yourself.', 409);
    $q = $pdo->prepare("SELECT id FROM rides WHERE passenger_id = ? AND status IN ('searching','accepted','in_progress','arrived') ORDER BY id DESC LIMIT 1"); $q->execute(array($cid));
    if ($q->fetchColumn()) fail('This customer already has a ride in progress. Wait for it to finish or be cancelled.', 409, array('reason' => 'ACTIVE_RIDE'));

    // New customer? (no completed trip and not already linked to an agent). Only then does the agent earn a bonus; the ride is booked either way.
    $q = $pdo->prepare("SELECT COUNT(*) FROM rides WHERE passenger_id = ? AND status = 'completed'"); $q->execute(array($cid)); $rode = intval($q->fetchColumn()) > 0;
    $q = $pdo->prepare('SELECT COUNT(*) FROM fa_referrals WHERE rider_user_id = ?'); $q->execute(array($cid)); $linked = intval($q->fetchColumn()) > 0;
    $eligible = !$rode && !$linked; $why = $rode ? 'RIDDEN_BEFORE' : ($linked ? 'ALREADY_REFERRED' : '');

    $fr = rides_calc_fare($pdo, $km, $vtype, $open); $fare = $fr['fare'];
    $cols = 'passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, pickup_address, dropoff_address, distance_km, fare_estimate, fare_final, payment_method, status, expires_at, created_at';
    $ph = "?,?,?,?,?,?,?,?,?,?,'cash','searching', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW()";
    $dest = $dnote !== '' ? $dnote : ($hasDrop ? 'Selected on the map' : ($open ? 'Open trip (driver directed on the way)' : 'By distance: ' . $km . ' km (driver directed on the way)'));
    $vals = array($cid, $plat, $plng, $hasDrop ? $dlat : null, $hasDrop ? $dlng : null, $paddr, $dest, round($km, 2), $fare, $fare);
    foreach (array('ride_code', 'request_code', 'code', 'tracking_code', 'order_code') as $cc) { if (fa_bk_has_col($pdo, $cc)) { $cols .= ", `$cc`"; $ph .= ', ?'; $vals[] = 'R' . date('ymdHis') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5)); } }
    if (fa_bk_has_col($pdo, 'vehicle_type')) { $cols .= ', vehicle_type'; $ph .= ', ?'; $vals[] = $vtype; }
    if (fa_bk_has_col($pdo, 'open_trip')) { $cols .= ', open_trip'; $ph .= ', ?'; $vals[] = $open ? 1 : 0; }
    if (fa_bk_has_col($pdo, 'surge_applied')) { $cols .= ', surge_applied'; $ph .= ', ?'; $vals[] = !empty($fr['surge']) ? 1 : 0; }
    $pdo->prepare("INSERT INTO rides ($cols) VALUES ($ph)")->execute($vals);
    $rid = intval($pdo->lastInsertId());

    $pdo->prepare('INSERT INTO fa_bookings (agent_id, ride_id, customer_user_id, phone_last9, vehicle_type, fare_estimate, bonus_eligible, new_account, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
        ->execute(array($agent['id'], $rid, $cid, $last9, $vtype, $fare, $eligible ? 1 : 0, $newAcct ? 1 : 0));
    $bid = intval($pdo->lastInsertId());
    if ($eligible) {
        try {
            $pdo->prepare("INSERT INTO fa_referrals (agent_id, rider_user_id, device_hash, ip_hash, claimed_at, status, created_at) VALUES (?,?,NULL,?,NOW(),'claimed',NOW())")
                ->execute(array($agent['id'], $cid, fa_hash(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')));
            $pdo->prepare('UPDATE fa_agents SET last_referral_at = NOW() WHERE id = ?')->execute(array($agent['id']));
        } catch (Exception $e) { $eligible = false; $why = 'ALREADY_REFERRED'; $pdo->prepare('UPDATE fa_bookings SET bonus_eligible = 0 WHERE id = ?')->execute(array($bid)); }
    }
    fa_audit($pdo, 'agent', $agent['id'], 'agent_booking_created', 'booking', $bid, '', array('ride' => $rid, 'vehicle' => $vtype, 'eligible' => $eligible ? 1 : 0, 'new_account' => $newAcct ? 1 : 0));

    try {
        $nd = $pdo->prepare('SELECT user_id, lat, lng FROM ride_drivers WHERE online = 1 AND lat IS NOT NULL AND lng IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND (LOWER(vehicle_type) = LOWER(?) OR vehicle_type IS NULL)');
        $nd->execute(array($vtype)); $inRange = array();
        foreach ($nd->fetchAll(PDO::FETCH_ASSOC) as $rd) { $d = rides_haversine_km($plat, $plng, floatval($rd['lat']), floatval($rd['lng'])); if ($d <= 8) { $rd['km'] = $d; $inRange[] = $rd; } }
        $targets = function_exists('rides_offer_first_push_targets') ? rides_offer_first_push_targets($pdo, $inRange, $plat, $plng) : $inRange;
        foreach ($targets as $rd) etel_push_send($pdo, intval($rd['user_id']), 'New ride request nearby!', 'ETB ' . number_format($fare, 0) . ' - ' . round($rd['km'], 1) . ' km away', array('type' => 'new_ride_request', 'ride_id' => $rid, 'url' => '/delivery/driver/'));
    } catch (Exception $e) { error_log('[ETEL][push] agent-booking push failed: ' . $e->getMessage()); }

    // Text the customer (only when the SMS service is set up and admin has it on); a failure never blocks the booking.
    $smsState = 'off';
    if (!empty($S['agent_booking_sms'])) $smsState = fa_bk_sms_customer($phone, $newAcct);

    $review = $eligible && intval($S['agent_booking_review_trips']) > 0 && fa_booking_clean_count($pdo, $agent['id']) < intval($S['agent_booking_review_trips']);
    $q = $pdo->prepare('SELECT COUNT(*) FROM fa_bookings WHERE agent_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'); $q->execute(array($agent['id']));
    ok(array('ride_id' => $rid, 'fare' => $fare, 'vehicle_type' => $vtype, 'new_account' => $newAcct ? 1 : 0, 'customer' => fa_mask_phone($phone), 'sms' => $smsState,
             'bonus' => array('eligible' => $eligible, 'reason' => $why, 'review' => $review), 'left_today' => max(0, $cap - intval($q->fetchColumn()))));
    break;
}

case 'fa_agent_bookings': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db(); $u = require_auth($pdo);
    $q = $pdo->prepare("SELECT * FROM fa_agents WHERE user_id = ? LIMIT 1"); $q->execute(array($u['id'])); $agent = $q->fetch(PDO::FETCH_ASSOC);
    if (!$agent) ok(array('bookings' => array()));
    $q = $pdo->prepare("SELECT b.id, b.ride_id, b.vehicle_type, b.fare_estimate, b.bonus_eligible, b.created_at, r.status AS ride_status, r.fare_final, r.completed_at, c.phone AS cust_phone, c.name AS cust_name,
                               fr.status AS ref_status, e.status AS bonus_status, e.reason_code AS bonus_reason
                        FROM fa_bookings b LEFT JOIN rides r ON r.id = b.ride_id LEFT JOIN users c ON c.id = b.customer_user_id
                        LEFT JOIN fa_referrals fr ON fr.agent_id = b.agent_id AND fr.rider_user_id = b.customer_user_id
                        LEFT JOIN fa_earnings e ON e.referral_id = fr.id AND e.kind = 'first_trip'
                        WHERE b.agent_id = ? ORDER BY b.id DESC LIMIT 50");
    $q->execute(array($agent['id']));
    $rows = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $bonus = 'none';
        if (!empty($b['bonus_eligible'])) {
            $bs = $b['bonus_status'];
            $bonus = $bs === 'held' ? 'checking' : ($bs === 'pending' ? 'pending' : ($bs === 'payable' ? 'ready' : ($bs === 'paid' ? 'paid' : (($bs === 'rejected' || $bs === 'clawed_back' || $b['ref_status'] === 'rejected' || $b['ref_status'] === 'expired') ? 'none' : 'waiting'))));
        }
        $rows[] = array('id' => intval($b['id']), 'ride_id' => intval($b['ride_id']), 'vehicle' => $b['vehicle_type'], 'fare' => floatval($b['fare_final'] ? $b['fare_final'] : $b['fare_estimate']),
                        'ride_status' => $b['ride_status'], 'customer' => $b['cust_name'] ? $b['cust_name'] : fa_mask_phone($b['cust_phone']), 'phone' => fa_mask_phone($b['cust_phone']), 'bonus' => $bonus, 'created_at' => $b['created_at']);
    }
    ok(array('bookings' => $rows));
    break;
}

}
