<?php
// ============================================================
// Live operations: what is happening right now, and how fast requests are being picked up. READ ONLY - it never changes a ride or an order.
//   now   : rides and orders waiting / on the way, drivers and couriers online and free, the oldest waits, and the requests that wait longest
//   speed : for today and for the last 7 days: requests, how many were taken, how long until someone took them (typical and slowest 1 in 10),
//           and for orders also how long until delivery
// Tables: rides, ride_drivers, orders, couriers (all already exist). A column or table that is missing on a server gives "no data", not an error.
// ============================================================
if (!function_exists('live_ops_snapshot')) {

/* Nearest-rank percentile of a list of numbers; null for an empty list. */
function live_ops_pct($values, $p) {
    $n = count($values);
    if ($n === 0) return null;
    sort($values);
    $i = (int) ceil(($p / 100) * $n) - 1;
    if ($i < 0) $i = 0; if ($i > $n - 1) $i = $n - 1;
    return $values[$i];
}

function live_ops_col($pdo, $table, $col) {
    try { return (bool) $pdo->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $col . "'")->fetch(); } catch (Exception $e) { return false; }
}

function live_ops_one($pdo, $sql, $args = array()) {
    try { $s = $pdo->prepare($sql); $s->execute($args); return intval($s->fetchColumn()); } catch (Exception $e) { return null; }
}

function live_ops_rows($pdo, $sql, $args = array()) {
    try { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return array(); }
}

function live_ops_secs_summary($vals) {
    return array('n' => count($vals), 'typical' => live_ops_pct($vals, 50), 'slow' => live_ops_pct($vals, 90));
}

/* $since: SQL condition on the created column, e.g. "created_at >= CURDATE()" */
function live_ops_rides_speed($pdo, $cond) {
    $req = live_ops_one($pdo, "SELECT COUNT(*) FROM rides WHERE " . $cond);
    $acc = live_ops_one($pdo, "SELECT COUNT(*) FROM rides WHERE assigned_at IS NOT NULL AND " . $cond);
    $gave = live_ops_one($pdo, "SELECT COUNT(*) FROM rides WHERE status = 'cancelled' AND assigned_at IS NULL AND " . $cond);
    $done = live_ops_one($pdo, "SELECT COUNT(*) FROM rides WHERE status = 'completed' AND " . $cond);
    $vals = array();
    foreach (live_ops_rows($pdo, "SELECT TIMESTAMPDIFF(SECOND, created_at, assigned_at) AS s FROM rides WHERE assigned_at IS NOT NULL AND " . $cond . " ORDER BY id DESC LIMIT 5000") as $r) {
        if ($r['s'] !== null && intval($r['s']) >= 0) $vals[] = intval($r['s']);
    }
    $t = live_ops_secs_summary($vals);
    return array('requests' => $req, 'taken' => $acc, 'gave_up' => $gave, 'completed' => $done,
                 'taken_pct' => ($req > 0 && $acc !== null) ? intval(round(100 * $acc / $req)) : null,
                 'secs_to_take' => $t);
}

function live_ops_orders_speed($pdo, $cond, $hasAssigned) {
    $placed = live_ops_one($pdo, "SELECT COUNT(*) FROM orders WHERE " . $cond);
    $cancelled = live_ops_one($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND " . $cond);
    $delivered = live_ops_one($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'delivered' AND " . $cond);
    $take = array(); $del = array(); $taken = null;
    if ($hasAssigned) {
        $taken = live_ops_one($pdo, "SELECT COUNT(*) FROM orders WHERE courier_assigned_at IS NOT NULL AND " . $cond);
        foreach (live_ops_rows($pdo, "SELECT TIMESTAMPDIFF(SECOND, placed_at, courier_assigned_at) AS s FROM orders WHERE courier_assigned_at IS NOT NULL AND " . $cond . " ORDER BY id DESC LIMIT 5000") as $r) {
            if ($r['s'] !== null && intval($r['s']) >= 0) $take[] = intval($r['s']);
        }
    }
    foreach (live_ops_rows($pdo, "SELECT TIMESTAMPDIFF(SECOND, placed_at, delivered_at) AS s FROM orders WHERE delivered_at IS NOT NULL AND " . $cond . " ORDER BY id DESC LIMIT 5000") as $r) {
        if ($r['s'] !== null && intval($r['s']) >= 0) $del[] = intval($r['s']);
    }
    return array('placed' => $placed, 'taken' => $taken, 'delivered' => $delivered, 'cancelled' => $cancelled,
                 'taken_pct' => ($placed > 0 && $taken !== null) ? intval(round(100 * $taken / $placed)) : null,
                 'secs_to_take' => live_ops_secs_summary($take), 'secs_to_deliver' => live_ops_secs_summary($del));
}

function live_ops_snapshot($pdo) {
    $hasAssigned = live_ops_col($pdo, 'orders', 'courier_assigned_at');
    $onTrip = "status IN ('accepted','arrived','in_progress')";
    $driversOnline = live_ops_one($pdo, "SELECT COUNT(*) FROM ride_drivers WHERE online = 1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $driversBusy = live_ops_one($pdo, "SELECT COUNT(DISTINCT rd.user_id) FROM ride_drivers rd JOIN rides r ON r.driver_id = rd.user_id AND r." . $onTrip . " WHERE rd.online = 1 AND rd.last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $couriersOnline = live_ops_one($pdo, "SELECT COUNT(*) FROM couriers WHERE status IN ('online','busy') AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $couriersFree = live_ops_one($pdo, "SELECT COUNT(*) FROM couriers WHERE status = 'online' AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $searching = live_ops_rows($pdo, "SELECT r.id, r.pickup_address, TIMESTAMPDIFF(MINUTE, r.created_at, NOW()) AS min, u.name AS passenger FROM rides r LEFT JOIN users u ON u.id = r.passenger_id WHERE r.status = 'searching' ORDER BY r.created_at ASC LIMIT 10");
    $searchingN = live_ops_one($pdo, "SELECT COUNT(*) FROM rides WHERE status = 'searching'");
    $waiting = live_ops_rows($pdo, "SELECT id, order_code, from_zone, to_zone, TIMESTAMPDIFF(MINUTE, placed_at, NOW()) AS min FROM orders WHERE status = 'pending' AND courier_id IS NULL ORDER BY placed_at ASC LIMIT 10");
    $waitingN = live_ops_one($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending' AND courier_id IS NULL");
    $wr = array(); foreach ($searching as $r) $wr[] = array('id' => intval($r['id']), 'minutes' => max(0, intval($r['min'])), 'where' => (string) $r['pickup_address'], 'who' => (string) $r['passenger']);
    $wo = array(); foreach ($waiting as $r) $wo[] = array('id' => intval($r['id']), 'code' => (string) $r['order_code'], 'minutes' => max(0, intval($r['min'])), 'where' => trim((string) $r['from_zone'] . ' > ' . (string) $r['to_zone']));
    $alerts = array();
    if (intval($searchingN) > 0 && intval($driversOnline) === 0) $alerts[] = array('level' => 'critical', 'text' => $searchingN . ' ride request(s) are waiting and no driver is online.');
    if (intval($waitingN) > 0 && intval($couriersOnline) === 0) $alerts[] = array('level' => 'critical', 'text' => $waitingN . ' order(s) are waiting for a courier and no courier is online.');
    if ($wr && $wr[0]['minutes'] >= 3) $alerts[] = array('level' => $wr[0]['minutes'] >= 6 ? 'critical' : 'warn', 'text' => 'A ride request has waited ' . $wr[0]['minutes'] . ' minutes for a driver.');
    if ($wo && $wo[0]['minutes'] >= 8) $alerts[] = array('level' => $wo[0]['minutes'] >= 15 ? 'critical' : 'warn', 'text' => 'An order has waited ' . $wo[0]['minutes'] . ' minutes for a courier.');
    return array(
        'generated_at' => date('Y-m-d H:i:s'),
        'now' => array(
            'rides' => array('searching' => $searchingN, 'on_the_way' => live_ops_one($pdo, "SELECT COUNT(*) FROM rides WHERE status IN ('accepted','arrived')"), 'in_progress' => live_ops_one($pdo, "SELECT COUNT(*) FROM rides WHERE status = 'in_progress'"),
                             'oldest_wait_min' => $wr ? $wr[0]['minutes'] : 0, 'waiting' => $wr),
            'drivers' => array('online' => $driversOnline, 'busy' => $driversBusy, 'free' => ($driversOnline === null || $driversBusy === null) ? null : max(0, $driversOnline - $driversBusy)),
            'orders' => array('waiting' => $waitingN, 'assigned' => live_ops_one($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'assigned'"), 'on_the_way' => live_ops_one($pdo, "SELECT COUNT(*) FROM orders WHERE status IN ('picked_up','in_transit')"),
                              'oldest_wait_min' => $wo ? $wo[0]['minutes'] : 0, 'waiting_list' => $wo),
            'couriers' => array('online' => $couriersOnline, 'free' => $couriersFree),
        ),
        'alerts' => $alerts,
        'speed' => array(
            'today' => array('rides' => live_ops_rides_speed($pdo, "created_at >= CURDATE()"), 'orders' => live_ops_orders_speed($pdo, "placed_at >= CURDATE()", $hasAssigned)),
            'week' => array('rides' => live_ops_rides_speed($pdo, "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"), 'orders' => live_ops_orders_speed($pdo, "placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", $hasAssigned)),
        ),
        'has_order_assign_time' => $hasAssigned,
    );
}

}
