<?php
/**
 * Courier weekly statement: one week (Monday to Sunday) of delivered orders with what the courier was paid for each one
 * (courier pay, which is the delivery fee unless pay by distance is on, plus the customer's tip) and totals per day.
 * Same rule as courier_earnings.php: the order total (the food) is never counted. The week is picked the same way as the
 * ride drivers' statement (up to 12 weeks back).
 */

require_once __DIR__ . '/courier_earnings.php';
require_once __DIR__ . '/ride_statement.php';

/** Statement of the courier $courierId (couriers.id) for the week that starts on Monday $weekStart (Y-m-d). */
function etel_courier_statement($pdo, $courierId, $weekStart) {
    etel_ensure_tip_column($pdo);
    $end = date('Y-m-d', strtotime($weekStart . ' +7 days'));
    $q = $pdo->prepare(
        "SELECT id, order_code, from_zone, to_zone, delivery_fee, courier_pay, courier_tip,
                COALESCE(delivered_at, placed_at) AS done_at, DATE(COALESCE(delivered_at, placed_at)) AS day
         FROM orders
         WHERE courier_id = ? AND status = 'delivered'
           AND COALESCE(delivered_at, placed_at) >= ? AND COALESCE(delivered_at, placed_at) < ?
         ORDER BY COALESCE(delivered_at, placed_at) DESC LIMIT 500");
    $q->execute(array($courierId, $weekStart, $end));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    $days = array();
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
        $days[$d] = array('date' => $d, 'deliveries' => 0, 'earned' => 0.0);
    }
    $t = array('deliveries' => 0, 'pay' => 0.0, 'tips' => 0.0, 'earned' => 0.0);
    $orders = array();
    foreach ($rows as $r) {
        $pay = ($r['courier_pay'] !== null && $r['courier_pay'] !== '') ? floatval($r['courier_pay']) : floatval($r['delivery_fee']);
        $tip = floatval($r['courier_tip']);
        $sum = round($pay + $tip, 2);
        $t['deliveries']++; $t['pay'] += $pay; $t['tips'] += $tip; $t['earned'] += $sum;
        if (isset($days[$r['day']])) { $days[$r['day']]['deliveries']++; $days[$r['day']]['earned'] += $sum; }
        $orders[] = array(
            'id' => intval($r['id']), 'order_code' => $r['order_code'], 'done_at' => $r['done_at'],
            'from' => $r['from_zone'], 'to' => $r['to_zone'],
            'pay' => round($pay, 2), 'tip' => round($tip, 2), 'earned' => $sum
        );
    }
    foreach ($t as $k => $v) if ($k !== 'deliveries') $t[$k] = round($v, 2);
    foreach ($days as $k => $v) $days[$k]['earned'] = round($v['earned'], 2);

    return array(
        'week_start' => $weekStart, 'week_end' => date('Y-m-d', strtotime($weekStart . ' +6 days')),
        'totals' => $t, 'per_delivery' => $t['deliveries'] > 0 ? round($t['earned'] / $t['deliveries'], 2) : null,
        'days' => array_values($days), 'orders' => $orders
    );
}

/** Picks the week from the request ($want: any Y-m-d, default today) and returns array(statement) or array(null, error message). */
function etel_courier_statement_for($pdo, $courierId, $want) {
    $today = date('Y-m-d');
    if ($want === null || $want === '') $want = $today;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $want, $m) || !checkdate(intval($m[2]), intval($m[3]), intval($m[1])) || rides_statement_monday($want) === null) return array(null, 'Invalid date format, expected YYYY-MM-DD');
    $thisMonday = rides_statement_monday($today);
    $monday = rides_statement_monday($want);
    if ($monday > $thisMonday) $monday = $thisMonday;
    $oldest = date('Y-m-d', strtotime($thisMonday . ' -84 days'));
    if ($monday < $oldest) return array(null, 'Statements go back 12 weeks');
    $st = etel_courier_statement($pdo, $courierId, $monday);
    $st['prev_week'] = $monday > $oldest ? date('Y-m-d', strtotime($monday . ' -7 days')) : null;
    $st['next_week'] = $monday < $thisMonday ? date('Y-m-d', strtotime($monday . ' +7 days')) : null;
    return array($st, '');
}
