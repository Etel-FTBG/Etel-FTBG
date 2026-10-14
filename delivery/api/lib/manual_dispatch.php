<?php
// Dispatch by hand for staff. Auto-dispatch picks the nearest free courier, but when nobody accepts (or the order is stuck) staff
// had no way to act: Admin could only change an order's status. This adds:
//   - a board of live orders that need attention (no courier, waiting for the courier to accept, accepted but not picked up, slow)
//   - assign / reassign a courier to an order that has not been picked up yet
//   - take an order back from its courier and offer it to the next one
// Every action is written to order_admin_actions (who, what, when). Mirrors what auto-dispatch does (orders.courier_id is a
// couriers.id, the courier turns 'busy', gets an in-app notice and a push). Never throws; returns array('ok' => bool, 'message' => ...).

function etel_dispatch_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_admin_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            admin_id INT NULL,
            action VARCHAR(30) NOT NULL,
            detail VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('[ETEL] dispatch schema: ' . $e->getMessage()); }
    try {
        require_once __DIR__ . '/courier_offers.php';
        etel_offers_ensure_schema($pdo);   // courier_accepted_at, courier_offer_expires_at
    } catch (Exception $e) {} catch (Throwable $e) {}
}

function etel_dispatch_log($pdo, $order_id, $admin_id, $action, $detail) {
    try {
        $pdo->prepare("INSERT INTO order_admin_actions (order_id, admin_id, action, detail) VALUES (?,?,?,?)")
            ->execute(array(intval($order_id), $admin_id ? intval($admin_id) : null, $action, substr((string) $detail, 0, 255)));
    } catch (Exception $e) { error_log('[ETEL] dispatch log: ' . $e->getMessage()); }
}

function etel_dispatch_first_name($n) {
    $n = trim((string) $n);
    if ($n === '') return '';
    $p = preg_split('/\s+/', $n);
    return $p[0];
}

// Pure: what a live order needs from staff. $o keys: status, courier_id, accepted (bool), age_min, since_assign_min, awaiting_payment.
// Returns '' (nothing, it is moving normally) or one of: no_courier, waiting_accept, not_picked_up, slow.
function etel_dispatch_need($o) {
    if (!empty($o['awaiting_payment'])) return '';
    $st = isset($o['status']) ? $o['status'] : '';
    if ($st === 'pending' && empty($o['courier_id'])) return 'no_courier';
    if ($st === 'assigned' && !empty($o['courier_id'])) {
        $since = isset($o['since_assign_min']) && $o['since_assign_min'] !== null ? $o['since_assign_min'] : (isset($o['age_min']) ? $o['age_min'] : 0);
        if (empty($o['accepted'])) return ($since >= 3) ? 'waiting_accept' : '';
        return ($since >= 30) ? 'not_picked_up' : '';
    }
    if (($st === 'picked_up' || $st === 'in_transit') && isset($o['age_min']) && $o['age_min'] >= 90) return 'slow';
    return '';
}

function etel_dispatch_board($pdo) {
    etel_dispatch_ensure_schema($pdo);
    $out = array('orders' => array(), 'couriers' => array(), 'needs_count' => 0);
    try {
        $hasAssigned = false;
        try { require_once __DIR__ . '/courier_stale.php'; $hasAssigned = etel_stale_has_col($pdo); } catch (Exception $e) {} catch (Throwable $e) {}
        $sinceAssign = $hasAssigned ? "TIMESTAMPDIFF(MINUTE, o.courier_assigned_at, NOW())" : "NULL";
        $s = $pdo->query("SELECT o.id, o.order_code, o.status, o.from_zone, o.to_zone, o.fare_total, o.placed_at, o.courier_id, o.courier_accepted_at,
                TIMESTAMPDIFF(MINUTE, o.placed_at, NOW()) AS age_min, $sinceAssign AS since_assign_min,
                (SELECT sg.business_name FROM order_assignments oa JOIN service_givers sg ON sg.id = oa.service_giver_id WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS shop,
                u.name AS courier_name, c.courier_code
            FROM orders o LEFT JOIN couriers c ON c.id = o.courier_id LEFT JOIN users u ON u.id = c.user_id
            WHERE o.status IN ('pending','assigned','picked_up','in_transit') AND o.placed_at > DATE_SUB(NOW(), INTERVAL 2 DAY)
            ORDER BY o.placed_at ASC LIMIT 150");
        $rank = array('no_courier' => 0, 'waiting_accept' => 1, 'not_picked_up' => 2, 'slow' => 3, '' => 4);
        $rows = array();
        foreach ($s->fetchAll() as $r) {
            $awaiting = false;
            if ($r['status'] === 'pending' && empty($r['courier_id'])) {
                try { require_once __DIR__ . '/order_dispatch.php'; $awaiting = etel_order_awaiting_chapa($pdo, intval($r['id'])); } catch (Exception $e) {} catch (Throwable $e) {}
            }
            $need = etel_dispatch_need(array('status' => $r['status'], 'courier_id' => $r['courier_id'], 'accepted' => $r['courier_accepted_at'] !== null,
                'age_min' => intval($r['age_min']), 'since_assign_min' => $r['since_assign_min'] === null ? null : intval($r['since_assign_min']), 'awaiting_payment' => $awaiting));
            $rows[] = array('order_id' => intval($r['id']), 'order_code' => $r['order_code'], 'status' => $r['status'], 'need' => $need, 'awaiting_payment' => $awaiting,
                'from_zone' => $r['from_zone'], 'to_zone' => $r['to_zone'], 'shop' => $r['shop'], 'fare_total' => round(floatval($r['fare_total']), 2),
                'age_min' => intval($r['age_min']), 'courier_id' => $r['courier_id'] ? intval($r['courier_id']) : null,
                'courier' => etel_dispatch_first_name($r['courier_name']), 'courier_code' => $r['courier_code'], 'accepted' => $r['courier_accepted_at'] !== null,
                'can_assign' => in_array($r['status'], array('pending', 'assigned'), true) && !$awaiting);
        }
        usort($rows, function ($a, $b) use ($rank) {
            $ra = $rank[$a['need']]; $rb = $rank[$b['need']];
            if ($ra !== $rb) return $ra < $rb ? -1 : 1;
            return $b['age_min'] - $a['age_min'];
        });
        foreach ($rows as $r) { if ($r['need'] !== '') $out['needs_count']++; }
        $out['orders'] = $rows;
        $cs = $pdo->query("SELECT c.id, c.courier_code, c.status, c.zone, u.name, u.phone, TIMESTAMPDIFF(MINUTE, c.last_seen, NOW()) AS seen_min,
                (SELECT COUNT(*) FROM orders o WHERE o.courier_id = c.id AND o.status IN ('assigned','picked_up','in_transit')) AS live_jobs
            FROM couriers c LEFT JOIN users u ON u.id = c.user_id
            WHERE c.active = 1 AND (c.status IN ('online','busy') OR c.last_seen > DATE_SUB(NOW(), INTERVAL 6 HOUR))
            ORDER BY (c.status = 'online') DESC, live_jobs ASC, c.last_seen DESC LIMIT 60");
        foreach ($cs->fetchAll() as $c) {
            $out['couriers'][] = array('courier_id' => intval($c['id']), 'courier_code' => $c['courier_code'], 'name' => etel_dispatch_first_name($c['name']), 'phone' => $c['phone'],
                'status' => $c['status'], 'zone' => $c['zone'], 'seen_min' => $c['seen_min'] === null ? null : intval($c['seen_min']), 'live_jobs' => intval($c['live_jobs']));
        }
    } catch (Exception $e) { error_log('[ETEL] dispatch board: ' . $e->getMessage()); }
    return $out;
}

// A courier is free again unless they still have another live delivery.
function etel_dispatch_free_courier($pdo, $courier_id) {
    if (!$courier_id) return;
    try {
        $pdo->prepare("UPDATE couriers SET status = 'online' WHERE id = ? AND status = 'busy'
                       AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.courier_id = couriers.id AND o.status IN ('assigned','picked_up','in_transit'))")
            ->execute(array(intval($courier_id)));
    } catch (Exception $e) {}
}

function etel_dispatch_notify_courier($pdo, $user_id, $order_id, $order_code, $from_zone, $to_zone, $fare) {
    $title = 'New Delivery Job!';
    $body = 'Order ' . $order_code . ' from ' . $from_zone . ' to ' . $to_zone . '  ETB ' . number_format($fare, 2);
    try {
        require_once __DIR__ . '/../push.php';
        etel_ensure_notifications_table($pdo);
        $pdo->prepare("INSERT INTO notifications (user_id, title, body, type, data) VALUES (?,?,?,?,?)")
            ->execute(array($user_id, $title, $body, 'new_order', json_encode(array('order_id' => $order_id, 'order_code' => $order_code))));
    } catch (Exception $e) { error_log('[ETEL] manual dispatch notice: ' . $e->getMessage()); } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/../push.php';
        etel_push_send_async($pdo, intval($user_id), $title, $body, array('type' => 'new_order', 'order_id' => $order_id, 'url' => '/delivery/courier/'));
    } catch (Exception $e) { error_log('[ETEL] manual dispatch push: ' . $e->getMessage()); } catch (Throwable $e) {}
}

// Staff give an order that has not been picked up yet to a specific courier (first assignment, or a change of courier).
function etel_admin_assign_courier($pdo, $order_id, $courier_id, $admin_id) {
    etel_dispatch_ensure_schema($pdo);
    $order_id = intval($order_id); $courier_id = intval($courier_id);
    try {
        $s = $pdo->prepare("SELECT id, order_code, status, courier_id, from_zone, to_zone, fare_total FROM orders WHERE id = ? LIMIT 1");
        $s->execute(array($order_id));
        $o = $s->fetch();
        if (!$o) return array('ok' => false, 'message' => 'Order not found.');
        if (!in_array($o['status'], array('pending', 'assigned'), true)) {
            return array('ok' => false, 'message' => $o['status'] === 'picked_up' || $o['status'] === 'in_transit'
                ? 'This order is already on its way. A courier cannot be changed after pickup.' : 'This order is already ' . $o['status'] . '.');
        }
        try { require_once __DIR__ . '/order_dispatch.php'; if (etel_order_awaiting_chapa($pdo, $order_id)) return array('ok' => false, 'message' => 'This order is waiting for the customer to pay.'); } catch (Exception $e) {} catch (Throwable $e) {}
        $c = $pdo->prepare("SELECT c.id, c.user_id, c.active, u.name FROM couriers c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ? LIMIT 1");
        $c->execute(array($courier_id));
        $cr = $c->fetch();
        if (!$cr) return array('ok' => false, 'message' => 'Courier not found.');
        if (intval($cr['active']) !== 1) return array('ok' => false, 'message' => 'This courier is not active.');
        $prev = $o['courier_id'] ? intval($o['courier_id']) : 0;
        if ($prev === $courier_id) return array('ok' => false, 'message' => 'This courier already has the order.');

        $assignedAt = '';
        try { require_once __DIR__ . '/courier_stale.php'; if (etel_stale_has_col($pdo)) $assignedAt = ', courier_assigned_at = NOW()'; } catch (Exception $e) {} catch (Throwable $e) {}
        $pdo->beginTransaction();
        try {
            // staff arranged it, so it counts as accepted: the stale-assignment sweep must not take it back from this courier
            $u = $pdo->prepare("UPDATE orders SET courier_id = ?, status = 'assigned', courier_accepted_at = NOW(), courier_offer_expires_at = NULL$assignedAt
                                WHERE id = ? AND status IN ('pending','assigned')");
            $u->execute(array($courier_id, $order_id));
            $pdo->prepare("UPDATE order_courier_offers SET response = 'reassigned', responded_at = NOW() WHERE order_id = ? AND response = 'pending'")->execute(array($order_id));
            $pdo->commit();
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        etel_dispatch_free_courier($pdo, $prev);
        $pdo->prepare("UPDATE couriers SET status = 'busy' WHERE id = ?")->execute(array($courier_id));
        etel_dispatch_log($pdo, $order_id, $admin_id, $prev ? 'reassign' : 'assign', ($prev ? 'from courier #' . $prev . ' ' : '') . 'to courier #' . $courier_id);
        if (function_exists('etel_notify_order_status')) { try { etel_notify_order_status($pdo, $order_id); } catch (Exception $e) {} }
        etel_dispatch_notify_courier($pdo, intval($cr['user_id']), $order_id, $o['order_code'], $o['from_zone'], $o['to_zone'], floatval($o['fare_total']));
        return array('ok' => true, 'message' => ($prev ? 'Courier changed.' : 'Courier assigned.'), 'order_id' => $order_id, 'courier_id' => $courier_id, 'previous_courier_id' => $prev ?: null);
    } catch (Exception $e) {
        error_log('[ETEL] admin assign: ' . $e->getMessage());
        return array('ok' => false, 'message' => 'Could not assign the courier. Please try again.');
    }
}

// Take an order that has not been picked up away from its courier and offer it to the next free one (the old courier is skipped).
function etel_admin_take_back($pdo, $order_id, $admin_id) {
    etel_dispatch_ensure_schema($pdo);
    $order_id = intval($order_id);
    try {
        $s = $pdo->prepare("SELECT id, order_code, status, courier_id FROM orders WHERE id = ? LIMIT 1");
        $s->execute(array($order_id));
        $o = $s->fetch();
        if (!$o) return array('ok' => false, 'message' => 'Order not found.');
        if ($o['status'] !== 'assigned' || !$o['courier_id']) {
            return array('ok' => false, 'message' => in_array($o['status'], array('picked_up', 'in_transit'), true) ? 'This order is already on its way. It cannot be taken back.' : 'This order has no courier to take it back from.');
        }
        $prev = intval($o['courier_id']);
        $assignedAt = '';
        try { require_once __DIR__ . '/courier_stale.php'; if (etel_stale_has_col($pdo)) $assignedAt = ', courier_assigned_at = NULL'; } catch (Exception $e) {} catch (Throwable $e) {}
        $u = $pdo->prepare("UPDATE orders SET courier_id = NULL, status = 'pending', courier_accepted_at = NULL, courier_offer_expires_at = NULL$assignedAt
                            WHERE id = ? AND courier_id = ? AND status = 'assigned'");
        $u->execute(array($order_id, $prev));
        if ($u->rowCount() !== 1) return array('ok' => false, 'message' => 'The order changed while you were looking at it. Refresh and try again.');
        $pdo->prepare("INSERT INTO order_courier_offers (order_id, courier_id, offered_at, expires_at, response, responded_at) VALUES (?, ?, NOW(), NOW(), 'taken_back', NOW())")->execute(array($order_id, $prev));
        $pdo->prepare("UPDATE order_courier_offers SET response = 'reassigned', responded_at = NOW() WHERE order_id = ? AND response = 'pending'")->execute(array($order_id));
        etel_dispatch_free_courier($pdo, $prev);
        etel_dispatch_log($pdo, $order_id, $admin_id, 'take_back', 'from courier #' . $prev);
        if (function_exists('etel_notify_order_status')) { try { etel_notify_order_status($pdo, $order_id); } catch (Exception $e) {} }
        $next = null;
        try {
            require_once __DIR__ . '/order_dispatch.php';
            require_once __DIR__ . '/courier_stale.php';
            $tried = etel_stale_tried($pdo, $order_id);
            if (!in_array($prev, $tried, true)) $tried[] = $prev;
            etel_dispatch_order($pdo, $order_id, $tried);
            $n = $pdo->prepare("SELECT courier_id FROM orders WHERE id = ?"); $n->execute(array($order_id));
            $next = $n->fetchColumn();
        } catch (Exception $e) { error_log('[ETEL] take back redispatch: ' . $e->getMessage()); } catch (Throwable $e) { error_log('[ETEL] take back redispatch: ' . $e->getMessage()); }
        return array('ok' => true, 'order_id' => $order_id, 'previous_courier_id' => $prev, 'new_courier_id' => $next ? intval($next) : null,
            'message' => $next ? 'Taken back and offered to another courier.' : 'Taken back. No other courier is free right now, so assign one by hand.');
    } catch (Exception $e) {
        error_log('[ETEL] admin take back: ' . $e->getMessage());
        return array('ok' => false, 'message' => 'Could not take the order back. Please try again.');
    }
}

// Number of live orders that need staff (for the dashboard row).
function etel_dispatch_needs_count($pdo) {
    try {
        $b = etel_dispatch_board($pdo);
        return intval($b['needs_count']);
    } catch (Exception $e) { return 0; }
}
