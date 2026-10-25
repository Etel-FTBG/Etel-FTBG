<?php
// One proper way to cancel an order, for the customer, the shop and staff.
// Before this, customers could not cancel at all, shops could not decline an order (for example when an item ran out), and the only
// path was Admin changing the status dropdown: no reason recorded, the courier stayed attached and busy, the shop's row stayed live.
//
// Who may cancel what (the rule is in etel_cancel_rule):
//   customer  only before the shop starts preparing (the shop has not accepted it yet), and never once it is on its way
//   shop      until the courier picks it up (pending, preparing or ready)
//   admin     any live order, even one on its way
// A reason is required (a short code from a fixed list, plus an optional note). The cancel clears the courier, frees them, closes the
// shop's row and any open courier offer, logs it, tells the customer and the courier, and says whether money is due back
// (a paid order then shows on Admin > Money > Refunds). Never throws; returns array('ok' => bool, 'message' => string, ...).

function etel_cancel_reasons($role) {
    $r = array(
        'customer' => array('changed_mind' => 'I changed my mind', 'wrong_order' => 'I ordered by mistake', 'too_slow' => 'It is taking too long', 'other' => 'Another reason'),
        'shop'     => array('out_of_stock' => 'An item is out of stock', 'too_busy' => 'Too busy right now', 'closing' => 'We are closing', 'other' => 'Another reason'),
        'admin'    => array('customer_request' => 'Customer asked us to', 'shop_problem' => 'Problem at the shop', 'no_courier' => 'No courier available', 'fraud' => 'Suspected fraud', 'test_order' => 'Test order', 'other' => 'Another reason'),
    );
    return isset($r[$role]) ? $r[$role] : array();
}

function etel_cancel_ensure_columns($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = array();
        foreach ($pdo->query("SHOW COLUMNS FROM orders")->fetchAll() as $c) $have[$c['Field']] = true;
        $add = array();
        if (empty($have['cancel_reason'])) $add[] = "ADD COLUMN cancel_reason VARCHAR(30) NULL DEFAULT NULL";
        if (empty($have['cancelled_by']))  $add[] = "ADD COLUMN cancelled_by VARCHAR(10) NULL DEFAULT NULL";
        if (empty($have['cancel_note']))   $add[] = "ADD COLUMN cancel_note VARCHAR(200) NULL DEFAULT NULL";
        if (empty($have['cancelled_at']))  $add[] = "ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL";
        if (empty($have['cancel_fee']))    $add[] = "ADD COLUMN cancel_fee DECIMAL(10,2) NOT NULL DEFAULT 0";
        if ($add) $pdo->exec("ALTER TABLE orders " . implode(', ', $add));
    } catch (Exception $e) { error_log('[ETEL] cancel columns: ' . $e->getMessage()); }
}

// Pure. Returns '' when $role may cancel an order in this state, otherwise the message to show.
// $order_status = orders.status; $assign_status = order_assignments.status ('' / null count as 'pending').
// $kind = 'package' for a parcel sent without a shop: there is no shop to start on it, so the customer may cancel until the courier has it.
function etel_cancel_rule($role, $order_status, $assign_status, $kind = 'shop') {
    if ($order_status === 'cancelled') return 'This order is already cancelled.';
    if ($order_status === 'delivered') return 'This order was already delivered.';
    $assign_status = ($assign_status === null || $assign_status === '') ? 'pending' : $assign_status;
    $onTheWay = ($order_status === 'picked_up' || $order_status === 'in_transit');
    if ($role === 'admin') return '';
    if ($onTheWay) return 'This order is already on its way and cannot be cancelled here. Please contact support.';
    if ($role === 'customer') {
        if ($kind === 'package') return '';
        if ($assign_status !== 'pending') return 'The shop has already started on your order, so it cannot be cancelled here. Please contact support.';
        return '';
    }
    if ($role === 'shop') {
        if (!in_array($assign_status, array('pending', 'preparing', 'accepted', 'assigned'), true)) return 'This order can no longer be declined.';
        return '';
    }
    return 'You cannot cancel this order.';
}

// What the sender would pay to cancel this order right now (ETB, 0 = free). Only a package, after a courier accepted it and before pickup,
// and only when it was paid online (that is the one case where the fee can really be kept: it comes out of the refund). The amount is the
// Admin setting (Package prices -> cancel fee). Never throws.
function etel_cancel_fee_for($pdo, $order_id) {
    try {
        require_once __DIR__ . '/package_orders.php';
        require_once __DIR__ . '/money_ledger.php';
        etel_package_ensure_schema($pdo); etel_cancel_ensure_columns($pdo);
        $paid = etel_money_paid_cond($pdo, 'o');
        $q = $pdo->prepare("SELECT o.status, o.order_kind, o.courier_id, o.courier_accepted_at, o.fare_total, ($paid) AS was_paid FROM orders o WHERE o.id = ? LIMIT 1");
        $q->execute(array(intval($order_id)));
        $o = $q->fetch();
        if (!$o || $o['order_kind'] !== 'package' || !intval($o['courier_id']) || empty($o['courier_accepted_at'])) return 0.0;
        if (!in_array($o['status'], array('pending', 'assigned', 'accepted'), true) || intval($o['was_paid']) !== 1) return 0.0;
        $fee = etel_package_setting($pdo, 'late_cancel_fee');
        return $fee > 0 ? round(min($fee, floatval($o['fare_total'])), 2) : 0.0;
    } catch (Exception $e) { error_log('[ETEL] cancel fee: ' . $e->getMessage()); } catch (Throwable $e) {}
    return 0.0;
}

function etel_cancel_notify_courier($pdo, $user_id, $order_id, $order_code) {
    $title = 'Order cancelled';
    $body = 'Order ' . $order_code . ' was cancelled. You do not need to deliver it.';
    try {
        require_once __DIR__ . '/../push.php';
        etel_ensure_notifications_table($pdo);
        $pdo->prepare("INSERT INTO notifications (user_id, title, body, type, data) VALUES (?,?,?,?,?)")
            ->execute(array($user_id, $title, $body, 'order_cancelled', json_encode(array('order_id' => $order_id, 'order_code' => $order_code))));
    } catch (Exception $e) { error_log('[ETEL] cancel courier notice: ' . $e->getMessage()); } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/../push.php';
        etel_push_send_async($pdo, intval($user_id), $title, $body, array('type' => 'order_cancelled', 'order_id' => $order_id, 'url' => '/delivery/courier/'));
    } catch (Exception $e) { error_log('[ETEL] cancel courier push: ' . $e->getMessage()); } catch (Throwable $e) {}
}

// $actor_id = the users.id doing it. $shop_id (shop role only) = the service_givers.id, which must be the shop on the order.
function etel_cancel_order($pdo, $order_id, $role, $actor_id, $reason, $note, $shop_id = 0, $confirm_fee = false) {
    $order_id = intval($order_id);
    $reasons = etel_cancel_reasons($role);
    if (!$reasons) return array('ok' => false, 'message' => 'You cannot cancel this order.');
    $reason = strtolower(trim((string) $reason));
    if (!isset($reasons[$reason])) return array('ok' => false, 'message' => 'Please choose a reason.');
    $note = substr(trim((string) $note), 0, 200);
    etel_cancel_ensure_columns($pdo);
    try { require_once __DIR__ . '/manual_dispatch.php'; etel_dispatch_ensure_schema($pdo); } catch (Exception $e) {} catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/package_orders.php';
        etel_package_ensure_schema($pdo);
        $s = $pdo->prepare("SELECT o.id, o.order_code, o.status, o.customer_id, o.courier_id, o.fare_total, o.order_kind,
                (SELECT oa.status FROM order_assignments oa WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS assign_status,
                (SELECT oa.service_giver_id FROM order_assignments oa WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS assign_shop
            FROM orders o WHERE o.id = ? LIMIT 1");
        $s->execute(array($order_id));
        $o = $s->fetch();
        if (!$o) return array('ok' => false, 'message' => 'Order not found.');
        if ($role === 'customer' && intval($o['customer_id']) !== intval($actor_id)) return array('ok' => false, 'message' => 'Order not found.');
        if ($role === 'shop' && (intval($shop_id) <= 0 || intval($o['assign_shop']) !== intval($shop_id))) return array('ok' => false, 'message' => 'Order not found.');
        $msg = etel_cancel_rule($role, $o['status'], $o['assign_status'], $o['order_kind'] === 'package' ? 'package' : 'shop');
        if ($msg !== '') return array('ok' => false, 'message' => $msg);

        // A customer cancelling a paid package after a courier accepted it pays a small fee (kept out of the refund). They must agree to it first.
        $fee = ($role === 'customer') ? etel_cancel_fee_for($pdo, $order_id) : 0.0;
        if ($fee > 0 && !$confirm_fee) {
            return array('ok' => false, 'fee' => $fee, 'message' => 'A courier has already accepted your package, so cancelling now costs ETB ' . rtrim(rtrim(number_format($fee, 2, '.', ''), '0'), '.') . ', taken from your refund. Please confirm to cancel.');
        }
        $prevCourier = $o['courier_id'] ? intval($o['courier_id']) : 0;
        $assignedAt = '';
        try { require_once __DIR__ . '/courier_stale.php'; if (etel_stale_has_col($pdo)) $assignedAt = ', courier_assigned_at = NULL'; } catch (Exception $e) {} catch (Throwable $e) {}
        $pdo->beginTransaction();
        try {
            $u = $pdo->prepare("UPDATE orders SET status = 'cancelled', courier_id = NULL, courier_accepted_at = NULL, courier_offer_expires_at = NULL$assignedAt,
                    cancel_reason = ?, cancelled_by = ?, cancel_note = ?, cancelled_at = NOW(), cancel_fee = ?
                WHERE id = ? AND status NOT IN ('delivered','cancelled')");
            $u->execute(array($reason, $role, $note, $fee, $order_id));
            if ($u->rowCount() !== 1) { $pdo->rollBack(); return array('ok' => false, 'message' => 'The order changed while you were looking at it. Refresh and try again.'); }
            try { $pdo->prepare("UPDATE order_assignments SET status = 'cancelled' WHERE order_id = ?")->execute(array($order_id)); } catch (Exception $e) { error_log('[ETEL] cancel assignment: ' . $e->getMessage()); }
            try { $pdo->prepare("UPDATE order_courier_offers SET response = 'cancelled', responded_at = NOW() WHERE order_id = ? AND response = 'pending'")->execute(array($order_id)); } catch (Exception $e) {}
            $pdo->commit();
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

        // a shop with Etel Business had these items taken off its stock when it accepted the order: put them back
        try { require_once __DIR__ . '/sg_business.php'; etel_sgb_order_cancelled($pdo, $order_id); } catch (Exception $e) {} catch (Throwable $e) {}

        if ($prevCourier) {
            try { require_once __DIR__ . '/manual_dispatch.php'; etel_dispatch_free_courier($pdo, $prevCourier); } catch (Exception $e) {} catch (Throwable $e) {}
        }
        try {
            require_once __DIR__ . '/manual_dispatch.php';
            etel_dispatch_log($pdo, $order_id, $actor_id, 'cancel', $role . ': ' . $reason . ($note !== '' ? ' - ' . $note : '') . ($fee > 0 ? ' (fee ETB ' . $fee . ')' : '') . ($prevCourier ? ' (courier #' . $prevCourier . ' released)' : ''));
        } catch (Exception $e) {} catch (Throwable $e) {}
        if (function_exists('etel_notify_order_status')) { try { etel_notify_order_status($pdo, $order_id, 'cancelled'); } catch (Exception $e) {} catch (Throwable $e) {} }
        if ($prevCourier) {
            try {
                $cu = $pdo->prepare("SELECT user_id FROM couriers WHERE id = ?"); $cu->execute(array($prevCourier));
                $uid = $cu->fetchColumn();
                if ($uid) etel_cancel_notify_courier($pdo, intval($uid), $order_id, $o['order_code']);
            } catch (Exception $e) {}
        }
        $refund = false; $amount = round(floatval($o['fare_total']), 2);
        try {
            require_once __DIR__ . '/money_ledger.php';
            $paid = etel_money_paid_cond($pdo, 'o');
            $q = $pdo->prepare("SELECT ($paid) FROM orders o WHERE o.id = ?"); $q->execute(array($order_id));
            $refund = (intval($q->fetchColumn()) === 1);
        } catch (Exception $e) {} catch (Throwable $e) {}
        return array('ok' => true, 'order_id' => $order_id, 'order_code' => $o['order_code'], 'refund_due' => $refund, 'amount' => $amount,
            'courier_released' => $prevCourier ?: null, 'fee' => $fee,
            'message' => 'Order cancelled.' . ($fee > 0 ? ' A cancel fee of ETB ' . rtrim(rtrim(number_format($fee, 2, '.', ''), '0'), '.') . ' was kept from your refund.' : '') . ($refund && $role === 'admin' ? ' The customer paid for it, so it now shows under Money > Refunds.' : ''));
    } catch (Exception $e) {
        error_log('[ETEL] cancel order: ' . $e->getMessage());
        return array('ok' => false, 'message' => 'Could not cancel the order. Please try again.');
    }
}
