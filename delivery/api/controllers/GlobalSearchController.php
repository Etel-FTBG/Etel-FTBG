<?php
// ============================================================
// ETEL Delivery - Admin -> Search everything from one box (read only)
//   admin_global_search { q, per? } -> { q, groups: [ { key, label, page, items: [ { id, title, sub, badge, q, period } ] } ] }
// Any signed-in admin may ask. Each group is only searched when the caller holds the capability that opens its page
// (orders.view, users.view, service_givers.view, items.view, rides.view); staff who may only see Ride accounts get Ride results only.
// Nothing is written. A search shorter than 2 characters returns nothing. Every group is searched on its own so a missing table
// (an install that never ran a migration) just leaves that group out.
// ============================================================

switch ($action) {

case 'admin_global_search': {
    $pdo = db();
    $u = require_admin($pdo);
    $q = isset($body['q']) ? trim((string) $body['q']) : '';
    if (function_exists('mb_substr')) $q = mb_substr($q, 0, 60, 'UTF-8'); else $q = substr($q, 0, 60);
    $per = max(1, min(8, isset($body['per']) ? intval($body['per']) : 5));
    $groups = array();
    if (strlen($q) < 2) { ok(array('q' => $q, 'groups' => $groups)); break; }
    $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
    $num  = ctype_digit($q) ? intval($q) : 0;
    $rideOnly = etel_is_ride_only($pdo, $u);
    $run = function ($sql, $params) use ($pdo) {
        try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return array(); }
    };
    $add = function ($key, $label, $page, $items) use (&$groups) { if ($items) $groups[] = array('key' => $key, 'label' => $label, 'page' => $page, 'items' => $items); };
    $money = function ($n) { return 'ETB ' . number_format(floatval($n), 0); };

    if (!$rideOnly && user_has_capability($pdo, $u, 'orders.view')) {
        $rows = $run("SELECT o.id, o.order_code, o.status, o.fare_total, o.placed_at, o.recipient_name, cu.name AS customer_name, cu.phone AS customer_phone
                      FROM orders o LEFT JOIN users cu ON cu.id = o.customer_id
                      WHERE o.order_code LIKE ? OR o.recipient_name LIKE ? OR o.recipient_phone LIKE ? OR cu.name LIKE ? OR cu.phone LIKE ? OR o.id = ?
                      ORDER BY o.placed_at DESC, o.id DESC LIMIT $per", array($like, $like, $like, $like, $like, $num));
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('id' => intval($r['id']), 'title' => (string) $r['order_code'], 'sub' => trim((string) $r['customer_name'] . ' ' . (string) $r['customer_phone']),
                             'badge' => (string) $r['status'], 'meta' => $money($r['fare_total']), 'q' => (string) $r['order_code'], 'period' => 'all');
        }
        $add('orders', 'Orders', 'orders', $items);
    }
    if (!$rideOnly && user_has_capability($pdo, $u, 'users.view')) {
        $rows = $run("SELECT c.id, c.courier_code, c.plate_number, c.status, u.name, u.phone FROM couriers c LEFT JOIN users u ON u.id = c.user_id
                      WHERE c.courier_code LIKE ? OR c.plate_number LIKE ? OR u.name LIKE ? OR u.phone LIKE ? ORDER BY u.name ASC LIMIT $per", array($like, $like, $like, $like));
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('id' => intval($r['id']), 'title' => (string) $r['name'], 'sub' => trim((string) $r['courier_code'] . ' · ' . (string) $r['phone']),
                             'badge' => (string) $r['status'], 'meta' => (string) $r['plate_number'], 'q' => (string) ($r['name'] !== '' ? $r['name'] : $r['courier_code']), 'period' => '');
        }
        $add('couriers', 'Couriers', 'couriers', $items);
        $rows = $run("SELECT id, name, phone, email, role FROM users WHERE role = 'customer' AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR id = ?) ORDER BY id DESC LIMIT $per", array($like, $like, $like, $num));
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('id' => intval($r['id']), 'title' => (string) $r['name'], 'sub' => trim((string) $r['phone'] . ' ' . (string) $r['email']), 'badge' => 'customer', 'meta' => '#' . intval($r['id']),
                             'q' => (string) $r['phone'], 'period' => '');
        }
        $add('customers', 'Customers', 'customers', $items);
    }
    if (!$rideOnly && user_has_capability($pdo, $u, 'service_givers.view')) {
        $rows = $run("SELECT sg.id, sg.service_giver_code, sg.business_name, sg.service_type, sg.status, sg.zone FROM service_givers sg LEFT JOIN users u ON u.id = sg.user_id
                      WHERE sg.business_name LIKE ? OR sg.service_giver_code LIKE ? OR u.phone LIKE ? ORDER BY sg.business_name ASC LIMIT $per", array($like, $like, $like));
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('id' => intval($r['id']), 'title' => (string) $r['business_name'], 'sub' => trim((string) $r['service_giver_code'] . ' · ' . (string) $r['zone']),
                             'badge' => (string) $r['status'], 'meta' => (string) $r['service_type'], 'q' => (string) $r['business_name'], 'period' => '');
        }
        $add('servicegivers', 'Businesses', 'servicegivers', $items);
    }
    if (!$rideOnly && user_has_capability($pdo, $u, 'items.view')) {
        $rows = $run("SELECT id, item_code, name, category, price, is_available FROM items WHERE name LIKE ? OR item_code LIKE ? ORDER BY name ASC LIMIT $per", array($like, $like));
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('id' => intval($r['id']), 'title' => (string) $r['name'], 'sub' => (string) $r['item_code'] . ' · ' . (string) $r['category'],
                             'badge' => intval($r['is_available']) ? 'available' : 'hidden', 'meta' => $money($r['price']), 'q' => (string) $r['name'], 'period' => '');
        }
        $add('items', 'Items', 'items', $items);
        $rows = $run("SELECT id, code, description, discount_type, value, is_active FROM promo_codes WHERE code LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT $per", array($like, $like));
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('id' => intval($r['id']), 'title' => (string) $r['code'], 'sub' => (string) $r['description'], 'badge' => intval($r['is_active']) ? 'on' : 'off',
                             'meta' => $r['discount_type'] === 'percent' ? (floatval($r['value']) . '%') : ($r['discount_type'] === 'fixed' ? $money($r['value']) : 'free delivery'), 'q' => (string) $r['code'], 'period' => '');
        }
        $add('promos', 'Promo codes', 'promos', $items);
    }
    if (user_has_capability($pdo, $u, 'rides.view')) {
        $rows = $run("SELECT r.id, r.status, r.pickup_address, r.dropoff_address, r.fare_final, r.fare_estimate, r.created_at, pu.name AS passenger_name, pu.phone AS passenger_phone, du.name AS driver_name
                      FROM rides r LEFT JOIN users pu ON pu.id = r.passenger_id LEFT JOIN users du ON du.id = r.driver_id LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
                      WHERE r.id = ? OR pu.name LIKE ? OR pu.phone LIKE ? OR du.name LIKE ? OR du.phone LIKE ? OR rd.vehicle_plate LIKE ? OR r.pickup_address LIKE ? OR r.dropoff_address LIKE ?
                      ORDER BY r.created_at DESC, r.id DESC LIMIT $per", array($num, $like, $like, $like, $like, $like, $like, $like));
        $items = array();
        foreach ($rows as $r) {
            $fare = floatval($r['fare_final']) > 0 ? $r['fare_final'] : $r['fare_estimate'];
            $items[] = array('id' => intval($r['id']), 'title' => 'Ride #' . intval($r['id']), 'sub' => trim((string) $r['passenger_name'] . ' → ' . (string) $r['dropoff_address']),
                             'badge' => (string) $r['status'], 'meta' => $money($fare), 'q' => (string) ($r['passenger_phone'] !== '' && $r['passenger_phone'] !== null ? $r['passenger_phone'] : $q), 'period' => 'all');
        }
        $add('rides', 'Rides', 'rides', $items);
    }
    ok(array('q' => $q, 'groups' => $groups));
    break;
}

}
