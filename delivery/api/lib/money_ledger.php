<?php
// Money bookkeeping the platform did not have:
//   1. Refunds owed   - an order was PAID (wallet, Telebirr, Chapa, bank transfer) and then CANCELLED. Customers pay outside the
//                       platform, so the money goes back outside it; this records that it did (who, how, reference, when) and
//                       flips orders.payment_status to 'refunded' so the same order is never refunded twice.
//   2. Courier cash   - on a CASH order the courier collects the customer's money. This shows, per courier, how much cash they
//                       collected on delivered orders, how much they already handed over, and what they still hold.
// Never throws from the report functions; the record functions return array('ok' => bool, 'message' => string, ...).

define('ETEL_MONEY_METHODS', 'wallet,telebirr,chapa,abysinia,cbe,cash,other');

function etel_money_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_refunds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            method VARCHAR(20) NOT NULL DEFAULT 'other',
            reference VARCHAR(80) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            admin_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS courier_cash_remittances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            courier_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            admin_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_courier (courier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('[ETEL] money ledger schema: ' . $e->getMessage()); }
}

// SQL condition: money really was taken for this order (cash orders never count: nothing was taken before delivery).
function etel_money_paid_sql($alias = 'o') {
    return "(" . $alias . ".payment_status = 'paid' OR (" . $alias . ".payment_method = 'wallet' AND " . $alias . ".wallet_tx_ref IS NOT NULL AND " . $alias . ".wallet_tx_ref <> ''))";
}

function etel_money_has_wallet_col($pdo) {
    static $has = null;
    if ($has !== null) return $has;
    try { $has = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'wallet_tx_ref'")->fetch(); } catch (Exception $e) { $has = false; }
    return $has;
}

function etel_money_paid_cond($pdo, $alias = 'o') {
    return etel_money_has_wallet_col($pdo) ? etel_money_paid_sql($alias) : ("(" . $alias . ".payment_status = 'paid')");
}

// Cancelled orders that were paid and not yet refunded, plus what was already refunded in the last $days days.
function etel_refunds_report($pdo, $days = 30) {
    etel_money_ensure_schema($pdo);
    $days = max(1, min(365, intval($days)));
    $out = array('owed' => array(), 'owed_count' => 0, 'owed_total' => 0.0, 'refunded' => array(), 'refunded_count' => 0, 'refunded_total' => 0.0, 'days' => $days);
    try {
        $paid = etel_money_paid_cond($pdo, 'o');
        $s = $pdo->query("SELECT o.id, o.order_code, o.fare_total, o.payment_method, o.placed_at, u.name AS customer_name, u.phone AS customer_phone
            FROM orders o LEFT JOIN users u ON u.id = o.customer_id
            WHERE o.status = 'cancelled' AND $paid AND o.payment_status <> 'refunded'
              AND NOT EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = o.id)
            ORDER BY o.placed_at ASC LIMIT 200");
        foreach ($s->fetchAll() as $r) {
            $out['owed'][] = array('order_id' => intval($r['id']), 'order_code' => $r['order_code'], 'amount' => round(floatval($r['fare_total']), 2),
                'method' => $r['payment_method'], 'placed_at' => $r['placed_at'], 'customer' => etel_money_first_name($r['customer_name']), 'customer_phone' => $r['customer_phone']);
            $out['owed_total'] += floatval($r['fare_total']);
        }
        $out['owed_count'] = count($out['owed']); $out['owed_total'] = round($out['owed_total'], 2);
        $q = $pdo->prepare("SELECT r.order_id, r.amount, r.method, r.reference, r.note, r.created_at, o.order_code
            FROM order_refunds r LEFT JOIN orders o ON o.id = r.order_id WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY r.id DESC LIMIT 100");
        $q->execute(array($days));
        foreach ($q->fetchAll() as $r) {
            $out['refunded'][] = array('order_id' => intval($r['order_id']), 'order_code' => $r['order_code'], 'amount' => round(floatval($r['amount']), 2),
                'method' => $r['method'], 'reference' => $r['reference'], 'note' => $r['note'], 'created_at' => $r['created_at']);
            $out['refunded_total'] += floatval($r['amount']);
        }
        $out['refunded_count'] = count($out['refunded']); $out['refunded_total'] = round($out['refunded_total'], 2);
    } catch (Exception $e) { error_log('[ETEL] refunds report: ' . $e->getMessage()); }
    return $out;
}

function etel_money_first_name($n) {
    $n = trim((string) $n);
    if ($n === '') return '';
    $p = preg_split('/\s+/', $n);
    return $p[0];
}

// Record that a cancelled, paid order was refunded. $amount null = the full order total; never more than the total.
function etel_refund_record($pdo, $order_id, $admin_id, $method, $reference, $note, $amount = null) {
    etel_money_ensure_schema($pdo);
    $order_id = intval($order_id);
    $method = strtolower(trim((string) $method));
    if (!in_array($method, explode(',', ETEL_MONEY_METHODS), true)) return array('ok' => false, 'message' => 'Choose how the money was returned.');
    $reference = substr(trim((string) $reference), 0, 80);
    $note = substr(trim((string) $note), 0, 255);
    try {
        $paid = etel_money_paid_cond($pdo, 'o');
        $s = $pdo->prepare("SELECT o.id, o.status, o.fare_total, o.payment_status, ($paid) AS was_paid FROM orders o WHERE o.id = ? LIMIT 1");
        $s->execute(array($order_id));
        $o = $s->fetch();
        if (!$o) return array('ok' => false, 'message' => 'Order not found.');
        if ($o['status'] !== 'cancelled') return array('ok' => false, 'message' => 'Only a cancelled order can be refunded.');
        if (intval($o['was_paid']) !== 1 && $o['payment_status'] !== 'refunded') return array('ok' => false, 'message' => 'No payment was taken for this order, so there is nothing to refund.');
        $total = round(floatval($o['fare_total']), 2);
        if ($amount === null || $amount === '') $amount = $total;
        if (!is_numeric($amount) || floatval($amount) <= 0) return array('ok' => false, 'message' => 'Enter an amount above zero.');
        $amount = round(floatval($amount), 2);
        if ($amount > $total) return array('ok' => false, 'message' => 'The refund cannot be more than the order total (ETB ' . number_format($total, 2) . ').');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO order_refunds (order_id, amount, method, reference, note, admin_id) VALUES (?,?,?,?,?,?)")
                ->execute(array($order_id, $amount, $method, $reference, $note, $admin_id ? intval($admin_id) : null));
            $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?")->execute(array($order_id));
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) return array('ok' => false, 'message' => 'This order was already refunded.');
            throw $e;
        }
        return array('ok' => true, 'message' => 'Refund recorded.', 'order_id' => $order_id, 'amount' => $amount);
    } catch (Exception $e) {
        error_log('[ETEL] refund record: ' . $e->getMessage());
        return array('ok' => false, 'message' => 'Could not record the refund. Please try again.');
    }
}

// Per courier: cash collected on delivered CASH orders, what they handed over, and what they still hold.
// own_earnings = their pay + tips on those same orders, shown so staff can see the net figure if couriers keep their own pay.
function etel_courier_cash_report($pdo) {
    etel_money_ensure_schema($pdo);
    $out = array('couriers' => array(), 'holding_total' => 0.0, 'holding_couriers' => 0);
    try {
        if (function_exists('etel_ensure_order_pay_columns')) etel_ensure_order_pay_columns($pdo);
        $hasPay = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'courier_pay'")->fetch();
        $hasTip = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'courier_tip'")->fetch();
        $pay = ($hasPay ? "COALESCE(o.courier_pay, o.delivery_fee, 0)" : "COALESCE(o.delivery_fee, 0)") . ($hasTip ? " + COALESCE(o.courier_tip, 0)" : '');
        $rows = $pdo->query("SELECT c.id AS courier_id, c.courier_code, u.name, u.phone,
                COUNT(o.id) AS cash_orders, COALESCE(SUM(o.fare_total), 0) AS collected, COALESCE(SUM($pay), 0) AS own_earnings,
                MAX(o.delivered_at) AS last_cash_delivery
            FROM orders o JOIN couriers c ON c.id = o.courier_id LEFT JOIN users u ON u.id = c.user_id
            WHERE o.status = 'delivered' AND o.payment_method = 'cash'
            GROUP BY c.id, c.courier_code, u.name, u.phone")->fetchAll();
        $rem = array();
        foreach ($pdo->query("SELECT courier_id, SUM(amount) AS handed, MAX(created_at) AS last_at FROM courier_cash_remittances GROUP BY courier_id")->fetchAll() as $r) {
            $rem[intval($r['courier_id'])] = $r;
        }
        foreach ($rows as $r) {
            $cid = intval($r['courier_id']);
            $handed = isset($rem[$cid]) ? floatval($rem[$cid]['handed']) : 0.0;
            $collected = round(floatval($r['collected']), 2);
            $holding = round($collected - $handed, 2);
            $out['couriers'][] = array('courier_id' => $cid, 'courier_code' => $r['courier_code'], 'name' => etel_money_first_name($r['name']), 'phone' => $r['phone'],
                'cash_orders' => intval($r['cash_orders']), 'collected' => $collected, 'handed_over' => round($handed, 2), 'holding' => $holding,
                'own_earnings' => round(floatval($r['own_earnings']), 2), 'holding_after_own_pay' => round($holding - floatval($r['own_earnings']), 2),
                'last_cash_delivery' => $r['last_cash_delivery'], 'last_handover' => isset($rem[$cid]) ? $rem[$cid]['last_at'] : null);
            if ($holding > 0) { $out['holding_total'] += $holding; $out['holding_couriers']++; }
        }
        usort($out['couriers'], function ($a, $b) { return $b['holding'] < $a['holding'] ? -1 : ($b['holding'] > $a['holding'] ? 1 : 0); });
        $out['holding_total'] = round($out['holding_total'], 2);
    } catch (Exception $e) { error_log('[ETEL] courier cash report: ' . $e->getMessage()); }
    return $out;
}

// Staff received $amount of cash from a courier. Refuses an amount above what that courier is recorded as holding (a typo guard).
function etel_cash_remit_record($pdo, $courier_id, $amount, $admin_id, $note) {
    etel_money_ensure_schema($pdo);
    $courier_id = intval($courier_id);
    if (!is_numeric($amount) || floatval($amount) <= 0) return array('ok' => false, 'message' => 'Enter an amount above zero.');
    $amount = round(floatval($amount), 2);
    try {
        $rep = etel_courier_cash_report($pdo);
        $row = null;
        foreach ($rep['couriers'] as $c) { if ($c['courier_id'] === $courier_id) { $row = $c; break; } }
        if (!$row) return array('ok' => false, 'message' => 'This courier has no recorded cash.');
        if ($amount > $row['holding'] + 0.001) return array('ok' => false, 'message' => 'This courier is recorded as holding ETB ' . number_format($row['holding'], 2) . ', so ETB ' . number_format($amount, 2) . ' is too much.');
        $pdo->prepare("INSERT INTO courier_cash_remittances (courier_id, amount, note, admin_id) VALUES (?,?,?,?)")
            ->execute(array($courier_id, $amount, substr(trim((string) $note), 0, 255), $admin_id ? intval($admin_id) : null));
        return array('ok' => true, 'message' => 'Handover recorded.', 'courier_id' => $courier_id, 'amount' => $amount, 'holding_after' => round($row['holding'] - $amount, 2));
    } catch (Exception $e) {
        error_log('[ETEL] cash remit: ' . $e->getMessage());
        return array('ok' => false, 'message' => 'Could not record the handover. Please try again.');
    }
}

// How many cancelled-but-paid orders still have no refund recorded (the dashboard's "Refunds to give" row).
function etel_refunds_owed_count($pdo) {
    try {
        etel_money_ensure_schema($pdo);
        $paid = etel_money_paid_cond($pdo, 'o');
        return intval($pdo->query("SELECT COUNT(*) FROM orders o WHERE o.status = 'cancelled' AND $paid AND o.payment_status <> 'refunded'
            AND NOT EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = o.id)")->fetchColumn());
    } catch (Exception $e) { return 0; }
}
