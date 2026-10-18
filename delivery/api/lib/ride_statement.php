<?php
// Driver earnings: what one trip paid, and the weekly statement.
//
// Nothing here moves money. The numbers are worked out from the same rows and the same 5% rate that
// rides_complete_and_pay() (lib/ride_fare.php) used, so the statement always agrees with the balances:
//   gross      = what the rider paid + the promo discount Etel funded
//   commission = 5% of gross
//   you keep   = gross - commission

if (!function_exists('rides_trip_money')) {

// same rate as rides_complete_and_pay() in lib/ride_fare.php
function rides_commission_rate() { return 0.05; }

// $r needs: fare_paid, promo_discount (may be missing), payment_method, wallet_tx_ref (may be missing).
function rides_trip_money($r) {
    $paid = round(floatval(isset($r['fare_paid']) ? $r['fare_paid'] : 0), 2);
    $promo = round(floatval(isset($r['promo_discount']) ? $r['promo_discount'] : 0), 2);
    $gross = round($paid + $promo, 2);
    $commission = round($gross * rides_commission_rate(), 2);
    $method = isset($r['payment_method']) ? strtolower((string)$r['payment_method']) : 'cash';
    $etel = ($method === 'wallet') || ($method === 'chapa' && !empty($r['wallet_tx_ref']));
    return array(
        'rider_paid'    => $paid,
        'promo'         => $promo,
        'gross'         => $gross,
        'commission'    => $commission,
        'you_keep'      => round($gross - $commission, 2),
        'collected_by'  => $etel ? 'etel' : 'cash'
    );
}

// rides.wallet_tx_ref is added the first time a platform-paid trip needs it, so an install can lack it: select NULL then.
function rides_statement_ref_col($pdo) {
    static $col = null;
    if ($col !== null) return $col;
    $col = 'NULL AS wallet_tx_ref';
    try {
        if ($pdo->query("SHOW COLUMNS FROM `rides` LIKE 'wallet_tx_ref'")->fetch()) $col = 'r.wallet_tx_ref';
    } catch (Exception $e) { /* keep NULL */ }
    return $col;
}

// Monday (Y-m-d) of the week that contains $ymd.
function rides_statement_monday($ymd) {
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$d) return null;
    $dow = intval($d->format('N'));   // 1 = Monday
    if ($dow > 1) $d->modify('-' . ($dow - 1) . ' days');
    return $d->format('Y-m-d');
}

// One driver's statement for the week starting on Monday $weekStart.
function rides_statement_week($pdo, $driverId, $weekStart) {
    $end = date('Y-m-d', strtotime($weekStart . ' +7 days'));
    $q = $pdo->prepare(
        "SELECT r.id, r.pickup_address, r.dropoff_address, r.distance_km, r.traveled_km, r.open_trip, r.fare_paid, r.promo_code,
                r.promo_discount, r.payment_method, " . rides_statement_ref_col($pdo) . ", r.completed_at, DATE(r.completed_at) AS day
         FROM rides r
         WHERE r.driver_id=? AND r.status='completed' AND r.completed_at >= ? AND r.completed_at < ?
         ORDER BY r.completed_at DESC LIMIT 500");
    $q->execute(array($driverId, $weekStart, $end));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    $days = array();
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
        $days[$d] = array('date' => $d, 'trips' => 0, 'gross' => 0.0, 'you_keep' => 0.0);
    }
    $t = array('trips' => 0, 'rider_paid' => 0.0, 'promo' => 0.0, 'gross' => 0.0, 'commission' => 0.0, 'you_keep' => 0.0,
               'cash_collected' => 0.0, 'etel_collected' => 0.0, 'km' => 0.0);
    $trips = array();
    foreach ($rows as $r) {
        $m = rides_trip_money($r);
        $t['trips']++;
        $t['rider_paid'] += $m['rider_paid']; $t['promo'] += $m['promo']; $t['gross'] += $m['gross'];
        $t['commission'] += $m['commission']; $t['you_keep'] += $m['you_keep'];
        if ($m['collected_by'] === 'cash') $t['cash_collected'] += $m['rider_paid']; else $t['etel_collected'] += $m['rider_paid'];
        $t['km'] += floatval(intval($r['open_trip']) === 1 ? $r['traveled_km'] : $r['distance_km']);
        $day = $r['day'];
        if (isset($days[$day])) {
            $days[$day]['trips']++; $days[$day]['gross'] += $m['gross']; $days[$day]['you_keep'] += $m['you_keep'];
        }
        $trips[] = array_merge(array(
            'id' => intval($r['id']), 'completed_at' => $r['completed_at'], 'pickup' => $r['pickup_address'],
            'dropoff' => $r['dropoff_address'], 'payment_method' => $r['payment_method'], 'promo_code' => $r['promo_code']
        ), $m);
    }
    foreach ($t as $k => $v) if ($k !== 'trips') $t[$k] = round($v, 2);
    foreach ($days as $k => $v) { $days[$k]['gross'] = round($v['gross'], 2); $days[$k]['you_keep'] = round($v['you_keep'], 2); }

    // tips (approved by Etel in this week) are the driver's in full
    $tips = 0.0;
    try {
        require_once __DIR__ . '/ride_tip.php';
        rides_tip_ensure($pdo);
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM ride_tips WHERE driver_id=? AND status='approved' AND reviewed_at >= ? AND reviewed_at < ?");
        $s->execute(array($driverId, $weekStart, $end));
        $tips = round(floatval($s->fetchColumn()), 2);
    } catch (Exception $e) { /* tips optional */ }

    $hours = 0.0;
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN ended_at IS NOT NULL THEN online_seconds ELSE TIMESTAMPDIFF(SECOND, started_at, NOW()) END),0)
                            FROM driver_shifts WHERE driver_user_id=? AND started_at >= ? AND started_at < ?");
        $s->execute(array($driverId, $weekStart, $end));
        $hours = round(floatval($s->fetchColumn()) / 3600, 2);
    } catch (Exception $e) { /* shifts optional */ }

    return array(
        'week_start' => $weekStart, 'week_end' => date('Y-m-d', strtotime($weekStart . ' +6 days')),
        'commission_rate' => rides_commission_rate(), 'totals' => $t, 'tips' => $tips,
        'total_with_tips' => round($t['you_keep'] + $tips, 2), 'online_hours' => $hours,
        'per_hour' => $hours > 0 ? round(($t['you_keep'] + $tips) / $hours, 2) : null,
        'days' => array_values($days), 'trips' => $trips
    );
}

}
