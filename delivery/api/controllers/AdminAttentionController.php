<?php
// ============================================================
// ETEL Delivery - Admin -> "Needs your attention" list for the dashboard (read only)
//   admin_attention {} -> { items: [ { key, label, count, page, filter, period, tone } ], all_clear }
// Any signed-in admin may ask; each line is only counted when the caller holds the capability that opens the page it links to
// (orders.view, users.view, service_givers.view). Staff who may only see Ride accounts get no Delivery lines.
// ============================================================

switch ($action) {

case 'admin_attention': {
    $pdo = db();
    $u = require_admin($pdo);
    $count = function ($sql) use ($pdo) { try { return intval($pdo->query($sql)->fetchColumn()); } catch (Exception $e) { return 0; } };
    $items = array();
    $add = function ($key, $label, $n, $page, $filter, $period, $tone) use (&$items) {
        if ($n > 0) $items[] = array('key' => $key, 'label' => $label, 'count' => $n, 'page' => $page, 'filter' => $filter, 'period' => $period, 'tone' => $tone);
    };
    if (!etel_is_ride_only($pdo, $u)) {
        if (user_has_capability($pdo, $u, 'orders.view')) {
            $oc = etel_table_columns($pdo, 'orders');
            $late = $count("SELECT COUNT(*) FROM orders WHERE (status = 'pending' AND placed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) OR (status IN ('assigned','picked_up','in_transit') AND placed_at < DATE_SUB(NOW(), INTERVAL 90 MINUTE))");
            $add('late_orders', 'Late orders', $late, 'orders', 'late', '7d', 'red');
            if (in_array('courier_id', $oc, true)) {
                $add('no_courier', 'Orders with no courier', $count("SELECT COUNT(*) FROM orders WHERE status IN ('pending','assigned') AND (courier_id IS NULL OR courier_id = 0) AND placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"), 'orders', 'unassigned', '7d', 'amber');
            }
            $add('stuck_confirms', 'Confirmations waiting 10+ min', $count("SELECT COUNT(*) FROM order_confirmations WHERE status = 'pending' AND requested_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"), 'orders', 'confirm', '7d', 'amber');
        }
        if (user_has_capability($pdo, $u, 'users.view')) {
            require_once __DIR__ . '/../lib/courier_approval.php';
            if (etel_courier_approval_ready($pdo)) {
                $add('couriers_pending', 'Couriers waiting for approval', $count("SELECT COUNT(*) FROM couriers WHERE approval_status = 'pending'"), 'couriers', 'pending', '', 'amber');
            }
        }
        if (user_has_capability($pdo, $u, 'service_givers.view')) {
            $add('sg_pending', 'Businesses waiting for verification', $count("SELECT COUNT(*) FROM service_givers WHERE status = 'pending'"), 'servicegivers', 'pending', '', 'amber');
        }
    }
    ok(array('items' => $items, 'all_clear' => count($items) === 0));
    break;
}

}
