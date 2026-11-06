<?php
// ============================================================
// ETEL Ride - Field Agents: agent-facing and rider-facing actions.
//   fa_ref_lookup              { code }                      public, rate limited -> { valid, venue }
//   fa_claim_ref               { code, device }              signed-in rider      -> { applied, reason }
//   fa_agent_apply             { venue_name, venue_type, venue_zone, payout_phone, device }
//   fa_agent_me                { device? }                   -> status, code, balances, progress, rules
//   fa_agent_referrals         {}                            -> referrals with the rider phone MASKED
//   fa_agent_payouts           {}                            -> payout history
//   fa_agent_update_profile    { payout_phone?, venue_name?, venue_zone? }
//   fa_agent_notifications     {}   /  fa_agent_notifications_read {}
// Agents sign in with EtelID like every other Ride user (etelid_login); "agent" is a row in fa_agents, not a users.role,
// so no login or role code changes. The agent never sees a risk score or a fraud reason, only a plain status.
// ============================================================
require_once __DIR__ . '/../lib/fa_core.php';
require_once __DIR__ . '/../lib/fa_risk.php';
require_once __DIR__ . '/../lib/fa_earnings.php';

if (!function_exists('fa_agent_of')) {

function fa_agent_of($pdo, $userId) {
    $q = $pdo->prepare('SELECT * FROM fa_agents WHERE user_id = ? LIMIT 1');
    $q->execute(array(intval($userId)));
    return $q->fetch(PDO::FETCH_ASSOC);
}

/* Server-to-server age check against EtelID (scope "age_check"; returns only {adult, reviewed}, never the date of birth).
   auto_verified needs BOTH: adult AND the Fayda ID photos reviewed by an admin (EtelID pass2_status = approved).
   Anything else is left 'unchecked' for a manual admin check; a confirmed minor is 'failed'. */
function fa_age_check($user) {
    $unchecked = array('status' => 'unchecked', 'note' => 'EtelID age check unavailable - verify the ID card manually');
    try {
        if (!function_exists('etelid_bridge_service_call')) require_once __DIR__ . '/../etelid_bridge.php';
        $ident = !empty($user['etel_id']) ? $user['etel_id'] : $user['phone'];
        $svc = etelid_bridge_service_call('age_check', array('identifier' => $ident), 8);
    } catch (Exception $e) { return $unchecked; }
    if (!is_array($svc) || empty($svc['ok'])) return $unchecked;
    if (empty($svc['adult'])) return array('status' => 'failed', 'note' => 'EtelID says under 18');
    if (!empty($svc['reviewed'])) return array('status' => 'auto_verified', 'note' => 'EtelID: adult, Fayda ID reviewed');
    return array('status' => 'unchecked', 'note' => 'EtelID: adult, but date of birth is self-declared - verify the ID card manually');
}

function fa_agent_view_status($earnStatus) {
    $m = array('pending' => 'pending', 'held' => 'pending', 'payable' => 'available', 'paid' => 'paid', 'rejected' => 'rejected', 'clawed_back' => 'reversed');
    return isset($m[$earnStatus]) ? $m[$earnStatus] : 'pending';
}

/* The agent only ever sees a simple class of reason. Self-match codes are merged so a fraudster learns nothing from them. */
function fa_agent_view_reason($code) {
    if (in_array($code, array('SELF_USER', 'SELF_ETELID', 'SELF_PHONE', 'PAYOUT_IS_RIDER'), true)) return 'SELF_REFERRAL';
    if (in_array($code, array('RISK_HOLD', 'CAP_DAILY', 'CAP_WEEKLY', ''), true)) return '';
    return $code;
}

function fa_clean_phone($raw) {
    $p = etel_validate_phone($raw);
    return $p === false ? '' : $p;
}

}

switch ($action) {

case 'fa_ref_lookup': {
    $pdo = db();
    rate_limit_check($pdo, 'fa_lookup', 15, 600, 900);          // 15 unknown codes per 10 min per IP, then a 15 min lockout
    $code = fa_normalize_code(isset($body['code']) ? $body['code'] : '');
    $row = false;
    if (strlen($code) >= 5 && strlen($code) <= 12) {
        $q = $pdo->prepare("SELECT venue_name FROM fa_agents WHERE code = ? AND status = 'approved' LIMIT 1");
        $q->execute(array($code));
        $row = $q->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) { rate_limit_fail($pdo, 'fa_lookup'); ok(array('valid' => false)); }
    ok(array('valid' => true, 'venue' => $row['venue_name']));
    break;
}

case 'fa_claim_ref': {
    $pdo = db();
    $u = require_auth($pdo);
    $S = fa_settings($pdo);
    if (empty($S['program_enabled'])) ok(array('applied' => false, 'reason' => 'PROGRAM_OFF'));
    rate_limit_check($pdo, 'fa_claim_u' . intval($u['id']), 8, 3600, 3600);
    $code = fa_normalize_code(isset($body['code']) ? $body['code'] : '');
    $dev  = isset($body['device']) ? substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $body['device']), 0, 128) : '';
    $q = $pdo->prepare("SELECT * FROM fa_agents WHERE code = ? AND status = 'approved' LIMIT 1");
    $q->execute(array($code));
    $agent = $q->fetch(PDO::FETCH_ASSOC);
    if (!$agent) { rate_limit_fail($pdo, 'fa_claim_u' . intval($u['id'])); ok(array('applied' => false, 'reason' => 'BAD_CODE')); }
    $ex = $pdo->prepare('SELECT id FROM fa_referrals WHERE rider_user_id = ? LIMIT 1');
    $ex->execute(array(intval($u['id'])));
    if ($ex->fetch()) ok(array('applied' => false, 'reason' => 'ALREADY_CLAIMED'));
    $done = $pdo->prepare("SELECT COUNT(*) FROM rides WHERE passenger_id = ? AND status = 'completed'");
    $done->execute(array(intval($u['id'])));
    if (intval($done->fetchColumn()) > 0) ok(array('applied' => false, 'reason' => 'NOT_NEW'));
    // Same person as the agent: answer exactly like a wrong code (no hint), and keep a record.
    $self = intval($agent['user_id']) === intval($u['id'])
         || fa_phone_last9($agent['phone']) === fa_phone_last9($u['phone'])
         || (!empty($agent['etel_id']) && !empty($u['etel_id']) && strcasecmp($agent['etel_id'], $u['etel_id']) === 0);
    if ($self) {
        fa_audit($pdo, 'rider', $u['id'], 'claim_blocked_self', 'agent', $agent['id'], 'SELF_REFERRAL', array());
        ok(array('applied' => false, 'reason' => 'BAD_CODE'));
    }
    try {
        $pdo->prepare('INSERT INTO fa_referrals (agent_id, rider_user_id, device_hash, ip_hash, claimed_at, status, created_at) VALUES (?,?,?,?,NOW(),?,NOW())')
            ->execute(array(intval($agent['id']), intval($u['id']), $dev !== '' ? fa_hash($dev) : null,
                            fa_hash(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 'claimed'));
    } catch (Exception $e) {
        ok(array('applied' => false, 'reason' => 'ALREADY_CLAIMED'));      // lost a race with a parallel claim: the unique key held
    }
    $rid = intval($pdo->lastInsertId());
    $pdo->prepare('UPDATE fa_agents SET last_referral_at = NOW() WHERE id = ?')->execute(array(intval($agent['id'])));
    fa_audit($pdo, 'rider', $u['id'], 'referral_claimed', 'referral', $rid, '', array('agent' => intval($agent['id'])));
    fa_notify($pdo, $agent['id'], 'referral_claimed', array('referral' => $rid, 'rider' => fa_mask_phone($u['phone'])));
    ok(array('applied' => true));
    break;
}

case 'fa_agent_apply': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $existing = fa_agent_of($pdo, $u['id']);
    if ($existing) ok(array('status' => $existing['status'], 'already' => true));
    $venue = trim(isset($body['venue_name']) ? (string) $body['venue_name'] : '');
    $type  = isset($body['venue_type']) ? preg_replace('/[^a-z_]/', '', strtolower((string) $body['venue_type'])) : 'other';
    if (!in_array($type, array('hotel', 'parking', 'shoe_shine', 'restaurant', 'shop', 'other'), true)) $type = 'other';
    $zone  = trim(isset($body['venue_zone']) ? (string) $body['venue_zone'] : '');
    $payout = fa_clean_phone(isset($body['payout_phone']) ? $body['payout_phone'] : '');
    if (strlen($venue) < 2 || strlen($venue) > 120) fail('Enter the name of your hotel, parking or business');
    if ($payout === '') fail('Enter a valid Telebirr phone number');
    $dev = isset($body['device']) ? substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $body['device']), 0, 128) : '';
    $age = fa_age_check($u);
    $status = ($age['status'] === 'failed') ? 'rejected' : 'pending';
    $pdo->prepare('INSERT INTO fa_agents (user_id, etel_id, full_name, phone, venue_name, venue_type, venue_zone, payout_phone, status, age_status, age_note, age_checked_at, device_hash, decision_reason, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,NOW())')
        ->execute(array(intval($u['id']), isset($u['etel_id']) ? $u['etel_id'] : null, substr($u['name'], 0, 120), $u['phone'], $venue, $type, substr($zone, 0, 80), $payout,
                        $status, $age['status'], $age['note'], $dev !== '' ? fa_hash($dev) : null, $status === 'rejected' ? 'AGE_REQUIREMENT' : ''));
    $aid = intval($pdo->lastInsertId());
    fa_audit($pdo, 'agent', $u['id'], 'agent_applied', 'agent', $aid, '', array('age' => $age['status'], 'status' => $status));
    ok(array('status' => $status, 'age' => $age['status'], 'reason' => $status === 'rejected' ? 'AGE_REQUIREMENT' : ''));
    break;
}

case 'fa_agent_me': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $a = fa_agent_of($pdo, $u['id']);
    if (!$a) ok(array('is_agent' => false, 'program_enabled' => !empty(fa_setting($pdo, 'program_enabled'))));
    if (!empty($body['device']) && $a['status'] === 'approved') {
        $pdo->prepare('UPDATE fa_agents SET device_hash = ? WHERE id = ?')->execute(array(fa_hash(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $body['device']), 0, 128)), $a['id']));
    }
    $S = fa_settings($pdo);
    $bal = array('pending' => 0, 'available' => 0, 'paid' => 0);
    $q = $pdo->prepare("SELECT status, COALESCE(SUM(amount),0) AS t FROM fa_earnings WHERE agent_id = ? GROUP BY status");
    $q->execute(array($a['id']));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['status'] === 'pending' || $r['status'] === 'held') $bal['pending'] += floatval($r['t']);
        elseif ($r['status'] === 'payable') $bal['available'] += floatval($r['t']);
        elseif ($r['status'] === 'paid') $bal['paid'] += floatval($r['t']);
    }
    $cnt = $pdo->prepare("SELECT status, COUNT(*) AS n FROM fa_referrals WHERE agent_id = ? GROUP BY status");
    $cnt->execute(array($a['id']));
    $counts = array();
    foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['status']] = intval($r['n']);
    // Progress toward the repeat bonus: the referred rider closest to the required trip count.
    $best = null;
    if ($a['status'] === 'approved') {
        $pr = $pdo->prepare("SELECT r.* FROM fa_referrals r WHERE r.agent_id = ? AND r.status = 'qualified'
                             AND r.first_ride_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                             AND NOT EXISTS (SELECT 1 FROM fa_earnings e WHERE e.referral_id = r.id AND e.kind = 'repeat3') ORDER BY r.first_ride_at DESC LIMIT 20");
        $pr->execute(array($a['id'], intval($S['repeat_window_days'])));
        foreach ($pr->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rq = $pdo->prepare("SELECT * FROM rides WHERE passenger_id = ? AND status = 'completed' AND completed_at >= ? AND completed_at <= DATE_ADD(?, INTERVAL ? DAY) LIMIT 60");
            $rq->execute(array(intval($r['rider_user_id']), $r['first_ride_at'], $r['first_ride_at'], intval($S['repeat_window_days'])));
            $n = 0; foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $ride) { if (fa_ride_qualifies($pdo, $ride) === '') $n++; }
            $left = max(0, intval(ceil((strtotime($r['first_ride_at']) + 86400 * $S['repeat_window_days'] - time()) / 86400)));
            if ($best === null || $n > $best['trips']) $best = array('trips' => min($n, intval($S['repeat_trips_required'])), 'days_left' => $left);
        }
    }
    $un = $pdo->prepare('SELECT COUNT(*) FROM fa_notifications WHERE agent_id = ? AND read_at IS NULL');
    $un->execute(array($a['id']));
    ok(array(
        'is_agent' => true, 'program_enabled' => !empty($S['program_enabled']),
        'agent' => array('id' => intval($a['id']), 'name' => $a['full_name'], 'venue' => $a['venue_name'], 'venue_type' => $a['venue_type'], 'venue_zone' => $a['venue_zone'],
                         'status' => $a['status'], 'code' => $a['status'] === 'approved' ? $a['code'] : null, 'tier' => $a['tier'],
                         'age_verified' => in_array($a['age_status'], array('auto_verified', 'manual_verified'), true), 'payout_phone' => $a['payout_phone'],
                         'decision_reason' => $a['status'] === 'rejected' ? ($a['decision_reason'] !== '' ? 'NOT_APPROVED' : '') : ''),
        'balances' => array('pending' => round($bal['pending'], 2), 'available' => round($bal['available'], 2), 'paid' => round($bal['paid'], 2),
                            'total' => round($bal['pending'] + $bal['available'] + $bal['paid'], 2)),
        'referral_counts' => $counts, 'progress' => $best, 'unread' => intval($un->fetchColumn()),
        'rules' => array('bonus_first' => $S['bonus_first_etb'], 'bonus_repeat' => $S['bonus_repeat_etb'], 'repeat_trips' => $S['repeat_trips_required'],
                         'repeat_days' => $S['repeat_window_days'], 'hold_days' => fa_hold_days($S, $a['tier']), 'min_payout' => $S['min_payout_etb'],
                         'min_km' => $S['min_trip_km'], 'min_minutes' => $S['min_trip_minutes']),
    ));
    break;
}

case 'fa_agent_referrals': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $a = fa_agent_of($pdo, $u['id']);
    if (!$a) fail('You are not registered as an agent', 403);
    $q = $pdo->prepare("SELECT r.id, r.claimed_at, r.status, r.reject_code, u.phone AS rider_phone FROM fa_referrals r LEFT JOIN users u ON u.id = r.rider_user_id
                        WHERE r.agent_id = ? ORDER BY r.id DESC LIMIT 100");
    $q->execute(array($a['id']));
    $out = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eq = $pdo->prepare("SELECT kind, amount, status, reason_code, payable_at FROM fa_earnings WHERE referral_id = ? AND kind <> 'clawback' ORDER BY id");
        $eq->execute(array($r['id']));
        $items = array();
        foreach ($eq->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $vs = fa_agent_view_status($e['status']);
            $items[] = array('kind' => $e['kind'], 'amount' => floatval($e['amount']), 'status' => $vs,
                             'reason' => $vs === 'rejected' || $vs === 'reversed' ? fa_agent_view_reason($e['reason_code']) : '',
                             'payable_at' => $vs === 'pending' ? $e['payable_at'] : null);
        }
        $st = $r['status'] === 'claimed' ? 'waiting' : ($r['status'] === 'expired' ? 'expired' : ($r['status'] === 'rejected' ? 'rejected' : 'active'));
        $out[] = array('id' => intval($r['id']), 'rider' => fa_mask_phone($r['rider_phone']), 'claimed_at' => $r['claimed_at'], 'status' => $st,
                       'reason' => $st === 'rejected' ? fa_agent_view_reason($r['reject_code']) : '', 'earnings' => $items);
    }
    ok(array('referrals' => $out));
    break;
}

case 'fa_agent_payouts': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $a = fa_agent_of($pdo, $u['id']);
    if (!$a) fail('You are not registered as an agent', 403);
    $q = $pdo->prepare("SELECT i.id, i.amount, i.status, i.tx_ref, i.paid_at, i.created_at FROM fa_payout_items i JOIN fa_payout_batches b ON b.id = i.batch_id
                        WHERE i.agent_id = ? AND b.status IN ('exported','paid') ORDER BY i.id DESC LIMIT 100");
    $q->execute(array($a['id']));
    ok(array('payouts' => $q->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'fa_agent_update_profile': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $a = fa_agent_of($pdo, $u['id']);
    if (!$a) fail('You are not registered as an agent', 403);
    $set = array(); $val = array(); $log = array();
    if (isset($body['payout_phone'])) {
        $p = fa_clean_phone($body['payout_phone']);
        if ($p === '') fail('Enter a valid Telebirr phone number');
        if ($p !== $a['payout_phone']) { $set[] = 'payout_phone = ?'; $val[] = $p; $log['payout_phone'] = array('old' => $a['payout_phone'], 'new' => $p); }
    }
    if (isset($body['venue_name']) && trim($body['venue_name']) !== '') { $set[] = 'venue_name = ?'; $val[] = substr(trim($body['venue_name']), 0, 120); }
    if (isset($body['venue_zone'])) { $set[] = 'venue_zone = ?'; $val[] = substr(trim($body['venue_zone']), 0, 80); }
    if ($set) {
        $val[] = $a['id'];
        $pdo->prepare('UPDATE fa_agents SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ?')->execute($val);
        fa_audit($pdo, 'agent', $u['id'], 'agent_profile_updated', 'agent', $a['id'], '', $log);
    }
    ok(array('saved' => true));
    break;
}

case 'fa_agent_notifications': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $a = fa_agent_of($pdo, $u['id']);
    if (!$a) fail('You are not registered as an agent', 403);
    $q = $pdo->prepare('SELECT id, kind, params, read_at, created_at FROM fa_notifications WHERE agent_id = ? ORDER BY id DESC LIMIT 30');
    $q->execute(array($a['id']));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $p = json_decode($r['params'], true);
        if (!is_array($p)) $p = array();
        if (isset($p['reason'])) $p['reason'] = fa_agent_view_reason($p['reason']);   // never expose risk or cap reasons to the agent
        $r['params'] = $p;
        if ($r['kind'] === 'bonus_held') $r['kind'] = 'bonus_pending';                // a held bonus looks like a pending one to the agent
    }
    unset($r);
    ok(array('notifications' => $rows));
    break;
}

case 'fa_agent_notifications_read': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $a = fa_agent_of($pdo, $u['id']);
    if (!$a) fail('You are not registered as an agent', 403);
    $pdo->prepare('UPDATE fa_notifications SET read_at = NOW() WHERE agent_id = ? AND read_at IS NULL')->execute(array($a['id']));
    ok(array('read' => true));
    break;
}

}
