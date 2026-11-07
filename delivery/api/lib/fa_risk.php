<?php
// ============================================================
// ETEL Ride - Field Agents: rule-based, explainable risk engine.
//
// fa_risk_evaluate() returns:
//   hard    - '' or a reason code (SELF_USER / SELF_ETELID / SELF_PHONE / PAYOUT_IS_RIDER): no bonus is possible
//   score   - 0..100 (sum of the weighted signals, capped)
//   reasons - list of array('code', 'points', 'text') so an admin always sees WHY
// A score at or above risk_hold_threshold means HOLD for admin review. This engine never rejects or pays
// anything by itself: callers record the result and a human decides. Weights and thresholds are admin settings.
// Reads rides/users only. ASCII only.
// ============================================================
if (!defined('APP_VER') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/fa_core.php';

if (!function_exists('fa_risk_evaluate')) {

function fa_risk_add(&$reasons, $code, $points, $text) {
    if ($points > 0) $reasons[] = array('code' => $code, 'points' => intval($points), 'text' => $text);
}

/* $agent: fa_agents row. $ref: fa_referrals row. $rider: users row (id, phone, etel_id). $ride: rides row (or null). */
function fa_risk_evaluate($pdo, $agent, $ref, $rider, $ride) {
    $S = fa_settings($pdo);
    $out = array('hard' => '', 'score' => 0, 'reasons' => array());

    // ---- hard blocks: the rider IS the agent (same account, Etel ID, phone, or payout number) ----
    if (intval($agent['user_id']) === intval($rider['id'])) $out['hard'] = 'SELF_USER';
    elseif (!empty($agent['etel_id']) && !empty($rider['etel_id']) && strcasecmp($agent['etel_id'], $rider['etel_id']) === 0) $out['hard'] = 'SELF_ETELID';
    elseif (fa_phone_last9($agent['phone']) !== '' && fa_phone_last9($agent['phone']) === fa_phone_last9(isset($rider['phone']) ? $rider['phone'] : '')) $out['hard'] = 'SELF_PHONE';
    elseif (fa_phone_last9($agent['payout_phone']) !== '' && fa_phone_last9($agent['payout_phone']) === fa_phone_last9(isset($rider['phone']) ? $rider['phone'] : '')) $out['hard'] = 'PAYOUT_IS_RIDER';
    if ($out['hard'] !== '') {
        $out['score'] = 100;
        $out['reasons'][] = array('code' => $out['hard'], 'points' => 100, 'text' => fa_reason_text($out['hard']));
        return $out;
    }

    $r = array();
    $claimedAt = !empty($ref['claimed_at']) ? $ref['claimed_at'] : date('Y-m-d H:i:s');
    // Agent-booked customers are claimed from the AGENT's own phone, so the device-based signals would fire on every honest booking.
    // They are replaced by the daily booking cap, the review hold (fa_create_earning) and the sequential-phones check below.
    $booked = fa_ref_is_agent_booked($pdo, $ref);

    // ---- same device: the code was claimed from the agent's own device ----
    if (!$booked && !empty($ref['device_hash']) && !empty($agent['device_hash']) && hash_equals($agent['device_hash'], $ref['device_hash'])) {
        fa_risk_add($r, 'SAME_DEVICE', $S['risk_w_same_device'], 'The code was claimed from the same device the agent uses.');
    }

    // ---- shared identity: this agent uses the same payout number or device as another agent ----
    $shared = fa_agent_shared_identity($pdo, $agent);
    if ($shared) fa_risk_add($r, 'SHARED_IDENTITY', $S['risk_w_shared_identity'], 'This agent shares a payout number or device with ' . count($shared) . ' other agent(s).');

    // ---- code reuse: one device/ip claiming several different riders' referrals, or several agents' codes ----
    try {
        if (!$booked && !empty($ref['device_hash'])) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM fa_referrals WHERE device_hash = ? AND id <> ? AND claimed_at > DATE_SUB(?, INTERVAL 24 HOUR)");
            $q->execute(array($ref['device_hash'], intval($ref['id']), $claimedAt));
            $n = intval($q->fetchColumn());
            if ($n >= 1) fa_risk_add($r, 'CODE_REUSE', $S['risk_w_code_reuse'], 'The same device claimed ' . ($n + 1) . ' referrals within 24 hours.');
        }
    } catch (Exception $e) {}

    // ---- burst: many claims for this agent in a short window ----
    if (!$booked) try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM fa_referrals WHERE agent_id = ? AND claimed_at BETWEEN DATE_SUB(?, INTERVAL ? MINUTE) AND DATE_ADD(?, INTERVAL ? MINUTE)");
        $w = intval($S['risk_burst_minutes']);
        $q->execute(array(intval($agent['id']), $claimedAt, $w, $claimedAt, $w));
        $n = intval($q->fetchColumn());
        if ($n >= intval($S['risk_burst_count'])) fa_risk_add($r, 'BURST', $S['risk_w_burst'], $n . ' referrals for this agent within ' . $w . ' minutes.');
    } catch (Exception $e) {}

    // ---- sequential phone numbers: an agent booking for numbers that sit next to each other (0911000001, 0911000002, ...) ----
    if ($booked) {
        try {
            $mine = fa_phone_last9(isset($rider['phone']) ? $rider['phone'] : '');
            if ($mine !== '') {
                $q = $pdo->prepare('SELECT phone_last9 FROM fa_bookings WHERE agent_id = ? AND customer_user_id <> ? AND created_at > DATE_SUB(?, INTERVAL 24 HOUR)');
                $q->execute(array(intval($agent['id']), intval($rider['id']), $claimedAt));
                $near = 0;
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $o) { if ($o !== '' && abs(intval($o) - intval($mine)) <= 5) $near++; }
                if ($near >= 2) fa_risk_add($r, 'SEQUENTIAL_PHONES', $S['risk_w_sequential_phones'], 'The agent booked for ' . ($near + 1) . ' customers with phone numbers right next to each other.');
            }
        } catch (Exception $e) {}
    }

    if ($ride) {
        // ---- repeated agent + driver pair ----
        try {
            $driver = intval($ride['driver_id']);
            if ($driver > 0) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM fa_referrals WHERE agent_id = ? AND driver_id = ? AND id <> ?");
                $q->execute(array(intval($agent['id']), $driver, intval($ref['id'])));
                $n = intval($q->fetchColumn()) + 1;
                if ($n >= intval($S['risk_pair_threshold'])) {
                    $extra = min(10, ($n - intval($S['risk_pair_threshold'])) * 5);
                    fa_risk_add($r, 'REPEAT_PAIR', $S['risk_w_repeat_pair'] + $extra, 'The same driver took ' . $n . ' of this agent\'s referred riders.');
                }
            }
        } catch (Exception $e) {}

        // ---- very short trip (still above the hard minimum, but suspiciously short) ----
        $km = fa_ride_km($ride);
        if ($km > 0 && $km < floatval($S['risk_short_trip_km'])) {
            fa_risk_add($r, 'SHORT_TRIP', $S['risk_w_short_trip'], 'The trip was only ' . round($km, 1) . ' km.');
        }

        // ---- odd hours (local time of completion) ----
        if (!empty($ride['completed_at'])) {
            $h = intval(date('G', strtotime($ride['completed_at'])));
            $a = intval($S['risk_odd_hour_start']); $b = intval($S['risk_odd_hour_end']);
            $odd = ($a <= $b) ? ($h >= $a && $h < $b) : ($h >= $a || $h < $b);
            if ($odd && $a !== $b) fa_risk_add($r, 'ODD_HOURS', $S['risk_w_odd_hours'], 'The trip finished at ' . sprintf('%02d:00', $h) . ' (odd hours).');
        }
    }

    // ---- agent history: a large share of this agent's earlier referrals were rejected or held ----
    try {
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status = 'rejected') AS bad FROM fa_referrals WHERE agent_id = ? AND id <> ?");
        $q->execute(array(intval($agent['id']), intval($ref['id'])));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && intval($row['n']) >= 5 && intval($row['bad']) / intval($row['n']) >= 0.3) {
            fa_risk_add($r, 'AGENT_HISTORY', $S['risk_w_agent_history'], intval($row['bad']) . ' of ' . intval($row['n']) . ' earlier referrals from this agent were rejected.');
        }
    } catch (Exception $e) {}

    $score = 0;
    foreach ($r as $x) $score += $x['points'];
    $out['score'] = min(100, $score);
    $out['reasons'] = $r;
    return $out;
}

/* Other agents that use the same Telebirr payout number (last 9 digits) or the same device. Returns list of array(id, name, why). */
function fa_agent_shared_identity($pdo, $agent) {
    $out = array();
    try {
        $last9 = fa_phone_last9(isset($agent['payout_phone']) ? $agent['payout_phone'] : '');
        $q = $pdo->prepare("SELECT id, full_name, payout_phone, device_hash FROM fa_agents WHERE id <> ? AND status IN ('pending','approved','suspended')");
        $q->execute(array(intval($agent['id'])));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $why = array();
            if ($last9 !== '' && fa_phone_last9($o['payout_phone']) === $last9) $why[] = 'payout number';
            if (!empty($agent['device_hash']) && !empty($o['device_hash']) && hash_equals($agent['device_hash'], $o['device_hash'])) $why[] = 'device';
            if ($why) $out[] = array('id' => intval($o['id']), 'name' => $o['full_name'], 'why' => implode(' and ', $why));
        }
    } catch (Exception $e) { /* optional signal */ }
    return $out;
}

/* Distance of a completed ride: the GPS meter when it produced a usable reading, else the booked distance. */
function fa_ride_km($ride) {
    $t = isset($ride['traveled_km']) ? floatval($ride['traveled_km']) : 0;
    if ($t > 0.1) return $t;
    return isset($ride['distance_km']) ? floatval($ride['distance_km']) : 0;
}

/* Minutes from trip start to arrival/completion. 0 when start time is unknown (treated as not meeting a minimum). */
function fa_ride_minutes($ride) {
    if (empty($ride['started_at'])) return 0;
    $end = !empty($ride['arrived_at']) ? $ride['arrived_at'] : (!empty($ride['completed_at']) ? $ride['completed_at'] : '');
    if ($end === '') return 0;
    $d = strtotime($end) - strtotime($ride['started_at']);
    return $d > 0 ? $d / 60 : 0;
}

/* Does this completed, paid trip count toward a bonus? Returns '' when it does, else a short reason. */
function fa_ride_qualifies($pdo, $ride, $agentBooked = false) {
    $S = fa_settings($pdo);
    if ($ride['status'] !== 'completed' || floatval($ride['fare_paid']) <= 0) return 'not completed and paid';
    // A customer an agent booked for may pay cash (new customers often have no wallet); those bonuses wait for admin review instead (fa_create_earning).
    $cashOk = $agentBooked && !empty($S['agent_booking_cash_counts']);
    if (!empty($S['require_digital_payment']) && $ride['payment_method'] === 'cash' && !$cashOk) return 'cash payment not accepted';
    if (fa_ride_km($ride) < floatval($S['min_trip_km'])) return 'trip too short (distance)';
    if (fa_ride_minutes($ride) < floatval($S['min_trip_minutes'])) return 'trip too short (time)';
    return '';
}

/* Agent-level score: the average of the agent's last 10 referral scores, with the reasons that appear most often.
   Stored on fa_agents for the admin list; recomputed by the earnings job. */
function fa_agent_risk_rollup($pdo, $agentId) {
    $q = $pdo->prepare("SELECT risk_score, risk_reasons FROM fa_referrals WHERE agent_id = ? AND risk_score > 0 ORDER BY id DESC LIMIT 10");
    $q->execute(array(intval($agentId)));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $sum = 0; $codes = array();
    foreach ($rows as $r) {
        $sum += intval($r['risk_score']);
        $list = json_decode((string) $r['risk_reasons'], true);
        if (is_array($list)) foreach ($list as $x) { if (isset($x['code'])) $codes[$x['code']] = (isset($codes[$x['code']]) ? $codes[$x['code']] : 0) + 1; }
    }
    $score = count($rows) ? intval(round($sum / count($rows))) : 0;
    arsort($codes);
    $top = array();
    foreach (array_slice($codes, 0, 4, true) as $c => $n) $top[] = array('code' => $c, 'count' => $n);
    $pdo->prepare('UPDATE fa_agents SET risk_score = ?, risk_reasons = ?, updated_at = NOW() WHERE id = ?')
        ->execute(array($score, json_encode($top), intval($agentId)));
    return array('score' => $score, 'top' => $top);
}

}
