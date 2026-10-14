<?php
// Real timestamps for the order journey.
//   placed_at -> courier_assigned_at -> courier_accepted_at -> picked_up_at -> delivered_at
// Before, nothing recorded when an order was picked up, so "how long does the courier take" could not be measured and the
// admin average delivery time was guesswork. Every code path that moves an order to 'picked_up' calls
// etel_order_stamp_pickup() AFTER its own transaction is committed (the column is created here when the migration has
// not been applied yet, and ALTER TABLE would end an open transaction). Never throws: a missing timestamp must not
// break a delivery.

function etel_order_has_pickup_col($pdo) {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'picked_up_at'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN picked_up_at DATETIME NULL DEFAULT NULL");
            $has = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'picked_up_at'")->fetch();
        }
    } catch (Exception $e) { error_log('[ETEL] picked_up_at column: ' . $e->getMessage()); $has = false; }
    return $has;
}

// Stamp the moment of pickup once (the first time wins). Only when the order really is picked up (or later).
function etel_order_stamp_pickup($pdo, $order_id) {
    try {
        if (!etel_order_has_pickup_col($pdo)) return false;
        $u = $pdo->prepare("UPDATE orders SET picked_up_at = NOW() WHERE id = ? AND picked_up_at IS NULL AND status IN ('picked_up','in_transit','delivered')");
        $u->execute(array((int) $order_id));
        return $u->rowCount() === 1;
    } catch (Exception $e) { error_log('[ETEL] stamp pickup: ' . $e->getMessage()); return false; }
}
