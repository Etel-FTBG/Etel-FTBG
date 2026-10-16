<?php
// ============================================================
// ETEL Delivery - Admin -> Customers (read only)
//   admin_customers_list { q?, sort?: recent|orders|spent|name, limit? } -> customers with order count, money spent, last order
//   admin_customer_get   { id }                                          -> one customer: summary + their last 25 orders
// Needs users.view. Staff who may only see ride accounts do not get delivery orders here.
// ============================================================

// True when orders has the column (older installs may not).
function etel_cust_has_col($pdo, $col) {
    static $c = array();
    if (!isset($c[$col])) {
        try { $c[$col] = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE '" . preg_replace('/[^a-z_]/', '', $col) . "'")->fetch(); } catch (Exception $e) { $c[$col] = false; }
    }
    return $c[$col];
}

switch ($action) {

case 'admin_customers_list': {
    $pdo = db();
    $u = require_admin($pdo, 'users.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    $q = isset($body['q']) ? trim((string) $body['q']) : (isset($_GET['q']) ? trim((string) $_GET['q']) : '');
    $sort = isset($body['sort']) ? (string) $body['sort'] : (isset($_GET['sort']) ? (string) $_GET['sort'] : 'recent');
    $limit = isset($body['limit']) ? intval($body['limit']) : (isset($_GET['limit']) ? intval($_GET['limit']) : 200);
    $limit = max(1, min(500, $limit));
    $order = array('recent' => 'MAX(o.placed_at) IS NULL, MAX(o.placed_at) DESC, u.id DESC', 'orders' => 'orders_total DESC, u.id DESC',
                   'spent' => 'spent DESC, u.id DESC', 'name' => 'u.name ASC');
    $orderBy = isset($order[$sort]) ? $order[$sort] : $order['recent'];
    $where = "u.role = 'customer'"; $params = array();
    if ($q !== '') {
        $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
        $where .= ' AND (u.name LIKE ? OR u.phone LIKE ?)'; $params[] = $like; $params[] = $like;
    }
    try {
        $s = $pdo->prepare("SELECT u.id, u.name, u.phone, u.created_at, " .
            "COUNT(o.id) AS orders_total, " .
            "SUM(o.status = 'delivered') AS delivered, SUM(o.status = 'cancelled') AS cancelled, " .
            "IFNULL(SUM(CASE WHEN o.status = 'delivered' THEN o.fare_total ELSE 0 END), 0) AS spent, " .
            "MAX(o.placed_at) AS last_order_at " .
            "FROM users u LEFT JOIN orders o ON o.customer_id = u.id WHERE $where GROUP BY u.id ORDER BY $orderBy LIMIT $limit");
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $tot = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
        $act = $pdo->query("SELECT COUNT(DISTINCT customer_id) FROM orders WHERE placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $new = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    } catch (Exception $e) { fail_ex($e, 'Could not load customers', 500); }
    foreach ($rows as &$r) {
        $r['id'] = intval($r['id']); $r['orders_total'] = intval($r['orders_total']);
        $r['delivered'] = intval($r['delivered']); $r['cancelled'] = intval($r['cancelled']);
        $r['spent'] = round(floatval($r['spent']), 2);
    }
    unset($r);
    ok(array('customers' => $rows, 'count' => count($rows),
             'summary' => array('total' => intval($tot), 'active_30d' => intval($act), 'new_7d' => intval($new))));
    break;
}

case 'admin_customer_get': {
    $pdo = db();
    $u = require_admin($pdo, 'users.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    $id = isset($body['id']) ? intval($body['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    if ($id <= 0) fail('id required', 400);
    try {
        $s = $pdo->prepare("SELECT id, name, phone, created_at FROM users WHERE id = ? AND role = 'customer' LIMIT 1");
        $s->execute(array($id));
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) fail('Customer not found', 404);
        $kind = etel_cust_has_col($pdo, 'order_kind') ? 'o.order_kind' : "'order'";
        $o = $pdo->prepare("SELECT o.id, o.order_code, $kind AS kind, o.status, o.payment_method, o.fare_total, o.placed_at " .
            "FROM orders o WHERE o.customer_id = ? ORDER BY o.id DESC LIMIT 25");
        $o->execute(array($id));
        $orders = $o->fetchAll(PDO::FETCH_ASSOC);
        $a = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status = 'delivered') AS d, SUM(status = 'cancelled') AS x, " .
            "IFNULL(SUM(CASE WHEN status = 'delivered' THEN fare_total ELSE 0 END), 0) AS spent, MAX(placed_at) AS last_at FROM orders WHERE customer_id = ?");
        $a->execute(array($id));
        $sum = $a->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { fail_ex($e, 'Could not load the customer', 500); }
    foreach ($orders as &$r) { $r['id'] = intval($r['id']); $r['fare_total'] = round(floatval($r['fare_total']), 2); }
    unset($r);
    ok(array('customer' => array('id' => intval($c['id']), 'name' => $c['name'], 'phone' => $c['phone'], 'created_at' => $c['created_at'],
                                 'orders_total' => intval($sum['n']), 'delivered' => intval($sum['d']), 'cancelled' => intval($sum['x']),
                                 'spent' => round(floatval($sum['spent']), 2), 'last_order_at' => $sum['last_at']),
             'orders' => $orders));
    break;
}

}
