<?php
// Loyalty points.
//
//  EARN    1 point for every full ETB 10 of ITEM sales on a DELIVERED order (delivery fee and
//          service charge don't count, and neither does the part paid with points), doubled for
//          orders placed on Saturday or Sunday (the "2x points weekend" banner).
//  SPEND   10 points = ETB 1 off the items. Needs a balance of at least 100 points, and can never
//          take more than the items cost (after any promo-code discount on the items).
//  The balance is the SUM of the ledger (loyalty_points) - there is no separate counter to drift.
//  A (user, order, kind) can exist only once, so awarding/refunding twice is impossible.
//  Cancelled orders give back any points they spent.
//  Spending is funded by the platform, like promo codes: only orders.fare_total shrinks.

define('ETEL_POINTS_PER_ETB', 0.1);     // earn: 1 point per ETB 10
define('ETEL_POINTS_STEP', 10);         // redeem in blocks of 10 points = ETB 1
define('ETEL_POINTS_MIN_REDEEM', 100);  // need at least this many points to spend any

function etel_ensure_loyalty_tables($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_id INT NULL,
            points INT NOT NULL,
            kind VARCHAR(12) NOT NULL,
            note VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_order_kind (user_id, order_id, kind),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = etel_table_columns($pdo, 'orders');
        if ($cols && !in_array('points_used', $cols, true))     $pdo->exec("ALTER TABLE orders ADD COLUMN points_used INT NOT NULL DEFAULT 0");
        if ($cols && !in_array('points_discount', $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN points_discount DECIMAL(10,2) NOT NULL DEFAULT 0");
        if ($cols && !in_array('discount', $cols, true))        $pdo->exec("ALTER TABLE orders ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        error_log('[ETEL] etel_ensure_loyalty_tables: ' . $e->getMessage());
    }
}

function etel_loyalty_balance($pdo, $user_id) {
    $s = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM loyalty_points WHERE user_id = ?');
    $s->execute(array($user_id));
    return intval($s->fetchColumn());
}

// Pure: points earned for an order. $items_paid = item sales actually paid in money.
function etel_loyalty_points_for($items_paid, $placed_at) {
    $base = (int)floor(max(0, floatval($items_paid)) * ETEL_POINTS_PER_ETB);
    $dow = (int)date('N', $placed_at ? strtotime($placed_at) : time()); // 6 = Sat, 7 = Sun
    return ($dow >= 6) ? $base * 2 : $base;
}

// Pure: how many points to spend on this basket (0 = none). $cap_etb = max ETB the points may cover.
function etel_loyalty_redeem_plan($balance, $cap_etb) {
    $balance = intval($balance);
    if ($balance < ETEL_POINTS_MIN_REDEEM) return 0;
    $max_by_cap = (int)floor(max(0, floatval($cap_etb)) * ETEL_POINTS_STEP);
    $n = min($balance, $max_by_cap);
    $n -= $n % ETEL_POINTS_STEP;
    return $n >= ETEL_POINTS_STEP ? $n : 0;
}

// Called once an order is delivered. Returns the points newly awarded (0 if none / already awarded).
function etel_loyalty_award_for_order($pdo, $order_id) {
    try {
        etel_ensure_loyalty_tables($pdo);
        $q = $pdo->prepare("SELECT customer_id, status, placed_at, COALESCE(subtotal, fare_total) AS items, COALESCE(points_discount,0) AS pd FROM orders WHERE id = ?");
        $q->execute(array(intval($order_id)));
        $o = $q->fetch(PDO::FETCH_ASSOC);
        if (!$o || $o['status'] !== 'delivered' || empty($o['customer_id'])) return 0;
        $pts = etel_loyalty_points_for(floatval($o['items']) - floatval($o['pd']), $o['placed_at']);
        if ($pts <= 0) return 0;
        $ins = $pdo->prepare("INSERT IGNORE INTO loyalty_points (user_id, order_id, points, kind, note) VALUES (?, ?, ?, 'earn', 'Delivered order')");
        $ins->execute(array($o['customer_id'], $order_id, $pts));
        return $ins->rowCount() > 0 ? $pts : 0;
    } catch (Exception $e) { error_log('[ETEL] loyalty award failed (non-fatal): ' . $e->getMessage()); return 0; }
}

// Cancelled order: give back the points it spent (once).
function etel_loyalty_refund_for_order($pdo, $order_id) {
    try {
        etel_ensure_loyalty_tables($pdo);
        $q = $pdo->prepare("SELECT customer_id, status, COALESCE(points_used,0) AS pu FROM orders WHERE id = ?");
        $q->execute(array(intval($order_id)));
        $o = $q->fetch(PDO::FETCH_ASSOC);
        if (!$o || $o['status'] !== 'cancelled' || intval($o['pu']) <= 0) return 0;
        $ins = $pdo->prepare("INSERT IGNORE INTO loyalty_points (user_id, order_id, points, kind, note) VALUES (?, ?, ?, 'refund', 'Order cancelled')");
        $ins->execute(array($o['customer_id'], $order_id, intval($o['pu'])));
        return $ins->rowCount() > 0 ? intval($o['pu']) : 0;
    } catch (Exception $e) { error_log('[ETEL] loyalty refund failed (non-fatal): ' . $e->getMessage()); return 0; }
}
