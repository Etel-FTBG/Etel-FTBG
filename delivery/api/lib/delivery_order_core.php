<?php
// Shared order-creation logic - used by BOTH the real customer_create_order
// HTTP action (OrderController.php) and agent_place_order (AgentController.php,
// called directly in-process, no HTTP round-trip). This file contains ONLY this
// function, deliberately - it must be safe to require_once from either controller
// without executing any switch/dispatch logic as a side effect.

// Extracted so agent_place_order (AgentController.php) can call this
// DIRECTLY in-process instead of making a fragile self-HTTP-call back
// into this same server (that self-call was found to be an unreliable
// point of failure - see AgentController.php's comments). Throws an
// Exception on any validation/creation failure instead of echo+exit,
// so both the real HTTP action below and the AI's tool can each wrap
// it in their own try/catch and respond however is right for them.
function delivery_execute_order_creation($pdo, $auth_user, $body) {
    // POST: (requires customer auth)  items (array of {item_id, quantity}), to_zone, recipient_name, recipient_phone, notes
    // Create a new order with items and assign to the service giver who owns those items
    if ($auth_user['role'] !== 'customer' && $auth_user['role'] !== 'admin') throw new Exception(etel_customer_only_message($auth_user));
    
    require_once __DIR__ . '/../config-etb.php';
    global $HAWASSA_DELIVERY_FEES, $DELIVERY_TIERS;

    // Get order details
    $items           = isset($body['items'])           ? $body['items']                    : array();
    $to_zone         = isset($body['to_zone'])         ? trim($body['to_zone'])            : '';
    $recipient_name  = isset($body['recipient_name'])  ? trim($body['recipient_name'])     : '';
    $recipient_phone = isset($body['recipient_phone']) ? trim($body['recipient_phone'])    : '';
    $notes           = isset($body['notes'])           ? trim($body['notes'])              : '';
    $payment_method  = isset($body['payment_method'])  ? trim($body['payment_method'])     : 'cash';
    $delivery_tier   = isset($body['delivery_tier'])   ? trim($body['delivery_tier'])      : 'moto';
    $delivery_lat    = isset($body['delivery_lat'])    && is_numeric($body['delivery_lat'])  ? floatval($body['delivery_lat'])  : null;
    $delivery_lng    = isset($body['delivery_lng'])    && is_numeric($body['delivery_lng'])  ? floatval($body['delivery_lng'])  : null;
    // Optional photo of the gate/entrance/landmark, uploaded separately via
    // api/upload.php - only accept a URL that actually points at our own
    // uploads path, never an arbitrary string a client could pass, since
    // this gets rendered as an <img src> for the courier later.
    $delivery_photo_url = isset($body['delivery_photo_url']) ? trim($body['delivery_photo_url']) : '';
    if ($delivery_photo_url && !preg_match('#^https?://[a-zA-Z0-9.\-]+/.*uploads/items/[a-zA-Z0-9_\.\-]+\.(jpg|jpeg|png|gif|webp)$#i', $delivery_photo_url)) {
        $delivery_photo_url = '';
    }
    // 'chapa' is only accepted from the customer checkout wrapper
    // (customer_create_order sets _allow_chapa), which immediately follows
    // up with chapa_init - never from callers like the EtelAI agent that
    // have no way to complete the Chapa payment.
    $allowed_methods = array('cash','telebirr','abysinia','cbe','wallet');
    if (!empty($body['_allow_chapa'])) $allowed_methods[] = 'chapa';
    if (!in_array($payment_method, $allowed_methods)) $payment_method = 'cash';
    if ($payment_method === 'chapa') {
        // orders.payment_method is an ENUM on most installs: add 'chapa' once
        // (DDL, so it must happen before any transaction below starts).
        try {
            $col = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'payment_method'")->fetch();
            if ($col && stripos($col['Type'], 'enum(') === 0 && stripos($col['Type'], "'chapa'") === false) {
                $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('cash','telebirr','abysinia','cbe','wallet','chapa') DEFAULT 'cash'");
            }
        } catch (Exception $e) {
            error_log('[ETEL][chapa] could not extend orders.payment_method: ' . $e->getMessage());
            throw new Exception('Chapa payment is not ready on this server yet. Please choose another payment method.');
        }
    }
    
    // Validate required fields
    if (empty($items) || !is_array($items)) throw new Exception('items array is required');
    // A client sending a single item as a bare object (e.g. {"item_id":12,
    // "quantity":1} instead of [{"item_id":12,"quantity":1}]) decodes to a
    // non-empty associative array, which passes the check above but has no
    // numeric key 0 -- $items[0]['item_id'] below would be undefined.
    // Detect that shape (an 'item_id' key present at the top level) and
    // wrap it into a proper single-element list instead of erroring on
    // what's actually valid, just differently-shaped, input.
    if (isset($items['item_id'])) $items = array($items);

    // Combine duplicate item_ids into one line with a summed quantity.
    // Not a pricing fix - the total was already mathematically correct
    // either way (splitting one item across two cart entries vs. one
    // combined entry contributes the same total) - this is purely so the
    // resulting order shows one "item x5" line instead of two identical
    // "item x2" / "item x3" lines on the receipt.
    $aggregated = array();
    foreach ($items as $cart_item) {
        if (!is_array($cart_item) || !isset($cart_item['item_id'])) continue;
        $iid = intval($cart_item['item_id']);
        $qty = isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 1;
        if (isset($aggregated[$iid])) {
            $aggregated[$iid]['quantity'] += $qty;
        } else {
            $aggregated[$iid] = array('item_id' => $iid, 'quantity' => $qty);
        }
    }
    $items = array_values($aggregated);
    if (empty($items)) throw new Exception('No valid items selected - each item needs an item_id');
    if (!$to_zone && !(isset($body['customer_lat']) && isset($body['customer_lng']) && $body['customer_lat'] && $body['customer_lng'])) {
        throw new Exception('to_zone is required (or send customer_lat/customer_lng for GPS-based pricing)');
    }
    if (!$recipient_name) throw new Exception('recipient_name is required');
    if (!$recipient_phone) throw new Exception('recipient_phone is required');
    $validated_recipient_phone = etel_validate_phone($recipient_phone);
    if (!$validated_recipient_phone) throw new Exception('Enter a valid Ethiopian mobile number for the recipient');
    $recipient_phone = $validated_recipient_phone;
    
    try {
        // Get first item to determine service giver and from_zone
        $first_item_id = intval($items[0]['item_id']);
        $item_query = $pdo->prepare("
            SELECT i.*, sg.zone as from_zone, sg.id as service_giver_id
            FROM items i
            JOIN service_givers sg ON i.service_giver_id = sg.id
            WHERE i.id = ?
        ");
        $item_query->execute(array($first_item_id));
        $first_item = $item_query->fetch();
        
        if (!$first_item) throw new Exception('Item not found');
        
        $from_zone = $first_item['from_zone'];
        $service_giver_id = $first_item['service_giver_id'];
        $package_type = $first_item['category'];
        
        // Refuse hidden / sold-out items and shops outside their opening hours (api/lib/shop_hours.php)
        require_once __DIR__ . '/shop_hours.php';
        $blocked = etel_order_blockers($pdo, array_map(function ($ci) { return isset($ci['item_id']) ? $ci['item_id'] : 0; }, $items));
        if ($blocked !== '') throw new Exception($blocked);

        // Calculate total
        $fare_total = 0;
        $item_details = array();

        // Batch-fetch every item in one query instead of one query per
        // cart line (N+1 pattern) - collect the IDs first, look them all
        // up at once, then just iterate the in-memory results below.
        $cart_item_ids = array();
        foreach ($items as $cart_item) {
            $cart_item_ids[] = intval($cart_item['item_id']);
        }
        $cart_item_ids = array_values(array_unique(array_filter($cart_item_ids)));
        $items_by_id = array();
        if ($cart_item_ids) {
            $placeholders = implode(',', array_fill(0, count($cart_item_ids), '?'));
            $batch_q = $pdo->prepare("SELECT id, name, price, service_giver_id FROM items WHERE id IN ($placeholders)");
            $batch_q->execute($cart_item_ids);
            foreach ($batch_q->fetchAll() as $row) { $items_by_id[$row['id']] = $row; }
        }

        foreach ($items as $cart_item) {
            $item_id  = intval($cart_item['item_id']);
            $quantity = isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 1;
            // Without this, a cart mixing a large positive quantity on one
            // item with a negative quantity on another could keep the
            // overall total positive (passing the total>0 check below)
            // while still shipping more items than were actually paid for.
            if ($quantity < 1) throw new Exception('Quantity must be at least 1 for every item');

            $item = isset($items_by_id[$item_id]) ? $items_by_id[$item_id] : null;

            if (!$item) throw new Exception('Item with ID ' . $item_id . ' not found');

            // Verify all items belong to same service giver
            if ($item['service_giver_id'] != $service_giver_id) {
                throw new Exception('All items must be from the same service giver');
            }

            $subtotal = $item['price'] * $quantity;
            $fare_total += $subtotal;

            $item_details[] = array(
                'item_id' => $item_id,
                'name' => $item['name'],
                'quantity' => $quantity,
                'unit_price' => $item['price'],
                'subtotal' => $subtotal
            );
        }
        
        $items_subtotal = $fare_total;
        if ($items_subtotal <= 0) throw new Exception('Order total must be greater than 0');

        // Delivery fee (zone + tier) - shared with AgentController.php's
        // order-quote tool via api/lib/delivery_pricing.php so a quote
        // shown in chat can never drift from what checkout actually
        // charges. See that file for the zone/tier DB-first, hardcoded-
        // fallback lookup this wraps.
        require_once __DIR__ . '/../lib/delivery_pricing.php';

        // GPS-based distance pricing (opt-in): if the caller sends real
        // coordinates, use those directly instead of requiring to_zone
        // to match a real named zone. Named zones remain fully
        // supported and unaffected for any caller that doesn't send
        // coordinates - this is purely additive.
        $customerLat = isset($body['customer_lat']) ? floatval($body['customer_lat']) : 0;
        $customerLng = isset($body['customer_lng']) ? floatval($body['customer_lng']) : 0;
        if ($customerLat && $customerLng) {
            $distanceKm = delivery_distance_from_center($customerLat, $customerLng);
            $priced = delivery_calc_fee_by_distance($pdo, $distanceKm);
            $priced['tier_used'] = $delivery_tier;
            if (strpos($to_zone, 'GPS location') === false) {
                $to_zone = 'GPS location (' . $priced['distance_km'] . ' km from Hawassa center)';
            }
        } else {
            $priced = delivery_calc_fee($pdo, $to_zone, $delivery_tier);
            if ($priced === null) {
                // Unknown zone and no hardcoded fallback either - same
                // behavior as before this extraction (was: default to 50).
                $priced = array('delivery_fee' => 50, 'tier_used' => $delivery_tier);
            }
        }
        $delivery_tier  = $priced['tier_used'];
        $delivery_fee   = $priced['delivery_fee'];
        $service_charge = delivery_calc_service_charge($items_subtotal);
        $fare_total     = $items_subtotal + $delivery_fee + $service_charge;

        // Optional promo code - re-validated here on the server from the real
        // basket (never trusts a discount amount sent by the client).
        $promo_row = null; $discount = 0.0; $claimed_promo_id = 0;
        $promo_code_in = isset($body['promo_code']) ? trim((string)$body['promo_code']) : '';
        if ($promo_code_in !== '') {
            require_once __DIR__ . '/promo.php';
            $chk = etel_promo_check($pdo, $promo_code_in, $auth_user['id'], $items_subtotal, $delivery_fee);
            if (!$chk['ok']) throw new EtelPromoException($chk['error']);
            if (!etel_promo_claim($pdo, $chk['promo']['id'])) throw new EtelPromoException('This promo code has been fully redeemed');
            $claimed_promo_id = intval($chk['promo']['id']);
            $promo_row = $chk['promo']; $discount = $chk['discount'];
            $fare_total = round($fare_total - $discount, 2);
        }

        // Optional: spend loyalty points. The client only says "use my points" - the server
        // decides the amount from the real balance and basket (api/lib/loyalty.php).
        $points_used = 0; $points_disc = 0.0; $redeem_id = 0;
        if (!empty($body['use_points'])) {
            require_once __DIR__ . '/promo.php';   // EtelPromoException
            require_once __DIR__ . '/loyalty.php';
            etel_ensure_loyalty_tables($pdo);
            $promo_items_disc = ($promo_row && $promo_row['discount_type'] !== 'free_delivery') ? $discount : 0.0;
            $plan = etel_loyalty_redeem_plan(etel_loyalty_balance($pdo, $auth_user['id']), $items_subtotal - $promo_items_disc);
            if ($plan > 0) {
                $pdo->prepare("INSERT INTO loyalty_points (user_id, order_id, points, kind, note) VALUES (?, NULL, ?, 'redeem', 'Used at checkout')")
                    ->execute(array($auth_user['id'], -$plan));
                $redeem_id = intval($pdo->lastInsertId());
                if (etel_loyalty_balance($pdo, $auth_user['id']) < 0) { // a parallel order spent them first
                    $pdo->prepare('DELETE FROM loyalty_points WHERE id = ?')->execute(array($redeem_id));
                    $redeem_id = 0;
                    throw new EtelPromoException('Not enough points for that order');
                }
                $points_used = $plan;
                $points_disc = $plan / ETEL_POINTS_STEP;
                $fare_total = round($fare_total - $points_disc, 2);
            }
        }

        // Optional courier tip: 100% the courier's, never discounted by promos or points, never part of the service charge.
        require_once __DIR__ . '/courier_earnings.php';
        $tipSettings = etel_cpay_settings($pdo);
        $tip = etel_clean_tip(isset($body['tip']) ? $body['tip'] : 0, $tipSettings['tip_max']);
        if ($tip > 0) {
            etel_ensure_tip_column($pdo);
            $fare_total = round($fare_total + $tip, 2);
        }

        // Generate order code
        $order_code = 'ETD-' . strtoupper(bin2hex(random_bytes(3)));

        // Try full INSERT with all columns; fall back to legacy schema
        $inserted = false;
        try {
            $ins = $pdo->prepare("
                INSERT INTO orders (
                    order_code, customer_id, from_zone, to_zone, package_type, delivery_tier,
                    recipient_name, recipient_phone, notes,
                    subtotal, delivery_fee, service_charge, fare_total,
                    payment_method, payment_status, status,
                    delivery_lat, delivery_lng, delivery_photo_url, placed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', 'pending', ?, ?, ?, NOW())
            ");
            $ins->execute(array(
                $order_code, $auth_user['id'], $from_zone, $to_zone, $package_type, $delivery_tier,
                $recipient_name, $recipient_phone, $notes,
                $items_subtotal, $delivery_fee, $service_charge, $fare_total,
                $payment_method, $delivery_lat, $delivery_lng, $delivery_photo_url ?: null
            ));
            $inserted = true;
        } catch (Exception $e) {}

        if (!$inserted) {
            $ins = $pdo->prepare("
                INSERT INTO orders (
                    order_code, customer_id, from_zone, to_zone, package_type,
                    recipient_name, recipient_phone, notes, fare_total, status, placed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $ins->execute(array(
                $order_code, $auth_user['id'], $from_zone, $to_zone, $package_type,
                $recipient_name, $recipient_phone, $notes, $fare_total
            ));
        }
        
        $order_id = $pdo->lastInsertId();

        if ($tip > 0) {
            try { $pdo->prepare('UPDATE orders SET courier_tip = ? WHERE id = ?')->execute(array($tip, $order_id)); }
            catch (Exception $e) { error_log('[ETEL] tip bookkeeping failed for order ' . $order_id . ': ' . $e->getMessage()); }
        }

        if ($points_used > 0) {
            try {
                $pdo->prepare('UPDATE orders SET points_used = ?, points_discount = ? WHERE id = ?')->execute(array($points_used, $points_disc, $order_id));
                $pdo->prepare('UPDATE loyalty_points SET order_id = ? WHERE id = ?')->execute(array($order_id, $redeem_id));
            } catch (Exception $e) { error_log('[ETEL] points bookkeeping failed for order ' . $order_id . ': ' . $e->getMessage()); }
        }
        if ($promo_row) {
            try {
                $pdo->prepare('UPDATE orders SET promo_code = ?, discount = ? WHERE id = ?')->execute(array($promo_row['code'], $discount, $order_id));
                $pdo->prepare('INSERT INTO promo_redemptions (promo_id, user_id, order_id, discount) VALUES (?, ?, ?, ?)')->execute(array($promo_row['id'], $auth_user['id'], $order_id, $discount));
            } catch (Exception $e) { error_log('[ETEL] promo bookkeeping failed for order ' . $order_id . ': ' . $e->getMessage()); }
        }
        
        // Insert order items
        $item_ins = $pdo->prepare("
            INSERT INTO order_items (order_id, item_id, item_name, quantity, unit_price, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($item_details as $detail) {
            $item_ins->execute(array(
                $order_id,
                $detail['item_id'],
                $detail['name'],
                $detail['quantity'],
                $detail['unit_price'],
                $detail['subtotal']
            ));
        }
        
        // Assign order to the service giver who owns the items
        $assign = $pdo->prepare("
            INSERT INTO order_assignments (
                order_id, service_giver_id, status, assigned_at
            ) VALUES (?, ?, 'pending', NOW())
        ");
        $assign->execute(array($order_id, $service_giver_id));

        // Courier dispatch. Chapa orders wait for a verified payment (chapa_settle dispatches them).
        if ($payment_method !== 'chapa') {
            require_once __DIR__ . '/order_dispatch.php';
            etel_dispatch_order($pdo, $order_id);
        }

        // Return order details
        return array(
            'order_id'         => $order_id,
            'order_code'       => $order_code,
            'status'           => 'pending',
            'service_giver_id' => $service_giver_id,
            'subtotal'         => $items_subtotal,
            'delivery_tier'    => $delivery_tier,
            'delivery_photo_url' => $delivery_photo_url ?: null,
            'delivery_fee'     => $delivery_fee,
            'service_charge'   => $service_charge,
            'tip'              => $tip,
            'total'            => $fare_total,
            'discount'         => $discount,
            'promo_code'       => $promo_row ? $promo_row['code'] : null,
            'points_used'      => $points_used,
            'points_discount'  => $points_disc,
            'payment_method'   => $payment_method,
            'items_count'      => count($item_details),
            'message'          => 'Order created and assigned to service giver',
        );
        
    } catch (Exception $e) {
        if (!empty($claimed_promo_id) && empty($order_id)) {
            try { $pdo->prepare('UPDATE promo_codes SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?')->execute(array($claimed_promo_id)); } catch (Exception $e2) {}
        }
        if (!empty($redeem_id) && empty($order_id)) {
            try { $pdo->prepare('DELETE FROM loyalty_points WHERE id = ? AND order_id IS NULL')->execute(array($redeem_id)); } catch (Exception $e3) {}
        }
        if ($e instanceof EtelPromoException) throw new Exception($e->getMessage());
        error_log('[ETEL] Order creation failed: ' . $e->getMessage());
        throw new Exception('Order creation failed - please try again or contact support');
    }
}
