<?php
// Couriers can see and call the recipient, but not see their name - mirrors
// how a lot of consumer delivery apps handle recipient privacy (tracking
// link + phone only). Only applied to courier-facing order lists; admin,
// the customer's own view, and service-giver views are unaffected.
if (!function_exists('etel_mask_recipient_for_courier')) {
    function etel_mask_recipient_for_courier(&$orders) {
        foreach ($orders as &$o) {
            if (array_key_exists('recipient_name', $o)) {
                $o['recipient_name'] = 'Recipient';
            }
        }
        unset($o);
    }
}
require_once __DIR__ . '/../lib/delivery_order_core.php';

switch ($action) {

case 'customer_create_order': {
    // POST: (requires customer auth)  items (array of {item_id, quantity}), to_zone, recipient_name, recipient_phone, notes
    // Thin wrapper - all real logic lives in delivery_execute_order_creation()
    // above so it can also be called directly (no HTTP round-trip) by
    // AgentController.php's agent_place_order.
    $pdo = db();
    $auth_user = require_auth($pdo);
    try {
        $body['_allow_chapa'] = true; // checkout UI follows up with chapa_init
        $t_order = microtime(true);
        $result = delivery_execute_order_creation($pdo, $auth_user, $body);
        $ms_order = (int)round((microtime(true) - $t_order) * 1000);
        if ($ms_order > 1500) error_log('[ETEL][timing] customer_create_order took ' . $ms_order . ' ms (sapi=' . PHP_SAPI . ')');
        ok($result);
    } catch (Exception $e) {
        fail($e->getMessage(), 400);
    }
    break;
}

case 'customer_cancel_order': {
    // POST: order_id, reason (changed_mind|wrong_order|too_slow|other), note?  -> the customer cancels their own order
    // Only before the shop starts preparing (api/lib/order_cancel.php decides). A paid order is then flagged for refund in Admin > Money.
    $pdo = db();
    $auth_user = require_auth($pdo);
    require_once __DIR__ . '/../lib/order_cancel.php';
    $r = etel_cancel_order($pdo, isset($body['order_id']) ? $body['order_id'] : 0, 'customer', $auth_user['id'], isset($body['reason']) ? $body['reason'] : '', isset($body['note']) ? $body['note'] : '', 0, !empty($body['confirm_fee']));
    if (!$r['ok']) fail($r['message'], 400);
    ok(array('message' => $r['message'], 'order_id' => $r['order_id'], 'refund_due' => $r['refund_due'], 'fee' => isset($r['fee']) ? $r['fee'] : 0));
    break;
}
case 'customer_get_orders': {
    // GET: (requires customer auth)  ?status=pending|accepted|picked_up|delivered
    // Get customer's orders
    $pdo = db();
    try { require_once __DIR__ . '/../lib/courier_offers.php'; etel_offers_sweep($pdo); } catch (Exception $swe) {} catch (Throwable $swe) {}
    try { require_once __DIR__ . '/../lib/order_dispatch.php'; etel_cancel_unpaid_chapa_orders($pdo); } catch (Exception $swe) {} catch (Throwable $swe) {}
    $auth_user = require_auth($pdo);
    
    // Role check removed - any authenticated EtelID can now use the
    // Customer app regardless of their assigned role (courier, driver,
    // service_giver, admin), matching the same policy just added at
    // login (see AuthController.php's 'login'/'etelid_login' - the
    // Customer tab is exempt from the tab-matches-role restriction
    // every other tab still enforces). Without this, a driver/courier/
    // service_giver account could log into customer.html successfully
    // but then get 403'd on the very first order/wallet-related call
    // it makes, which looked exactly like an automatic logout even
    // though nothing actually logged them out. require_auth() above
    // still requires a valid, real session either way.
    
    require_once __DIR__ . '/../lib/package_orders.php';
    etel_package_ensure_schema($pdo);   // the package columns must exist before they are selected below
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $where = 'WHERE o.customer_id = ?';
    $params = array($auth_user['id']);
    
    if ($status && in_array($status, array('pending','accepted','picked_up','delivered','cancelled'))) {
        $where .= ' AND o.status = ?';
        $params[] = $status;
    }
    
    try {
        $s = $pdo->prepare("
            SELECT 
                o.id,
                o.order_code,
                o.from_zone,
                o.to_zone,
                o.package_type, o.delivery_tier, o.order_kind,
                o.recipient_name,
                o.recipient_phone,
                o.delivery_photo_url,
                o.fare_total,
                o.status,
                o.placed_at,
                o.notes,
                oa.status as assignment_status,
                oa.accepted_at,
                (SELECT r.stars FROM order_ratings r WHERE r.order_id = o.id LIMIT 1) AS my_rating
            FROM orders o
            LEFT JOIN order_assignments oa ON o.id = oa.order_id
            $where
            ORDER BY o.placed_at DESC
        ");
        $s->execute($params);
        $orders = $s->fetchAll();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }
    
    ok(array('orders' => $orders, 'count' => count($orders)));
    break;
}

case 'customer_reorder_items': {
    // GET ?order_id=N  (customer auth). Returns the lines of one of the caller's
    // own past orders joined to the CURRENT item row, so the app can rebuild the
    // cart at today's prices and skip anything no longer orderable.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : (isset($body['order_id']) ? intval($body['order_id']) : 0);
    if ($order_id <= 0) fail('order_id required', 400);
    try {
        $own = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND customer_id = ?');
        $own->execute(array($order_id, $auth_user['id']));
        if (!$own->fetch()) fail('Order not found', 404);
        require_once __DIR__ . '/../lib/shop_hours.php';
        etel_hours_ensure_columns($pdo);
        $s = $pdo->prepare("
            SELECT oi.item_id, oi.item_name, oi.quantity,
                   i.name AS cur_name, i.price AS cur_price, i.is_available, i.stock_quantity, i.sold_out_date, sg.opens_at, sg.closes_at,
                   i.service_giver_id, sg.business_name, sg.status AS sg_status, COALESCE(sg.is_online,0) AS sg_online
            FROM order_items oi
            LEFT JOIN items i ON i.id = oi.item_id
            LEFT JOIN service_givers sg ON sg.id = i.service_giver_id
            WHERE oi.order_id = ?
        ");
        $s->execute(array($order_id));
        $rows = $s->fetchAll();
    } catch (Exception $e) { fail_ex($e, 'Query failed', 500); }
    $lines = array();
    foreach ($rows as $r) {
        $stock = ($r['stock_quantity'] === null) ? null : intval($r['stock_quantity']);
        $orderable = $r['cur_price'] !== null && intval($r['is_available']) === 1
                     && ($stock === null || $stock > 0) && $r['sg_status'] === 'verified'
                     && !etel_item_sold_out($r['sold_out_date'], date('Y-m-d'));
        $shop = etel_shop_state(intval($r['sg_online']) === 1, $r['opens_at'], $r['closes_at'], date('H:i'));
        $lines[] = array(
            'item_id'          => intval($r['item_id']),
            'name'             => $r['cur_name'] !== null ? $r['cur_name'] : $r['item_name'],
            'quantity'         => max(1, intval($r['quantity'])),
            'price'            => $r['cur_price'] !== null ? floatval($r['cur_price']) : null,
            'service_giver_id' => $r['service_giver_id'] !== null ? intval($r['service_giver_id']) : null,
            'business_name'    => $r['business_name'],
            'is_online'        => $shop['open'],
            'orderable'        => $orderable,
        );
    }
    ok(array('items' => $lines));
    break;
}

case 'submit_order_issue': {
    // POST: (requires customer auth)  order_id (optional), category, details
    // Delivery's counterpart to RideController.php's submit_ride_issue -
    // Ride has had a real dispute pipeline (report -> admin review ->
    // resolve) for a while; orders had nothing, so a customer with a
    // botched delivery had no in-app recourse. Same shape, same table
    // pattern (self-healing CREATE TABLE, no FK constraints - matches
    // sos_alerts in RideController.php, not ride_issues, since this repo
    // has no migration file for order_issues to run and shared hosting
    // makes a manual one easy to forget).
    $pdo = db();
    $auth_user = require_auth($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_issues (
            id INT(11) NOT NULL AUTO_INCREMENT,
            order_id INT(11) DEFAULT NULL,
            reporter_id INT(11) NOT NULL,
            category VARCHAR(30) NOT NULL,
            details VARCHAR(2000) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_order (order_id),
            KEY idx_reporter (reporter_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $category = isset($body['category']) ? trim($body['category']) : '';
    $allowed = array('item_missing', 'item_damaged', 'wrong_item', 'late_delivery', 'never_arrived', 'courier_behavior', 'other');
    if (!in_array($category, $allowed, true)) fail('Invalid issue category', 400);
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : null;
    if ($order_id) {
        // Scope check - a customer can only file an issue against their
        // own order, same reasoning as every other customer_* endpoint
        // in this file (o.customer_id = ?).
        $chk = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND customer_id = ?');
        $chk->execute(array($order_id, $auth_user['id']));
        if (!$chk->fetch()) fail('Order not found', 404);
    } else {
        $order_id = null;
    }
    $details = isset($body['details']) ? trim(substr($body['details'], 0, 2000)) : '';
    try {
        $s = $pdo->prepare('INSERT INTO order_issues (order_id, reporter_id, category, details, status) VALUES (?, ?, ?, ?, "open")');
        $s->execute(array($order_id, $auth_user['id'], $category, $details));
        ok(array('message' => 'Report submitted - our team will follow up', 'id' => intval($pdo->lastInsertId())));
    } catch (Exception $e) {
        fail_ex($e, 'Could not submit report', 500);
    }
    break;
}

case 'customer_get_order_issues': {
    // GET: (requires customer auth) - lets the customer see their own past
    // reports and status, same as they can already see order history.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_issues (
            id INT(11) NOT NULL AUTO_INCREMENT,
            order_id INT(11) DEFAULT NULL,
            reporter_id INT(11) NOT NULL,
            category VARCHAR(30) NOT NULL,
            details VARCHAR(2000) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_order (order_id),
            KEY idx_reporter (reporter_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $s = $pdo->prepare('SELECT * FROM order_issues WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 100');
    $s->execute(array($auth_user['id']));
    ok(array('issues' => $s->fetchAll()));
    break;
}

case 'courier_get_available_orders': {
    $pdo = db();
    $courier_user = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);
    try { require_once __DIR__ . '/../lib/courier_offers.php'; etel_offers_sweep($pdo); } catch (Exception $swe) {} catch (Throwable $swe) {}
    try { require_once __DIR__ . '/../lib/order_dispatch.php'; etel_cancel_unpaid_chapa_orders($pdo); } catch (Exception $swe) {} catch (Throwable $swe) {}

    require_once __DIR__ . '/../lib/courier_stale.php';
    $old_jobs = etel_job_age_filter($pdo);   // hides jobs nobody took for hours (see lib/courier_stale.php)
    // Package jobs show only to couriers whose vehicle is the one the customer chose (api/lib/package_orders.php).
    require_once __DIR__ . '/../lib/package_orders.php';
    etel_package_ensure_schema($pdo);
    $vc = $pdo->prepare('SELECT vehicle_type FROM couriers WHERE id = ?'); $vc->execute(array($courier['id']));
    $my_class = etel_vehicle_class_of($vc->fetchColumn(), $pdo);
    if (!empty($_GET['pkg'])) etel_package_mark_capable($pdo, $courier['id']);   // this app can run package jobs
    // An app that cannot run packages (no pickup address, no PIN step) is never shown one: it would accept it and be stuck.
    $pkg_filter = etel_package_courier_capable($pdo, $courier['id']) ? " AND (o.order_kind <> 'package' OR o.vehicle_class = ?)" : " AND (o.order_kind <> 'package' OR o.vehicle_class = ? AND 1 = 0)";
    $pkg_filter .= ' AND o.customer_id <> ' . intval($courier_user['id']);   // a courier never sees an order they placed themselves
    $pkg_cols = ', o.order_kind, o.vehicle_class, o.pickup_address, o.pkg_size, o.pkg_weight_kg, o.pkg_desc, o.distance_km';

    // Ensure order_assignments exists and has needed columns
    try {
        $pdo->query("CREATE TABLE IF NOT EXISTS order_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            service_giver_id INT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY(order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    // Same fix as courier_get_my_orders: check first, alter only if missing.
    try {
        require_once __DIR__ . '/../lib/schema_helpers.php';
        rides_add_missing_cols($pdo, 'order_assignments', array('status' => "`status` VARCHAR(30) NOT NULL DEFAULT 'pending'"));
    } catch (Exception $e) {}

    // Try full query with order_assignments join first
    try {
        $s = $pdo->prepare("
            SELECT o.id, o.order_code, o.from_zone, o.to_zone, o.package_type, o.delivery_tier,
                   o.recipient_name, o.recipient_phone, o.delivery_photo_url, o.fare_total, o.delivery_fee, o.status, o.placed_at,
                   o.payment_method,
                   oa.service_giver_id, oa.status AS assignment_status$pkg_cols
            FROM orders o
            LEFT JOIN order_assignments oa ON o.id = oa.order_id
            WHERE (oa.status IN ('pending','preparing','assigned','accepted') OR (oa.id IS NULL AND o.order_kind = 'package'))
              AND (o.courier_id IS NULL OR o.courier_id = 0)
              AND o.status NOT IN ('cancelled','delivered')
              AND NOT (o.payment_method = 'chapa' AND o.payment_status <> 'paid')
              $old_jobs$pkg_filter
            ORDER BY o.placed_at ASC LIMIT 50
        ");
        $s->execute(array($my_class));
        $orders = $s->fetchAll();
    } catch (Exception $e) {
        // Fallback: show all unassigned pending orders without the join
        try {
            $s = $pdo->prepare("
                SELECT o.id, o.order_code, o.from_zone, o.to_zone, o.package_type, o.delivery_tier,
                       o.recipient_name, o.recipient_phone, o.delivery_photo_url, o.fare_total, o.delivery_fee, o.status, o.placed_at,
                       o.payment_method,
                       NULL AS service_giver_id, 'pending' AS assignment_status$pkg_cols
                FROM orders o
                WHERE (o.courier_id IS NULL OR o.courier_id = 0)
                  AND o.status IN ('pending','assigned')
                  AND NOT (o.payment_method = 'chapa' AND o.payment_status <> 'paid')
                  $old_jobs$pkg_filter
                ORDER BY o.placed_at ASC LIMIT 50
            ");
            $s->execute(array($my_class));
            $orders = $s->fetchAll();
        } catch (Exception $e2) { fail_ex($e2, 'Query failed', 500); }
    }
    etel_mask_recipient_for_courier($orders);
    require_once __DIR__ . '/../lib/courier_earnings.php';
    etel_apply_courier_tip($pdo, $orders, $courier['id'], false);   // offers: an estimate from this courier's position, not saved
    ok(array('orders' => $orders, 'count' => count($orders)));
    break;
}
case 'courier_accept_order': {
    // POST: (requires courier auth)  order_id
    // Courier accepts an order for delivery
    $pdo = db();
    $courier_user = require_courier($pdo);
    
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);

    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);

    // Check if order exists and is available
    try {
        $o = $pdo->prepare('SELECT id, status, courier_id FROM orders WHERE id=?');
        $o->execute(array($order_id));
        $order = $o->fetch();
    } catch (Exception $e) { $order = null; }
    
    if (!$order) fail('Order not found', 404);
    if ($order['courier_id']) fail('Order already assigned to another courier', 409);
    require_once __DIR__ . '/../lib/order_dispatch.php';
    if (in_array($order['status'], array('cancelled', 'delivered'), true) || etel_order_awaiting_chapa($pdo, $order_id)) fail('This order is not available', 409);
    // A package goes only to a courier with the vehicle the customer chose.
    require_once __DIR__ . '/../lib/package_orders.php';
    etel_package_ensure_schema($pdo);
    $pk = $pdo->prepare('SELECT o.order_kind, o.vehicle_class, c.vehicle_type FROM orders o JOIN couriers c ON c.id = ? WHERE o.id = ?');
    $pk->execute(array($courier['id'], $order_id));
    $pkr = $pk->fetch();
    if ($pkr && $pkr['order_kind'] === 'package' && etel_vehicle_class_of($pkr['vehicle_type'], $pdo) !== $pkr['vehicle_class']) fail('This package needs a different vehicle.', 403);
    if ($pkr && $pkr['order_kind'] === 'package' && !etel_package_courier_capable($pdo, $courier['id'])) fail('Update your courier app to take package jobs.', 403);
    $own = $pdo->prepare('SELECT customer_id FROM orders WHERE id = ?'); $own->execute(array($order_id));
    if (intval($own->fetchColumn()) === intval($courier_user['id'])) fail('You cannot deliver an order you placed yourself.', 403);
    
    // Assign courier to order
    try {
        $pdo->beginTransaction();
        
        // First courier to claim wins: the update only matches while nobody else has the order.
        if (!etel_claim_order($pdo, $order_id, $courier['id'])) { $pdo->rollBack(); fail('Another courier already took this job.', 409); }
        try { require_once __DIR__ . '/../lib/courier_offers.php'; etel_offer_mark_accepted($pdo, $order_id); } catch (Exception $oe) {} catch (Throwable $oe) {}

        // Mark assignment as courier-assigned (distinct from SG-accepted)
        $upd_asgn = $pdo->prepare('UPDATE order_assignments SET status="assigned" WHERE order_id=?');
        $upd_asgn->execute(array($order_id));
        
        $pdo->commit();
        if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id);
        
        ok(array('message' => 'Order accepted successfully', 'order_id' => $order_id));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Failed to accept order', 500);
    }
    break;
}
case 'courier_accept_offer': {
    // POST order_id -> the courier accepts the order that was offered to them (before the offer runs out).
    $pdo = db();
    $courier_user = require_courier($pdo);
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);
    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);
    try {
        require_once __DIR__ . '/../lib/courier_offers.php';
        etel_offers_sweep($pdo);
        require_once __DIR__ . '/../lib/package_orders.php';
        $pk = $pdo->prepare("SELECT order_kind, customer_id FROM orders WHERE id = ?"); $pk->execute(array($order_id));
        $pkRow = $pk->fetch(PDO::FETCH_ASSOC);
        if ($pkRow && intval($pkRow['customer_id']) === intval($courier_user['id'])) fail('You cannot deliver an order you placed yourself.', 403);
        if ($pkRow && $pkRow['order_kind'] === 'package' && !etel_package_courier_capable($pdo, $courier['id'])) fail('Update your courier app to take package jobs.', 403);
        $r = etel_offer_accept($pdo, $order_id, $courier['id']);
    } catch (Exception $e) { fail_ex($e, 'Could not accept the offer', 500); }
    if ($r !== 'accepted') fail('This offer has expired or was given to another courier', 409);
    ok(array('message' => 'Offer accepted', 'order_id' => $order_id));
    break;
}
case 'courier_decline_offer': {
    // POST order_id -> the courier declines; the order is offered to the next courier.
    $pdo = db();
    $courier_user = require_courier($pdo);
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);
    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);
    try {
        require_once __DIR__ . '/../lib/courier_offers.php';
        etel_offer_decline($pdo, $order_id, $courier['id']);
    } catch (Exception $e) { fail_ex($e, 'Could not decline the offer', 500); }
    ok(array('message' => 'Offer declined', 'order_id' => $order_id));
    break;
}
case 'courier_update_order_status': {
    // POST: (requires courier auth)  order_id, status (picked_up|in_transit|delivered)
    // Update order status during delivery
    $pdo = db();
    $courier_user = require_courier($pdo);
    
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $status = isset($body['status']) ? trim($body['status']) : '';
    
    if (!$order_id) fail('order_id is required', 400);
    if (!in_array($status, array('picked_up', 'in_transit', 'delivered'))) {
        fail('Invalid status. Must be: picked_up, in_transit, or delivered', 400);
    }
    
    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);

    // Verify courier is assigned to this order
    try {
        $o = $pdo->prepare('SELECT id, courier_id, order_kind FROM orders WHERE id=?');
        $o->execute(array($order_id));
        $order = $o->fetch();
    } catch (Exception $e) { $order = null; }
    
    if (!$order) fail('Order not found', 404);
    if ($order['courier_id'] != $courier['id']) fail('You are not assigned to this order', 403);
    // A package is picked up and delivered only with the PINs (courier_package_pickup / courier_package_deliver), never by a plain status change.
    if (isset($order['order_kind']) && $order['order_kind'] === 'package' && in_array($status, array('picked_up', 'delivered'), true)) {
        fail('Packages are picked up and delivered with the PIN. Use the package buttons in the app.', 400);
    }
    // Moving the order forward counts as accepting it (an expiring offer can no longer be taken back).
    try { require_once __DIR__ . '/../lib/courier_offers.php'; etel_offer_mark_accepted($pdo, $order_id); } catch (Exception $oe) {} catch (Throwable $oe) {}
    
    // Update order status
    try {
        $pdo->beginTransaction();
        
        if ($status === 'delivered') {
            $upd = $pdo->prepare('UPDATE orders SET status=?, delivered_at=NOW() WHERE id=?');
            $upd->execute(array($status, $order_id));
            $pdo->prepare('UPDATE order_assignments SET status="delivered" WHERE order_id=?')->execute(array($order_id));
        } elseif ($status === 'picked_up') {
            $upd = $pdo->prepare('UPDATE orders SET status=? WHERE id=?');
            $upd->execute(array($status, $order_id));
            // Notify SG and admin: assignment now shows picked_up
            $pdo->prepare('UPDATE order_assignments SET status="picked_up" WHERE order_id=?')->execute(array($order_id));
        } else {
            $upd = $pdo->prepare('UPDATE orders SET status=? WHERE id=?');
            $upd->execute(array($status, $order_id));
        }
        
        $pdo->commit();
        if ($status === 'picked_up') { require_once __DIR__ . '/../lib/order_stamps.php'; etel_order_stamp_pickup($pdo, $order_id); }
        if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id);
        
        ok(array('message' => 'Order status updated', 'status' => $status));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Failed to update status', 500);
    }
    break;
}
case 'courier_get_profile': {
    // This action was called by the live courier app but never
    // existed anywhere in the backend - a genuinely missing feature,
    // not a whitelist gap, which is why the app's Profile page showed
    // blank Vehicle/Zone/Courier ID fields (confirmed via the app's
    // own diagnostic panel showing a 404 on this exact action).
    $pdo = db();
    $u = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $u['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);
    try {
        $s = $pdo->prepare('SELECT courier_code, vehicle_type, plate_number, home_zone, zone, status, rating, total_deliveries FROM couriers WHERE id=?');
        $s->execute(array($courier['id']));
        $full = $s->fetch();
        $e = $pdo->prepare("SELECT COALESCE(SUM(delivery_fee),0) AS total FROM orders WHERE courier_id=? AND status='delivered'");
        $e->execute(array($courier['id']));
        $allTimeEarnings = round(floatval($e->fetch()['total']), 2);
    } catch (Exception $e2) {
        fail_ex($e2, 'Could not load courier profile', 500);
    }
    ok(array(
        'courier_code' => $full['courier_code'], 'vehicle_type' => $full['vehicle_type'],
        'plate_number' => $full['plate_number'], 'zone' => $full['zone'] ?: $full['home_zone'],
        'status' => $full['status'], 'rating' => floatval($full['rating']),
        'total_deliveries' => intval($full['total_deliveries']), 'all_time_earnings' => $allTimeEarnings,
    ));
    break;
}

case 'courier_go_online': {
    $pdo = db();
    $u = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $u['id']);
    if (!$courier) fail('Courier profile could not be created', 500);
    try {
        $lat = isset($body['lat']) ? floatval($body['lat']) : null;
        $lng = isset($body['lng']) ? floatval($body['lng']) : null;
        if ($lat !== null && $lng !== null) {
            list($hexQ, $hexR) = hexgrid_cell_for($lat, $lng);
            $pdo->prepare("UPDATE couriers SET status='online', last_lat=?, last_lng=?, last_seen=NOW(), hex_q=?, hex_r=? WHERE id=?")
                ->execute(array($lat, $lng, $hexQ, $hexR, $courier['id']));
        } else {
            $pdo->prepare("UPDATE couriers SET status='online' WHERE id=?")->execute(array($courier['id']));
        }
        // Same shift-tracking pattern as driver_go_online - guard
        // against a duplicate open shift from an app relaunch.
        $openChk = $pdo->prepare('SELECT id FROM courier_shifts WHERE courier_user_id=? AND ended_at IS NULL LIMIT 1');
        $openChk->execute(array($u['id']));
        if (!$openChk->fetch()) {
            $pdo->prepare('INSERT INTO courier_shifts (courier_user_id, started_at) VALUES (?, NOW())')->execute(array($u['id']));
        }
        ok(array('online' => true));
    } catch (Exception $e) {
        fail_ex($e, 'Could not go online - has migration 043_courier_shifts.sql been applied?', 500);
    }
    break;
}

case 'courier_update_location': {
    // The missing piece: nothing anywhere in this codebase previously
    // wrote to couriers.last_lat/last_lng after go-online, so the
    // delivery auto-dispatch matcher's "nearest courier" logic was
    // always working from stale or entirely absent GPS data, silently
    // falling back to its coarse zone-match branch in practice. This
    // mirrors Ride's driver_update_location - called periodically by
    // the courier app while online.
    $pdo = db();
    $u = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $u['id']);
    if (!$courier) fail('Courier profile could not be created', 500);
    $lat = isset($body['lat']) ? floatval($body['lat']) : null;
    $lng = isset($body['lng']) ? floatval($body['lng']) : null;
    if ($lat === null || $lng === null) fail('lat/lng are required', 400);
    list($hexQ, $hexR) = hexgrid_cell_for($lat, $lng);
    try {
        $pdo->prepare("UPDATE couriers SET last_lat=?, last_lng=?, last_seen=NOW(), hex_q=?, hex_r=? WHERE id=?")
            ->execute(array($lat, $lng, $hexQ, $hexR, $courier['id']));
        ok(array('updated' => true));
    } catch (Exception $e) {
        fail_ex($e, 'Could not update location - has migration 046_hex_grid_matching.sql been applied?', 500);
    }
    break;
}

case 'courier_go_offline': {
    $pdo = db();
    $u = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $u['id']);
    if (!$courier) fail('Courier profile could not be created', 500);
    try {
        $pdo->prepare("UPDATE couriers SET status='offline' WHERE id=?")->execute(array($courier['id']));
        $pdo->prepare('UPDATE courier_shifts SET ended_at=NOW(), online_seconds=TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE courier_user_id=? AND ended_at IS NULL')
            ->execute(array($u['id']));
        ok(array('online' => false));
    } catch (Exception $e) {
        fail_ex($e, 'Could not go offline', 500);
    }
    break;
}

case 'courier_get_kpi_summary': {
    // Mirrors driver_get_kpi_summary. Note: unlike rides, deliveries
    // currently have no platform commission at all (delivery_fee goes
    // 100% to the courier) and no distance tracking - this reflects
    // that real, current business model rather than assuming parity
    // with the ride side.
    $pdo = db();
    $u = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $u['id']);
    if (!$courier) fail('Courier profile could not be created', 500);
    $period = isset($_GET['period']) ? $_GET['period'] : (isset($body['period']) ? $body['period'] : 'today');
    if (!in_array($period, array('today', 'week', 'month'), true)) fail('period must be today, week, or month', 400);
    $sinceExpr = $period === 'today' ? 'CURDATE()' : ($period === 'week' ? 'DATE_SUB(CURDATE(), INTERVAL 6 DAY)' : 'DATE_SUB(CURDATE(), INTERVAL 29 DAY)');
    try {
        require_once __DIR__ . '/../lib/courier_earnings.php';
        etel_ensure_tip_column($pdo);
        $s = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(courier_pay, delivery_fee) + courier_tip),0) AS earnings
            FROM orders WHERE courier_id=? AND status='delivered' AND delivered_at >= $sinceExpr");
        $s->execute(array($courier['id']));
        $r = $s->fetch();
        $shiftStmt = $pdo->prepare("SELECT COALESCE(SUM(
                CASE WHEN ended_at IS NOT NULL THEN online_seconds
                     ELSE TIMESTAMPDIFF(SECOND, started_at, NOW()) END
            ), 0) AS secs FROM courier_shifts WHERE courier_user_id=? AND started_at >= $sinceExpr");
        $shiftStmt->execute(array($u['id']));
        $onlineSeconds = intval($shiftStmt->fetch()['secs']);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load KPI summary - has migration 043_courier_shifts.sql been applied?', 500);
    }
    $completedDeliveries = intval($r['n']);
    $earnings = round(floatval($r['earnings']), 2);
    $onlineHours = round($onlineSeconds / 3600, 2);
    $netPerHour = $onlineHours > 0 ? round($earnings / $onlineHours, 2) : null;

    // Fuel: unlike rides, deliveries have no distance column to
    // multiply a cost/km rate against, so this only ever reports a
    // real fuel cost when the courier has actually logged 2+ odometer
    // readings inside this specific period - never an estimate
    // derived from delivery count, which would be fabricated.
    $periodFuelCost = null; $usingRealFuelRate = false;
    try {
        $odo = $pdo->prepare("SELECT odometer_km, cost_etb, logged_at FROM courier_fuel_logs
            WHERE courier_user_id=? AND odometer_km IS NOT NULL AND logged_at >= $sinceExpr ORDER BY logged_at ASC");
        $odo->execute(array($u['id']));
        $odoRows = $odo->fetchAll(PDO::FETCH_ASSOC);
        if (count($odoRows) >= 2) {
            $cSum = $pdo->prepare("SELECT COALESCE(SUM(cost_etb),0) AS c FROM courier_fuel_logs WHERE courier_user_id=? AND logged_at >= ? AND logged_at <= ?");
            $cSum->execute(array($u['id'], $odoRows[0]['logged_at'], $odoRows[count($odoRows) - 1]['logged_at']));
            $periodFuelCost = round(floatval($cSum->fetch()['c']), 2);
            $usingRealFuelRate = true;
        }
    } catch (Exception $e) { /* fuel table may not exist yet - net_after_fuel just won't be shown */ }
    $netAfterFuel = $periodFuelCost !== null ? round($earnings - $periodFuelCost, 2) : null;

    ok(array(
        'period' => $period, 'completed_deliveries' => $completedDeliveries, 'earnings' => $earnings,
        'online_hours' => $onlineHours, 'net_etb_per_hour' => $netPerHour,
        'fuel_cost_this_period' => $periodFuelCost, 'using_real_fuel_data' => $usingRealFuelRate,
        'net_after_fuel' => $netAfterFuel,
        'note' => $onlineHours > 0 ? null : 'No tracked online time in this period yet - go online to start tracking net ETB/hour.',
    ));
    break;
}

case 'courier_get_my_orders': {
    $pdo = db();
    $courier_user = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);
    // The v5 courier app sends offers=1 on every poll: it can show Accept / Decline, so it may receive expiring offers.
    try {
        require_once __DIR__ . '/../lib/courier_offers.php';
        if (!empty($_GET['offers'])) etel_offer_mark_capable($pdo, $courier['id']);
        etel_offers_sweep($pdo);
    } catch (Exception $oe) {} catch (Throwable $oe) {}
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $where = 'WHERE o.courier_id = ?';
    $params = array($courier['id']);
    if ($status && in_array($status, array('assigned','picked_up','in_transit','delivered','cancelled'))) {
        $where .= ' AND o.status = ?';
        $params[] = $status;
    }

    // Auto-add missing columns so live DB catches up without a schema migration
    $cols_to_add = array(
        'orders'        => array(
            'recipient_address' => 'TEXT NULL',
            'delivery_lat'      => 'DECIMAL(10,7) NULL',
            'delivery_lng'      => 'DECIMAL(10,7) NULL',
        ),
        'service_givers' => array(
            'lat' => 'DECIMAL(10,7) NULL',
            'lng' => 'DECIMAL(10,7) NULL',
        ),
    );
    // Was: one "ALTER TABLE ... ADD COLUMN" per column on EVERY poll, each
    // failing with "duplicate column" once the column exists. A failing ALTER
    // still takes a metadata lock on the table, which is why this action
    // averaged ~620 ms (worst 2.4 s) in the live API timings with only a few
    // couriers. rides_add_missing_cols() does one cheap SHOW COLUMNS per table
    // and only alters when a column is genuinely missing.
    require_once __DIR__ . '/../lib/schema_helpers.php';
    foreach ($cols_to_add as $tbl => $cols) {
        $ddl = array();
        foreach ($cols as $col => $def) { $ddl[$col] = "`$col` $def"; }
        try { rides_add_missing_cols($pdo, $tbl, $ddl); } catch (Exception $e) { /* table missing: same as the old per-ALTER try/catch */ }
    }

    // Also ensure order_assignments table exists
    try {
        $pdo->query("CREATE TABLE IF NOT EXISTS order_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            service_giver_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY(order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    // Package columns for the courier (never the PINs): the app shows the pickup address, the sender and what is being sent.
    require_once __DIR__ . '/../lib/package_orders.php';
    etel_package_ensure_schema($pdo);
    if (!empty($_GET['pkg'])) etel_package_mark_capable($pdo, $courier['id']);
    $pkg_my_cols = ', o.order_kind, o.vehicle_class, o.pickup_address, o.pickup_lat, o.pickup_lng, o.pkg_size, o.pkg_weight_kg, o.pkg_desc, o.distance_km, o.sender_name, o.sender_phone';
    // Full query with service-giver GPS join
    try {
        $s = $pdo->prepare("
            SELECT o.id, o.order_code, o.from_zone, o.to_zone, o.package_type, o.delivery_tier,
                   o.recipient_name, o.recipient_phone, o.fare_total, o.delivery_fee, o.status,
                   o.placed_at, o.delivered_at, o.notes,
                   o.recipient_address, o.delivery_photo_url, o.payment_method,
                   o.delivery_lat, o.delivery_lng$pkg_my_cols,
                   sg.lat  AS sg_lat,  sg.lng  AS sg_lng,
                   sg.business_name AS sg_business_name,
                   sg.address       AS sg_address
            FROM orders o
            LEFT JOIN order_assignments oa ON oa.order_id = o.id
            LEFT JOIN service_givers    sg ON sg.id = oa.service_giver_id
            $where ORDER BY o.placed_at DESC LIMIT 50
        ");
        $s->execute($params);
        $orders = $s->fetchAll();
    } catch (Exception $e) {
        // Fallback: simpler query without the GPS join if schema still missing
        try {
            $s = $pdo->prepare("
                SELECT o.id, o.order_code, o.from_zone, o.to_zone, o.package_type, o.delivery_tier,
                       o.recipient_name, o.recipient_phone, o.fare_total, o.delivery_fee, o.status,
                       o.placed_at, o.delivered_at, o.notes,
                       NULL AS recipient_address, o.delivery_photo_url, o.payment_method,
                       NULL AS delivery_lat, NULL AS delivery_lng,
                       NULL AS sg_lat, NULL AS sg_lng,
                       NULL AS sg_business_name, NULL AS sg_address
                FROM orders o
                $where ORDER BY o.placed_at DESC LIMIT 50
            ");
            $s->execute($params);
            $orders = $s->fetchAll();
        } catch (Exception $e2) { fail_ex($e2, 'Query failed', 500); }
    }
    etel_mask_recipient_for_courier($orders);
    require_once __DIR__ . '/../lib/courier_earnings.php';
    etel_apply_courier_tip($pdo, $orders, $courier['id'], true);    // own orders: pay is saved the first time the app sees an active order
    try { require_once __DIR__ . '/../lib/courier_offers.php'; $orders = etel_offer_attach($pdo, $orders); } catch (Exception $oe) {} catch (Throwable $oe) {}
    // Stage times of a package on the database clock (the app's timers): only for the few packages that are still in progress or just finished.
    $pkgN = 0;
    foreach ($orders as $pi => $po) {
        if ($pkgN >= 10) break;
        if (isset($po['order_kind']) && $po['order_kind'] === 'package' && in_array($po['status'], array('assigned', 'picked_up', 'in_transit', 'delivered'), true)) {
            try { $orders[$pi]['pkg_times'] = etel_package_times($pdo, $po['id']); $pkgN++; } catch (Exception $pe) {} catch (Throwable $pe) {}
        }
    }
    ok(array('orders' => $orders, 'count' => count($orders)));
    break;
}
case 'courier_earnings_summary': {
    $pdo = db();
    $courier_user = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);
    require_once __DIR__ . '/../lib/courier_earnings.php';
    ok(etel_courier_earnings($pdo, $courier['id']));
    break;
}
case 'rate_order': {
    $pdo      = db();
    $u        = require_auth($pdo);
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $stars    = isset($body['stars'])    ? intval($body['stars'])   : 5;
    $comment  = isset($body['comment'])  ? trim($body['comment'])   : '';
    require_once __DIR__ . '/../lib/reviews.php';
    etel_ensure_review_columns($pdo);
    $food_rating    = etel_review_star(isset($body['food_rating'])    ? $body['food_rating']    : null);
    $courier_rating = etel_review_star(isset($body['courier_rating']) ? $body['courier_rating'] : null);
    $tags           = etel_review_clean_tags(isset($body['tags']) ? $body['tags'] : array());
    if (function_exists('mb_substr')) $comment = mb_substr($comment, 0, 500); else $comment = substr($comment, 0, 500);

    if (!$order_id) fail('order_id required', 400);
    if ($stars < 1 || $stars > 5) fail('stars must be 15', 400);

    // Verify order belongs to customer
    $chk = $pdo->prepare("SELECT id FROM orders WHERE id=? AND customer_id=? AND status='delivered'");
    $chk->execute(array($order_id, $u['id']));
    if (!$chk->fetch()) fail('Order not found or not yet delivered', 404);

    // Check not already rated
    $dup = $pdo->prepare('SELECT id FROM order_ratings WHERE order_id=?');
    $dup->execute(array($order_id));
    if ($dup->fetch()) fail('Order already rated', 409);

    try {
        $ins = $pdo->prepare("INSERT INTO order_ratings
                               (order_id, customer_id, stars, comment, food_rating, courier_rating, tags, rated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $ins->execute(array($order_id, $u['id'], $stars, $comment, $food_rating, $courier_rating, $tags !== '' ? $tags : null));
        etel_review_update_shop_aggregate($pdo, $order_id);
        if ($courier_rating) etel_review_update_courier_aggregate($pdo, $order_id);
        ok(array('message' => 'Thank you for your rating!', 'stars' => $stars));
    } catch (Exception $e) {
        fail_ex($e, 'Rating failed', 500);
    }
    break;
}
case 'get_order_ratings': {
    $pdo   = db();
    require_admin($pdo, 'orders.view');
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    try {
        $s = $pdo->prepare("SELECT r.*, o.order_code, u.name as customer_name
                             FROM order_ratings r
                             JOIN orders o ON r.order_id = o.id
                             LEFT JOIN users u ON r.customer_id = u.id
                             ORDER BY r.rated_at DESC LIMIT ?");
        $s->execute(array($limit));
        $ratings = $s->fetchAll();
        $avg = $pdo->query('SELECT AVG(stars) as avg, COUNT(*) as total FROM order_ratings')->fetch();
    } catch (Exception $e) { fail_ex($e, 'Query failed', 500); }
    ok(array('ratings' => $ratings, 'average' => round(floatval($avg['avg']), 2), 'total' => intval($avg['total'])));
    break;
}
case 'get_shop_reviews': {
    // Customer-facing per-shop reviews for the Shop Detail "Reviews" tab.
    // get_order_ratings above is admin-only and platform-wide, not scoped
    // to one shop, so it can't back a customer-facing tab - this is a
    // separate, narrower read: only stars/comment/rated_at plus the
    // reviewer's first name (never phone/full name) for orders actually
    // assigned to this service_giver_id.
    $pdo = db();
    require_auth($pdo);
    $sgId  = isset($_GET['service_giver_id']) ? intval($_GET['service_giver_id']) : 0;
    $limit = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 20;
    if (!$sgId) fail('service_giver_id required', 400);
    try {
        require_once __DIR__ . '/../lib/reviews.php';
        etel_ensure_review_columns($pdo);
        $s = $pdo->prepare("SELECT r.stars, r.comment, r.rated_at, r.tags, r.shop_reply, r.shop_replied_at,
                                    SUBSTRING_INDEX(u.name, ' ', 1) AS reviewer_first_name
                             FROM order_ratings r
                             JOIN orders o ON r.order_id = o.id
                             JOIN order_assignments oa ON oa.order_id = o.id
                             LEFT JOIN users u ON r.customer_id = u.id
                             WHERE oa.service_giver_id = ?
                             ORDER BY r.rated_at DESC LIMIT ?");
        $s->execute(array($sgId, $limit));
        $reviews = $s->fetchAll();
        $agg = $pdo->prepare("SELECT AVG(r.stars) as avg, COUNT(*) as total
                               FROM order_ratings r
                               JOIN order_assignments oa ON oa.order_id = r.order_id
                               WHERE oa.service_giver_id = ?");
        $agg->execute(array($sgId));
        $a = $agg->fetch();
    } catch (Exception $e) { fail_ex($e, 'Query failed', 500); }
    ok(array('reviews' => $reviews, 'average' => round(floatval($a['avg']), 2), 'total' => intval($a['total'])));
    break;
}
case 'track_order': {
    $pdo = db();
    $u   = require_auth($pdo);
    $order_id   = isset($_GET['order_id'])   ? intval($_GET['order_id'])   : (isset($body['order_id'])   ? intval($body['order_id'])   : 0);
    $order_code = isset($_GET['order_code']) ? trim($_GET['order_code'])   : (isset($body['order_code']) ? trim($body['order_code'])   : '');

    require_once __DIR__ . '/../lib/package_orders.php';
    etel_package_ensure_schema($pdo);
    $where  = 'WHERE (o.customer_id=? OR o.recipient_phone=?)';
    $params = array($u['id'], isset($u['phone']) ? $u['phone'] : '');

    if ($order_id) { $where .= ' AND o.id=?'; $params[] = $order_id; }
    elseif ($order_code) { $where .= ' AND o.order_code=?'; $params[] = $order_code; }
    else fail('order_id or order_code required', 400);

    try {
        $s = $pdo->prepare("
            SELECT
                o.id, o.order_code, o.status, o.payment_method, o.payment_status, o.customer_id AS _cid,
                o.from_zone, o.to_zone, o.fare_total, o.delivery_fee, o.service_charge,
                o.placed_at, o.delivered_at, o.notes,
                o.recipient_name, o.recipient_phone, o.recipient_address, o.delivery_photo_url,
                o.delivery_lat, o.delivery_lng,
                o.order_kind, o.vehicle_class, o.pickup_address, o.pkg_size, o.pkg_weight_kg, o.pkg_desc, o.distance_km, o.sender_name, o.pickup_pin, o.delivery_pin,
                oa.status as assignment_status, oa.accepted_at, oa.pickup_qr_scanned,
                sg.business_name as service_giver_name, sg.zone as sg_zone,
                u_sg.phone as sg_phone,
                c.vehicle_type as courier_vehicle, c.rating AS courier_avg_rating, c.last_lat as courier_lat, c.last_lng as courier_lng, c.last_seen as courier_last_seen,
                TIMESTAMPDIFF(SECOND, c.last_seen, NOW()) AS courier_seen_sec,
                u_c.name as courier_name, u_c.phone as courier_phone
            FROM orders o
            LEFT JOIN order_assignments oa ON o.id = oa.order_id
            LEFT JOIN service_givers sg ON oa.service_giver_id = sg.id
            LEFT JOIN users u_sg ON sg.user_id = u_sg.id
            LEFT JOIN couriers c ON o.courier_id = c.id
            LEFT JOIN users u_c ON c.user_id = u_c.id
            $where
            LIMIT 1
        ");
        $s->execute($params);
        $order = $s->fetch();
    } catch (Exception $e) { fail_ex($e, 'Query failed', 500); }

    if (!$order) fail('Order not found', 404);

    // Build tracking timeline
    $statusOrder = array('pending', 'assigned', 'preparing', 'accepted', 'picked_up', 'in_transit', 'delivered');
    $labels = array(
        'pending'    => 'Order placed',
        'assigned'   => 'Assigned to service giver',
        'preparing'  => 'Being prepared',
        'accepted'   => 'Ready for pickup',
        'picked_up'  => 'Courier picked up',
        'in_transit' => 'On the way',
        'delivered'  => 'Delivered',
    );
    $is_package = (isset($order['order_kind']) && $order['order_kind'] === 'package');
    if ($is_package) {   // no shop steps for a package
        $statusOrder = array('pending', 'assigned', 'picked_up', 'in_transit', 'delivered');
        $labels = array('pending' => 'Package booked', 'assigned' => 'Courier on the way to pick it up', 'picked_up' => 'Package picked up', 'in_transit' => 'On the way to the recipient', 'delivered' => 'Delivered');
    }
    $currentIdx  = array_search($order['status'], $statusOrder);
    $timeline = array();
    foreach ($statusOrder as $idx => $st) {
        $timeline[] = array(
            'status'    => $st,
            'label'     => $labels[$st],
            'done'      => $idx <= $currentIdx,
            'current'   => $idx === $currentIdx,
        );
    }

    // arrival estimate + hides a courier pin that has gone stale (api/lib/track_eta.php)
    try { require_once __DIR__ . '/../lib/track_eta.php'; $order = etel_track_eta_apply($pdo, $order); } catch (Exception $te) {} catch (Throwable $te) {}
    // can the customer still cancel it themselves, and which reasons to offer (api/lib/order_cancel.php)
    try {
        require_once __DIR__ . '/../lib/order_cancel.php';
        $order['can_cancel'] = ((int) $order['_cid'] === (int) $u['id']) && etel_cancel_rule('customer', $order['status'], isset($order['assignment_status']) ? $order['assignment_status'] : null, $is_package ? 'package' : 'shop') === '';
        $order['cancel_reasons'] = etel_cancel_reasons('customer');
        $order['cancel_fee'] = $order['can_cancel'] ? etel_cancel_fee_for($pdo, $order['id']) : 0;   // ETB the sender pays to cancel right now (0 = free)
    } catch (Exception $ce) {} catch (Throwable $ce) {}
    // The pins are for the sender only (the courier asks the sender for the pickup pin; the recipient gives the delivery pin).
    if ((int) $order['_cid'] !== (int) $u['id'] || !$is_package) { unset($order['pickup_pin'], $order['delivery_pin']); }
    if ($is_package) { try { $order['pkg_times'] = etel_package_times($pdo, $order['id']); } catch (Exception $te2) {} catch (Throwable $te2) {} }   // stage times for the app's timers
    unset($order['_cid']);   // internal: used above to decide can_cancel, not sent to the app
    ok(array('order' => $order, 'timeline' => $timeline));
    break;
}
case 'schedule_order': {
    $pdo          = db();
    $u            = require_auth($pdo);
    $scheduled_at = isset($body['scheduled_at'])  ? trim($body['scheduled_at'])   : '';
    $repeat_daily = isset($body['repeat_daily'])  ? intval($body['repeat_daily']) : 0;
    $template     = isset($body['order_template'])? $body['order_template']       : null;

    if (!$scheduled_at) fail('scheduled_at required (ISO datetime)', 400);
    if (!$template)     fail('order_template required', 400);

    try { $pdo->query("SELECT 1 FROM scheduled_orders LIMIT 0"); }
    catch (Exception $e) { fail('Scheduled orders table not initialised. Import payments_wallet_schema.sql', 503); }

    $ins = $pdo->prepare("INSERT INTO scheduled_orders (customer_id, scheduled_at, repeat_daily, order_template)
                          VALUES (?, ?, ?, ?)");
    $ins->execute(array($u['id'], $scheduled_at, $repeat_daily, json_encode($template)));
    ok(array('scheduled_id' => $pdo->lastInsertId(), 'message' => 'Order scheduled for ' . $scheduled_at));
    break;
}
case 'get_scheduled_orders': {
    $pdo = db();
    $u   = require_auth($pdo);

    try { $pdo->query("SELECT 1 FROM scheduled_orders LIMIT 0"); }
    catch (Exception $e) { ok(array('scheduled' => array())); break; }

    $s = $pdo->prepare("SELECT * FROM scheduled_orders WHERE customer_id=? AND status='active' ORDER BY scheduled_at ASC");
    $s->execute(array($u['id']));
    ok(array('scheduled' => $s->fetchAll()));
    break;
}
case 'cancel_scheduled_order': {
    $pdo = db();
    $u   = require_auth($pdo);
    $id  = isset($body['scheduled_id']) ? intval($body['scheduled_id']) : 0;
    if (!$id) fail('scheduled_id required', 400);

    try { $pdo->query("SELECT 1 FROM scheduled_orders LIMIT 0"); }
    catch (Exception $e) { fail('Scheduled orders table not initialised.', 503); }

    // Ownership check first (same pattern as request_pickup_confirmation
    // below) - never let a customer cancel another customer's schedule.
    $own = $pdo->prepare("SELECT id FROM scheduled_orders WHERE id=? AND customer_id=? AND status='active'");
    $own->execute(array($id, $u['id']));
    if (!$own->fetch()) fail('Scheduled order not found', 404);

    $pdo->prepare("UPDATE scheduled_orders SET status='cancelled' WHERE id=?")->execute(array($id));
    ok(array('message' => 'Scheduled order cancelled'));
    break;
}
case 'request_pickup_confirmation': {
    $pdo     = db();
    $cu      = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $cu['id']);
    if (!$courier) fail('Courier profile not found', 404);

    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id required', 400);

    $s = $pdo->prepare('SELECT id, status FROM orders WHERE id=? AND courier_id=?');
    $s->execute(array($order_id, $courier['id']));
    $order = $s->fetch();
    if (!$order) fail('Order not found or not assigned to you', 403);
    if ($order['status'] !== 'assigned') fail('Order must be in "assigned" status', 400);

    // Invalidate any previous pending pickup request for this order
    $pdo->prepare("UPDATE order_confirmations SET status='rejected' WHERE order_id=? AND type='pickup' AND status='pending'")
        ->execute(array($order_id));

    $pdo->prepare("INSERT INTO order_confirmations (order_id, type, status, requested_by) VALUES (?, 'pickup', 'pending', ?)")
        ->execute(array($order_id, $cu['id']));

    ok(array('confirmation_id' => $pdo->lastInsertId(), 'message' => 'Pickup confirmation requested'));
    break;
}
case 'check_pickup_confirmation': {
    $pdo = db();
    $cu = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $cu['id']);
    if (!$courier) fail('Courier profile not found', 404);

    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id'])
              : (isset($body['order_id']) ? intval($body['order_id']) : 0);
    if (!$order_id) fail('order_id required', 400);

    // Ownership check - a courier could otherwise pass any order_id and
    // read another courier's pickup-confirmation status. Same pattern as
    // request_pickup_confirmation above.
    $ownChk = $pdo->prepare('SELECT id FROM orders WHERE id=? AND courier_id=?');
    $ownChk->execute(array($order_id, $courier['id']));
    if (!$ownChk->fetch()) fail('Order not found or not assigned to you', 403);

    $s = $pdo->prepare("SELECT status, responded_at FROM order_confirmations WHERE order_id=? AND type='pickup' ORDER BY requested_at DESC LIMIT 1");
    $s->execute(array($order_id));
    $conf = $s->fetch();

    ok(array('status' => $conf ? $conf['status'] : 'none', 'responded_at' => isset($conf['responded_at']) ? $conf['responded_at'] : null));
    break;
}
case 'sg_pending_confirmations': {
    $pdo      = db();
    $sg_user  = require_auth($pdo);
    if ($sg_user['role'] !== 'service_giver' && ($sg_user['role'] !== 'admin' || etel_is_ride_only(db(), $sg_user))) fail('Service giver access required', 403);

    $sgs = $pdo->prepare('SELECT id, zone FROM service_givers WHERE user_id=?');
    $sgs->execute(array($sg_user['id']));
    $sg = $sgs->fetch();
    if (!$sg) ok(array('confirmations' => array()));

    $confs = array();

    // Primary: orders.service_giver_id is the direct, authoritative
    // link set when the order was placed - checked FIRST, since the
    // order_assignments join and zone-string fallback below can both
    // independently fail to match a real pending confirmation (a
    // missing assignment row, or a zone string that doesn't match
    // exactly), silently hiding a courier who is actually waiting.
    try {
        $s0 = $pdo->prepare("
            SELECT oc.id, oc.order_id, oc.requested_at,
                   o.order_code, o.fare_total, o.from_zone,
                   u_c.name  AS courier_name,
                   u_c.phone AS courier_phone
            FROM   order_confirmations oc
            JOIN   orders            o   ON o.id = oc.order_id AND o.service_giver_id = ?
            JOIN   couriers          c   ON c.id = o.courier_id
            JOIN   users             u_c ON u_c.id = c.user_id
            WHERE  oc.type = 'pickup' AND oc.status = 'pending'
            ORDER  BY oc.requested_at DESC
        ");
        $s0->execute(array($sg['id']));
        $confs = $s0->fetchAll();
    } catch (Exception $e0) { $confs = array(); }

    // Secondary: match via order_assignments (in case service_giver_id
    // wasn't set on this particular order for any reason).
    if (empty($confs)) {
        try {
            $s = $pdo->prepare("
                SELECT oc.id, oc.order_id, oc.requested_at,
                       o.order_code, o.fare_total, o.from_zone,
                       u_c.name  AS courier_name,
                       u_c.phone AS courier_phone
                FROM   order_confirmations oc
                JOIN   orders            o   ON o.id = oc.order_id
                JOIN   order_assignments oa  ON oa.order_id = o.id AND oa.service_giver_id = ?
                JOIN   couriers          c   ON c.id = o.courier_id
                JOIN   users             u_c ON u_c.id = c.user_id
                WHERE  oc.type = 'pickup' AND oc.status = 'pending'
                ORDER  BY oc.requested_at DESC
            ");
            $s->execute(array($sg['id']));
            $confs = $s->fetchAll();
        } catch (Exception $e) { $confs = array(); }
    }

    // Last resort: match by SG's zone.
    if (empty($confs) && !empty($sg['zone'])) {
        try {
            $s2 = $pdo->prepare("
                SELECT oc.id, oc.order_id, oc.requested_at,
                       o.order_code, o.fare_total, o.from_zone,
                       u_c.name  AS courier_name,
                       u_c.phone AS courier_phone
                FROM   order_confirmations oc
                JOIN   orders o   ON o.id = oc.order_id AND o.from_zone = ?
                JOIN   couriers c ON c.id = o.courier_id
                JOIN   users u_c  ON u_c.id = c.user_id
                WHERE  oc.type = 'pickup' AND oc.status = 'pending'
                ORDER  BY oc.requested_at DESC
            ");
            $s2->execute(array($sg['zone']));
            $confs = $s2->fetchAll();
        } catch (Exception $e2) { $confs = array(); }
    }

    ok(array('confirmations' => $confs));
    break;
}
case 'sg_confirm_pickup': {
    $pdo     = db();
    $sg_user = require_auth($pdo);
    if ($sg_user['role'] !== 'service_giver' && ($sg_user['role'] !== 'admin' || etel_is_ride_only(db(), $sg_user))) fail('Service giver access required', 403);

    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id required', 400);

    $sgs = $pdo->prepare('SELECT id, zone FROM service_givers WHERE user_id=?');
    $sgs->execute(array($sg_user['id']));
    $sg = $sgs->fetch();
    if (!$sg) fail('Service giver profile not found', 404);

    // Verify order belongs to this SG: direct service_giver_id link
    // first (authoritative, set at order creation), then
    // order_assignments, then zone as a last resort - same priority
    // order as sg_pending_confirmations above, so a confirmation that
    // correctly shows up in the list can't then fail here for a
    // different reason.
    $allowed = false;
    try {
        $chk0 = $pdo->prepare('SELECT id FROM orders WHERE id=? AND service_giver_id=?');
        $chk0->execute(array($order_id, $sg['id']));
        $allowed = (bool) $chk0->fetch();
    } catch (Exception $e0) {}
    if (!$allowed) {
        try {
            $chk = $pdo->prepare('SELECT o.id FROM orders o JOIN order_assignments oa ON oa.order_id=o.id WHERE o.id=? AND oa.service_giver_id=?');
            $chk->execute(array($order_id, $sg['id']));
            $allowed = (bool)$chk->fetch();
        } catch (Exception $e) {}
    }
    if (!$allowed && !empty($sg['zone'])) {
        $chk2 = $pdo->prepare('SELECT id FROM orders WHERE id=? AND from_zone=?');
        $chk2->execute(array($order_id, $sg['zone']));
        $allowed = (bool)$chk2->fetch();
    }
    if (!$allowed) fail('Order not assigned to you', 403);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE order_confirmations SET status='confirmed', responded_by=?, responded_at=NOW() WHERE order_id=? AND type='pickup' AND status='pending'")
            ->execute(array($sg_user['id'], $order_id));
        $pdo->prepare("UPDATE orders SET status='picked_up' WHERE id=? AND status='assigned'")
            ->execute(array($order_id));
        $pdo->prepare("UPDATE order_assignments SET status='picked_up' WHERE order_id=?")
            ->execute(array($order_id));
        $pdo->commit();
        require_once __DIR__ . '/../lib/order_stamps.php'; etel_order_stamp_pickup($pdo, $order_id);
        if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id);
        ok(array('message' => 'Pickup confirmed  order is now in transit'));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Failed to confirm pickup', 500);
    }
    break;
}
case 'request_delivery_confirmation': {
    $pdo     = db();
    $cu      = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $cu['id']);
    if (!$courier) fail('Courier profile not found', 404);

    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id required', 400);

    $s = $pdo->prepare('SELECT id, status FROM orders WHERE id=? AND courier_id=?');
    $s->execute(array($order_id, $courier['id']));
    $order = $s->fetch();
    if (!$order) fail('Order not found or not assigned to you', 403);
    if (!in_array($order['status'], array('picked_up', 'in_transit'))) fail('Order must be picked up first', 400);

    $pdo->prepare("UPDATE order_confirmations SET status='rejected' WHERE order_id=? AND type='delivery' AND status='pending'")
        ->execute(array($order_id));

    $pdo->prepare("INSERT INTO order_confirmations (order_id, type, status, requested_by) VALUES (?, 'delivery', 'pending', ?)")
        ->execute(array($order_id, $cu['id']));

    ok(array('confirmation_id' => $pdo->lastInsertId(), 'message' => 'Delivery confirmation requested from customer'));
    break;
}
case 'check_delivery_confirmation': {
    $pdo = db();
    $cu = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $cu['id']);
    if (!$courier) fail('Courier profile not found', 404);

    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id'])
              : (isset($body['order_id']) ? intval($body['order_id']) : 0);
    if (!$order_id) fail('order_id required', 400);

    // Ownership check - a courier could otherwise pass any order_id and
    // read another courier's delivery-confirmation status/payment_confirmed
    // flag. Same pattern as request_delivery_confirmation above.
    $ownChk = $pdo->prepare('SELECT id FROM orders WHERE id=? AND courier_id=?');
    $ownChk->execute(array($order_id, $courier['id']));
    if (!$ownChk->fetch()) fail('Order not found or not assigned to you', 403);

    $s = $pdo->prepare("SELECT status, responded_at, payment_confirmed FROM order_confirmations WHERE order_id=? AND type='delivery' ORDER BY requested_at DESC LIMIT 1");
    $s->execute(array($order_id));
    $conf = $s->fetch();

    ok(array(
        'status'            => $conf ? $conf['status']            : 'none',
        'responded_at'      => $conf ? $conf['responded_at']      : null,
        'payment_confirmed' => $conf ? $conf['payment_confirmed'] : 0,
    ));
    break;
}
case 'customer_pending_confirmations': {
    $pdo       = db();
    $auth_user = require_auth($pdo);
    // Role check removed - any authenticated EtelID can now use the
    // Customer app regardless of their assigned role (courier, driver,
    // service_giver, admin), matching the same policy just added at
    // login (see AuthController.php's 'login'/'etelid_login' - the
    // Customer tab is exempt from the tab-matches-role restriction
    // every other tab still enforces). Without this, a driver/courier/
    // service_giver account could log into customer.html successfully
    // but then get 403'd on the very first order/wallet-related call
    // it makes, which looked exactly like an automatic logout even
    // though nothing actually logged them out. require_auth() above
    // still requires a valid, real session either way.

    $s = $pdo->prepare("
        SELECT oc.id, oc.order_id, oc.requested_at,
               o.order_code, o.fare_total, o.payment_method,
               COALESCE(u_c.name,  'Courier') AS courier_name,
               COALESCE(u_c.phone, '')         AS courier_phone
        FROM   order_confirmations oc
        JOIN   orders o   ON o.id = oc.order_id AND o.customer_id = ?
        LEFT JOIN couriers c ON c.id = o.courier_id
        LEFT JOIN users u_c  ON u_c.id = c.user_id
        WHERE  oc.type = 'delivery' AND oc.status = 'pending'
        ORDER  BY oc.requested_at DESC
    ");
    $s->execute(array($auth_user['id']));
    ok(array('confirmations' => $s->fetchAll()));
    break;
}
case 'customer_confirm_delivery': {
    $pdo       = db();
    $auth_user = require_auth($pdo);
    // Role check removed - any authenticated EtelID can now use the
    // Customer app regardless of their assigned role (courier, driver,
    // service_giver, admin), matching the same policy just added at
    // login (see AuthController.php's 'login'/'etelid_login' - the
    // Customer tab is exempt from the tab-matches-role restriction
    // every other tab still enforces). Without this, a driver/courier/
    // service_giver account could log into customer.html successfully
    // but then get 403'd on the very first order/wallet-related call
    // it makes, which looked exactly like an automatic logout even
    // though nothing actually logged them out. require_auth() above
    // still requires a valid, real session either way.

    $order_id      = isset($body['order_id'])         ? intval($body['order_id']) : 0;
    $payment_conf  = isset($body['payment_confirmed']) ? (intval(boolval($body['payment_confirmed']))) : 0;
    if (!$order_id) fail('order_id required', 400);

    $s = $pdo->prepare('SELECT id, payment_method, fare_total FROM orders WHERE id=? AND customer_id=?');
    $s->execute(array($order_id, $auth_user['id']));
    $order = $s->fetch();
    if (!$order) fail('Order not found', 403);

    // The customer can choose cash or ETEL Pay right at the door, independent
    // of whatever payment_method was picked back at checkout -- only allow
    // switching between these two "pay on arrival" methods here, never to
    // telebirr/bank which need their own pre-payment flow.
    $chosen_method = isset($body['confirm_payment_method']) ? trim($body['confirm_payment_method']) : $order['payment_method'];
    if (!in_array($chosen_method, array('cash', 'wallet'), true)) $chosen_method = $order['payment_method'];

    // If paying by wallet, "payment confirmed" must be backed by a real,
    // independently-verified Etel Pay transfer -- never just the client's
    // boolean claim.
    if ($chosen_method === 'wallet' && $payment_conf) {
        $tx_ref = isset($body['wallet_tx_ref']) ? trim($body['wallet_tx_ref']) : '';
        if (!$tx_ref) fail('wallet_tx_ref required to confirm a wallet-paid order');

        require_once __DIR__ . '/../etel_pay_bridge.php';

        try { $pdo->exec("ALTER TABLE orders ADD COLUMN wallet_tx_ref VARCHAR(40) NULL"); } catch (Exception $e) {}
        $dupe = $pdo->prepare("SELECT id FROM orders WHERE wallet_tx_ref = ? LIMIT 1");
        $dupe->execute(array($tx_ref));
        if ($dupe->fetch()) fail('This payment has already been used for another order', 409);

        $tx = etel_pay_verify_tx($tx_ref, $auth_user['phone'], floatval($order['fare_total']));
        if (!$tx) fail('Could not verify Etel Pay payment. If money left your account, contact support with reference ' . $tx_ref, 402);
        if (!etel_pay_claim_tx($pdo, $tx_ref, 'order')) fail('This payment has already been used', 409);

        $pdo->prepare("UPDATE orders SET payment_method='wallet', wallet_tx_ref=? WHERE id=?")->execute(array($tx_ref, $order_id));
    } elseif ($chosen_method !== $order['payment_method']) {
        $pdo->prepare("UPDATE orders SET payment_method=? WHERE id=?")->execute(array($chosen_method, $order_id));
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE order_confirmations SET status='confirmed', responded_by=?, responded_at=NOW(), payment_confirmed=? WHERE order_id=? AND type='delivery' AND status='pending'")
            ->execute(array($auth_user['id'], $payment_conf, $order_id));
        if ($chosen_method === 'wallet' && $payment_conf) {
            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW(), payment_status='paid' WHERE id=?")
                ->execute(array($order_id));
        } else {
            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")
                ->execute(array($order_id));
        }
        $pdo->prepare("UPDATE order_assignments SET status='delivered' WHERE order_id=?")
            ->execute(array($order_id));
        $pdo->commit();
        if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id);
        etel_maybe_pay_referral_reward($pdo, $auth_user['id']);
        ok(array('message' => 'Delivery confirmed! Thank you.'));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Failed to confirm delivery', 500);
    }
    break;
}

case 'customer_wallet_history': {
    // Real Etel Pay payment history for the "Wallet & Payments" screen --
    // replaces the old internal user_wallets/wallet_transactions system,
    // which was never wired into a live route and always showed ETB 0.00.
    $pdo       = db();
    $auth_user = require_auth($pdo);
    // Role check removed - any authenticated EtelID can now use the
    // Customer app regardless of their assigned role (courier, driver,
    // service_giver, admin), matching the same policy just added at
    // login (see AuthController.php's 'login'/'etelid_login' - the
    // Customer tab is exempt from the tab-matches-role restriction
    // every other tab still enforces). Without this, a driver/courier/
    // service_giver account could log into customer.html successfully
    // but then get 403'd on the very first order/wallet-related call
    // it makes, which looked exactly like an automatic logout even
    // though nothing actually logged them out. require_auth() above
    // still requires a valid, real session either way.

    try { $pdo->exec("ALTER TABLE orders ADD COLUMN wallet_tx_ref VARCHAR(40) NULL"); } catch (Exception $e) {}

    $s = $pdo->prepare("SELECT id, order_code, fare_total, wallet_tx_ref, status, placed_at, delivered_at
                         FROM orders
                         WHERE customer_id = ? AND payment_method = 'wallet' AND wallet_tx_ref IS NOT NULL
                         ORDER BY id DESC LIMIT 50");
    $s->execute(array($auth_user['id']));
    ok(array('orders' => $s->fetchAll()));
    break;
}

// ── FAVORITES ────────────────────────────────────────────────────
// Powers the "My Favorites" profile menu item, which previously had no
// backend at all - the button rendered but had no onclick handler.

case 'customer_get_favorites': {
    // GET: (requires customer auth) - list of favorited shop IDs plus
    // basic shop info, joined from service_givers. The frontend already
    // has the full item catalog cached client-side, so this only needs
    // to return enough to identify + display each favorited shop; the
    // rest (items, ratings) comes from itemsCache the same way the home
    // screen's shop cards already work.
    $pdo = db();
    $auth_user = require_auth($pdo);
    try {
        $s = $pdo->prepare("SELECT f.service_giver_id, f.created_at, sg.business_name, sg.service_type, sg.zone, sg.average_rating
                             FROM customer_favorites f
                             LEFT JOIN service_givers sg ON sg.id = f.service_giver_id
                             WHERE f.user_id = ? ORDER BY f.created_at DESC");
        $s->execute(array($auth_user['id']));
        $rows = $s->fetchAll();
    } catch (Exception $e) { $rows = array(); }
    ok(array('favorites' => $rows));
    break;
}

case 'customer_toggle_favorite': {
    // POST: (requires customer auth)  service_giver_id
    // Idempotent toggle - if the pair already exists, remove it;
    // otherwise add it. Returns the new state so the button can update
    // without a second round-trip.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $sg_id = isset($body['service_giver_id']) ? intval($body['service_giver_id']) : 0;
    if (!$sg_id) fail('service_giver_id is required');
    try {
        $chk = $pdo->prepare('SELECT id FROM customer_favorites WHERE user_id=? AND service_giver_id=?');
        $chk->execute(array($auth_user['id'], $sg_id));
        $existing = $chk->fetch();
        if ($existing) {
            $pdo->prepare('DELETE FROM customer_favorites WHERE id=?')->execute(array($existing['id']));
            ok(array('favorited' => false));
        } else {
            $pdo->prepare('INSERT INTO customer_favorites (user_id, service_giver_id, created_at) VALUES (?,?,NOW())')
                ->execute(array($auth_user['id'], $sg_id));
            ok(array('favorited' => true));
        }
    } catch (Exception $e) { fail_ex($e, 'Could not update favorites', 500); }
    break;
}

// ── SAVED ADDRESSES ─────────────────────────────────────────────────
case 'customer_get_addresses': {
    $pdo = db();
    $auth_user = require_auth($pdo);
    try {
        $s = $pdo->prepare('SELECT id, label, address_line, zone, lat, lng, is_default, created_at FROM customer_addresses WHERE user_id=? ORDER BY is_default DESC, created_at DESC');
        $s->execute(array($auth_user['id']));
        $rows = $s->fetchAll();
    } catch (Exception $e) { $rows = array(); }
    ok(array('addresses' => $rows));
    break;
}

case 'customer_add_address': {
    // POST: label, address_line, zone?, lat?, lng?, is_default?
    $pdo = db();
    $auth_user = require_auth($pdo);
    $label = isset($body['label']) ? trim($body['label']) : 'Home';
    $line  = isset($body['address_line']) ? trim($body['address_line']) : '';
    $zone  = isset($body['zone']) ? trim($body['zone']) : null;
    $lat   = (isset($body['lat']) && $body['lat'] !== '') ? floatval($body['lat']) : null;
    $lng   = (isset($body['lng']) && $body['lng'] !== '') ? floatval($body['lng']) : null;
    $is_default = !empty($body['is_default']) ? 1 : 0;
    if (!$line) fail('Address is required');
    try {
        if ($is_default) {
            $pdo->prepare('UPDATE customer_addresses SET is_default=0 WHERE user_id=?')->execute(array($auth_user['id']));
        }
        $ins = $pdo->prepare('INSERT INTO customer_addresses (user_id,label,address_line,zone,lat,lng,is_default,created_at) VALUES (?,?,?,?,?,?,?,NOW())');
        $ins->execute(array($auth_user['id'], $label, $line, $zone, $lat, $lng, $is_default));
        ok(array('id' => intval($pdo->lastInsertId()), 'message' => 'Address saved'));
    } catch (Exception $e) { fail_ex($e, 'Could not save address', 500); }
    break;
}

case 'customer_update_address': {
    $pdo = db();
    $auth_user = require_auth($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('Address id is required');
    $chk = $pdo->prepare('SELECT id FROM customer_addresses WHERE id=? AND user_id=?');
    $chk->execute(array($id, $auth_user['id']));
    if (!$chk->fetch()) fail('Address not found', 404);
    $label = isset($body['label']) ? trim($body['label']) : null;
    $line  = isset($body['address_line']) ? trim($body['address_line']) : null;
    $zone  = isset($body['zone']) ? trim($body['zone']) : null;
    $lat   = (isset($body['lat']) && $body['lat'] !== '') ? floatval($body['lat']) : null;
    $lng   = (isset($body['lng']) && $body['lng'] !== '') ? floatval($body['lng']) : null;
    $is_default = isset($body['is_default']) ? !empty($body['is_default']) : null;
    try {
        if ($is_default) {
            $pdo->prepare('UPDATE customer_addresses SET is_default=0 WHERE user_id=?')->execute(array($auth_user['id']));
        }
        $fields = array(); $params = array();
        if ($label !== null) { $fields[] = 'label=?';        $params[] = $label; }
        if ($line  !== null) { $fields[] = 'address_line=?'; $params[] = $line; }
        if ($zone  !== null) { $fields[] = 'zone=?';         $params[] = $zone; }
        if ($lat   !== null) { $fields[] = 'lat=?';          $params[] = $lat; }
        if ($lng   !== null) { $fields[] = 'lng=?';          $params[] = $lng; }
        if ($is_default !== null) { $fields[] = 'is_default=?'; $params[] = $is_default ? 1 : 0; }
        if ($fields) {
            $params[] = $id;
            $pdo->prepare('UPDATE customer_addresses SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
        }
        ok(array('message' => 'Address updated'));
    } catch (Exception $e) { fail_ex($e, 'Could not update address', 500); }
    break;
}

case 'customer_delete_address': {
    $pdo = db();
    $auth_user = require_auth($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('Address id is required');
    try {
        $d = $pdo->prepare('DELETE FROM customer_addresses WHERE id=? AND user_id=?');
        $d->execute(array($id, $auth_user['id']));
        if ($d->rowCount() === 0) fail('Address not found', 404);
        ok(array('message' => 'Address removed'));
    } catch (Exception $e) { fail_ex($e, 'Could not delete address', 500); }
    break;
}

// ── MESSAGES ─────────────────────────────────────────────────────
// Backs the Messages nav tab, which previously had no view-section in
// customer.html at all - switching to it just hid every other view and
// showed nothing (the reported "black screen"). Merges real order
// status into the same feed (read-only, computed at read time) so the
// list isn't just an empty inbox on day one.
case 'customer_get_messages': {
    $pdo = db();
    $auth_user = require_auth($pdo);
    $items = array();
    try {
        $s = $pdo->prepare("SELECT id, sender, subject, body, is_read, created_at FROM customer_messages WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
        $s->execute(array($auth_user['id']));
        foreach ($s->fetchAll() as $row) {
            $items[] = array(
                'kind' => 'message', 'id' => $row['id'], 'sender' => $row['sender'],
                'subject' => $row['subject'], 'body' => $row['body'],
                'is_read' => (bool)$row['is_read'], 'created_at' => $row['created_at'],
            );
        }
    } catch (Exception $e) { /* table may not exist yet */ }
    try {
        $o = $pdo->prepare("SELECT id, order_code, status, placed_at, delivered_at FROM orders WHERE customer_id=? ORDER BY placed_at DESC LIMIT 15");
        $o->execute(array($auth_user['id']));
        foreach ($o->fetchAll() as $ord) {
            $statusText = array(
                'pending' => 'Order placed', 'accepted' => 'Order accepted by courier',
                'picked_up' => 'Order picked up  on the way', 'in_transit' => 'Order on the way',
                'delivered' => 'Order delivered', 'cancelled' => 'Order cancelled',
            );
            $items[] = array(
                'kind' => 'order_update', 'id' => 'order-' . $ord['id'],
                'sender' => 'system', 'subject' => 'Order ' . $ord['order_code'],
                'body' => isset($statusText[$ord['status']]) ? $statusText[$ord['status']] : ('Status: ' . $ord['status']),
                'is_read' => true,
                'created_at' => $ord['delivered_at'] ? $ord['delivered_at'] : $ord['placed_at'],
            );
        }
    } catch (Exception $e) { /* orders table always exists, but stay safe */ }
    usort($items, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
    ok(array('messages' => array_slice($items, 0, 60)));
    break;
}

case 'customer_send_message': {
    // POST: subject?, body  - a real "Contact Support" composer, stored
    // for admin follow-up rather than a mailto:/no-op placeholder.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $subject = isset($body['subject']) ? trim($body['subject']) : 'Support request';
    $text = isset($body['body']) ? trim($body['body']) : '';
    if (!$text) fail('Message body is required');
    try {
        $pdo->prepare("INSERT INTO customer_messages (user_id, sender, subject, body, is_read, created_at) VALUES (?, 'customer', ?, ?, 1, NOW())")
            ->execute(array($auth_user['id'], $subject, $text));
        ok(array('message' => 'Message sent to support'));
    } catch (Exception $e) { fail_ex($e, 'Could not send message', 500); }
    break;
}

// ── REFERRAL & REWARDS ──────────────────────────────────────────────
case 'customer_get_referral_info': {
    // GET: (requires customer auth) - generates a referral code on first
    // use (lazily, not at registration) so existing accounts get one too.
    $pdo = db();
    $auth_user = require_auth($pdo);
    try {
        $s = $pdo->prepare('SELECT referral_code FROM users WHERE id=?');
        $s->execute(array($auth_user['id']));
        $code = $s->fetchColumn();
        if (!$code) {
            // Short, human-shareable code: first 3 letters of name (or CU) + zero-padded id.
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $auth_user['name']), 0, 3));
            if (strlen($prefix) < 3) $prefix = str_pad($prefix, 3, 'X');
            $code = $prefix . str_pad($auth_user['id'], 4, '0', STR_PAD_LEFT);
            try { $pdo->prepare('UPDATE users SET referral_code=? WHERE id=?')->execute(array($code, $auth_user['id'])); }
            catch (Exception $e) { /* unique clash on the rare shared prefix+id  fall back to id-only */
                $code = 'ETL' . str_pad($auth_user['id'], 5, '0', STR_PAD_LEFT);
                try { $pdo->prepare('UPDATE users SET referral_code=? WHERE id=?')->execute(array($code, $auth_user['id'])); } catch (Exception $e2) {}
            }
        }
        $cnt = 0; $total = 0.0;
        try {
            $r = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(reward_etb),0) AS t FROM customer_referral_rewards WHERE referrer_user_id=?');
            $r->execute(array($auth_user['id']));
            $row = $r->fetch();
            $cnt = intval($row['c']); $total = floatval($row['t']);
        } catch (Exception $e) {}
        ok(array('referral_code' => $code, 'referred_count' => $cnt, 'total_reward_etb' => round($total, 2), 'reward_per_referral_etb' => 50.00));
    } catch (Exception $e) { fail_ex($e, 'Could not load referral info', 500); }
    break;
}

// ── SETTINGS & PRIVACY ───────────────────────────────────────────────
case 'customer_get_settings': {
    $pdo = db();
    $auth_user = require_auth($pdo);
    $defaults = array('notif_order_updates' => true, 'notif_promotions' => true, 'language' => 'en');
    try {
        $s = $pdo->prepare('SELECT settings_json FROM users WHERE id=?');
        $s->execute(array($auth_user['id']));
        $raw = $s->fetchColumn();
        $saved = $raw ? json_decode($raw, true) : array();
        if (!is_array($saved)) $saved = array();
        ok(array_merge($defaults, $saved));
    } catch (Exception $e) { ok($defaults); }
    break;
}

case 'customer_update_settings': {
    // POST: any of notif_order_updates, notif_promotions, language
    $pdo = db();
    $auth_user = require_auth($pdo);
    $allowed = array('notif_order_updates', 'notif_promotions', 'language');
    $updates = array();
    foreach ($allowed as $k) { if (isset($body[$k])) $updates[$k] = $body[$k]; }
    try {
        $s = $pdo->prepare('SELECT settings_json FROM users WHERE id=?');
        $s->execute(array($auth_user['id']));
        $raw = $s->fetchColumn();
        $saved = $raw ? json_decode($raw, true) : array();
        if (!is_array($saved)) $saved = array();
        $saved = array_merge($saved, $updates);
        $pdo->prepare('UPDATE users SET settings_json=? WHERE id=?')->execute(array(json_encode($saved), $auth_user['id']));
        ok($saved);
    } catch (Exception $e) { fail_ex($e, 'Could not update settings', 500); }
    break;
}

case 'initiate_telebirr_payment': {
    // POST: (requires customer auth)  order_id, amount, phone
    // Reuses the real telebirr_payments table (already built for a full
    // TelebirrGateway API integration in telebirr-handler.php - see
    // config-etb.php, whose TELEBIRR_MERCHANT_ID/API_KEY/SECRET_KEY are
    // all still blank, meaning that integration has no real credentials
    // yet and cannot actually run). This just records an auditable
    // "awaiting manual confirmation" row using the SAME schema, so
    // switching to the real gateway later needs no data migration - the
    // customer still has to actually dial *150# themselves, and the
    // founder checks his own Telebirr merchant SMS/dashboard separately
    // and confirms the matching record via admin_confirm_telebirr_payment.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $phone    = isset($body['phone']) ? trim($body['phone']) : '';
    $amount   = isset($body['amount']) ? floatval($body['amount']) : 0;
    if (!$order_id) fail('order_id is required', 400);
    if (!$phone) fail('phone is required', 400);
    if ($amount <= 0) fail('amount must be positive', 400);

    try {
        $chk = $pdo->prepare('SELECT id, customer_id, payment_method FROM orders WHERE id=?');
        $chk->execute(array($order_id));
        $order = $chk->fetch();
        if (!$order) fail('Order not found', 404);
        if (intval($order['customer_id']) !== intval($auth_user['id'])) fail('Not your order', 403);
        if ($order['payment_method'] !== 'telebirr') fail('This order is not set up for Telebirr payment', 400);

        $merchantTxId = 'ETEL' . time() . rand(1000, 9999);
        $ins = $pdo->prepare("INSERT INTO telebirr_payments
                               (user_id, order_id, amount, currency, phone, merchant_transaction_id, status, created_at)
                               VALUES (?, ?, ?, 'ETB', ?, ?, 'pending', NOW())");
        $ins->execute(array($auth_user['id'], $order_id, $amount, $phone, $merchantTxId));
        $payment_id = intval($pdo->lastInsertId());
        ok(array('payment_id' => $payment_id, 'status' => 'pending'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not start Telebirr payment', 500);
    }
    break;
}

case 'check_telebirr_payment': {
    // POST: (requires customer auth)  payment_id
    // Frontend polls this every 5s for up to 2 minutes waiting for
    // status to become "completed" - see startTelebirrPolling() in
    // customer.js. Returns whatever the founder's manual confirmation
    // has set it to; stays "pending" until then.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $payment_id = isset($body['payment_id']) ? intval($body['payment_id']) : 0;
    if (!$payment_id) fail('payment_id is required', 400);

    try {
        $s = $pdo->prepare('SELECT id, user_id, status FROM telebirr_payments WHERE id=?');
        $s->execute(array($payment_id));
        $row = $s->fetch();
        if (!$row) fail('Payment record not found', 404);
        if (intval($row['user_id']) !== intval($auth_user['id'])) fail('Not your payment', 403);
        // Frontend only checks for the literal string "completed" -
        // "pending"/"processing"/"failed" all just mean "keep polling or
        // time out", matching the real gateway's own status vocabulary
        // (see telebirr-callback.php) even though nothing here ever sets
        // "processing" in the manual flow.
        ok(array('status' => $row['status']));
    } catch (Exception $e) {
        fail_ex($e, 'Could not check payment status', 500);
    }
    break;
}

case 'admin_list_telebirr_payments': {
    // GET: (admin only) every pending Telebirr payment awaiting manual
    // verification against the founder's own Telebirr merchant
    // SMS/dashboard - the whole point of this feature existing at all.
    $pdo = db();
    $auth_user = require_admin($pdo, 'finance.view');
    try {
        $s = $pdo->query("SELECT tp.id, tp.order_id, tp.phone, tp.amount, tp.merchant_transaction_id, tp.status, tp.created_at,
                                  o.order_code, u.name AS customer_name, u.phone AS customer_phone
                           FROM telebirr_payments tp
                           JOIN orders o ON o.id = tp.order_id
                           JOIN users u ON u.id = tp.user_id
                           WHERE tp.status = 'pending'
                           ORDER BY tp.created_at ASC");
        ok(array('payments' => $s->fetchAll()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load Telebirr payments', 500);
    }
    break;
}

case 'admin_confirm_telebirr_payment': {
    // POST: (admin only)  payment_id, action ('confirm'|'reject')
    // Deliberately only ever touches orders.payment_status, never
    // orders.status - the pre-existing (uncredentialed, never actually
    // run) telebirr-handler.php/telebirr-callback.php both set
    // orders.status='paid' on confirmation, which would silently knock
    // a real order out of courier_get_available_orders (that query
    // filters on status='pending') the moment real Telebirr credentials
    // ever get configured and that code path actually runs for the
    // first time. Not replicating that here - payment_status is the
    // correct column for "has this been paid", status is the delivery
    // lifecycle (pending/accepted/picked_up/delivered) and must stay
    // independent of payment state, exactly like a normal cash order
    // already works.
    $pdo = db();
    $auth_user = require_admin($pdo, 'finance.manage');
    $payment_id = isset($body['payment_id']) ? intval($body['payment_id']) : 0;
    // Named "decision", not "action" - core.js's api() does
    // JSON.stringify({ action, ...body }), so a body parameter also
    // called "action" silently overwrites the real routing action
    // (the object spread comes last and wins) - this shipped broken
    // the first time around for exactly that reason, sending literally
    // "action=confirm" to boot.php's dispatcher and 404ing.
    $decision = isset($body['decision']) ? trim($body['decision']) : '';
    if (!$payment_id) fail('payment_id is required', 400);
    if (!in_array($decision, array('confirm','reject'), true)) fail("decision must be 'confirm' or 'reject'", 400);

    try {
        $s = $pdo->prepare('SELECT id, order_id, status FROM telebirr_payments WHERE id=?');
        $s->execute(array($payment_id));
        $row = $s->fetch();
        if (!$row) fail('Payment record not found', 404);
        if ($row['status'] !== 'pending') fail('This payment was already ' . $row['status'], 409);

        $pdo->beginTransaction();
        if ($decision === 'confirm') {
            $pdo->prepare("UPDATE telebirr_payments SET status='completed', completed_at=NOW() WHERE id=?")
                ->execute(array($payment_id));
            $pdo->prepare("UPDATE orders SET payment_status='paid' WHERE id=?")
                ->execute(array($row['order_id']));
        } else {
            $pdo->prepare("UPDATE telebirr_payments SET status='failed', error_message='Rejected by admin - manual review' WHERE id=?")
                ->execute(array($payment_id));
        }
        $pdo->commit();
        ok(array('message' => $decision === 'confirm' ? 'Payment confirmed' : 'Payment rejected',
                 'status' => $decision === 'confirm' ? 'completed' : 'failed'));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not update payment', 500);
    }
    break;
}

case 'confirm_bank_payment': {
    // POST: (requires customer auth)  order_id, bank ('abysinia'|'cbe'),
    // amount, reference_no
    // Records the customer's own claimed bank transfer for manual
    // verification - same philosophy as Telebirr's manual flow (see
    // initiate_telebirr_payment above), reusing the real bank_payments
    // table that already existed in the schema (verified_by/verified_at
    // columns make its intended manual-review design obvious) but had
    // no action writing to it yet, same as telebirr_payments before.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $order_id     = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $bank         = isset($body['bank']) ? trim($body['bank']) : '';
    $amount       = isset($body['amount']) ? floatval($body['amount']) : 0;
    $reference_no = isset($body['reference_no']) ? trim($body['reference_no']) : '';
    if (!$order_id) fail('order_id is required', 400);
    if (!in_array($bank, array('abysinia','cbe'), true)) fail("bank must be 'abysinia' or 'cbe'", 400);
    if ($amount <= 0) fail('amount must be positive', 400);
    if (!$reference_no) fail('reference_no is required', 400);

    try {
        $chk = $pdo->prepare('SELECT id, customer_id, payment_method FROM orders WHERE id=?');
        $chk->execute(array($order_id));
        $order = $chk->fetch();
        if (!$order) fail('Order not found', 404);
        if (intval($order['customer_id']) !== intval($auth_user['id'])) fail('Not your order', 403);
        if ($order['payment_method'] !== $bank) fail('This order was not set up for ' . $bank . ' payment', 400);

        $ins = $pdo->prepare("INSERT INTO bank_payments (user_id, order_id, bank, amount, reference_no, status, created_at)
                               VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $ins->execute(array($auth_user['id'], $order_id, $bank, $amount, $reference_no));
        ok(array('payment_id' => intval($pdo->lastInsertId()), 'status' => 'pending'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not record bank payment', 500);
    }
    break;
}

case 'admin_list_bank_payments': {
    // GET: (admin only) every pending bank transfer awaiting manual
    // verification against the founder's own bank statement/SMS alerts.
    $pdo = db();
    $auth_user = require_admin($pdo, 'finance.view');
    try {
        $s = $pdo->query("SELECT bp.id, bp.order_id, bp.bank, bp.amount, bp.reference_no, bp.status, bp.created_at,
                                  o.order_code, u.name AS customer_name, u.phone AS customer_phone
                           FROM bank_payments bp
                           JOIN orders o ON o.id = bp.order_id
                           JOIN users u ON u.id = bp.user_id
                           WHERE bp.status = 'pending'
                           ORDER BY bp.created_at ASC");
        ok(array('payments' => $s->fetchAll()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load bank payments', 500);
    }
    break;
}

case 'admin_confirm_bank_payment': {
    // POST: (admin only)  payment_id, decision ('confirm'|'reject')
    // Named "decision", NOT "action" - core.js's api() does
    // JSON.stringify({ action, ...body }), so a body parameter also
    // called "action" silently overwrites the real routing action and
    // 404s (exactly what happened building admin_confirm_telebirr_payment
    // the first time). Only ever touches orders.payment_status, never
    // orders.status, for the same reason documented on the Telebirr
    // version above - delivery lifecycle and payment state must stay
    // independent.
    $pdo = db();
    $auth_user = require_admin($pdo, 'finance.manage');
    $payment_id = isset($body['payment_id']) ? intval($body['payment_id']) : 0;
    $decision = isset($body['decision']) ? trim($body['decision']) : '';
    if (!$payment_id) fail('payment_id is required', 400);
    if (!in_array($decision, array('confirm','reject'), true)) fail("decision must be 'confirm' or 'reject'", 400);

    try {
        $s = $pdo->prepare('SELECT id, order_id, status FROM bank_payments WHERE id=?');
        $s->execute(array($payment_id));
        $row = $s->fetch();
        if (!$row) fail('Payment record not found', 404);
        if ($row['status'] !== 'pending') fail('This payment was already ' . $row['status'], 409);

        $pdo->beginTransaction();
        if ($decision === 'confirm') {
            $pdo->prepare("UPDATE bank_payments SET status='verified', verified_by=?, verified_at=NOW() WHERE id=?")
                ->execute(array($auth_user['id'], $payment_id));
            $pdo->prepare("UPDATE orders SET payment_status='paid' WHERE id=?")
                ->execute(array($row['order_id']));
        } else {
            $pdo->prepare("UPDATE bank_payments SET status='rejected', verified_by=?, verified_at=NOW(), notes='Rejected by admin - manual review' WHERE id=?")
                ->execute(array($auth_user['id'], $payment_id));
        }
        $pdo->commit();
        ok(array('message' => $decision === 'confirm' ? 'Payment confirmed' : 'Payment rejected',
                 'status' => $decision === 'confirm' ? 'verified' : 'rejected'));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not update payment', 500);
    }
    break;
}

case 'admin_financial_report': {    // GET: (admin only, or any role with finance.view)  ?days=7|30|90|all
    // Real revenue-by-method ledger, not just the plain order-count
    // breakdown admin_get_reports already has - confirmed vs pending
    // amounts for Telebirr and bank transfers specifically, since those
    // are the two methods that actually need manual verification before
    // the money is real. Cash is inherently "confirmed at delivery" (see
    // OrderController.php's delivery-confirmation flow, which already
    // sets payment_status='paid' at that point) so there's no separate
    // pending/confirmed split to show for it here.
    $pdo = db();
    $auth_user = require_admin($pdo, 'finance.view');
    if (!user_has_capability($pdo, $auth_user, 'finance.view')) {
        fail("You don't have permission to do this (requires: finance.view)", 403);
    }

    $days = isset($_GET['days']) ? trim($_GET['days']) : (isset($body['days']) ? trim($body['days']) : '30');
    $dateFilter = 'placed_at >= DATE_SUB(NOW(), INTERVAL ' . (ctype_digit($days) ? intval($days) : 30) . ' DAY)';
    if ($days === 'all') $dateFilter = '1=1';

    try {
        // Cash - always effectively "confirmed", counted at face value.
        $cash = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(fare_total),0) AS total
                              FROM orders WHERE payment_method='cash' AND $dateFilter")->fetch();

        // Telebirr - split by the real per-transaction status, not the
        // order's own payment_method alone, since a customer can place
        // the order and never actually complete the USSD dial.
        $tb = $pdo->query("SELECT tp.status, COUNT(*) AS n, COALESCE(SUM(tp.amount),0) AS total
                            FROM telebirr_payments tp JOIN orders o ON o.id = tp.order_id
                            WHERE o.$dateFilter GROUP BY tp.status")->fetchAll();

        // Bank transfers (Abysinia + CBE combined, and separately).
        $bank = $pdo->query("SELECT bp.bank, bp.status, COUNT(*) AS n, COALESCE(SUM(bp.amount),0) AS total
                              FROM bank_payments bp JOIN orders o ON o.id = bp.order_id
                              WHERE o.$dateFilter GROUP BY bp.bank, bp.status")->fetchAll();

        $tbByStatus = array();
        foreach ($tb as $row) $tbByStatus[$row['status']] = array('count' => intval($row['n']), 'total' => floatval($row['total']));

        $bankByBankAndStatus = array();
        foreach ($bank as $row) {
            if (!isset($bankByBankAndStatus[$row['bank']])) $bankByBankAndStatus[$row['bank']] = array();
            $bankByBankAndStatus[$row['bank']][$row['status']] = array('count' => intval($row['n']), 'total' => floatval($row['total']));
        }

        $confirmedTotal = floatval($cash['total'])
            + (isset($tbByStatus['completed']) ? $tbByStatus['completed']['total'] : 0);
        foreach ($bankByBankAndStatus as $statuses) {
            $confirmedTotal += isset($statuses['verified']) ? $statuses['verified']['total'] : 0;
        }
        $pendingTotal = (isset($tbByStatus['pending']) ? $tbByStatus['pending']['total'] : 0);
        foreach ($bankByBankAndStatus as $statuses) {
            $pendingTotal += isset($statuses['pending']) ? $statuses['pending']['total'] : 0;
        }

        ok(array(
            'range_days' => $days,
            'cash' => array('count' => intval($cash['n']), 'total' => floatval($cash['total'])),
            'telebirr' => $tbByStatus,
            'bank' => $bankByBankAndStatus,
            'confirmed_total' => round($confirmedTotal, 2),
            'pending_total' => round($pendingTotal, 2),
        ));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load financial report', 500);
    }
    break;
}

case 'get_payment_method_logos': {
    // GET: (any authenticated user) - returns whatever logo images the
    // founder has uploaded per payment method, so every screen that
    // shows a Telebirr/CBE/Abysinia/wallet icon can use the real
    // uploaded image instead of a custom-drawn badge, the moment one
    // exists. No auth restriction beyond being logged in - this is
    // just icon assets, not sensitive data.
    $pdo = db();
    require_auth($pdo);
    try {
        $s = $pdo->query('SELECT method_key, logo_url FROM payment_method_logos');
        $logos = array();
        foreach ($s->fetchAll() as $row) $logos[$row['method_key']] = $row['logo_url'];
        ok(array('logos' => $logos));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load payment method logos - has migration 054_sg_separate_banks_and_logos.sql been applied?', 500);
    }
    break;
}

case 'admin_set_payment_logo': {
    // POST: (admin only)  method_key ('telebirr'|'cbe'|'abysinia'|'wallet'|'cash'), logo_url
    // logo_url comes from the same upload.php endpoint the business
    // logo upload already uses - this just records which URL belongs
    // to which payment method, it doesn't handle the file upload itself.
    $pdo = db();
    $auth_user = require_admin($pdo, 'finance.manage');
    $method_key = isset($body['method_key']) ? trim($body['method_key']) : '';
    $logo_url = isset($body['logo_url']) ? trim($body['logo_url']) : '';
    $validKeys = array('telebirr','cbe','abysinia','wallet','cash');
    if (!in_array($method_key, $validKeys, true)) fail('method_key must be one of: ' . implode(', ', $validKeys), 400);
    if (!$logo_url) fail('logo_url is required', 400);
    try {
        $s = $pdo->prepare('INSERT INTO payment_method_logos (method_key, logo_url, updated_by, updated_at)
                             VALUES (?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE logo_url=VALUES(logo_url), updated_by=VALUES(updated_by), updated_at=NOW()');
        $s->execute(array($method_key, $logo_url, $auth_user['id']));
        ok(array('message' => ucfirst($method_key) . ' logo updated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save logo', 500);
    }
    break;
}
}
