<?php
// ============================================================
// ETEL Ride - Field Agents: earnings job (READ-ONLY on rides and users).
//
// fa_run_earnings() turns referral claims into bonuses by LOOKING at completed trips. It never edits a ride,
// a user, a wallet or any Ride code path. It writes only to the fa_* tables and is idempotent: running it twice
// (cron plus an admin click) cannot create a bonus twice (unique key on fa_earnings: referral, kind, parent).
//
// Rules (all numbers are admin settings, see fa_defaults()):
//   Bonus 1  first_trip : once, for a NEW rider's first QUALIFYING completed and paid trip after the code was claimed.
//            "New" = the rider had no completed trip before claiming. Qualifying = completed, paid (fare_paid > 0),
//            at least min_trip_km AND min_trip_minutes (set one to 0 to switch it off), and cash allowed unless
//            require_digital_payment = 1. Shorter trips are skipped, not rejected: the rider can still qualify later
//            inside qualify_window_days.
//   Bonus 2  repeat3    : once, when that same rider has repeat_trips_required qualifying trips (the first included)
//            within repeat_window_days of the first one.
//   Flow     pending (hold_days) -> payable -> paid. A risk score at or above risk_hold_threshold, or a daily/weekly
//            cap, puts the bonus on HELD for an admin to release or reject: nothing is ever auto-rejected for risk and
//            nothing risky is auto-paid. Hard self-match is the only automatic rejection, with its reason recorded.
//   Clawback a trip that is later no longer completed-and-paid (cancelled/refunded) cancels a pending or payable
//            bonus, or adds a negative adjustment when the bonus was already paid (within clawback_window_days).
// ASCII only.
// ============================================================
if (!defined('APP_VER') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/fa_core.php';
require_once __DIR__ . '/fa_risk.php';

if (!function_exists('fa_run_earnings')) {

function fa_hold_days($S, $tier) {
    if ($tier === 'gold') return intval($S['hold_days_gold']);
    if ($tier === 'silver') return intval($S['hold_days_silver']);
    return intval($S['hold_days']);
}

/* '' when the earning fits inside the agent's daily and weekly limits, else CAP_DAILY / CAP_WEEKLY. */
function fa_cap_check($pdo, $agentId, $earnedAt, $amount) {
    $S = fa_settings($pdo);
    $base = "SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE agent_id = ? AND kind <> 'clawback' AND status IN ('pending','payable','paid')";
    if ($S['daily_cap_etb'] > 0) {
        $q = $pdo->prepare($base . ' AND DATE(earned_at) = DATE(?)');
        $q->execute(array(intval($agentId), $earnedAt));
        if (floatval($q->fetchColumn()) + $amount > $S['daily_cap_etb']) return 'CAP_DAILY';
    }
    if ($S['weekly_cap_etb'] > 0) {
        $q = $pdo->prepare($base . ' AND YEARWEEK(earned_at, 1) = YEARWEEK(?, 1)');
        $q->execute(array(intval($agentId), $earnedAt));
        if (floatval($q->fetchColumn()) + $amount > $S['weekly_cap_etb']) return 'CAP_WEEKLY';
    }
    return '';
}

/* How many agent-booked customers of this agent already earned a first-trip bonus that passed (released by an admin, or later). */
function fa_booking_clean_count($pdo, $agentId) {
    try {
        $q = $pdo->prepare("SELECT COUNT(DISTINCT e.referral_id) FROM fa_earnings e JOIN fa_referrals r ON r.id = e.referral_id
                            JOIN fa_bookings b ON b.agent_id = r.agent_id AND b.customer_user_id = r.rider_user_id
                            WHERE e.agent_id = ? AND e.kind = 'first_trip' AND e.status IN ('pending','payable','paid')");
        $q->execute(array(intval($agentId)));
        return intval($q->fetchColumn());
    } catch (Exception $e) { return 0; }
}

/* True while every trip behind this earning is still completed and paid. */
function fa_earning_rides_ok($pdo, $e) {
    $ids = array();
    foreach (explode(',', (string) $e['ride_ids']) as $x) { if (intval($x) > 0) $ids[intval($x)] = 1; }
    if (!$ids && intval($e['ride_id']) > 0) $ids[intval($e['ride_id'])] = 1;
    if (!$ids) return true;
    $in = implode(',', array_keys($ids));
    $n = intval($pdo->query("SELECT COUNT(*) FROM rides WHERE id IN ($in) AND status = 'completed' AND fare_paid > 0")->fetchColumn());
    return $n === count($ids);
}

/* Creates one earning (idempotent) and returns its status, or '' when it already existed. */
function fa_create_earning($pdo, $agent, $ref, $rider, $ride, $kind, $amount, $rideIds, $forceReject) {
    $S = fa_settings($pdo);
    $risk = fa_risk_evaluate($pdo, $agent, $ref, $rider, $ride);
    $earnedAt = $ride['completed_at'];
    $status = 'pending'; $reason = '';
    if ($risk['hard'] !== '' || $forceReject !== '') { $status = 'rejected'; $reason = $risk['hard'] !== '' ? $risk['hard'] : $forceReject; }
    elseif ($risk['score'] >= $S['risk_hold_threshold']) { $status = 'held'; $reason = 'RISK_HOLD'; }
    else {
        $cap = fa_cap_check($pdo, $agent['id'], $earnedAt, $amount);
        if ($cap !== '') { $status = 'held'; $reason = $cap; }
        elseif (intval($S['agent_booking_review_trips']) > 0 && fa_ref_is_agent_booked($pdo, $ref) && fa_booking_clean_count($pdo, $agent['id']) < intval($S['agent_booking_review_trips'])) {
            $status = 'held'; $reason = 'AGENT_BOOKING_REVIEW';     // an admin releases the first bookings; after enough clean ones the agent is trusted
        }
    }
    $payableAt = date('Y-m-d H:i:s', strtotime($earnedAt) + 86400 * fa_hold_days($S, $agent['tier']));
    $ins = $pdo->prepare("INSERT IGNORE INTO fa_earnings
        (referral_id, agent_id, kind, parent_id, amount, ride_id, ride_ids, status, reason_code, earned_at, payable_at, risk_score, risk_reasons, created_at, updated_at)
        VALUES (?,?,?,0,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
    $ins->execute(array(intval($ref['id']), intval($agent['id']), $kind, $amount, intval($ride['id']), implode(',', $rideIds), $status, $reason,
                        $earnedAt, $payableAt, $risk['score'], json_encode($risk['reasons'])));
    if ($ins->rowCount() < 1) return array('status' => '', 'risk' => $risk);
    $eid = intval($pdo->lastInsertId());
    fa_audit($pdo, 'system', 0, 'earning_' . $status, 'earning', $eid, $reason, array('agent' => intval($agent['id']), 'referral' => intval($ref['id']),
             'kind' => $kind, 'amount' => $amount, 'score' => $risk['score'], 'reasons' => $risk['reasons'], 'ride' => intval($ride['id'])));
    $kinds = array('pending' => 'bonus_pending', 'held' => 'bonus_held', 'rejected' => 'referral_rejected');
    fa_notify($pdo, $agent['id'], $kinds[$status], array('earning' => $eid, 'amount' => $amount, 'reason' => $reason, 'kind' => $kind));
    return array('status' => $status, 'risk' => $risk, 'id' => $eid);
}

function fa_load_rider($pdo, $id) {
    $q = $pdo->prepare('SELECT id, phone, etel_id, created_at FROM users WHERE id = ? LIMIT 1');
    $q->execute(array(intval($id)));
    return $q->fetch(PDO::FETCH_ASSOC);
}

function fa_run_earnings($pdo) {
    $sum = array('enabled' => false, 'qualified' => 0, 'rejected' => 0, 'expired' => 0, 'repeat' => 0, 'held' => 0, 'payable' => 0, 'clawed_back' => 0, 'errors' => 0, 'skipped' => '');
    $S = fa_settings($pdo, true);
    if (empty($S['program_enabled'])) { $sum['skipped'] = 'programme is switched off'; return $sum; }
    $sum['enabled'] = true;
    $locked = false;
    try { $locked = (bool) $pdo->query("SELECT GET_LOCK('fa_run_earnings', 0)")->fetchColumn(); } catch (Exception $e) {}
    if (!$locked) { $sum['skipped'] = 'a previous run is still going'; return $sum; }
    $touched = array();

    // ---- 1. first_trip: claimed referrals of approved agents ----
    $q = $pdo->query("SELECT r.* FROM fa_referrals r JOIN fa_agents a ON a.id = r.agent_id WHERE r.status = 'claimed' AND a.status = 'approved' ORDER BY r.id ASC LIMIT 500");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $ref) {
        try {
            $ag = $pdo->prepare('SELECT * FROM fa_agents WHERE id = ?'); $ag->execute(array($ref['agent_id'])); $agent = $ag->fetch(PDO::FETCH_ASSOC);
            $rider = fa_load_rider($pdo, $ref['rider_user_id']);
            if (!$agent || !$rider) continue;
            $touched[$agent['id']] = 1;
            $pc = $pdo->prepare("SELECT COUNT(*) FROM rides WHERE passenger_id = ? AND status = 'completed' AND completed_at < ?");
            $pc->execute(array(intval($rider['id']), $ref['claimed_at']));
            if (intval($pc->fetchColumn()) > 0) {
                $pdo->prepare("UPDATE fa_referrals SET status='rejected', reject_code='NOT_NEW_RIDER', updated_at=NOW() WHERE id = ? AND status='claimed'")->execute(array($ref['id']));
                fa_audit($pdo, 'system', 0, 'referral_rejected', 'referral', $ref['id'], 'NOT_NEW_RIDER', array('agent' => intval($agent['id'])));
                fa_notify($pdo, $agent['id'], 'referral_rejected', array('referral' => intval($ref['id']), 'reason' => 'NOT_NEW_RIDER'));
                $sum['rejected']++;
                continue;
            }
            $rq = $pdo->prepare("SELECT * FROM rides WHERE passenger_id = ? AND status = 'completed' AND completed_at >= ? ORDER BY completed_at ASC, id ASC LIMIT 20");
            $rq->execute(array(intval($rider['id']), $ref['claimed_at']));
            $ride = null;
            $bk = fa_ref_is_agent_booked($pdo, $ref);
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $cand) { if (fa_ride_qualifies($pdo, $cand, $bk) === '') { $ride = $cand; break; } }
            if (!$ride) {
                if (strtotime($ref['claimed_at']) < time() - 86400 * intval($S['qualify_window_days'])) {
                    $pdo->prepare("UPDATE fa_referrals SET status='expired', reject_code='CLAIM_EXPIRED', updated_at=NOW() WHERE id = ? AND status='claimed'")->execute(array($ref['id']));
                    fa_audit($pdo, 'system', 0, 'referral_expired', 'referral', $ref['id'], 'CLAIM_EXPIRED', array('agent' => intval($agent['id'])));
                    $sum['expired']++;
                }
                continue;
            }
            $amount = floatval($S['bonus_first_etb']);
            $res = array('status' => 'pending', 'risk' => fa_risk_evaluate($pdo, $agent, $ref, $rider, $ride));
            if ($amount > 0) $res = fa_create_earning($pdo, $agent, $ref, $rider, $ride, 'first_trip', $amount, array($ride['id']), '');
            $newStatus = ($res['risk']['hard'] !== '') ? 'rejected' : 'qualified';
            $pdo->prepare("UPDATE fa_referrals SET status=?, reject_code=?, first_ride_id=?, first_ride_at=?, driver_id=?, risk_score=?, risk_reasons=?, updated_at=NOW() WHERE id=? AND status='claimed'")
                ->execute(array($newStatus, $res['risk']['hard'], $ride['id'], $ride['completed_at'], intval($ride['driver_id']), $res['risk']['score'], json_encode($res['risk']['reasons']), $ref['id']));
            if ($newStatus === 'rejected') $sum['rejected']++; else $sum['qualified']++;
            if ($res['status'] === 'held') $sum['held']++;
        } catch (Exception $e) { $sum['errors']++; error_log('[ETEL][fa_run] first_trip ref ' . $ref['id'] . ': ' . $e->getMessage()); }
    }

    // ---- 2. repeat3: qualified referrals that reached the required number of trips ----
    $need = intval($S['repeat_trips_required']);
    if ($S['bonus_repeat_etb'] > 0) {
        $q = $pdo->query("SELECT r.* FROM fa_referrals r JOIN fa_agents a ON a.id = r.agent_id
                          WHERE r.status = 'qualified' AND a.status = 'approved'
                            AND r.first_ride_at > DATE_SUB(NOW(), INTERVAL " . (intval($S['repeat_window_days']) + 14) . " DAY)
                            AND NOT EXISTS (SELECT 1 FROM fa_earnings e WHERE e.referral_id = r.id AND e.kind = 'repeat3')
                            AND EXISTS (SELECT 1 FROM fa_earnings f WHERE f.referral_id = r.id AND f.kind = 'first_trip' AND f.status NOT IN ('rejected','clawed_back'))
                          ORDER BY r.id ASC LIMIT 500");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $ref) {
            try {
                $ag = $pdo->prepare('SELECT * FROM fa_agents WHERE id = ?'); $ag->execute(array($ref['agent_id'])); $agent = $ag->fetch(PDO::FETCH_ASSOC);
                $rider = fa_load_rider($pdo, $ref['rider_user_id']);
                if (!$agent || !$rider) continue;
                $rq = $pdo->prepare("SELECT * FROM rides WHERE passenger_id = ? AND status = 'completed' AND completed_at >= ?
                                     AND completed_at <= DATE_ADD(?, INTERVAL ? DAY) ORDER BY completed_at ASC, id ASC LIMIT 60");
                $rq->execute(array(intval($rider['id']), $ref['first_ride_at'], $ref['first_ride_at'], intval($S['repeat_window_days'])));
                $good = array();
                $bk = fa_ref_is_agent_booked($pdo, $ref);
                foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $cand) { if (fa_ride_qualifies($pdo, $cand, $bk) === '') $good[] = $cand; if (count($good) >= $need) break; }
                if (count($good) < $need) continue;
                $touched[$agent['id']] = 1;
                $ids = array(); foreach ($good as $g) $ids[] = intval($g['id']);
                $res = fa_create_earning($pdo, $agent, $ref, $rider, $good[$need - 1], 'repeat3', floatval($S['bonus_repeat_etb']), $ids, '');
                if ($res['status'] !== '') { $sum['repeat']++; if ($res['status'] === 'held') $sum['held']++; }
            } catch (Exception $e) { $sum['errors']++; error_log('[ETEL][fa_run] repeat ref ' . $ref['id'] . ': ' . $e->getMessage()); }
        }
    }

    // ---- 3. pending: cancel if a trip was reversed, otherwise release to payable once the hold has passed ----
    $q = $pdo->query("SELECT * FROM fa_earnings WHERE status = 'pending' ORDER BY id ASC LIMIT 500");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
        try {
            if (!fa_earning_rides_ok($pdo, $e)) {
                $u = $pdo->prepare("UPDATE fa_earnings SET status='clawed_back', reason_code='RIDE_REVERSED', decided_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'");
                $u->execute(array($e['id']));
                if ($u->rowCount()) {
                    fa_audit($pdo, 'system', 0, 'earning_clawed_back', 'earning', $e['id'], 'RIDE_REVERSED', array('agent' => intval($e['agent_id']), 'amount' => $e['amount']));
                    fa_notify($pdo, $e['agent_id'], 'bonus_reversed', array('earning' => intval($e['id']), 'amount' => $e['amount'], 'reason' => 'RIDE_REVERSED'));
                    $sum['clawed_back']++;
                }
            } elseif (strtotime($e['payable_at']) <= time()) {
                $u = $pdo->prepare("UPDATE fa_earnings SET status='payable', updated_at=NOW() WHERE id=? AND status='pending'");
                $u->execute(array($e['id']));
                if ($u->rowCount()) {
                    fa_audit($pdo, 'system', 0, 'earning_payable', 'earning', $e['id'], '', array('agent' => intval($e['agent_id']), 'amount' => $e['amount']));
                    fa_notify($pdo, $e['agent_id'], 'bonus_payable', array('earning' => intval($e['id']), 'amount' => $e['amount']));
                    $sum['payable']++;
                }
            }
        } catch (Exception $ex) { $sum['errors']++; error_log('[ETEL][fa_run] pending ' . $e['id'] . ': ' . $ex->getMessage()); }
    }

    // ---- 4. payable / paid inside the clawback window whose trip was reversed ----
    $q = $pdo->query("SELECT * FROM fa_earnings WHERE kind <> 'clawback' AND status IN ('payable','paid')
                      AND earned_at > DATE_SUB(NOW(), INTERVAL " . intval($S['clawback_window_days']) . " DAY) ORDER BY id ASC LIMIT 500");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
        try {
            if (fa_earning_rides_ok($pdo, $e)) continue;
            if ($e['status'] === 'payable' && intval($e['payout_item_id']) === 0) {
                $u = $pdo->prepare("UPDATE fa_earnings SET status='clawed_back', reason_code='RIDE_REVERSED', decided_at=NOW(), updated_at=NOW() WHERE id=? AND status='payable' AND payout_item_id=0");
                $u->execute(array($e['id']));
                if ($u->rowCount()) {
                    fa_audit($pdo, 'system', 0, 'earning_clawed_back', 'earning', $e['id'], 'RIDE_REVERSED', array('agent' => intval($e['agent_id']), 'amount' => $e['amount']));
                    fa_notify($pdo, $e['agent_id'], 'bonus_reversed', array('earning' => intval($e['id']), 'amount' => $e['amount'], 'reason' => 'RIDE_REVERSED'));
                    $sum['clawed_back']++;
                }
            } elseif ($e['status'] === 'paid') {
                $i = $pdo->prepare("INSERT IGNORE INTO fa_earnings (referral_id, agent_id, kind, parent_id, amount, ride_id, ride_ids, status, reason_code, earned_at, payable_at, created_at, updated_at)
                                    VALUES (?,?,'clawback',?,?,?,?,'payable','RIDE_REVERSED',NOW(),NOW(),NOW(),NOW())");
                $i->execute(array($e['referral_id'], $e['agent_id'], $e['id'], -1 * floatval($e['amount']), $e['ride_id'], $e['ride_ids']));
                if ($i->rowCount()) {
                    fa_audit($pdo, 'system', 0, 'clawback_created', 'earning', $e['id'], 'RIDE_REVERSED', array('agent' => intval($e['agent_id']), 'amount' => -1 * floatval($e['amount'])));
                    fa_notify($pdo, $e['agent_id'], 'bonus_reversed', array('earning' => intval($e['id']), 'amount' => $e['amount'], 'reason' => 'RIDE_REVERSED'));
                    $sum['clawed_back']++;
                }
            }
        } catch (Exception $ex) { $sum['errors']++; error_log('[ETEL][fa_run] clawback ' . $e['id'] . ': ' . $ex->getMessage()); }
    }

    foreach (array_keys($touched) as $aid) { try { fa_agent_risk_rollup($pdo, $aid); } catch (Exception $e) {} }
    // tiers are quality-based and cheap to recompute; a failure here must never undo the earnings work above
    try { require_once __DIR__ . '/fa_insights.php'; $sum['tiers_changed'] = count(fa_recompute_tiers($pdo)['changed']); } catch (Exception $e) { error_log('[ETEL][fa_run] tiers: ' . $e->getMessage()); }
    try { $pdo->query("SELECT RELEASE_LOCK('fa_run_earnings')"); } catch (Exception $e) {}
    return $sum;
}

/* Month-to-date cost (everything earned this month that is not rejected or clawed back), a straight-line
   projection to month end, and whether the alert percentage of the monthly budget has been reached. */
function fa_budget_status($pdo) {
    $S = fa_settings($pdo);
    $spent = floatval($pdo->query("SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE status IN ('pending','payable','held','paid')
                                   AND earned_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn());
    $day = max(1, intval(date('j'))); $days = intval(date('t'));
    $proj = round($spent / $day * $days, 2);
    $budget = floatval($S['monthly_budget_etb']);
    $pct = $budget > 0 ? round($spent / $budget * 100, 1) : 0;
    return array('spent' => round($spent, 2), 'projected' => $proj, 'budget' => $budget, 'pct_used' => $pct,
                 'projected_pct' => $budget > 0 ? round($proj / $budget * 100, 1) : 0,
                 'alert' => ($budget > 0 && ($pct >= $S['budget_alert_pct'] || $proj >= $budget)));
}

}
