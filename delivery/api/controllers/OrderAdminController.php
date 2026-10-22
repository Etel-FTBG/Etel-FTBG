<?php
// ============================================================
// ETEL Delivery - Admin -> Orders board (read only; the controls are the existing admin_update_order, admin_assign_courier,
// admin_take_back_order, admin_cancel_order, admin_force_confirm actions)
//   admin_orders_board { status?, q?, period?: today|7d|30d|all, limit? } -> orders with customer / shop / courier, flags, and a summary
//   admin_order_detail { id }                                              -> one order: parties, items, confirmations, timeline, staff log, couriers to choose from
// Needs orders.view. Staff who may only see Ride accounts get nothing here. Columns that older installs may lack are only
// selected when they exist.
// ============================================================

function etel_ob_has($pdo, $col) {
    return in_array($col, etel_table_columns($pdo, 'orders'), true);
}

switch ($action) {

case 'admin_orders_board': {
    $pdo = db();
    $u = require_admin($pdo, 'orders.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    $status = isset($body['status']) ? trim((string) $body['status']) : '';
    $q      = isset($body['q']) ? trim((string) $body['q']) : '';
    $period = isset($body['period']) ? (string) $body['period'] : 'today';
    $limit  = max(1, min(500, isset($body['limit']) ? intval($body['limit']) : 300));
    $since  = array('today' => 'o.placed_at >= CURDATE()', '7d' => 'o.placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)', '30d' => 'o.placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $where = array(isset($since[$period]) ? $since[$period] : '1=1'); $params = array();
    if (in_array($status, array('pending', 'assigned', 'picked_up', 'in_transit', 'delivered', 'cancelled'), true)) { $where[] = 'o.status = ?'; $params[] = $status; }
    if ($q !== '') {
        $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
        $where[] = '(o.order_code LIKE ? OR o.from_zone LIKE ? OR o.to_zone LIKE ? OR o.recipient_name LIKE ? OR o.recipient_phone LIKE ? OR cu.name LIKE ? OR cu.phone LIKE ?)';
        for ($i = 0; $i < 7; $i++) $params[] = $like;
    }
    $has = function ($c) use ($pdo) { return etel_ob_has($pdo, $c); };
    $cols = array('o.id', 'o.order_code', 'o.status', 'o.package_type', 'o.from_zone', 'o.to_zone', 'o.fare_total', 'o.placed_at', 'o.delivered_at', 'o.customer_id', 'o.recipient_name', 'o.recipient_phone',
                  'cu.name AS customer_name', 'cu.phone AS customer_phone', 'TIMESTAMPDIFF(MINUTE, o.placed_at, NOW()) AS age_min');
    foreach (array('order_kind', 'payment_method', 'payment_status', 'delivery_fee', 'cancel_reason', 'cancelled_at') as $c) { if ($has($c)) $cols[] = 'o.' . $c; }
    $join = 'LEFT JOIN users cu ON cu.id = o.customer_id';
    if ($has('courier_id')) {
        $cols[] = 'o.courier_id'; $cols[] = 'ccu.name AS courier_name'; $cols[] = 'ccu.phone AS courier_phone'; $cols[] = 'c.courier_code';
        $join .= ' LEFT JOIN couriers c ON c.id = o.courier_id LEFT JOIN users ccu ON ccu.id = c.user_id';
    }
    $cols[] = "(SELECT sg.business_name FROM order_assignments oa JOIN service_givers sg ON sg.id = oa.service_giver_id WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS shop_name";
    $cols[] = "(SELECT oc.status FROM order_confirmations oc WHERE oc.order_id = o.id AND oc.type = 'pickup' ORDER BY oc.id DESC LIMIT 1) AS pickup_conf";
    $cols[] = "(SELECT oc.status FROM order_confirmations oc WHERE oc.order_id = o.id AND oc.type = 'delivery' ORDER BY oc.id DESC LIMIT 1) AS delivery_conf";
    try {
        $s = $pdo->prepare('SELECT ' . implode(', ', $cols) . " FROM orders o $join WHERE " . implode(' AND ', $where) . " ORDER BY o.placed_at DESC, o.id DESC LIMIT $limit");
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { fail_ex($e, 'Could not load the orders', 500); }
    foreach ($rows as &$r) {
        $r['id'] = intval($r['id']); $r['fare_total'] = round(floatval($r['fare_total']), 2);
        $r['age_min'] = $r['age_min'] === null ? null : intval($r['age_min']);
        $r['kind'] = (isset($r['order_kind']) && $r['order_kind'] === 'package') ? 'package' : 'order';
        $age = intval($r['age_min']);
        $r['late'] = ($r['status'] === 'pending' && $age > 10) || (in_array($r['status'], array('assigned', 'picked_up', 'in_transit'), true) && $age > 90);
        $r['unassigned'] = in_array($r['status'], array('pending', 'assigned'), true) && empty($r['courier_id']);
        unset($r['order_kind']);
    }
    unset($r);
    $sum = array('total' => 0, 'pending' => 0, 'assigned' => 0, 'on_way' => 0, 'delivered' => 0, 'cancelled' => 0, 'today' => 0, 'revenue_today' => 0, 'late' => 0, 'avg_delivery_min' => null);
    $sw = isset($since[$period]) ? str_replace('o.', '', $since[$period]) : '1=1';
    try {
        $a = $pdo->query("SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending, SUM(status = 'assigned') AS assigned, SUM(status IN ('picked_up','in_transit')) AS on_way,
                SUM(status = 'delivered') AS delivered, SUM(status = 'cancelled') AS cancelled,
                SUM(placed_at >= CURDATE()) AS today,
                IFNULL(SUM(CASE WHEN status = 'delivered' AND delivered_at >= CURDATE() THEN fare_total ELSE 0 END), 0) AS revenue_today,
                SUM((status = 'pending' AND placed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) OR (status IN ('assigned','picked_up','in_transit') AND placed_at < DATE_SUB(NOW(), INTERVAL 90 MINUTE))) AS late
            FROM orders WHERE $sw")->fetch(PDO::FETCH_ASSOC);
        foreach ($a as $k => $v) { $sum[$k] = $k === 'revenue_today' ? round(floatval($v), 2) : intval($v); }
        $avg = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, placed_at, delivered_at)) FROM orders WHERE status = 'delivered' AND delivered_at IS NOT NULL AND delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        $sum['avg_delivery_min'] = $avg === null || $avg === false ? null : round(floatval($avg));
    } catch (Exception $e) { /* the list still works without the summary */ }
    ok(array('orders' => $rows, 'count' => count($rows), 'summary' => $sum, 'period' => isset($since[$period]) ? $period : 'all'));
    break;
}

case 'admin_order_detail': {
    $pdo = db();
    $u = require_admin($pdo, 'orders.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    $id = isset($body['id']) ? intval($body['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    if ($id <= 0) fail('id required', 400);
    $one = function ($sql, $args) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Exception $e) { return null; } };
    $all = function ($sql, $args) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return array(); } };
    $o = $one('SELECT * FROM orders WHERE id = ? LIMIT 1', array($id));
    if (!$o) fail('Order not found', 404);
    $customer = $one('SELECT id, name, phone FROM users WHERE id = ?', array($o['customer_id']));
    $courier = !empty($o['courier_id']) ? $one('SELECT c.id, c.courier_code, c.vehicle_type, c.status, u.name, u.phone FROM couriers c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?', array($o['courier_id'])) : null;
    $shop = $one('SELECT oa.status AS assign_status, oa.assigned_at, oa.accepted_at, oa.pickup_scanned_at, oa.delivery_scanned_at, sg.id, sg.business_name, sg.service_giver_code, u.phone FROM order_assignments oa JOIN service_givers sg ON sg.id = oa.service_giver_id LEFT JOIN users u ON u.id = sg.user_id WHERE oa.order_id = ? ORDER BY oa.id DESC LIMIT 1', array($id));
    $items = $all('SELECT item_name, quantity, unit_price, subtotal FROM order_items WHERE order_id = ? ORDER BY id', array($id));
    $conf = $all('SELECT id, type, status, requested_at, responded_at, payment_confirmed FROM order_confirmations WHERE order_id = ? ORDER BY id', array($id));
    $log = $all('SELECT a.action, a.detail, a.created_at, u.name AS admin_name FROM order_admin_actions a LEFT JOIN users u ON u.id = a.admin_id WHERE a.order_id = ? ORDER BY a.id DESC LIMIT 15', array($id));
    $kind = (isset($o['order_kind']) && $o['order_kind'] === 'package') ? 'package' : 'shop';
    $live = in_array($o['status'], array('pending', 'assigned', 'picked_up', 'in_transit'), true);
    $canCancel = false;
    try { require_once __DIR__ . '/../lib/order_cancel.php'; $canCancel = etel_cancel_rule('admin', $o['status'], $shop ? $shop['assign_status'] : null, $kind) === ''; } catch (Exception $e) { $canCancel = $live; } catch (Throwable $e) { $canCancel = $live; }
    $couriers = array();
    if (in_array($o['status'], array('pending', 'assigned'), true)) {
        require_once __DIR__ . '/../lib/courier_approval.php';
        $couriers = $all("SELECT c.id, c.courier_code, c.status, c.vehicle_type, c.zone, u.name FROM couriers c LEFT JOIN users u ON u.id = c.user_id
                          WHERE c.active = 1" . etel_courier_approved_sql($pdo) . " ORDER BY (c.status = 'online') DESC, (c.status = 'busy') DESC, u.name LIMIT 60", array());
    }
    $money = array();
    foreach (array('subtotal', 'delivery_fee', 'service_charge', 'fare_total', 'cancel_fee') as $f) { if (isset($o[$f])) $money[$f] = round(floatval($o[$f]), 2); }
    ok(array('order' => array(
            'id' => intval($o['id']), 'order_code' => $o['order_code'], 'status' => $o['status'], 'kind' => $kind === 'package' ? 'package' : 'order',
            'package_type' => isset($o['package_type']) ? $o['package_type'] : '', 'from_zone' => $o['from_zone'], 'to_zone' => $o['to_zone'],
            'recipient_name' => isset($o['recipient_name']) ? $o['recipient_name'] : '', 'recipient_phone' => isset($o['recipient_phone']) ? $o['recipient_phone'] : '',
            'recipient_address' => isset($o['recipient_address']) ? $o['recipient_address'] : '', 'notes' => isset($o['notes']) ? $o['notes'] : '',
            'payment_method' => isset($o['payment_method']) ? $o['payment_method'] : '', 'payment_status' => isset($o['payment_status']) ? $o['payment_status'] : '',
            'money' => $money, 'placed_at' => $o['placed_at'], 'delivered_at' => isset($o['delivered_at']) ? $o['delivered_at'] : null,
            'cancelled_at' => isset($o['cancelled_at']) ? $o['cancelled_at'] : null, 'cancel_reason' => isset($o['cancel_reason']) ? $o['cancel_reason'] : null,
            'cancelled_by' => isset($o['cancelled_by']) ? $o['cancelled_by'] : null, 'cancel_note' => isset($o['cancel_note']) ? $o['cancel_note'] : null,
            'courier_accepted_at' => isset($o['courier_accepted_at']) ? $o['courier_accepted_at'] : null),
        'customer' => $customer, 'courier' => $courier, 'shop' => $shop, 'items' => $items, 'confirmations' => $conf, 'log' => $log,
        'controls' => array('can_cancel' => $canCancel, 'can_assign' => in_array($o['status'], array('pending', 'assigned'), true), 'can_take_back' => $o['status'] === 'assigned' && !empty($o['courier_id']), 'live' => $live),
        'couriers' => $couriers,
        'cancel_reasons' => function_exists('etel_cancel_reasons') ? etel_cancel_reasons('admin') : array('other' => 'Another reason')));
    break;
}

}
