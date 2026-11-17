<?php
// ============================================================
// ETEL Agent app - dashboard numbers.
//   fa_agent_dashboard   { days? 7 | 30 | 90 }   -> totals, previous-period totals and one row per day
// Read-only. Only for the signed-in, approved agent and only about that agent. No risk score, no fraud reason, no other agent.
// Money = fa_earnings rows that still count (pending, held, payable, paid); clawbacks are negative rows so they reduce the day.
// ============================================================
require_once __DIR__ . '/../lib/fa_core.php';

switch ($action) {
case 'fa_agent_dashboard': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db();
    $u = require_auth($pdo);
    $aq = $pdo->prepare('SELECT id, status FROM fa_agents WHERE user_id = ? LIMIT 1');
    $aq->execute(array(intval($u['id'])));
    $a = $aq->fetch(PDO::FETCH_ASSOC);
    if (!$a || $a['status'] !== 'approved') fail('You are not an approved agent', 403);
    $days = isset($body['days']) ? intval($body['days']) : 30;
    if ($days !== 7 && $days !== 90) $days = 30;
    $aid = intval($a['id']);
    $from = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' day'));      // twice the window: this period and the one before
    $rows = array();
    for ($i = 2 * $days - 1; $i >= 0; $i--) $rows[date('Y-m-d', strtotime('-' . $i . ' day'))] = array('earned' => 0.0, 'riders' => 0, 'qualified' => 0, 'bookings' => 0);
    $fill = function ($sql, $key, $isMoney) use ($pdo, $aid, $from, &$rows) {
        try {
            $q = $pdo->prepare($sql);
            $q->execute(array($aid, $from . ' 00:00:00'));
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
                if (!isset($rows[$r[0]])) continue;
                $rows[$r[0]][$key] = $isMoney ? round(floatval($r[1]), 2) : intval($r[1]);
            }
        } catch (Exception $e) { /* a table that is not migrated yet simply gives zeros */ }
    };
    $fill("SELECT DATE(earned_at), SUM(amount) FROM fa_earnings WHERE agent_id = ? AND earned_at >= ? AND status IN ('pending','held','payable','paid') GROUP BY DATE(earned_at)", 'earned', true);
    $fill("SELECT DATE(claimed_at), COUNT(*) FROM fa_referrals WHERE agent_id = ? AND claimed_at >= ? AND status <> 'rejected' GROUP BY DATE(claimed_at)", 'riders', false);
    $fill("SELECT DATE(first_ride_at), COUNT(*) FROM fa_referrals WHERE agent_id = ? AND first_ride_at >= ? AND status = 'qualified' GROUP BY DATE(first_ride_at)", 'qualified', false);
    $fill("SELECT DATE(created_at), COUNT(*) FROM fa_bookings WHERE agent_id = ? AND created_at >= ? GROUP BY DATE(created_at)", 'bookings', false);
    $keys = array_keys($rows);
    $cur = array('earned' => 0, 'riders' => 0, 'qualified' => 0, 'bookings' => 0);
    $prev = $cur; $series = array(); $best = null; $streak = 0; $run = 0;
    foreach ($keys as $idx => $d) {
        $r = $rows[$d];
        $isCur = $idx >= $days;
        foreach ($cur as $k => $_) { if ($isCur) $cur[$k] += $r[$k]; else $prev[$k] += $r[$k]; }
        if ($isCur) {
            $series[] = array('d' => $d, 'earned' => $r['earned'], 'riders' => $r['riders'], 'qualified' => $r['qualified'], 'bookings' => $r['bookings']);
            if ($best === null || $r['earned'] > $best['earned']) $best = array('d' => $d, 'earned' => $r['earned']);
        }
        $run = ($r['riders'] > 0 || $r['bookings'] > 0) ? $run + 1 : 0;       // days in a row with a new rider or a booking, up to the last day
        $streak = $run;
    }
    $today = $rows[end($keys)];
    foreach (array('earned') as $k) { $cur[$k] = round($cur[$k], 2); $prev[$k] = round($prev[$k], 2); }
    $conv = $cur['riders'] > 0 ? intval(round(100 * $cur['qualified'] / $cur['riders'])) : 0;
    ok(array('days' => $days, 'today' => $today, 'current' => $cur, 'previous' => $prev, 'conversion_pct' => $conv,
             'best_day' => ($best !== null && $best['earned'] > 0) ? $best : null, 'streak_days' => $streak, 'series' => $series,
             'generated_at' => date('Y-m-d H:i:s')));
    break;
}

}
