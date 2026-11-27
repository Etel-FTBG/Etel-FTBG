<?php
// ============================================================
// ETEL - the courier's "please confirm" requests (table order_confirmations) must follow the order.
// When staff move an order from the Admin Orders board (admin_update_order) the courier's open request used to stay "pending", so the customer
// still saw "Your order has been delivered! Pay ..." and every tap was refused ("not out for delivery"). These helpers close the requests
// that the new order status has made pointless, and sweep the ones that are already stale.
// ============================================================

// Staff set $status on an order: close the open requests it answers. $admin_id may be 0.
//   delivered           -> pickup and delivery requests are "confirmed" (the assignment is delivered too)
//   picked_up/in_transit-> an open pickup request is "confirmed"
//   pending/assigned    -> every open request is "rejected" (the order went back; the courier asks again when it is time)
function etel_confirmations_settle_for_status($pdo, $order_id, $status, $admin_id) {
    $order_id = intval($order_id); $admin_id = intval($admin_id);
    if ($order_id <= 0) return;
    if ($status === 'delivered') {
        $pdo->prepare("UPDATE order_confirmations SET status='confirmed', responded_by=?, responded_at=NOW() WHERE order_id=? AND status='pending'")->execute(array($admin_id, $order_id));
        $pdo->prepare("UPDATE order_assignments SET status='delivered' WHERE order_id=?")->execute(array($order_id));
    } elseif ($status === 'picked_up' || $status === 'in_transit') {
        $pdo->prepare("UPDATE order_confirmations SET status='confirmed', responded_by=?, responded_at=NOW() WHERE order_id=? AND type='pickup' AND status='pending'")->execute(array($admin_id, $order_id));
    } elseif ($status === 'pending' || $status === 'assigned') {
        $pdo->prepare("UPDATE order_confirmations SET status='rejected', responded_by=?, responded_at=NOW() WHERE order_id=? AND status='pending'")->execute(array($admin_id, $order_id));
    }
}

// Requests that are still "pending" although their order is already delivered (confirmed) or cancelled (rejected). Safe to run any time.
function etel_confirmations_sweep($pdo) {
    $pdo->exec("UPDATE order_confirmations oc JOIN orders o ON o.id = oc.order_id SET oc.status='confirmed', oc.responded_at=NOW() WHERE oc.status='pending' AND o.status='delivered'");
    $pdo->exec("UPDATE order_confirmations oc JOIN orders o ON o.id = oc.order_id SET oc.status='rejected', oc.responded_at=NOW() WHERE oc.status='pending' AND o.status='cancelled'");
}
