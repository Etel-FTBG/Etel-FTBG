<?php
// Driver fuel management - lets a driver log real fuel purchases so
// their true fuel cost per km can be measured instead of always using
// the ~8 ETB/km flat estimate from the recruitment playbook (1,900
// ETB/10L, ~220-240km per tank). Falls back to that estimate only
// when a driver has no logged fuel data yet.
if (!defined('ETEL_FUEL_FALLBACK_RATE_PER_KM')) define('ETEL_FUEL_FALLBACK_RATE_PER_KM', 8.0);

$pdo = db();
$u = require_auth($pdo);

switch ($action) {

case 'driver_add_fuel_log': {
    $liters = isset($body['liters']) ? etel_validate_amount($body['liters'], false) : false;
    if ($liters === false || $liters <= 0) fail('liters must be a positive amount', 400);
    $cost = isset($body['cost_etb']) ? etel_validate_amount($body['cost_etb'], false) : false;
    if ($cost === false || $cost <= 0) fail('cost_etb must be a positive amount', 400);
    $odometer = isset($body['odometer_km']) && $body['odometer_km'] !== '' ? floatval($body['odometer_km']) : null;
    try {
        $s = $pdo->prepare('INSERT INTO driver_fuel_logs (driver_user_id, liters, cost_etb, odometer_km, logged_at) VALUES (?,?,?,?,NOW())');
        $s->execute(array($u['id'], $liters, $cost, $odometer));
        ok(array('message' => 'Fuel log added', 'id' => $pdo->lastInsertId()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save fuel log - has migration 038 been applied?', 500);
    }
    break;
}

case 'driver_list_fuel_logs': {
    try {
        $s = $pdo->prepare('SELECT id, liters, cost_etb, odometer_km, logged_at FROM driver_fuel_logs WHERE driver_user_id=? ORDER BY logged_at DESC LIMIT 30');
        $s->execute(array($u['id']));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load fuel logs', 500);
    }
    ok(array('logs' => $rows));
    break;
}

case 'driver_delete_fuel_log': {
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id is required', 400);
    try {
        // Scoped to driver_user_id so a driver can never delete
        // someone else's log by guessing an id.
        $pdo->prepare('DELETE FROM driver_fuel_logs WHERE id=? AND driver_user_id=?')->execute(array($id, $u['id']));
        ok(array('message' => 'Fuel log removed'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not delete fuel log', 500);
    }
    break;
}

case 'driver_get_fuel_summary': {
    // Real cost/km needs two odometer readings to measure a real
    // distance driven between fill-ups. With fewer than 2 logs (or no
    // odometer entered), fall back to the flat estimate rather than
    // computing a misleading number from insufficient data.
    $period = isset($body['period']) ? $body['period'] : 'month';
    if (!in_array($period, array('today', 'week', 'month'), true)) fail('period must be today, week, or month', 400);
    $sinceExpr = $period === 'today' ? 'CURDATE()' : ($period === 'week' ? 'DATE_SUB(CURDATE(), INTERVAL 6 DAY)' : 'DATE_SUB(CURDATE(), INTERVAL 29 DAY)');
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(liters),0) AS liters, COALESCE(SUM(cost_etb),0) AS cost, COUNT(*) AS n
            FROM driver_fuel_logs WHERE driver_user_id=? AND logged_at >= $sinceExpr");
        $s->execute(array($u['id']));
        $r = $s->fetch();

        // Real cost/km: use the oldest and newest odometer readings
        // with actual values, across ALL logs (not just this period),
        // so a brand-new driver isn't stuck with zero data just
        // because their two fill-ups happened to straddle a period
        // boundary.
        $odo = $pdo->prepare("SELECT odometer_km, logged_at FROM driver_fuel_logs
            WHERE driver_user_id=? AND odometer_km IS NOT NULL ORDER BY logged_at ASC");
        $odo->execute(array($u['id']));
        $odoRows = $odo->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load fuel summary - has migration 038 been applied?', 500);
    }

    $realCostPerKm = null;
    if (count($odoRows) >= 2) {
        $first = floatval($odoRows[0]['odometer_km']);
        $last = floatval($odoRows[count($odoRows) - 1]['odometer_km']);
        $kmDriven = $last - $first;
        // Total fuel cost across that same odometer span (all logs
        // from the first to last odometer reading), not just this
        // period's logs.
        $s2 = $pdo->prepare('SELECT COALESCE(SUM(cost_etb),0) AS total_cost FROM driver_fuel_logs
            WHERE driver_user_id=? AND logged_at >= ? AND logged_at <= (SELECT logged_at FROM driver_fuel_logs WHERE driver_user_id=? AND odometer_km IS NOT NULL ORDER BY logged_at DESC LIMIT 1)');
        $s2->execute(array($u['id'], $odoRows[0]['logged_at'], $u['id']));
        $totalCostAcrossSpan = floatval($s2->fetch()['total_cost']);
        if ($kmDriven > 0) $realCostPerKm = round($totalCostAcrossSpan / $kmDriven, 2);
    }

    ok(array(
        'period' => $period,
        'fuel_logs_count' => intval($r['n']),
        'liters_bought' => round(floatval($r['liters']), 2),
        'fuel_cost' => round(floatval($r['cost']), 2),
        'real_cost_per_km' => $realCostPerKm,
        'fallback_cost_per_km' => ETEL_FUEL_FALLBACK_RATE_PER_KM,
        'using_real_rate' => $realCostPerKm !== null,
        'note' => $realCostPerKm === null
            ? 'Log at least 2 fill-ups with your odometer reading to see your real cost per km instead of the ' . ETEL_FUEL_FALLBACK_RATE_PER_KM . ' ETB/km estimate.'
            : null,
    ));
    break;
}

}
