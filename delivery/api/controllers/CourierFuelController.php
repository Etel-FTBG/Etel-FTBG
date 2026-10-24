<?php
// Courier fuel management - mirrors DriverFuelController.php exactly.
// Bicycle/on-foot couriers simply won't log anything here, which is
// fine - this is opt-in, not assumed for every courier.
if (!defined('ETEL_FUEL_FALLBACK_RATE_PER_KM')) define('ETEL_FUEL_FALLBACK_RATE_PER_KM', 8.0);

$pdo = db();
$u = require_courier($pdo);   // couriers only (a customer or shop account has no fuel log to keep)

switch ($action) {

case 'courier_add_fuel_log': {
    $liters = isset($body['liters']) ? etel_validate_amount($body['liters'], false) : false;
    if ($liters === false || $liters <= 0) fail('liters must be a positive amount', 400);
    $cost = isset($body['cost_etb']) ? etel_validate_amount($body['cost_etb'], false) : false;
    if ($cost === false || $cost <= 0) fail('cost_etb must be a positive amount', 400);
    $odometer = isset($body['odometer_km']) && $body['odometer_km'] !== '' ? floatval($body['odometer_km']) : null;
    // Sent from the offline queue: a resend of an entry already saved must not add it twice.
    if (!empty($body['client_req_id'])) {
        require_once __DIR__ . '/../lib/offline_sync.php';
        if (etel_offline_seen($pdo, $u['id'], $body['client_req_id'])) ok(array('message' => 'Fuel log added', 'duplicate' => true));
    }
    try {
        $s = $pdo->prepare('INSERT INTO courier_fuel_logs (courier_user_id, liters, cost_etb, odometer_km, logged_at) VALUES (?,?,?,?,NOW())');
        $s->execute(array($u['id'], $liters, $cost, $odometer));
        ok(array('message' => 'Fuel log added', 'id' => $pdo->lastInsertId()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save fuel log - has migration 044_courier_fuel_logs.sql been applied?', 500);
    }
    break;
}

case 'courier_list_fuel_logs': {
    try {
        $s = $pdo->prepare('SELECT id, liters, cost_etb, odometer_km, logged_at FROM courier_fuel_logs WHERE courier_user_id=? ORDER BY logged_at DESC LIMIT 30');
        $s->execute(array($u['id']));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load fuel logs', 500);
    }
    ok(array('logs' => $rows));
    break;
}

case 'courier_delete_fuel_log': {
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id is required', 400);
    try {
        $pdo->prepare('DELETE FROM courier_fuel_logs WHERE id=? AND courier_user_id=?')->execute(array($id, $u['id']));
        ok(array('message' => 'Fuel log removed'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not delete fuel log', 500);
    }
    break;
}

case 'courier_get_fuel_summary': {
    $period = isset($_GET['period']) ? $_GET['period'] : (isset($body['period']) ? $body['period'] : 'month');
    if (!in_array($period, array('today', 'week', 'month'), true)) fail('period must be today, week, or month', 400);
    $sinceExpr = $period === 'today' ? 'CURDATE()' : ($period === 'week' ? 'DATE_SUB(CURDATE(), INTERVAL 6 DAY)' : 'DATE_SUB(CURDATE(), INTERVAL 29 DAY)');
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(liters),0) AS liters, COALESCE(SUM(cost_etb),0) AS cost, COUNT(*) AS n
            FROM courier_fuel_logs WHERE courier_user_id=? AND logged_at >= $sinceExpr");
        $s->execute(array($u['id']));
        $r = $s->fetch();
        $odo = $pdo->prepare("SELECT odometer_km, logged_at FROM courier_fuel_logs
            WHERE courier_user_id=? AND odometer_km IS NOT NULL ORDER BY logged_at ASC");
        $odo->execute(array($u['id']));
        $odoRows = $odo->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load fuel summary - has migration 044_courier_fuel_logs.sql been applied?', 500);
    }
    $realCostPerKm = null;
    if (count($odoRows) >= 2) {
        $first = floatval($odoRows[0]['odometer_km']);
        $last = floatval($odoRows[count($odoRows) - 1]['odometer_km']);
        $kmDriven = $last - $first;
        $s2 = $pdo->prepare('SELECT COALESCE(SUM(cost_etb),0) AS total_cost FROM courier_fuel_logs
            WHERE courier_user_id=? AND logged_at >= ? AND logged_at <= (SELECT logged_at FROM courier_fuel_logs WHERE courier_user_id=? AND odometer_km IS NOT NULL ORDER BY logged_at DESC LIMIT 1)');
        $s2->execute(array($u['id'], $odoRows[0]['logged_at'], $u['id']));
        $totalCostAcrossSpan = floatval($s2->fetch()['total_cost']);
        if ($kmDriven > 0) $realCostPerKm = round($totalCostAcrossSpan / $kmDriven, 2);
    }
    ok(array(
        'period' => $period, 'fuel_logs_count' => intval($r['n']),
        'liters_bought' => round(floatval($r['liters']), 2), 'fuel_cost' => round(floatval($r['cost']), 2),
        'real_cost_per_km' => $realCostPerKm, 'fallback_cost_per_km' => ETEL_FUEL_FALLBACK_RATE_PER_KM,
        'using_real_rate' => $realCostPerKm !== null,
        'note' => $realCostPerKm === null
            ? 'Log at least 2 fill-ups with your odometer reading to see your real cost per km instead of the ' . ETEL_FUEL_FALLBACK_RATE_PER_KM . ' ETB/km estimate.'
            : null,
    ));
    break;
}

}
