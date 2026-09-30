<?php
if (!function_exists('sg_build_kitchen_ticket')) {
    function sg_build_kitchen_ticket($pdo, $order_id) {
        $oq = $pdo->prepare('SELECT order_code, recipient_name, recipient_phone, notes, placed_at, from_zone, to_zone, fare_total, subtotal, delivery_fee, service_charge FROM orders WHERE id=?');
        $oq->execute(array($order_id));
        $orderRow = $oq->fetch();
        if (!$orderRow) return null;

        $iq = $pdo->prepare('SELECT item_name, quantity, unit_price, subtotal FROM order_items WHERE order_id=? ORDER BY id ASC');
        $iq->execute(array($order_id));
        $items = $iq->fetchAll();

        return array(
            'order_code'      => $orderRow['order_code'],
            'recipient_name'  => $orderRow['recipient_name'],
            'recipient_phone' => $orderRow['recipient_phone'],
            'notes'           => $orderRow['notes'],
            'placed_at'       => $orderRow['placed_at'],
            'from_zone'       => $orderRow['from_zone'],
            'to_zone'         => $orderRow['to_zone'],
            'fare_total'      => $orderRow['fare_total'],
            'subtotal'        => $orderRow['subtotal'],
            'delivery_fee'    => $orderRow['delivery_fee'],
            'service_charge'  => $orderRow['service_charge'],
            'items'           => $items,
        );
    }
}

/* Role-based access for a service giver business: the account that
   registered the business (service_givers.user_id) is always the
   'owner', with unrestricted access to their own business always -
   same pattern as Delivery's '*' capability for unrestricted accounts.
   Additional staff logins (sg_staff table, added via sg_add_staff
   below) are scoped to a business they don't own, with a CUSTOM role
   (sg_roles table) defining exactly what they can do - a business can
   name its own roles ("Kitchen", "Pharmacist", whatever fits) rather
   than being stuck with a single fixed "cashier" tier. A staff row with
   no role_id (legacy accounts from before custom roles existed) falls
   back to a safe default matching the old cashier behavior: view+manage
   orders only. Returns array('sg_id'=>int,'role'=>'owner'|'staff',
   'role_name'=>string|null,'capabilities'=>string[]) or null if this
   user isn't linked to any business at all. */
if (!function_exists('sg_get_context')) {
    function sg_get_context($pdo, $user_id) {
        try {
            $s = $pdo->prepare('SELECT id FROM service_givers WHERE user_id=?');
            $s->execute(array($user_id));
            $row = $s->fetch();
            if ($row) return array('sg_id' => intval($row['id']), 'role' => 'owner', 'role_name' => 'Owner', 'capabilities' => array('*'));
        } catch (Exception $e) { error_log('[ServiceGiverController] sg_get_context owner lookup failed: ' . $e->getMessage()); }
        try {
            $s2 = $pdo->prepare('SELECT st.service_giver_id, st.role_id, r.role_name, r.capabilities
                                  FROM sg_staff st LEFT JOIN sg_roles r ON r.id = st.role_id
                                  WHERE st.user_id=? AND st.active=1');
            $s2->execute(array($user_id));
            $row2 = $s2->fetch();
            if ($row2) {
                if ($row2['role_id'] && $row2['capabilities'] !== null) {
                    $caps = json_decode($row2['capabilities'], true);
                    if (!is_array($caps)) $caps = array('orders.view', 'orders.manage');
                    $roleName = $row2['role_name'];
                } else {
                    // Legacy staff row predating custom roles, or its
                    // role was since deleted - same default the old
                    // hardcoded 'cashier' tier always had.
                    $caps = array('orders.view', 'orders.manage');
                    $roleName = 'Cashier';
                }
                return array('sg_id' => intval($row2['service_giver_id']), 'role' => 'staff', 'role_name' => $roleName, 'capabilities' => $caps);
            }
        } catch (Exception $e) { /* sg_staff table not migrated yet on this deployment - no staff accounts possible, fine */ }
        return null;
    }
}
/* Fails the request unless the caller is the business owner (or a
   platform admin, who can act on behalf of any business). Cashiers hit
   this on reprint and business-settings/payment actions. */
if (!function_exists('sg_require_owner')) {
    function sg_require_owner($auth_user, $ctx) {
        if ($auth_user['role'] === 'admin') return;
        if (!$ctx || $ctx['role'] !== 'owner') fail('Only the business owner can do this - ask them to do it, or to add you as owner.', 403);
    }
}
/* Fails the request unless the caller has a specific capability within
   their own business - the owner always passes (via the '*' wildcard
   set above), everyone else only if their role was explicitly granted
   this capability. Mirrors Delivery's user_has_capability() pattern,
   scoped to a single business instead of the whole platform. */
if (!function_exists('sg_has_capability')) {
    function sg_has_capability($ctx, $cap) {
        if (!$ctx) return false;
        $caps = isset($ctx['capabilities']) ? $ctx['capabilities'] : array();
        return in_array('*', $caps, true) || in_array($cap, $caps, true);
    }
}
if (!function_exists('sg_require_capability')) {
    function sg_require_capability($auth_user, $ctx, $cap) {
        if ($auth_user['role'] === 'admin') return;
        if (!sg_has_capability($ctx, $cap)) {
            $label = isset($ctx['role_name']) ? $ctx['role_name'] : 'Your role';
            fail($label . " doesn't have permission to do this (requires: $cap)", 403);
        }
    }
}
/* The full set of capabilities a business owner can grant to a custom
   role - deliberately generic so it fits a restaurant, pharmacy, or
   electronics shop equally well, rather than modeling one business
   type specifically. */
if (!function_exists('sg_capability_catalog')) {
    function sg_capability_catalog() {
        return array(
            'orders.view'    => 'See incoming and past orders',
            'orders.manage'  => 'Accept, reject, or mark orders ready',
            'menu.view'      => 'See the item/menu catalogue',
            'menu.manage'    => 'Add, edit, or remove items',
            'payments.view'  => 'See earnings and payout history',
            'staff.manage'   => 'Add or remove staff, define roles',
            'settings.manage'=> 'Edit business profile, hours, zone',
            'reports.view'   => 'See sales/performance reports',
        );
    }
}

switch ($action) {
case 'sg_profile': {
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);

    etel_ensure_sg_branding_columns($pdo);
    $ctx = sg_get_context($pdo, $auth_user['id']);

    // Try full column set first, fall back to minimal columns if schema is behind
    $sg = null;
    if ($ctx) {
        try {
            $s = $pdo->prepare('SELECT id,service_giver_code,business_name,service_type,vehicle_type,vehicle_plate,zone,address,city,bank_account,payout_bank,telebirr_number,cbe_account,abysinia_account,cbe_account_name,abysinia_account_name,telebirr_account_name,status,is_online,average_rating,total_deliveries,completed_deliveries,failed_deliveries,total_earnings,rating_count,logo_url,cover_url,created_at FROM service_givers WHERE id=?');
            $s->execute(array($ctx['sg_id']));
            $sg = $s->fetch();
        } catch (Exception $e) {
            // Minimal fallback if some columns are missing
            try {
                $s = $pdo->prepare('SELECT id,business_name,service_type,zone,address,status FROM service_givers WHERE id=?');
                $s->execute(array($ctx['sg_id']));
                $sg = $s->fetch();
            } catch (Exception $e2) { error_log('[ServiceGiverController] sg_profile fallback query failed: ' . $e2->getMessage()); $sg = null; }
        }
    }

    // Auto-create only applies to a genuinely brand-new OWNER (no
    // service_givers row AND no sg_staff row at all) - a cashier whose
    // sg_staff link is missing/broken should fail loudly so an admin can
    // fix the link, not silently get handed a fresh, empty business.
    if (!$sg && !$ctx) {
        try {
            $code = 'SG-' . strtoupper(substr(md5($auth_user['id'] . time()), 0, 6));
            $ins  = $pdo->prepare("INSERT IGNORE INTO service_givers (user_id, service_giver_code, business_name, service_type, zone, status, created_at)
                                   VALUES (?, ?, ?, 'restaurant', 'Mehal Ketema', 'active', NOW())");
            $ins->execute(array($auth_user['id'], $code, $auth_user['name'] . "'s Shop"));
            $s2 = $pdo->prepare('SELECT * FROM service_givers WHERE user_id=?');
            $s2->execute(array($auth_user['id']));
            $sg = $s2->fetch();
            if ($sg) $ctx = array('sg_id' => intval($sg['id']), 'role' => 'owner', 'role_name' => 'Owner', 'capabilities' => array('*'));
        } catch (Exception $e3) { error_log('[ServiceGiverController] service_giver auto-create failed: ' . $e3->getMessage()); }
    }

    if (!$sg) fail('Service giver profile could not be loaded. Contact admin.', 404);
    // Fill any missing optional fields with defaults
    $sg += array('average_rating'=>0,'rating_count'=>0,'total_deliveries'=>0,'total_earnings'=>0,'is_online'=>0);

    $staff_role = $ctx ? $ctx['role'] : 'owner';
    if ($ctx && !sg_has_capability($ctx, 'payments.view')) {
        // Staff without payments.view run the till day-to-day but don't
        // see the business's money or its bank account.
        unset($sg['total_earnings']);
        unset($sg['bank_account']);
    }
    ok(array('user' => $auth_user, 'service_giver' => $sg, 'staff_role' => $staff_role,
             'role_name' => $ctx ? $ctx['role_name'] : 'Owner',
             'capabilities' => $ctx ? $ctx['capabilities'] : array('*')));
    break;
}
case 'sg_update_profile': {
    // PUT: (requires Authorization, owner or platform admin)  business_name,
    // service_type, zone, address, city, vehicle_type, vehicle_plate,
    // bank_account, logo_url, lat/lng
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);

    // A platform admin editing a business from the admin panel has no
    // service_givers/sg_staff row of their own to resolve a context from
    // (same gap already found and fixed for sg_get_staff/sg_remove_staff)
    // - without sg_id, this silently updated WHERE user_id=<admin's own
    // id>, matching zero real rows and doing nothing while still
    // reporting "success". An owner's own edit still resolves normally.
    $target_sg_id = isset($body['sg_id']) ? intval($body['sg_id']) : (isset($_GET['sg_id']) ? intval($_GET['sg_id']) : 0);
    if ($auth_user['role'] === 'admin' && $target_sg_id) {
        $ctx = array('sg_id' => $target_sg_id, 'role' => 'owner', 'role_name' => 'Owner', 'capabilities' => array('*'));
    } else {
        // Business settings, payment/bank info, and branding require
        // settings.manage - a staff login without it can run the till but
        // can't change what the business looks like or where its money goes.
        $ctx = sg_get_context($pdo, $auth_user['id']);
        sg_require_capability($auth_user, $ctx, 'settings.manage');
    }

    $isAdminEdit = ($auth_user['role'] === 'admin' && $target_sg_id);

    $updates = array();
    if (isset($body['business_name']) && $body['business_name']) {
        $updates['business_name'] = $body['business_name'];
    }
    if (isset($body['service_type']) && $body['service_type']) {
        $updates['service_type'] = $body['service_type'];
    }
    if (isset($body['zone']) && $body['zone']) {
        $updates['zone'] = $body['zone'];
    }
    if (isset($body['address'])) {
        $updates['address'] = $body['address'];
    }
    if (isset($body['city']) && $body['city']) {
        $updates['city'] = $body['city'];
    }
    if (isset($body['vehicle_type']) && $body['vehicle_type']) {
        $updates['vehicle_type'] = $body['vehicle_type'];
    }
    if (isset($body['vehicle_plate']) && $body['vehicle_plate']) {
        $updates['vehicle_plate'] = $body['vehicle_plate'];
    }
    // Payout details (bank_account, payout_bank, telebirr_number,
    // cbe_account, abysinia_account) are deliberately handled
    // separately below, NOT added to $updates here - a business owner
    // changing where their money goes doesn't take effect immediately;
    // it creates a pending request the founder must approve first
    // (fraud/mistake protection - the business keeps getting paid to
    // its OLD details until then). An admin editing via the Manage
    // modal (sg_id present) is the approver themselves, so their own
    // edits still apply directly - no self-approval loop.
    $payoutFieldsSubmitted = isset($body['bank_account']) || isset($body['payout_bank']) || isset($body['telebirr_number'])
        || isset($body['cbe_account']) || isset($body['abysinia_account'])
        || isset($body['cbe_account_name']) || isset($body['abysinia_account_name']) || isset($body['telebirr_account_name']);
    if ($isAdminEdit) {
        if (isset($body['bank_account'])) $updates['bank_account'] = $body['bank_account'];
        if (isset($body['payout_bank']) && in_array($body['payout_bank'], array('abysinia','cbe'), true)) $updates['payout_bank'] = $body['payout_bank'];
        if (isset($body['telebirr_number'])) $updates['telebirr_number'] = trim($body['telebirr_number']);
        if (isset($body['cbe_account'])) $updates['cbe_account'] = trim($body['cbe_account']);
        if (isset($body['abysinia_account'])) $updates['abysinia_account'] = trim($body['abysinia_account']);
        if (isset($body['cbe_account_name'])) $updates['cbe_account_name'] = trim($body['cbe_account_name']);
        if (isset($body['abysinia_account_name'])) $updates['abysinia_account_name'] = trim($body['abysinia_account_name']);
        if (isset($body['telebirr_account_name'])) $updates['telebirr_account_name'] = trim($body['telebirr_account_name']);
    }
    if (isset($body['logo_url'])) {
        $updates['logo_url'] = trim($body['logo_url']);
    }
    // Cover photo: empty string clears it; otherwise it must be an http(s) URL
    // (what upload.php returns), so nothing like javascript: can ever be stored.
    if (isset($body['cover_url'])) {
        $cv = trim($body['cover_url']);
        if ($cv !== '' && (strlen($cv) > 512 || !preg_match('#^https?://#i', $cv))) fail('Invalid cover photo URL', 400);
        etel_ensure_sg_branding_columns($pdo);
        $updates['cover_url'] = ($cv === '') ? null : $cv;
    }
    // Real shop coordinates were never settable through any action
    // before this - only the text-only zone/address/city fields were.
    // Delivery's auto-dispatch matching (delivery_order_core.php) now
    // prefers this real pin over its hardcoded zone-centroid fallback
    // the moment one is set, and computes the hex cell needed for
    // hex-grid courier matching at the same time.
    if (isset($body['lat']) && isset($body['lng']) && $body['lat'] !== '' && $body['lng'] !== '') {
        $sgLat = floatval($body['lat']);
        $sgLng = floatval($body['lng']);
        $updates['lat'] = $sgLat;
        $updates['lng'] = $sgLng;
        if (function_exists('hexgrid_cell_for')) {
            list($sgHexQ, $sgHexR) = hexgrid_cell_for($sgLat, $sgLng);
            $updates['hex_q'] = $sgHexQ;
            $updates['hex_r'] = $sgHexR;
        }
    }

    if (empty($updates) && !($payoutFieldsSubmitted && !$isAdminEdit)) fail('No fields to update', 400);

    $messages = array();
    $updated_fields = array();

    // Non-payout fields (and admin's own payout edits) still save
    // immediately, exactly as before.
    if (!empty($updates)) {
        $set_clauses = array();
        $params = array();
        foreach ($updates as $col => $val) {
            $set_clauses[] = "$col = ?";
            $params[] = $val;
        }
        if ($ctx) {
            $query = 'UPDATE service_givers SET ' . implode(', ', $set_clauses) . ' WHERE id=?';
            $params[] = $ctx['sg_id'];
        } else {
            $query = 'UPDATE service_givers SET ' . implode(', ', $set_clauses) . ' WHERE user_id=?';
            $params[] = $auth_user['id'];
        }
        try {
            $s = $pdo->prepare($query);
            $s->execute($params);
            $updated_fields = array_keys($updates);
            $messages[] = 'Profile updated';
        } catch (Exception $e) {
            fail_ex($e, 'Update failed', 500);
        }
    }

    // A non-admin submitting a payout change creates a pending request
    // instead of touching service_givers directly.
    if ($payoutFieldsSubmitted && !$isAdminEdit) {
        if (!$ctx) fail('Service giver profile not found', 404);
        try {
            $cur = $pdo->prepare('SELECT bank_account, payout_bank, telebirr_number, cbe_account, abysinia_account,
                                          cbe_account_name, abysinia_account_name, telebirr_account_name
                                   FROM service_givers WHERE id=?');
            $cur->execute(array($ctx['sg_id']));
            $curRow = $cur->fetch();
            $newBank    = isset($body['bank_account']) ? $body['bank_account'] : ($curRow ? $curRow['bank_account'] : null);
            $newPayoutB = (isset($body['payout_bank']) && in_array($body['payout_bank'], array('abysinia','cbe'), true)) ? $body['payout_bank'] : ($curRow ? $curRow['payout_bank'] : null);
            $newTelebirr = isset($body['telebirr_number']) ? trim($body['telebirr_number']) : ($curRow ? $curRow['telebirr_number'] : null);
            $newCbe = isset($body['cbe_account']) ? trim($body['cbe_account']) : ($curRow ? $curRow['cbe_account'] : null);
            $newAbysinia = isset($body['abysinia_account']) ? trim($body['abysinia_account']) : ($curRow ? $curRow['abysinia_account'] : null);
            $newCbeName = isset($body['cbe_account_name']) ? trim($body['cbe_account_name']) : ($curRow ? $curRow['cbe_account_name'] : null);
            $newAbysiniaName = isset($body['abysinia_account_name']) ? trim($body['abysinia_account_name']) : ($curRow ? $curRow['abysinia_account_name'] : null);
            $newTelebirrName = isset($body['telebirr_account_name']) ? trim($body['telebirr_account_name']) : ($curRow ? $curRow['telebirr_account_name'] : null);

            // Cancel any earlier still-pending request for this business
            // first, so there's only ever one live request to review -
            // otherwise a second edit before the first was reviewed would
            // leave two conflicting pending rows.
            $pdo->prepare("UPDATE sg_payout_change_requests SET status='rejected', admin_note='Superseded by a newer request', reviewed_at=NOW() WHERE service_giver_id=? AND status='pending'")
                ->execute(array($ctx['sg_id']));

            $ins = $pdo->prepare('INSERT INTO sg_payout_change_requests
                (service_giver_id, requested_by,
                 old_bank_account, old_payout_bank, old_telebirr_number, old_cbe_account, old_abysinia_account,
                 old_cbe_account_name, old_abysinia_account_name, old_telebirr_account_name,
                 new_bank_account, new_payout_bank, new_telebirr_number, new_cbe_account, new_abysinia_account,
                 new_cbe_account_name, new_abysinia_account_name, new_telebirr_account_name,
                 status, requested_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", NOW())');
            $ins->execute(array(
                $ctx['sg_id'], $auth_user['id'],
                $curRow ? $curRow['bank_account'] : null, $curRow ? $curRow['payout_bank'] : null, $curRow ? $curRow['telebirr_number'] : null,
                $curRow ? $curRow['cbe_account'] : null, $curRow ? $curRow['abysinia_account'] : null,
                $curRow ? $curRow['cbe_account_name'] : null, $curRow ? $curRow['abysinia_account_name'] : null, $curRow ? $curRow['telebirr_account_name'] : null,
                $newBank, $newPayoutB, $newTelebirr, $newCbe, $newAbysinia,
                $newCbeName, $newAbysiniaName, $newTelebirrName,
            ));
            $messages[] = 'Payout change submitted for admin approval - your current payout details keep working until then';
        } catch (Exception $e) {
            fail_ex($e, 'Could not submit payout change - has migration 053_sg_payout_approvals.sql been applied?', 500);
        }
    }

    ok(array('message' => implode('. ', $messages), 'updated_fields' => $updated_fields,
             'payout_pending' => ($payoutFieldsSubmitted && !$isAdminEdit)));
    break;
}
case 'sg_orders': {
    // GET: (requires auth)  ?status=pending|accepted|picked_up|delivered|cancelled
    // List assigned orders with filtering
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    
    // Fetch service giver ID
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_orders: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.view');
    
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $where = 'WHERE oa.service_giver_id = ?';
    $params = array($sg['id']);
    
    if ($status && in_array($status, array('pending','preparing','assigned','accepted','picked_up','delivered','cancelled'))) {
        $where .= ' AND oa.status = ?';
        $params[] = $status;
    }
    
    try {
        $s = $pdo->prepare("
            SELECT 
                oa.id as assignment_id,
                oa.order_id,
                oa.status,
                oa.assigned_at,
                oa.accepted_at,
                oa.pickup_qr_scanned,
                oa.delivery_qr_scanned,
                o.order_code,
                o.from_zone,
                o.to_zone,
                o.package_type,
                o.recipient_name,
                o.recipient_phone,
                o.fare_total,
                o.placed_at,
                o.notes
            FROM order_assignments oa
            JOIN orders o ON oa.order_id = o.id
            $where
            ORDER BY oa.assigned_at DESC
        ");
        $s->execute($params);
        $orders = $s->fetchAll();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }
    
    ok(array('orders' => $orders, 'count' => count($orders)));
    break;
}
case 'sg_accept_order': {
    // POST: (requires auth)  order_id
    // Accept an assigned order and start fulfillment
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_accept_order: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.manage');
    
    // Get assignment
    try {
        $asgn = $pdo->prepare('SELECT id, status FROM order_assignments WHERE order_id=? AND service_giver_id=?');
        $asgn->execute(array($order_id, $sg['id']));
        $assign = $asgn->fetch();
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_accept_order: assignment lookup failed: ' . $e->getMessage()); $assign = null; }
    
    if (!$assign) fail('Order assignment not found', 404);
    if ($assign['status'] !== 'pending') fail('Order is already ' . $assign['status'], 409);
    
    // Update status to accepted
    try {
        $upd = $pdo->prepare('UPDATE order_assignments SET status="accepted", accepted_at=NOW() WHERE id=?');
        $upd->execute(array($assign['id']));
        ok(array('message' => 'Order accepted', 'order_id' => $order_id, 'status' => 'accepted'));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}
case 'sg_get_kitchen_ticket': {
    // GET/POST: (requires auth)  order_id - lets the service giver
    // reprint a kitchen ticket (lost, smudged, second copy needed) at
    // any time after prep started, not just at the moment it started.
    // Owner-only: a reprint can hand a second copy of a receipt to
    // someone, so it's restricted the same way voiding/reprinting a
    // sale is in a normal POS system.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);

    $ctx = sg_get_context($pdo, $auth_user['id']);
    sg_require_capability($auth_user, $ctx, 'orders.manage');
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : (isset($_GET['order_id']) ? intval($_GET['order_id']) : 0);
    if (!$order_id) fail('order_id is required', 400);

    $sg = $ctx ? array('id' => $ctx['sg_id']) : null;
    if (!$sg) fail('Service giver profile not found', 404);

    $asgn = $pdo->prepare('SELECT id FROM order_assignments WHERE order_id=? AND service_giver_id=?');
    $asgn->execute(array($order_id, $sg['id']));
    if (!$asgn->fetch()) fail('Order assignment not found', 404);

    try {
        $ticket = sg_build_kitchen_ticket($pdo, $order_id);
    } catch (Exception $e) {
        fail_ex($e, 'Could not build kitchen ticket', 500);
    }
    if (!$ticket) fail('Order not found', 404);
    // Tells the frontend to stamp "REPRINT" on this copy - distinguishes
    // it from the original that auto-printed when prep started.
    $ticket['is_reprint'] = true;
    ok(array('ticket' => $ticket));
    break;
}

case 'sg_update_order_status': {
    // POST: (requires auth)  order_id, new_status (preparing|accepted)
    // SG advances: pendingpreparing (Start Preparing) or preparingaccepted (Mark Ready for Pickup)
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);

    $order_id   = isset($body['order_id'])   ? intval($body['order_id'])  : 0;
    $new_status = isset($body['new_status']) ? trim($body['new_status'])  : '';

    if (!$order_id) fail('order_id is required', 400);
    if (!in_array($new_status, array('preparing','accepted'))) fail('new_status must be preparing or accepted', 400);

    // ROOT CAUSE FIX: order_assignments.status is an ENUM that historically
    // did NOT include 'preparing'. In MySQL's default non-strict mode,
    // writing a value that is not in an ENUM's list does not error -- it
    // silently stores '' (empty string) while still reporting the row as
    // changed (rowCount = 1). That is exactly why "Start Preparing" showed
    // a success toast every time while the column stayed blank. Widen the
    // ENUM once here (idempotent -- MODIFY to the same definition is a
    // no-op on later calls, and any failure is swallowed like the other
    // auto-migrations in this codebase).
    try {
        $pdo->exec("ALTER TABLE order_assignments MODIFY COLUMN status
            ENUM('pending','preparing','assigned','accepted','picked_up','delivered','cancelled')
            NOT NULL DEFAULT 'pending'");
    } catch (Exception $e) { error_log('[ServiceGiverController] status ENUM widen failed (non-fatal, idempotent): ' . $e->getMessage()); }

    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_update_order_status: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.manage');

    try {
        $asgn = $pdo->prepare('SELECT id, status FROM order_assignments WHERE order_id=? AND service_giver_id=?');
        $asgn->execute(array($order_id, $sg['id']));
        $assign = $asgn->fetch();
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_update_order_status: assignment lookup failed: ' . $e->getMessage()); $assign = null; }
    if (!$assign) fail('Order assignment not found', 404);

    // Some assignment rows ended up with an empty/NULL status (predates a
    // schema change that added this column, or were inserted by a path
    // that didn't set it) -- that always semantically means 'pending', the
    // column's own DEFAULT. Treat it as such, and heal the row so this
    // order doesn't keep tripping the same error on every future click.
    $current_status = (isset($assign['status']) && $assign['status'] !== '') ? $assign['status'] : 'pending';
    if ($current_status !== $assign['status']) {
        try {
            $pdo->prepare("UPDATE order_assignments SET status='pending' WHERE id=? AND (status IS NULL OR status='')")
                ->execute(array($assign['id']));
        } catch (Exception $e) { error_log('[ServiceGiverController] status heal update failed (non-fatal): ' . $e->getMessage()); }
    }

    // Only allow valid forward transitions
    $allowed = array('pending' => 'preparing', 'preparing' => 'accepted');
    if (!isset($allowed[$current_status]) || $allowed[$current_status] !== $new_status) {
        fail('Cannot transition from ' . $current_status . ' to ' . $new_status, 409);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE order_assignments SET status=? WHERE id=?')->execute(array($new_status, $assign['id']));
        // Deliberately NOT writing $new_status into orders.status here.
        // 'preparing'/'accepted' are service-giver-internal prep states that
        // the customer's own order-tracking screen (pending/assigned/
        // picked_up/in_transit/delivered/cancelled) does not understand --
        // writing them there made the customer's progress bar and status
        // badge look broken/frozen the moment prep started. orders.status
        // correctly stays 'pending' through the whole prep pipeline; it
        // moves to 'assigned' for real when a courier accepts the job.
        $pdo->commit();

        // Notify the customer (and courier, if one already accepted this
        // job) the moment prep is actually done -- this is the "ready for
        // pickup" moment, not just a silent status flip. Best-effort: push
        // failures (no FCM key configured yet, no token on file, etc.)
        // never block or fail this response.
        if ($new_status === 'accepted') {
            try {
                require_once __DIR__ . '/../push.php';
                $ord = $pdo->prepare('SELECT customer_id, courier_id, order_code FROM orders WHERE id=?');
                $ord->execute(array($order_id));
                $orow = $ord->fetch();
                if ($orow) {
                    if (!empty($orow['customer_id'])) {
                        etel_push_send($pdo, $orow['customer_id'], 'Order Ready!',
                            'Your order ' . $orow['order_code'] . ' is ready and waiting for pickup.',
                            array('order_id' => $order_id, 'type' => 'order_ready', 'url' => '/delivery/customer.html'));
                    }
                    if (!empty($orow['courier_id'])) {
                        etel_push_send($pdo, $orow['courier_id'], 'Order Ready for Pickup',
                            'Order ' . $orow['order_code'] . ' is ready -- head to the shop now.',
                            array('order_id' => $order_id, 'type' => 'order_ready', 'url' => '/delivery/courier/'));
                    }
                }
            } catch (Exception $e) { error_log('[ServiceGiverController] order-ready push notification failed (non-fatal): ' . $e->getMessage()); }
        }

        // Kitchen ticket data - only needed the moment prep starts, not
        // on every "Mark Ready" click, so this only runs for that one
        // transition. Fetched here (not a separate endpoint) so the
        // frontend can print immediately on the same click with no
        // second round trip.
        $ticket = null;
        if ($new_status === 'preparing') {
            try {
                $ticket = sg_build_kitchen_ticket($pdo, $order_id);
            } catch (Exception $e) {
                // Ticket data is a bonus, not a requirement - the status
                // update itself already succeeded and committed above.
                // Never fail the whole request just because the ticket
                // couldn't be assembled.
                error_log('[ServiceGiverController] kitchen ticket data fetch failed (non-fatal): ' . $e->getMessage());
            }
        }

        ok(array('message' => 'Order status updated to ' . $new_status, 'order_id' => $order_id, 'status' => $new_status, 'ticket' => $ticket));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Update failed', 500);
    }
    break;
}
case 'sg_order_detail': {
    // GET: (requires auth)  ?order_id=N
    // Get full details of an assigned order
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_order_detail: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.view');
    
    try {
        $s = $pdo->prepare("
            SELECT 
                oa.id as assignment_id,
                oa.status,
                oa.assigned_at,
                oa.accepted_at,
                oa.pickup_qr_scanned,
                oa.delivery_qr_scanned,
                o.id,
                o.order_code,
                o.from_zone,
                o.to_zone,
                o.package_type,
                o.description,
                o.weight,
                o.sender_name,
                o.sender_phone,
                o.recipient_name,
                o.recipient_phone,
                o.recipient_address,
                o.fare_total,
                o.placed_at,
                o.notes
            FROM order_assignments oa
            JOIN orders o ON oa.order_id = o.id
            WHERE oa.order_id=? AND oa.service_giver_id=?
        ");
        $s->execute(array($order_id, $sg['id']));
        $order = $s->fetch();
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_order_detail: order query failed: ' . $e->getMessage()); $order = null; }
    
    if (!$order) fail('Order not found', 404);
    
    ok(array('order' => $order));
    break;
}
case 'sg_scan_qr': {
    // POST: (requires auth)  order_id, qr_type (pickup|delivery)
    // Scan QR code for pickup or delivery
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $qr_type  = isset($body['qr_type'])  ? trim($body['qr_type'])  : '';
    
    if (!$order_id) fail('order_id is required', 400);
    if (!in_array($qr_type, array('pickup', 'delivery'))) fail('qr_type must be pickup or delivery', 400);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_scan_qr: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.manage');
    
    // Get assignment
    try {
        $asgn = $pdo->prepare('SELECT id FROM order_assignments WHERE order_id=? AND service_giver_id=?');
        $asgn->execute(array($order_id, $sg['id']));
        $assign = $asgn->fetch();
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_scan_qr: assignment lookup failed: ' . $e->getMessage()); $assign = null; }
    
    if (!$assign) fail('Assignment not found', 404);
    
    // Update scan records
    try {
        $pdo->beginTransaction();
        
        if ($qr_type === 'pickup') {
            $upd = $pdo->prepare('UPDATE order_assignments SET pickup_qr_scanned=1, pickup_scanned_at=NOW(), status="picked_up" WHERE id=?');
            $upd->execute(array($assign['id']));
            
            // Update order status
            $upd_ord = $pdo->prepare('UPDATE orders SET status="picked_up" WHERE id=?');
            $upd_ord->execute(array($order_id));
        } else { // delivery
            $upd = $pdo->prepare('UPDATE order_assignments SET delivery_qr_scanned=1, delivery_scanned_at=NOW(), status="delivered" WHERE id=?');
            $upd->execute(array($assign['id']));
            
            // Update order status
            $upd_ord = $pdo->prepare('UPDATE orders SET status="delivered", delivered_at=NOW() WHERE id=?');
            $upd_ord->execute(array($order_id));
        }
        
        $pdo->commit();
        
        ok(array('message' => ucfirst($qr_type) . ' confirmed', 'qr_type' => $qr_type, 'scanned_at' => date('Y-m-d H:i:s')));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Scan failed', 500);
    }
    break;
}
case 'sg_complete_order': {
    // POST: (requires auth)  order_id
    // Mark order as completely delivered (alternative to QR scan)
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_complete_order: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.manage');
    
    // Verify assignment
    try {
        $chk = $pdo->prepare('SELECT id FROM order_assignments WHERE order_id=? AND service_giver_id=? AND status!="cancelled"');
        $chk->execute(array($order_id, $sg['id']));
        $assign = $chk->fetch();
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_complete_order: assignment lookup failed: ' . $e->getMessage()); $assign = null; }
    
    if (!$assign) fail('Assignment not found', 404);
    
    // Mark delivered
    try {
        $pdo->beginTransaction();
        
        $upd = $pdo->prepare('UPDATE order_assignments SET status="delivered", delivery_qr_scanned=1, delivery_scanned_at=NOW() WHERE id=?');
        $upd->execute(array($assign['id']));
        
        $upd_ord = $pdo->prepare('UPDATE orders SET status="delivered", delivered_at=NOW() WHERE id=?');
        $upd_ord->execute(array($order_id));
        
        $pdo->commit();
        
        ok(array('message' => 'Order marked as delivered'));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Update failed', 500);
    }
    break;
}
case 'sg_stats': {
    // GET: (requires auth)
    // Returns: performance metrics for service giver dashboard
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_stats: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.view');
    
    $stats = array(
        'total_orders' => 0,
        'pending_orders' => 0,
        'completed_orders' => 0,
        'failed_orders' => 0,
        'total_earnings' => 0,
        'average_rating' => 0,
        'today_deliveries' => 0,
        'this_week_deliveries' => 0
    );
    
    try {
        // Total stats
        $s = $pdo->prepare('SELECT COUNT(*) as c FROM order_assignments WHERE service_giver_id=?');
        $s->execute(array($sg['id']));
        $r = $s->fetch();
        $stats['total_orders'] = intval($r['c']);
        
        // Pending
        $s = $pdo->prepare('SELECT COUNT(*) as c FROM order_assignments WHERE service_giver_id=? AND status IN ("pending","accepted")');
        $s->execute(array($sg['id']));
        $r = $s->fetch();
        $stats['pending_orders'] = intval($r['c']);
        
        // Completed
        $s = $pdo->prepare('SELECT COUNT(*) as c FROM order_assignments WHERE service_giver_id=? AND status="delivered"');
        $s->execute(array($sg['id']));
        $r = $s->fetch();
        $stats['completed_orders'] = intval($r['c']);
        
        // Today
        $s = $pdo->prepare('SELECT COUNT(*) as c FROM order_assignments WHERE service_giver_id=? AND DATE(assigned_at)=CURDATE()');
        $s->execute(array($sg['id']));
        $r = $s->fetch();
        $stats['today_deliveries'] = intval($r['c']);
        
        // This week
        $s = $pdo->prepare('SELECT COUNT(*) as c FROM order_assignments WHERE service_giver_id=? AND WEEK(assigned_at, 1)=WEEK(NOW(), 1)');
        $s->execute(array($sg['id']));
        $r = $s->fetch();
        $stats['this_week_deliveries'] = intval($r['c']);
        
        // Earnings & rating from service_givers table
        $s = $pdo->prepare('SELECT total_earnings, average_rating FROM service_givers WHERE id=?');
        $s->execute(array($sg['id']));
        $r = $s->fetch();
        if ($r) {
            $stats['total_earnings'] = floatval($r['total_earnings']);
            $stats['average_rating'] = floatval($r['average_rating']);
        }
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_stats: metrics query failed (non-fatal): ' . $e->getMessage()); }

    // Cashiers see operational counts (orders, deliveries) but not the
    // business's earnings.
    if ($sg_ctx && !sg_has_capability($sg_ctx, 'payments.view')) unset($stats['total_earnings']);

    ok($stats);
    break;
}
case 'sg_set_online': {
    // POST: (requires auth)  is_online (0|1)
    // Toggle service giver online/offline status
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);

    // Going online/offline is a normal day-to-day action, gated to
    // orders.manage rather than owner-only - but it must resolve through
    // sg_get_context (not user_id directly), or a staff login's toggle
    // silently updates zero rows since their own user_id never appears in
    // service_givers.
    $ctx = sg_get_context($pdo, $auth_user['id']);
    if (!$ctx) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $ctx, 'orders.manage');

    $is_online = isset($body['is_online']) ? intval($body['is_online']) : 0;

    try {
        $upd = $pdo->prepare('UPDATE service_givers SET is_online=?, last_seen=NOW() WHERE id=?');
        $upd->execute(array($is_online, $ctx['sg_id']));

        ok(array('message' => ($is_online ? 'Online' : 'Offline'), 'is_online' => $is_online));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}
case 'sg_notifications': {
    // GET: (requires auth)
    // Get pending notifications (new order assignments, etc)
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_notifications: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'orders.view');
    
    $notifications = array();
    
    try {
        // Pending order assignments
        $s = $pdo->prepare("
            SELECT 
                CONCAT('new_order_', oa.order_id) as id,
                'new_order' as type,
                CONCAT('New order assigned: ', o.order_code) as message,
                oa.assigned_at as created_at
            FROM order_assignments oa
            JOIN orders o ON oa.order_id = o.id
            WHERE oa.service_giver_id=? AND oa.status='pending'
            ORDER BY oa.assigned_at DESC
            LIMIT 10
        ");
        $s->execute(array($sg['id']));
        $notifications = $s->fetchAll();
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_notifications: query failed (non-fatal): ' . $e->getMessage()); }
    
    ok(array('notifications' => $notifications, 'count' => count($notifications)));
    break;
}
case 'sg_add_item': {
    // POST: (requires service giver auth)  name, description, category, price, original_price, stock_quantity, preparation_time
    // Service giver adds a new item/product
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Service giver access required', 403);
    
    // Get service giver ID
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_add_item: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'menu.manage');
    
    $name = isset($body['name']) ? trim($body['name']) : '';
    $name_am = isset($body['name_am']) ? trim($body['name_am']) : '';
    $description = isset($body['description']) ? trim($body['description']) : '';
    $description_am = isset($body['description_am']) ? trim($body['description_am']) : '';
    $category = isset($body['category']) ? trim($body['category']) : '';
    $stock_quantity = isset($body['stock_quantity']) ? intval($body['stock_quantity']) : 0;
    $preparation_time = isset($body['preparation_time']) ? intval($body['preparation_time']) : 30;
    $image_url = isset($body['image_url']) ? trim($body['image_url']) : '';

    if (!$name) fail('Item name is required', 400);
    if (!$category || !in_array($category, array('food','grocery','package','pharmacy','flower'))) {
        fail('Valid category is required (food, grocery, package, pharmacy, flower)', 400);
    }
    $price = etel_validate_amount(isset($body['price']) ? $body['price'] : null);
    if ($price === false) fail('Price must be a valid amount greater than 0', 400);
    $original_price = isset($body['original_price']) && $body['original_price'] !== ''
        ? etel_validate_amount($body['original_price'])
        : $price;
    if ($original_price === false) fail('Original price must be a valid amount greater than 0', 400);

    try {
        // Generate item code
        $item_code = strtoupper(substr($category, 0, 4)) . rand(1000, 9999);
        
        $ins = $pdo->prepare("
            INSERT INTO items (
                service_giver_id, item_code, name, name_am, description, description_am, category,
                price, original_price, stock_quantity, preparation_time, image_url, is_available
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $ins->execute(array(
            $sg['id'], $item_code, $name, ($name_am ?: null), $description, ($description_am ?: null), $category,
            $price, $original_price, $stock_quantity, $preparation_time, $image_url
        ));
        
        $item_id = $pdo->lastInsertId();
        
        ok(array(
            'item_id' => $item_id,
            'item_code' => $item_code,
            'message' => 'Item added successfully'
        ));
    } catch (Exception $e) {
        fail_ex($e, 'Failed to add item', 500);
    }
    break;
}
case 'sg_get_my_items': {
    // GET: (requires service giver auth)  ?category=food
    // Get service giver's own items
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Service giver access required', 403);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_get_my_items: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'menu.view');
    
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $where = 'WHERE service_giver_id = ?';
    $params = array($sg['id']);
    
    if ($category && in_array($category, array('food','grocery','package','pharmacy','flower'))) {
        $where .= ' AND category = ?';
        $params[] = $category;
    }
    
    try {
        $s = $pdo->prepare("
            SELECT 
                id, item_code, name, name_am, description, description_am, category, price, original_price,
                image_url, stock_quantity, preparation_time, is_available, created_at
            FROM items
            $where
            ORDER BY created_at DESC
        ");
        $s->execute($params);
        $items = $s->fetchAll();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }
    
    ok(array('items' => $items, 'count' => count($items)));
    break;
}
case 'sg_update_item': {
    // POST: (requires service giver auth)  item_id, name, price, stock_quantity, is_available
    // Update service giver's item
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Service giver access required', 403);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_update_item: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'menu.manage');
    
    $item_id = isset($body['item_id']) ? intval($body['item_id']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    
    // Verify ownership
    try {
        $check = $pdo->prepare('SELECT id FROM items WHERE id=? AND service_giver_id=?');
        $check->execute(array($item_id, $sg['id']));
        if (!$check->fetch()) fail('Item not found or access denied', 404);
    } catch (Exception $e) {
        fail_ex($e, 'Verification failed', 500);
    }
    
    // Build update query dynamically
    $updates = array();
    $params = array();
    
    if (isset($body['name'])) {
        $updates[] = 'name = ?';
        $params[] = trim($body['name']);
    }
    if (isset($body['name_am'])) {
        $updates[] = 'name_am = ?';
        $params[] = trim($body['name_am']) ?: null;
    }
    if (isset($body['description'])) {
        $updates[] = 'description = ?';
        $params[] = trim($body['description']);
    }
    if (isset($body['description_am'])) {
        $updates[] = 'description_am = ?';
        $params[] = trim($body['description_am']) ?: null;
    }
    if (isset($body['price'])) {
        $vp = etel_validate_amount($body['price']);
        if ($vp === false) fail('Price must be a valid amount greater than 0', 400);
        $updates[] = 'price = ?';
        $params[] = $vp;
    }
    if (isset($body['original_price'])) {
        $vop = etel_validate_amount($body['original_price']);
        if ($vop === false) fail('Original price must be a valid amount greater than 0', 400);
        $updates[] = 'original_price = ?';
        $params[] = $vop;
    }
    if (isset($body['stock_quantity'])) {
        $updates[] = 'stock_quantity = ?';
        $params[] = intval($body['stock_quantity']);
    }
    if (isset($body['preparation_time'])) {
        $updates[] = 'preparation_time = ?';
        $params[] = intval($body['preparation_time']);
    }
    if (isset($body['is_available'])) {
        $updates[] = 'is_available = ?';
        $params[] = intval($body['is_available']);
    }
    if (isset($body['image_url'])) {
        $updates[] = 'image_url = ?';
        $params[] = trim($body['image_url']);
    }
    
    if (empty($updates)) fail('No fields to update', 400);
    
    $params[] = $item_id;
    
    try {
        $upd = $pdo->prepare('UPDATE items SET ' . implode(', ', $updates) . ' WHERE id=?');
        $upd->execute($params);
        
        ok(array('message' => 'Item updated successfully'));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}
case 'sg_delete_item': {
    // POST: (requires service giver auth)  item_id
    // Delete service giver's item
    $pdo = db();
    $auth_user = require_auth($pdo);
    
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Service giver access required', 403);
    
    try {
        $sg_ctx = sg_get_context($pdo, $auth_user['id']);
        $sg = $sg_ctx ? array('id' => $sg_ctx['sg_id']) : null;
    } catch (Exception $e) { error_log('[ServiceGiverController] sg_delete_item: service_giver lookup failed: ' . $e->getMessage()); $sg = null; }
    
    if (!$sg) fail('Service giver profile not found', 404);
    sg_require_capability($auth_user, $sg_ctx, 'menu.manage');
    
    $item_id = isset($body['item_id']) ? intval($body['item_id']) : 0;
    if (!$item_id) fail('item_id is required', 400);

    try {
        // Verify ownership first
        $own = $pdo->prepare('SELECT id FROM items WHERE id=? AND service_giver_id=?');
        $own->execute(array($item_id, $sg['id']));
        if (!$own->fetch()) fail('Item not found or access denied', 404);

        // Is this item referenced by any past order? If so we cannot hard-delete
        // (foreign key constraint 1451). Soft-delete instead so order history stays intact.
        $referenced = false;
        try {
            $rc = $pdo->prepare('SELECT 1 FROM order_items WHERE item_id=? LIMIT 1');
            $rc->execute(array($item_id));
            $referenced = (bool) $rc->fetch();
        } catch (Exception $e) { error_log('[ServiceGiverController] sg_delete_item: order-reference check failed, assuming referenced for safety: ' . $e->getMessage()); $referenced = true; }

        if ($referenced) {
            // Soft delete  hide from catalogue but keep the row for order history
            $pdo->prepare('UPDATE items SET active=0 WHERE id=? AND service_giver_id=?')
                ->execute(array($item_id, $sg['id']));
            ok(array('message' => 'Item removed from your catalogue', 'soft' => true));
        } else {
            // Safe to hard delete  no orders reference it
            $pdo->prepare('DELETE FROM items WHERE id=? AND service_giver_id=?')
                ->execute(array($item_id, $sg['id']));
            ok(array('message' => 'Item deleted successfully', 'soft' => false));
        }
    } catch (Exception $e) {
        // Last-resort fallback: if a hard delete still trips the FK, soft-delete
        try {
            $pdo->prepare('UPDATE items SET active=0 WHERE id=? AND service_giver_id=?')
                ->execute(array($item_id, $sg['id']));
            ok(array('message' => 'Item removed from your catalogue', 'soft' => true));
        } catch (Exception $e2) {
            fail_ex($e2, 'Delete failed', 500);
        }
    }
    break;
}

case 'sg_get_staff': {
    // GET: (requires auth, owner or platform admin) list a business's
    // staff logins. An owner always sees their own business
    // (sg_get_context resolves it from their own account); a platform
    // admin has no service_givers/sg_staff row of their own, so they
    // must pass sg_id explicitly to look at a SPECIFIC business - this
    // is what makes admin's Service Givers panel able to show staff at
    // all, which it silently couldn't before (ctx always resolved null
    // for an admin's own account, failing 404 regardless of the role
    // check above technically allowing admin through).
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $target_sg_id = isset($body['sg_id']) ? intval($body['sg_id']) : (isset($_GET['sg_id']) ? intval($_GET['sg_id']) : 0);
    if ($auth_user['role'] === 'admin' && $target_sg_id) {
        $ctx = array('sg_id' => $target_sg_id, 'role' => 'owner', 'role_name' => 'Owner', 'capabilities' => array('*'));
    } else {
        $ctx = sg_get_context($pdo, $auth_user['id']);
        sg_require_capability($auth_user, $ctx, 'staff.manage');
    }
    if (!$ctx) fail('Service giver profile not found', 404);

    try {
        $s = $pdo->prepare('SELECT st.id, st.active, st.created_at, st.role_id, u.name, u.phone,
                                    COALESCE(r.role_name, "Cashier") AS role_name
                             FROM sg_staff st JOIN users u ON st.user_id = u.id
                             LEFT JOIN sg_roles r ON r.id = st.role_id
                             WHERE st.service_giver_id = ? ORDER BY st.created_at DESC');
        $s->execute(array($ctx['sg_id']));
        $staff = $s->fetchAll();
    } catch (Exception $e) {
        // sg_staff table not migrated on this deployment yet - no staff, not an error
        $staff = array();
    }
    ok(array('staff' => $staff, 'count' => count($staff)));
    break;
}

case 'sg_add_staff': {
    // POST: (requires auth, owner-only)  name, phone, password
    // Creates a cashier login for this business - same 'service_giver'
    // role at the auth level, but scoped via sg_staff so every action in
    // this file treats them as staff, not the business owner.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $ctx = sg_get_context($pdo, $auth_user['id']);
    sg_require_capability($auth_user, $ctx, 'staff.manage');
    if (!$ctx) fail('Service giver profile not found', 404);

    $name  = isset($body['name'])  ? trim($body['name'])  : '';
    $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    $pw    = isset($body['password']) ? $body['password'] : '';
    $role_id = isset($body['role_id']) ? intval($body['role_id']) : 0;
    if (!$name) fail('Name is required', 400);
    if (!$phone) fail('Phone is required', 400);
    if ($role_id) {
        $rchk = $pdo->prepare('SELECT id FROM sg_roles WHERE id=? AND service_giver_id=?');
        $rchk->execute(array($role_id, $ctx['sg_id']));
        if (!$rchk->fetch()) fail('That role does not belong to your business', 400);
    }

    try {
        // Everyone on the platform has one EtelID-backed account already -
        // if this phone number is already registered (as a customer,
        // driver, another business's staff, whatever), link that EXISTING
        // account as staff here instead of trying to create a second,
        // separate login for the same person. Their own phone + password
        // keeps working exactly as it always has; a password typed into
        // this form is silently ignored in that case, since it's not this
        // owner's account to set a password for.
        $chk = $pdo->prepare('SELECT id, name FROM users WHERE phone=?');
        $chk->execute(array($phone));
        $existing = $chk->fetch();

        if ($existing) {
            $user_id = intval($existing['id']);
            $already = $pdo->prepare('SELECT service_giver_id FROM sg_staff WHERE user_id=?');
            $already->execute(array($user_id));
            $row = $already->fetch();
            if ($row) {
                if (intval($row['service_giver_id']) === intval($ctx['sg_id'])) {
                    fail('This EtelID is already linked as staff at your business', 409);
                }
                fail('This EtelID already works at a different business - they need to be removed there first', 409);
            }
            $is = $pdo->prepare("INSERT INTO sg_staff (service_giver_id, user_id, staff_role, role_id, active, created_at) VALUES (?, ?, 'cashier', ?, 1, NOW())");
            $is->execute(array($ctx['sg_id'], $user_id, $role_id ?: null));
            ok(array('message' => 'Existing EtelID account linked as staff', 'user_id' => $user_id,
                     'name' => $existing['name'], 'phone' => $phone, 'linked_existing' => true));
        } else {
            if (!$pw || strlen($pw) < 8) fail('Password must be at least 8 characters (this phone has no EtelID yet, so a new login needs one)', 400);
            $pdo->beginTransaction();
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $iu = $pdo->prepare('INSERT INTO users (name,phone,password_hash,role,active,created_at) VALUES (?,?,?,"service_giver",1,NOW())');
            $iu->execute(array($name, $phone, $hash));
            $user_id = intval($pdo->lastInsertId());
            if (!$user_id) { $pdo->rollBack(); fail('Failed to create login', 500); }

            $is = $pdo->prepare("INSERT INTO sg_staff (service_giver_id, user_id, staff_role, role_id, active, created_at) VALUES (?, ?, 'cashier', ?, 1, NOW())");
            $is->execute(array($ctx['sg_id'], $user_id, $role_id ?: null));
            $pdo->commit();
            ok(array('message' => 'New EtelID account created and added as staff', 'user_id' => $user_id,
                     'name' => $name, 'phone' => $phone, 'linked_existing' => false));
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not add staff account', 500);
    }
    break;
}

case 'sg_remove_staff': {
    // POST: (requires auth, owner or platform admin) staff_id, active (0|1)
    // Deactivates (or reactivates) a staff login. Soft toggle, not a
    // delete - keeps the account's order history/audit trail intact and
    // is reversible if disabled by mistake. A platform admin can act on
    // ANY business's staff row (for moderation - e.g. a support case),
    // not just their own, since they have no sg_staff row of their own
    // to resolve a scoping context from in the first place.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $isPlatformAdmin = $auth_user['role'] === 'admin';
    $ctx = $isPlatformAdmin ? null : sg_get_context($pdo, $auth_user['id']);
    if (!$isPlatformAdmin) {
        sg_require_capability($auth_user, $ctx, 'staff.manage');
        if (!$ctx) fail('Service giver profile not found', 404);
    }

    $staff_id = isset($body['staff_id']) ? intval($body['staff_id']) : 0;
    $active   = isset($body['active']) ? intval($body['active']) : 0;
    if (!$staff_id) fail('staff_id is required', 400);

    try {
        // Ownership check for a business owner: only their own business's
        // staff row can be touched. A platform admin skips the
        // service_giver_id match entirely - moderation reaches any row.
        if ($isPlatformAdmin) {
            $upd = $pdo->prepare('UPDATE sg_staff SET active=? WHERE id=?');
            $upd->execute(array($active, $staff_id));
        } else {
            $upd = $pdo->prepare('UPDATE sg_staff SET active=? WHERE id=? AND service_giver_id=?');
            $upd->execute(array($active, $staff_id, $ctx['sg_id']));
        }
        if ($upd->rowCount() === 0) fail('Staff member not found', 404);
        ok(array('message' => $active ? 'Staff reactivated' : 'Staff deactivated', 'staff_id' => $staff_id, 'active' => $active));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}

case 'sg_update_staff_role': {
    // POST: (owner or admin) - staff_id, role_id (0/omit clears back to
    // the legacy default capability set)
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $ctx = sg_get_context($pdo, $auth_user['id']);
    sg_require_capability($auth_user, $ctx, 'staff.manage');
    if (!$ctx) fail('Service giver profile not found', 404);

    $staff_id = isset($body['staff_id']) ? intval($body['staff_id']) : 0;
    $role_id  = isset($body['role_id']) ? intval($body['role_id']) : 0;
    if (!$staff_id) fail('staff_id is required', 400);
    if ($role_id) {
        $rchk = $pdo->prepare('SELECT id FROM sg_roles WHERE id=? AND service_giver_id=?');
        $rchk->execute(array($role_id, $ctx['sg_id']));
        if (!$rchk->fetch()) fail('That role does not belong to your business', 400);
    }

    try {
        $upd = $pdo->prepare('UPDATE sg_staff SET role_id=? WHERE id=? AND service_giver_id=?');
        $upd->execute(array($role_id ?: null, $staff_id, $ctx['sg_id']));
        if ($upd->rowCount() === 0) {
            $chk = $pdo->prepare('SELECT id FROM sg_staff WHERE id=? AND service_giver_id=?');
            $chk->execute(array($staff_id, $ctx['sg_id']));
            if (!$chk->fetch()) fail('Staff member not found', 404);
        }
        ok(array('message' => 'Role updated', 'staff_id' => $staff_id, 'role_id' => $role_id ?: null));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}

case 'sg_list_roles': {
    // GET: (owner or admin) - every custom role this business has defined,
    // plus the fixed capability catalog so the UI can render checkboxes
    // for capabilities that don't have a role yet.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $ctx = sg_get_context($pdo, $auth_user['id']);
    sg_require_capability($auth_user, $ctx, 'staff.manage');
    if (!$ctx) fail('Service giver profile not found', 404);

    $roles = array();
    try {
        $s = $pdo->prepare('SELECT r.id, r.role_name, r.capabilities, r.created_at,
                                    (SELECT COUNT(*) FROM sg_staff st WHERE st.role_id = r.id AND st.active = 1) AS staff_count
                             FROM sg_roles r WHERE r.service_giver_id = ? ORDER BY r.role_name ASC');
        $s->execute(array($ctx['sg_id']));
        foreach ($s->fetchAll() as $row) {
            $row['capabilities'] = json_decode($row['capabilities'], true) ?: array();
            $roles[] = $row;
        }
    } catch (Exception $e) {
        fail_ex($e, 'Could not load roles - has migration 049_sg_custom_roles.sql been applied? (api/migrate.php --apply)', 500);
    }
    ok(array('roles' => $roles, 'capability_catalog' => sg_capability_catalog()));
    break;
}

case 'sg_save_role': {
    // POST: (owner or admin) - role_id (omit to create new), role_name, capabilities[]
    // A role's capabilities can only ever be a subset of the fixed
    // catalog above - not a free-text list - so a typo can't silently
    // grant a capability that doesn't correspond to any real check.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $ctx = sg_get_context($pdo, $auth_user['id']);
    sg_require_capability($auth_user, $ctx, 'staff.manage');
    if (!$ctx) fail('Service giver profile not found', 404);

    $role_id   = isset($body['role_id']) ? intval($body['role_id']) : 0;
    $role_name = isset($body['role_name']) ? trim($body['role_name']) : '';
    $rawCaps   = isset($body['capabilities']) && is_array($body['capabilities']) ? $body['capabilities'] : array();
    if (!$role_name) fail('Role name is required', 400);
    if (mb_strlen($role_name) > 40) fail('Role name is too long (max 40 characters)', 400);
    $catalog = sg_capability_catalog();
    $caps = array_values(array_intersect($rawCaps, array_keys($catalog)));
    if (!$caps) fail('Select at least one capability for this role', 400);
    $capsJson = json_encode($caps);

    try {
        if ($role_id) {
            $upd = $pdo->prepare('UPDATE sg_roles SET role_name=?, capabilities=? WHERE id=? AND service_giver_id=?');
            $upd->execute(array($role_name, $capsJson, $role_id, $ctx['sg_id']));
            if ($upd->rowCount() === 0) {
                // Either genuinely missing, or the name/caps were already
                // identical (rowCount 0 either way) - confirm which.
                $chk = $pdo->prepare('SELECT id FROM sg_roles WHERE id=? AND service_giver_id=?');
                $chk->execute(array($role_id, $ctx['sg_id']));
                if (!$chk->fetch()) fail('Role not found', 404);
            }
            ok(array('message' => 'Role updated', 'role_id' => $role_id));
        } else {
            $ins = $pdo->prepare('INSERT INTO sg_roles (service_giver_id, role_name, capabilities, created_at) VALUES (?, ?, ?, NOW())');
            $ins->execute(array($ctx['sg_id'], $role_name, $capsJson));
            ok(array('message' => 'Role created', 'role_id' => intval($pdo->lastInsertId())));
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') fail('A role with this name already exists at your business', 409);
        fail_ex($e, 'Could not save role', 500);
    }
    break;
}

case 'sg_delete_role': {
    // POST: (owner or admin) - role_id
    // Refuses to delete a role that's still assigned to active staff -
    // they'd silently fall back to the legacy default capability set
    // (orders.view+orders.manage) with no visible warning, which could
    // quietly grant or revoke access the owner didn't intend. Reassign
    // or deactivate those staff first.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $ctx = sg_get_context($pdo, $auth_user['id']);
    sg_require_capability($auth_user, $ctx, 'staff.manage');
    if (!$ctx) fail('Service giver profile not found', 404);

    $role_id = isset($body['role_id']) ? intval($body['role_id']) : 0;
    if (!$role_id) fail('role_id is required', 400);

    try {
        $chk = $pdo->prepare('SELECT COUNT(*) AS n FROM sg_staff WHERE role_id=? AND active=1');
        $chk->execute(array($role_id));
        if (intval($chk->fetch()['n']) > 0) fail('This role is still assigned to active staff - reassign or remove them first', 409);

        $del = $pdo->prepare('DELETE FROM sg_roles WHERE id=? AND service_giver_id=?');
        $del->execute(array($role_id, $ctx['sg_id']));
        if ($del->rowCount() === 0) fail('Role not found', 404);
        ok(array('message' => 'Role deleted'));
    } catch (Exception $e) {
        fail_ex($e, 'Delete failed', 500);
    }
    break;
}

case 'sg_my_role': {
    // GET: (any authenticated service giver account, no special
    // capability required - this is "what am I allowed to do", every
    // staff member needs to be able to see their own answer to that,
    // not just the owner)
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $ctx = sg_get_context($pdo, $auth_user['id']);
    if (!$ctx) fail('Service giver profile not found', 404);

    $catalog = sg_capability_catalog();
    $granted = array();
    $isUnrestricted = in_array('*', $ctx['capabilities'], true);
    foreach ($catalog as $key => $label) {
        if ($isUnrestricted || in_array($key, $ctx['capabilities'], true)) {
            $granted[] = array('key' => $key, 'label' => $label);
        }
    }

    $business_name = '';
    try {
        $b = $pdo->prepare('SELECT business_name FROM service_givers WHERE id=?');
        $b->execute(array($ctx['sg_id']));
        $brow = $b->fetch();
        $business_name = $brow ? $brow['business_name'] : '';
    } catch (Exception $e) { /* cosmetic only, fine to omit on failure */ }

    ok(array(
        'name'          => $auth_user['name'],
        'phone'         => $auth_user['phone'],
        'business_name' => $business_name,
        'role'          => $ctx['role'],
        'role_name'     => $ctx['role_name'],
        'is_unrestricted' => $isUnrestricted,
        'capabilities'  => $granted,
        'capability_count' => count($granted),
        'catalog_count' => count($catalog),
    ));
    break;
}

case 'sg_financial_report': {
    // GET: (owner or payments.view capability)  ?days=7|30|90|all
    // Computed LIVE from order_assignments+orders, not from
    // service_givers.total_earnings - that column is never actually
    // incremented anywhere in this codebase (checked every controller
    // that could plausibly update it), so it's permanently 0 for every
    // business. Same live-SUM approach the platform-wide "Top Service
    // Givers" report in AdminController.php already uses successfully.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $target_sg_id = isset($body['sg_id']) ? intval($body['sg_id']) : (isset($_GET['sg_id']) ? intval($_GET['sg_id']) : 0);
    if ($auth_user['role'] === 'admin' && $target_sg_id) {
        $ctx = array('sg_id' => $target_sg_id, 'role' => 'owner', 'role_name' => 'Owner', 'capabilities' => array('*'));
    } else {
        $ctx = sg_get_context($pdo, $auth_user['id']);
        sg_require_capability($auth_user, $ctx, 'payments.view');
    }
    if (!$ctx) fail('Service giver profile not found', 404);

    $days = isset($_GET['days']) ? trim($_GET['days']) : (isset($body['days']) ? trim($body['days']) : '30');
    $dateFilter = 'o.placed_at >= DATE_SUB(NOW(), INTERVAL ' . (ctype_digit($days) ? intval($days) : 30) . ' DAY)';
    if ($days === 'all') $dateFilter = '1=1';

    try {
        $s = $pdo->prepare("SELECT o.payment_method,
                                    SUM(CASE WHEN o.payment_status='paid' THEN 1 ELSE 0 END) AS paid_count,
                                    SUM(CASE WHEN o.payment_status='paid' THEN o.fare_total ELSE 0 END) AS paid_total,
                                    SUM(CASE WHEN o.payment_status!='paid' THEN 1 ELSE 0 END) AS unpaid_count,
                                    SUM(CASE WHEN o.payment_status!='paid' THEN o.fare_total ELSE 0 END) AS unpaid_total
                             FROM order_assignments oa
                             JOIN orders o ON o.id = oa.order_id
                             WHERE oa.service_giver_id = ? AND $dateFilter
                             GROUP BY o.payment_method");
        $s->execute(array($ctx['sg_id']));
        $rows = $s->fetchAll();

        $byMethod = array();
        $paidTotal = 0; $unpaidTotal = 0;
        foreach ($rows as $r) {
            $byMethod[$r['payment_method']] = array(
                'paid_count' => intval($r['paid_count']), 'paid_total' => floatval($r['paid_total']),
                'unpaid_count' => intval($r['unpaid_count']), 'unpaid_total' => floatval($r['unpaid_total']),
            );
            $paidTotal += floatval($r['paid_total']);
            $unpaidTotal += floatval($r['unpaid_total']);
        }
        ok(array('range_days' => $days, 'by_method' => $byMethod,
                 'paid_total' => round($paidTotal, 2), 'unpaid_total' => round($unpaidTotal, 2)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load financial report', 500);
    }
    break;
}

case 'sg_get_pending_payout_request': {
    // GET: (owner or settings.manage capability) - is there a payout
    // change awaiting the founder's review right now, and what does it
    // say? Lets the Settings page show "Pending approval" honestly
    // instead of silently reverting the form to the old value with no
    // explanation.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'service_giver' && $auth_user['role'] !== 'admin') fail('Not a service giver account', 403);
    $target_sg_id = isset($body['sg_id']) ? intval($body['sg_id']) : (isset($_GET['sg_id']) ? intval($_GET['sg_id']) : 0);
    if ($auth_user['role'] === 'admin' && $target_sg_id) {
        $sgId = $target_sg_id;
    } else {
        $ctx = sg_get_context($pdo, $auth_user['id']);
        if (!$ctx) fail('Service giver profile not found', 404);
        $sgId = $ctx['sg_id'];
    }
    try {
        $s = $pdo->prepare("SELECT id, new_bank_account, new_payout_bank, new_telebirr_number, new_cbe_account, new_abysinia_account,
                                    new_cbe_account_name, new_abysinia_account_name, new_telebirr_account_name, requested_at
                             FROM sg_payout_change_requests WHERE service_giver_id=? AND status='pending'
                             ORDER BY requested_at DESC LIMIT 1");
        $s->execute(array($sgId));
        $row = $s->fetch();
        ok(array('pending' => $row ?: null));
    } catch (Exception $e) {
        fail_ex($e, 'Could not check pending payout request', 500);
    }
    break;
}

case 'admin_list_payout_requests': {
    // GET: (admin only) every payout change awaiting review, across
    // every business - the actual approval queue.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'admin') fail('Admin only', 403);
    try {
        $s = $pdo->query("SELECT r.id, r.service_giver_id, r.old_bank_account, r.old_payout_bank, r.old_telebirr_number, r.old_cbe_account, r.old_abysinia_account,
                                  r.old_cbe_account_name, r.old_abysinia_account_name, r.old_telebirr_account_name,
                                  r.new_bank_account, r.new_payout_bank, r.new_telebirr_number, r.new_cbe_account, r.new_abysinia_account,
                                  r.new_cbe_account_name, r.new_abysinia_account_name, r.new_telebirr_account_name, r.requested_at,
                                  sg.business_name, u.name AS requested_by_name, u.phone AS requested_by_phone
                           FROM sg_payout_change_requests r
                           JOIN service_givers sg ON sg.id = r.service_giver_id
                           JOIN users u ON u.id = r.requested_by
                           WHERE r.status = 'pending'
                           ORDER BY r.requested_at ASC");
        ok(array('requests' => $s->fetchAll()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load payout requests', 500);
    }
    break;
}

case 'admin_review_payout_request': {
    // POST: (admin only)  request_id, decision ('approve'|'reject'), note (optional)
    // Named "decision", not "action" - see the exact same collision
    // documented on admin_confirm_telebirr_payment above; core.js's
    // api() does JSON.stringify({ action, ...body }), and a body
    // parameter also called "action" silently overwrites the real
    // routing action.
    $pdo = db();
    $auth_user = require_auth($pdo);
    if ($auth_user['role'] !== 'admin') fail('Admin only', 403);
    $request_id = isset($body['request_id']) ? intval($body['request_id']) : 0;
    $decision = isset($body['decision']) ? trim($body['decision']) : '';
    $note = isset($body['note']) ? trim($body['note']) : null;
    if (!$request_id) fail('request_id is required', 400);
    if (!in_array($decision, array('approve','reject'), true)) fail("decision must be 'approve' or 'reject'", 400);

    try {
        $s = $pdo->prepare('SELECT * FROM sg_payout_change_requests WHERE id=?');
        $s->execute(array($request_id));
        $row = $s->fetch();
        if (!$row) fail('Request not found', 404);
        if ($row['status'] !== 'pending') fail('This request was already ' . $row['status'], 409);

        $pdo->beginTransaction();
        if ($decision === 'approve') {
            $pdo->prepare('UPDATE service_givers SET bank_account=?, payout_bank=?, telebirr_number=?, cbe_account=?, abysinia_account=?,
                                  cbe_account_name=?, abysinia_account_name=?, telebirr_account_name=? WHERE id=?')
                ->execute(array($row['new_bank_account'], $row['new_payout_bank'], $row['new_telebirr_number'], $row['new_cbe_account'], $row['new_abysinia_account'],
                                 $row['new_cbe_account_name'], $row['new_abysinia_account_name'], $row['new_telebirr_account_name'], $row['service_giver_id']));
        }
        $pdo->prepare("UPDATE sg_payout_change_requests SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute(array($decision === 'approve' ? 'approved' : 'rejected', $note, $auth_user['id'], $request_id));
        $pdo->commit();
        ok(array('message' => $decision === 'approve' ? 'Payout change approved and applied' : 'Payout change rejected'));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not review request', 500);
    }
    break;
}
}
