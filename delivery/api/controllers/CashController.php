<?php
// ============================================================
// ETEL Delivery - cash / unpaid reconciliation (admin only)
//   admin_unpaid_delivered  {}                          -> delivered orders never marked paid, with totals per courier
//   admin_mark_order_paid   { order_ids: [..], note }   -> marks delivered orders as paid and logs who did it
// Cash orders were never marked paid when delivered (only the wallet path did), so collected cash had no record.
// ============================================================

function etel_cash_ensure_log($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_payment_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        admin_id INT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        method VARCHAR(20) NOT NULL DEFAULT '',
        note VARCHAR(200) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_opl_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

switch ($action) {

case 'admin_unpaid_delivered': {
    $pdo = db();
    require_admin($pdo, 'finance.view');
    $st = $pdo->query("SELECT o.id, o.order_code, o.payment_method, o.fare_total, o.courier_id, o.placed_at, o.delivered_at,
            cu.name AS courier_name, u.name AS customer_name, u.phone AS customer_phone
        FROM orders o
        LEFT JOIN couriers c ON c.id = o.courier_id
        LEFT JOIN users cu ON cu.id = c.user_id
        LEFT JOIN users u ON u.id = o.customer_id
        WHERE o.status = 'delivered' AND o.payment_status <> 'paid' AND o.payment_method <> 'chapa'
        ORDER BY o.placed_at DESC LIMIT 300");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $byCourier = array(); $total = 0.0;
    foreach ($rows as $r) {
        $k = $r['courier_name'] ? $r['courier_name'] : 'No courier recorded';
        if (!isset($byCourier[$k])) $byCourier[$k] = array('courier' => $k, 'orders' => 0, 'amount' => 0.0);
        $byCourier[$k]['orders']++; $byCourier[$k]['amount'] += floatval($r['fare_total']);
        $total += floatval($r['fare_total']);
    }
    ok(array('orders' => $rows, 'by_courier' => array_values($byCourier), 'total' => $total, 'count' => count($rows)));
    break;
}

case 'admin_mark_order_paid': {
    $pdo = db();
    $u = require_admin($pdo, 'finance.manage');
    $ids = isset($body['order_ids']) && is_array($body['order_ids']) ? array_slice(array_values(array_unique(array_map('intval', $body['order_ids']))), 0, 50) : array();
    $ids = array_values(array_filter($ids));
    if (!$ids) fail('Choose at least one order');
    $note = isset($body['note']) ? substr(trim((string) $body['note']), 0, 200) : '';
    etel_cash_ensure_log($pdo);
    $done = 0;
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("SELECT id, fare_total, payment_method FROM orders WHERE id = ? AND status = 'delivered' AND payment_status <> 'paid' AND payment_method <> 'chapa' FOR UPDATE");
            $s->execute(array($id));
            $o = $s->fetch(PDO::FETCH_ASSOC);
            if ($o) {
                $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute(array($id));
                $pdo->prepare("INSERT INTO order_payment_log (order_id, admin_id, amount, method, note) VALUES (?,?,?,?,?)")
                    ->execute(array($id, $u['id'], $o['fare_total'], (string) $o['payment_method'], $note));
                $done++;
            }
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('[ETEL] admin_mark_order_paid #' . $id . ': ' . $e->getMessage()); }
    }
    ok(array('marked' => $done, 'message' => $done . ' order(s) marked as paid'));
    break;
}

}
