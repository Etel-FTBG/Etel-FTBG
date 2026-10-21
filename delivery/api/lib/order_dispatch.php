<?php
require_once __DIR__ . '/courier_approval.php';
/**
 * Courier auto-dispatch and unpaid-Chapa-order cleanup for delivery orders.
 *
 * Cash/wallet orders dispatch as soon as they are created. Chapa orders are held
 * (no courier) until the payment is verified (chapa_settle calls
 * etel_dispatch_order), and an unpaid Chapa order is cancelled after
 * ETEL_CHAPA_UNPAID_MINUTES so no courier is ever sent for money that never came.
 */
if (!defined('ETEL_CHAPA_UNPAID_MINUTES')) define('ETEL_CHAPA_UNPAID_MINUTES', 30);

/** True when the order is a Chapa order that has not been paid yet. */
function etel_order_awaiting_chapa($pdo, $order_id) {
    $s = $pdo->prepare("SELECT payment_method, payment_status FROM orders WHERE id = ?");
    $s->execute(array((int)$order_id));
    $o = $s->fetch(PDO::FETCH_ASSOC);
    return $o && $o['payment_method'] === 'chapa' && $o['payment_status'] !== 'paid';
}

/**
 * Free couriers stuck on status 'busy' with no live delivery. Nothing resets a
 * courier after a delivery is finished or cancelled, so without this they never
 * receive another job until they toggle Go Online by hand. A courier seen in the
 * last 30 minutes goes back to 'online'; otherwise 'offline' (they tap Go Online
 * when they start work). Returns the number of couriers released. Never throws.
 */
function etel_release_idle_couriers($pdo) {
    try {
        $u = $pdo->prepare("UPDATE couriers c
            SET c.status = IF(c.last_seen IS NOT NULL AND c.last_seen > (NOW() - INTERVAL 30 MINUTE), 'online', 'offline')
            WHERE c.status = 'busy' AND c.active = 1
              AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.courier_id = c.id AND o.status IN ('assigned','picked_up','in_transit'))");
        $u->execute();
        return $u->rowCount();
    } catch (Exception $e) {
        error_log('[ETEL] release idle couriers failed: ' . $e->getMessage());
        return 0;
    }
}

/** Find the nearest online courier for an order and offer/assign it. Never throws. */
function etel_dispatch_order($pdo, $order_id, $exclude = array()) {
    $order_id = (int)$order_id;
    $exclude = array_values(array_filter(array_map('intval', (array) $exclude)));
    $excludeSql = $exclude ? (' AND c.id NOT IN (' . implode(',', $exclude) . ')') : '';
    try {
        require_once __DIR__ . '/package_orders.php';
        etel_package_ensure_schema($pdo);   // makes sure the package columns exist before they are read below
        $q = $pdo->prepare("SELECT o.order_code, o.from_zone, o.to_zone, o.fare_total, o.status, o.courier_id, oa.service_giver_id,
                                   o.order_kind, o.vehicle_class, o.pickup_lat, o.pickup_lng, o.customer_id
                            FROM orders o LEFT JOIN order_assignments oa ON oa.order_id = o.id WHERE o.id = ? LIMIT 1");
        $q->execute(array($order_id));
        $o = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[ETEL] dispatch could not read order_id=' . $order_id . ': ' . $e->getMessage());
        return;
    }
    if (!$o || $o['status'] !== 'pending' || $o['courier_id']) return; // cancelled, or already dispatched
    etel_release_idle_couriers($pdo); // so a courier who just finished a job is a candidate again
    $order_code = $o['order_code']; $from_zone = $o['from_zone']; $to_zone = $o['to_zone'];
    $fare_total = (float)$o['fare_total']; $service_giver_id = (int)$o['service_giver_id'];

    //  Auto-dispatch: find nearest online courier 
    // Prefer the shop's real registered coordinates over the
    // hardcoded zone-centroid table below - a real pin is more
    // accurate than a per-zone approximation. The zone table only
    // kicks in when service_givers.lat/lng genuinely isn't set.
    require_once __DIR__ . '/zones.php';
    $zone_coords = etel_zone_centroids();
    $pickup_coord = isset($zone_coords[$from_zone]) ? $zone_coords[$from_zone] : array(7.0559, 38.4762);
    // A package is picked up where the sender is, and only a courier with the vehicle the customer chose may get it.
    $is_package = ($o['order_kind'] === 'package');
    $vehSql = ''; $vehParams = array();
    if ($is_package) {
        if ($o['pickup_lat'] && $o['pickup_lng']) $pickup_coord = array((float)$o['pickup_lat'], (float)$o['pickup_lng']);
        list($vehSql, $vehParams) = etel_vehicle_sql($o['vehicle_class'], 'c', $pdo);
        $vehSql .= etel_package_capable_sql('c');   // only couriers whose app can run a package (pickup address, PINs)
    }
    $vehSql .= etel_not_own_order_sql($o['customer_id'], 'c');   // never the courier who placed it
    // A package is NOT handed to one courier. Every online courier with the chosen vehicle sees it in the Jobs tab, is told, and the first
    // one to accept gets it (courier_accept_order claims it atomically). The order stays 'pending' until someone does.
    if ($is_package) {
        etel_package_broadcast($pdo, $order_id, $o, $vehSql, $vehParams, $excludeSql, $pickup_coord);
        return;
    }
    try {
        if ($is_package) throw new Exception('no shop');
        $sgCoordStmt = $pdo->prepare('SELECT lat, lng FROM service_givers WHERE id=? AND lat IS NOT NULL AND lng IS NOT NULL');
        $sgCoordStmt->execute(array($service_giver_id));
        $sgCoordRow = $sgCoordStmt->fetch();
        if ($sgCoordRow) {
            $pickup_coord = array(floatval($sgCoordRow['lat']), floatval($sgCoordRow['lng']));
        }
    } catch (Exception $eCoord) {}

    try {
        // Hex-grid pre-filter instead of "20 most recently seen" -
        // that recency cap was a real correctness bug: the truly
        // nearest online courier could easily not be among the 20
        // most recently active ones, especially as courier count
        // grows. This narrows by actual proximity (indexed hex
        // cell range) instead, with no arbitrary recency cutoff.
        $searchRadiusKm = 15; // generous - exact Haversine below still finds the true nearest within whatever this returns
        $hexWhere = '1=1'; $hexParams = array();
        if (function_exists('hexgrid_radius_where')) {
            list($hexWhere, $hexParams) = hexgrid_radius_where($pickup_coord[0], $pickup_coord[1], $searchRadiusKm);
        }
        $cs = $pdo->prepare("
            SELECT c.id, c.user_id, c.last_lat, c.last_lng, c.zone
            FROM couriers c
            WHERE c.status = 'online' AND c.active = 1" . etel_courier_approved_sql($pdo) . "
              AND $hexWhere
              $excludeSql
              $vehSql
              AND c.id NOT IN (
                SELECT courier_id FROM orders
                WHERE status IN ('assigned','picked_up','in_transit')
                AND courier_id IS NOT NULL
              )
        ");
        $cs->execute(array_merge($hexParams, $vehParams));
        $candidates = $cs->fetchAll();
        $best_id = null; $best_dist = PHP_INT_MAX;
        foreach ($candidates as $c) {
            if ($c['last_lat'] && $c['last_lng']) {
                // Haversine distance in km
                $dlat = deg2rad($c['last_lat'] - $pickup_coord[0]);
                $dlng = deg2rad($c['last_lng'] - $pickup_coord[1]);
                $a = sin($dlat/2)*sin($dlat/2) + cos(deg2rad($pickup_coord[0]))*cos(deg2rad($c['last_lat']))*sin($dlng/2)*sin($dlng/2);
                $dist = 6371 * 2 * atan2(sqrt($a), sqrt(1-$a));
            } else {
                // No GPS  rank by zone match
                $dist = ($c['zone'] === $from_zone) ? 0.5 : 5.0;
            }
            if ($dist < $best_dist) { $best_dist = $dist; $best_id = $c; }
        }
        // If the hex-filtered search found nobody (e.g. every
        // online courier is currently outside the search radius),
        // fall back to the old unfiltered zone-match behavior
        // rather than leaving the order completely unassigned -
        // matches this function's original behavior when no GPS
        // data was available at all.
        if (!$best_id) {
            $csFallback = $pdo->prepare("
                SELECT c.id, c.user_id, c.last_lat, c.last_lng, c.zone
                FROM couriers c
                WHERE c.status = 'online' AND c.active = 1" . etel_courier_approved_sql($pdo) . " $excludeSql $vehSql
                  AND c.id NOT IN (
                    SELECT courier_id FROM orders
                    WHERE status IN ('assigned','picked_up','in_transit')
                    AND courier_id IS NOT NULL
                  )
                ORDER BY (c.zone = ?) DESC, c.last_seen DESC
                LIMIT 1
            ");
            $csFallback->execute(array_merge($vehParams, array($from_zone)));
            $best_id = $csFallback->fetch();
        }
        if ($best_id) {
            // Auto-assign this courier
            // orders.courier_id is a couriers.id everywhere else (joins, the courier's own order
            // lists, NFC, the "busy" filter above). This used to store the courier's users.id,
            // so an auto-assigned order could be missing from that courier's list.
            $assignedAt = '';
            try { require_once __DIR__ . '/courier_stale.php'; if (etel_stale_has_col($pdo)) $assignedAt = ', courier_assigned_at=NOW()'; } catch (Exception $ce) {}
            $pdo->prepare("UPDATE orders SET courier_id=?, status='assigned'$assignedAt WHERE id=?")
                ->execute(array($best_id['id'], $order_id));
    if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id);
            $pdo->prepare("UPDATE couriers SET status='busy' WHERE user_id=?")
                ->execute(array($best_id['user_id']));
            // Expiring job offers (off unless the admin turned them on, and only for couriers whose app supports them).
            try {
                require_once __DIR__ . '/courier_offers.php';
                etel_offer_after_dispatch($pdo, $order_id, $best_id['id']);
            } catch (Exception $oe) { error_log('[ETEL] offer start failed for order_id=' . $order_id . ': ' . $oe->getMessage()); }
              catch (Throwable $oe) { error_log('[ETEL] offer start failed for order_id=' . $order_id . ': ' . $oe->getMessage()); }
            // Notify courier: in-app row first, then the real push. They are
            // independent on purpose - a failure of the in-app table must not
            // swallow the push that wakes a backgrounded courier app.
            $notifTitle = $is_package ? 'New Package Job!' : 'New Delivery Job!';
            $notifBody  = ($is_package ? 'Package ' : 'Order ') . $order_code . ' from ' . $from_zone . ' to ' . $to_zone . '  ETB ' . number_format($fare_total, 2);
            try {
                require_once __DIR__ . '/../push.php';
                etel_ensure_notifications_table($pdo);
                $pdo->prepare("INSERT INTO notifications (user_id, title, body, type, data) VALUES (?,?,?,?,?)")
                    ->execute(array(
                        $best_id['user_id'],
                        $notifTitle,
                        $notifBody,
                        'new_order',
                        json_encode(array('order_id' => $order_id, 'order_code' => $order_code))
                    ));
            } catch (Exception $ne) {
                error_log('[ETEL] auto-dispatch in-app notification failed for order_id=' . $order_id . ': ' . $ne->getMessage());
            }
            try {
                require_once __DIR__ . '/../push.php';
                etel_push_send_async($pdo, intval($best_id['user_id']), $notifTitle, $notifBody,
                    array('type' => 'new_order', 'order_id' => $order_id, 'url' => '/delivery/courier/'));
            } catch (Exception $ne) {
                error_log('[ETEL] auto-dispatch push failed for order_id=' . $order_id . ': ' . $ne->getMessage());
            }
        }
    } catch (Exception $de) {
        error_log('[ETEL] auto-dispatch failed for order_id=' . $order_id . ': ' . $de->getMessage());
    }
    //  End auto-dispatch
}

/**
 * Cancel Chapa orders still unpaid after the grace period (customer abandoned or
 * Chapa failed). Skips orders with a Chapa checkout opened in the last 15 minutes
 * or any completed payment. Returns the number cancelled. Never throws.
 */
function etel_cancel_unpaid_chapa_orders($pdo) {
    static $ran = false;
    if ($ran) return 0;
    $ran = true;
    $n = 0;
    try {
        $s = $pdo->prepare("SELECT o.id FROM orders o
            WHERE o.payment_method = 'chapa' AND o.payment_status <> 'paid' AND o.status = 'pending'
              AND o.courier_id IS NULL AND o.placed_at < (NOW() - INTERVAL " . (int)ETEL_CHAPA_UNPAID_MINUTES . " MINUTE)
              AND NOT EXISTS (SELECT 1 FROM chapa_payments p WHERE p.kind = 'order' AND p.target_id = o.id
                              AND (p.status = 'completed' OR p.created_at > (NOW() - INTERVAL 15 MINUTE)))
            ORDER BY o.id LIMIT 20");
        $s->execute();
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $u = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending' AND payment_status <> 'paid' AND courier_id IS NULL");
            $u->execute(array((int)$id));
            if ($u->rowCount() !== 1) continue;
            $n++;
            try { $pdo->prepare("UPDATE order_assignments SET status = 'cancelled' WHERE order_id = ?")->execute(array((int)$id)); } catch (Exception $e) {}
            try { if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, (int)$id); } catch (Exception $e) {}
        }
    } catch (Exception $e) { /* chapa_payments missing = Chapa never used, nothing to cancel */ }
    return $n;
}


/** Tell every eligible courier about an open package job (nearest first, at most 40). They accept it from the Jobs tab. Returns how many were told. Never throws. */
function etel_package_broadcast($pdo, $order_id, $o, $vehSql, $vehParams, $excludeSql, $pickup_coord) {
    $told = 0;
    try {
        $cs = $pdo->prepare("SELECT c.id, c.user_id, c.last_lat, c.last_lng, c.zone FROM couriers c
            WHERE c.status = 'online' AND c.active = 1" . etel_courier_approved_sql($pdo) . " $excludeSql $vehSql
              AND c.id NOT IN (SELECT courier_id FROM orders WHERE status IN ('assigned','picked_up','in_transit') AND courier_id IS NOT NULL)");
        $cs->execute($vehParams);
        $list = $cs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as $k => $c) {
            if ($c['last_lat'] && $c['last_lng']) {
                $dlat = deg2rad($c['last_lat'] - $pickup_coord[0]); $dlng = deg2rad($c['last_lng'] - $pickup_coord[1]);
                $a = sin($dlat/2)*sin($dlat/2) + cos(deg2rad($pickup_coord[0]))*cos(deg2rad($c['last_lat']))*sin($dlng/2)*sin($dlng/2);
                $list[$k]['d'] = 6371 * 2 * atan2(sqrt($a), sqrt(1-$a));
            } else { $list[$k]['d'] = 9999; }
        }
        usort($list, function ($x, $y) { return $x['d'] <=> $y['d']; });
        $list = array_slice($list, 0, 40);
        $title = 'New Package Job!';
        $body = 'Package ' . $o['order_code'] . ' from ' . $o['from_zone'] . ' to ' . $o['to_zone'] . '  ETB ' . number_format((float) $o['fare_total'], 2) . ' - open Jobs to accept';
        foreach ($list as $c) {
            try {
                require_once __DIR__ . '/../push.php';
                etel_ensure_notifications_table($pdo);
                $pdo->prepare("INSERT INTO notifications (user_id, title, body, type, data) VALUES (?,?,?,?,?)")
                    ->execute(array($c['user_id'], $title, $body, 'new_order', json_encode(array('order_id' => (int) $order_id, 'order_code' => $o['order_code']))));
            } catch (Exception $ne) { error_log('[ETEL] package broadcast notification failed for order_id=' . $order_id . ': ' . $ne->getMessage()); }
            try {
                require_once __DIR__ . '/../push.php';
                etel_push_send_async($pdo, intval($c['user_id']), $title, $body, array('type' => 'new_order', 'order_id' => (int) $order_id, 'url' => '/delivery/courier/'));
            } catch (Exception $ne) { error_log('[ETEL] package broadcast push failed for order_id=' . $order_id . ': ' . $ne->getMessage()); }
            $told++;
        }
    } catch (Exception $e) { error_log('[ETEL] package broadcast failed for order_id=' . $order_id . ': ' . $e->getMessage()); }
    return $told;
}

/** The first courier to claim an open order gets it. Atomic: false when someone else already has it or it is cancelled/delivered. */
function etel_claim_order($pdo, $order_id, $courier_id) {
    $set = '';
    try { require_once __DIR__ . '/courier_stale.php'; if (etel_stale_has_col($pdo)) $set = ', courier_assigned_at = NOW()'; } catch (Exception $e) {}
    $u = $pdo->prepare("UPDATE orders SET courier_id = ?, status = 'assigned'$set WHERE id = ? AND (courier_id IS NULL OR courier_id = 0) AND status IN ('pending','assigned')");
    $u->execute(array((int) $courier_id, (int) $order_id));
    return $u->rowCount() === 1;
}
