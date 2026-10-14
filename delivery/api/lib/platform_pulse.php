<?php
// Platform pulse: one read-only snapshot for the admin Dashboard.
//   - period KPIs (orders, sales, delivered/cancelled, new + active customers, average delivery time,
//     discounts given) with the previous period of the same length for comparison,
//   - "needs attention" counts, live activity, top shops, and a look at promo codes / loyalty points /
//     reviews / order chat.
// Every block is independent: a table that doesn't exist yet (promo codes, chat, ...) just yields zeros,
// it never breaks the dashboard. Money definitions: "sales" = fare_total of DELIVERED orders (what customers
// paid, incl. fees); "item sales" = subtotal of delivered orders.

function etel_pulse_one($pdo, $sql, $params = array(), $default = 0) {
    try { $s = $pdo->prepare($sql); $s->execute($params); $v = $s->fetchColumn(); return $v === false || $v === null ? $default : $v; }
    catch (Exception $e) { return $default; }
}
function etel_pulse_rows($pdo, $sql, $params = array()) {
    try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return array(); }
}

// Pure: % change from $prev to $cur, or null when it can't be expressed (no previous value).
function etel_pulse_delta($cur, $prev) {
    $cur = floatval($cur); $prev = floatval($prev);
    if ($prev <= 0) return $cur > 0 ? null : 0;
    return (int)round(($cur - $prev) / $prev * 100);
}

// A delivery time only counts when it is believable: delivered after it was placed and within this many minutes.
// Orders marked delivered hours later (a courier forgot to tap, an admin closed it afterwards) say nothing about
// speed and made the average read 397 minutes. They are left out and counted separately as 'avg_skipped'.
define('ETEL_PULSE_MAX_DELIVERY_MIN', 180);

require_once __DIR__ . '/ratings_report.php';

function etel_pulse_period($pdo, $from, $to) {
    $base = "FROM orders o WHERE o.placed_at >= $from AND o.placed_at < $to";
    $r = array();
    $row = null;
    try {
        $s = $pdo->query("SELECT COUNT(*) AS orders,
                SUM(CASE WHEN o.status='delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
                COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.fare_total ELSE 0 END),0) AS sales,
                COALESCE(SUM(CASE WHEN o.status='delivered' THEN COALESCE(o.subtotal, o.fare_total) ELSE 0 END),0) AS item_sales,
                COALESCE(SUM(CASE WHEN o.status='delivered' THEN COALESCE(o.service_charge,0) ELSE 0 END),0) AS service_fees,
                COUNT(DISTINCT o.customer_id) AS active_customers,
                AVG(CASE WHEN o.status='delivered' AND o.delivered_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, o.placed_at, o.delivered_at) BETWEEN 1 AND " . ETEL_PULSE_MAX_DELIVERY_MIN . " THEN TIMESTAMPDIFF(MINUTE, o.placed_at, o.delivered_at) END) AS avg_minutes,
                SUM(CASE WHEN o.status='delivered' AND o.delivered_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, o.placed_at, o.delivered_at) BETWEEN 1 AND " . ETEL_PULSE_MAX_DELIVERY_MIN . " THEN 1 ELSE 0 END) AS avg_n,
                SUM(CASE WHEN o.status='delivered' AND o.delivered_at IS NOT NULL AND NOT (TIMESTAMPDIFF(MINUTE, o.placed_at, o.delivered_at) BETWEEN 1 AND " . ETEL_PULSE_MAX_DELIVERY_MIN . ") THEN 1 ELSE 0 END) AS avg_skipped
            $base");
        $row = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $row = array(); }
    $r['orders']           = intval(isset($row['orders']) ? $row['orders'] : 0);
    $r['delivered']        = intval(isset($row['delivered']) ? $row['delivered'] : 0);
    $r['cancelled']        = intval(isset($row['cancelled']) ? $row['cancelled'] : 0);
    $r['sales']            = round(floatval(isset($row['sales']) ? $row['sales'] : 0), 2);
    $r['item_sales']       = round(floatval(isset($row['item_sales']) ? $row['item_sales'] : 0), 2);
    $r['service_fees']     = round(floatval(isset($row['service_fees']) ? $row['service_fees'] : 0), 2);
    $r['active_customers'] = intval(isset($row['active_customers']) ? $row['active_customers'] : 0);
    $r['avg_n']            = intval(isset($row['avg_n']) ? $row['avg_n'] : 0);
    $r['avg_skipped']      = intval(isset($row['avg_skipped']) ? $row['avg_skipped'] : 0);
    $r['avg_minutes']      = (isset($row['avg_minutes']) && $row['avg_minutes'] !== null) ? (int)round(floatval($row['avg_minutes'])) : null;
    // Real courier timings (need orders.picked_up_at, migration 075 / api/lib/order_stamps.php; empty until orders carry it).
    //   trip = picked up -> delivered     wait = courier accepted -> picked up
    $r['avg_trip'] = null; $r['avg_trip_n'] = 0; $r['avg_wait'] = null; $r['avg_wait_n'] = 0;
    try {
        $tripOk = "o.status='delivered' AND o.picked_up_at IS NOT NULL AND o.delivered_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, o.picked_up_at, o.delivered_at) BETWEEN 1 AND " . ETEL_PULSE_MAX_DELIVERY_MIN;
        $waitOk = "o.picked_up_at IS NOT NULL AND o.courier_accepted_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, o.courier_accepted_at, o.picked_up_at) BETWEEN 0 AND " . ETEL_PULSE_MAX_DELIVERY_MIN;
        $t = $pdo->query("SELECT AVG(CASE WHEN $tripOk THEN TIMESTAMPDIFF(MINUTE, o.picked_up_at, o.delivered_at) END) AS trip,
                SUM(CASE WHEN $tripOk THEN 1 ELSE 0 END) AS trip_n,
                AVG(CASE WHEN $waitOk THEN TIMESTAMPDIFF(MINUTE, o.courier_accepted_at, o.picked_up_at) END) AS wait,
                SUM(CASE WHEN $waitOk THEN 1 ELSE 0 END) AS wait_n
            $base")->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            $r['avg_trip'] = ($t['trip'] !== null) ? (int) round(floatval($t['trip'])) : null; $r['avg_trip_n'] = intval($t['trip_n']);
            $r['avg_wait'] = ($t['wait'] !== null) ? (int) round(floatval($t['wait'])) : null; $r['avg_wait_n'] = intval($t['wait_n']);
        }
    } catch (Exception $e) { /* picked_up_at not created yet: nothing to measure */ }
    // discounts given on delivered orders (columns exist once promo codes / loyalty are in use)
    $r['discounts'] = round(floatval(etel_pulse_one($pdo, "SELECT COALESCE(SUM(COALESCE(o.discount,0) + COALESCE(o.points_discount,0)),0) $base AND o.status='delivered'")), 2);
    $r['new_customers'] = intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM users u WHERE u.role='customer' AND u.created_at >= $from AND u.created_at < $to"));
    return $r;
}

function etel_platform_pulse($pdo, $days) {
    $days = in_array(intval($days), array(1, 7, 30), true) ? intval($days) : 7;
    $curFrom  = 'DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY)';
    $prevFrom = 'DATE_SUB(CURDATE(), INTERVAL ' . (2 * $days - 1) . ' DAY)';
    $tomorrow = 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)';

    $cur  = etel_pulse_period($pdo, $curFrom, $tomorrow);
    $prev = etel_pulse_period($pdo, $prevFrom, $curFrom);

    $attention = array(
        'pending_shops'    => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM service_givers WHERE status = 'pending'")),
        'open_issues'      => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM order_issues WHERE status = 'open'")),
        'pending_payments' => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM bank_payments WHERE status = 'pending'"))
                            + intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM telebirr_payments WHERE status IN ('pending','processing') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")),
        'stuck_orders'     => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM orders WHERE status IN ('pending','assigned') AND placed_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE) AND placed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")),
        // a courier accepted the order but it is still not picked up after 30 minutes (the courier may be waiting at a slow shop, or gone)
        // customers who rated 1-2 stars (or the courier/food 1 star) in the last 7 days; opens Admin > Ratings
        'low_ratings'      => (function_exists('etel_low_ratings_count') ? etel_low_ratings_count($pdo, 7) : 0),
        'accepted_idle'    => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'assigned' AND courier_accepted_at IS NOT NULL AND courier_accepted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND placed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")),
    );

    $live = array(
        'in_flight'       => array(),
        'couriers_online' => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM couriers WHERE status = 'online' AND active = 1")),
        'couriers_total'  => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM couriers WHERE active = 1")),
        'shops_online'    => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM service_givers WHERE status = 'verified' AND is_online = 1")),
        'shops_total'     => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM service_givers WHERE status = 'verified'")),
    );
    foreach (etel_pulse_rows($pdo, "SELECT status, COUNT(*) AS c FROM orders WHERE status IN ('pending','assigned','picked_up','in_transit') AND placed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY status") as $r) {
        $live['in_flight'][$r['status']] = intval($r['c']);
    }

    $top = array();
    foreach (etel_pulse_rows($pdo, "SELECT sg.id, sg.business_name, COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN o.status='delivered' THEN COALESCE(o.subtotal, o.fare_total) ELSE 0 END),0) AS sales,
                sg.average_rating AS rating
            FROM orders o JOIN order_assignments oa ON oa.order_id = o.id JOIN service_givers sg ON sg.id = oa.service_giver_id
            WHERE o.placed_at >= $curFrom AND o.placed_at < $tomorrow AND o.status <> 'cancelled'
            GROUP BY sg.id, sg.business_name, sg.average_rating ORDER BY sales DESC, orders DESC LIMIT 5") as $r) {
        $top[] = array('id' => intval($r['id']), 'name' => $r['business_name'], 'orders' => intval($r['orders']),
                       'sales' => round(floatval($r['sales']), 2), 'rating' => $r['rating'] !== null ? round(floatval($r['rating']), 1) : null);
    }

    $programs = array(
        'promo_uses'       => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM promo_redemptions WHERE created_at >= $curFrom")),
        'promo_given'      => round(floatval(etel_pulse_one($pdo, "SELECT COALESCE(SUM(discount),0) FROM promo_redemptions WHERE created_at >= $curFrom")), 2),
        'points_issued'    => intval(etel_pulse_one($pdo, "SELECT COALESCE(SUM(points),0) FROM loyalty_points WHERE kind = 'earn' AND created_at >= $curFrom")),
        'points_spent'     => abs(intval(etel_pulse_one($pdo, "SELECT COALESCE(SUM(points),0) FROM loyalty_points WHERE kind = 'redeem' AND created_at >= $curFrom"))),
        'reviews'          => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM order_ratings WHERE rated_at >= $curFrom")),
        'avg_rating'       => etel_pulse_one($pdo, "SELECT ROUND(AVG(stars),2) FROM order_ratings WHERE rated_at >= $curFrom", array(), null),
        'chat_messages'    => intval(etel_pulse_one($pdo, "SELECT COUNT(*) FROM order_messages WHERE created_at >= $curFrom")),
        'chat_orders'      => intval(etel_pulse_one($pdo, "SELECT COUNT(DISTINCT order_id) FROM order_messages WHERE created_at >= $curFrom")),
    );
    if ($programs['avg_rating'] !== null) $programs['avg_rating'] = round(floatval($programs['avg_rating']), 2);

    return array(
        'days' => $days, 'current' => $cur, 'previous' => $prev,
        'deltas' => array(
            'orders'           => etel_pulse_delta($cur['orders'], $prev['orders']),
            'sales'            => etel_pulse_delta($cur['sales'], $prev['sales']),
            'new_customers'    => etel_pulse_delta($cur['new_customers'], $prev['new_customers']),
            'active_customers' => etel_pulse_delta($cur['active_customers'], $prev['active_customers']),
        ),
        'attention' => $attention, 'live' => $live, 'top_shops' => $top, 'programs' => $programs,
    );
}
