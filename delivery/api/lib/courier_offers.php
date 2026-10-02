<?php
// Expiring courier job offers.
//
// Until now auto-dispatch force-assigned the nearest courier ('assigned', courier marked busy) and nothing ever
// took the order back if that courier never reacted. With offers switched on (Admin -> Couriers -> "Job offers"):
//   1. the chosen courier gets a time-limited offer (orders.courier_offer_expires_at) and must tap Accept;
//   2. Decline, or running out of time, releases the order and offers it to the next nearest courier
//      (up to `max_attempts` couriers in total);
//   3. when nobody takes it, the order goes back to the open job list (courier_id NULL, status 'pending'),
//      which is exactly how it already behaved when no courier was online.
// Safety rails: the whole thing is off while courier_offer_settings.offer_seconds = 0 (default), and only couriers
// whose app reported offer support in the last 90 s (couriers.offer_capable_until) ever receive an offer - a courier
// on an older app keeps the old behaviour and is never "timed out" on a button they do not have.
// Expired offers are processed lazily (etel_offers_sweep) whenever a courier / shop / customer screen polls.

function etel_offers_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $steps = array(
        "CREATE TABLE IF NOT EXISTS courier_offer_settings (id TINYINT NOT NULL PRIMARY KEY, offer_seconds INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 3) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS order_courier_offers (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, courier_id INT NOT NULL, offered_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, response VARCHAR(12) NOT NULL DEFAULT 'pending', responded_at DATETIME NULL, KEY idx_oco_order (order_id), KEY idx_oco_courier (courier_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );
    foreach ($steps as $sql) { try { $pdo->exec($sql); } catch (Exception $e) { error_log('[ETEL] etel_offers_ensure_schema: ' . $e->getMessage()); } }
    $cols = array('orders' => array('courier_offer_expires_at' => 'DATETIME NULL DEFAULT NULL', 'courier_accepted_at' => 'DATETIME NULL DEFAULT NULL'),
                  'couriers' => array('offer_capable_until' => 'DATETIME NULL DEFAULT NULL'));
    foreach ($cols as $tbl => $adds) {
        $have = function_exists('etel_table_columns') ? etel_table_columns($pdo, $tbl) : array();
        foreach ($adds as $col => $def) {
            if (in_array($col, $have, true)) continue;
            try { $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def"); } catch (Exception $e) { error_log('[ETEL] offers column ' . $tbl . '.' . $col . ': ' . $e->getMessage()); }
        }
    }
    // The sweep filters on this column on every poll, so it needs an index.
    try {
        if (!$pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'idx_orders_offer_expires'")->fetch()) {
            $pdo->exec('ALTER TABLE orders ADD INDEX idx_orders_offer_expires (courier_offer_expires_at)');
        }
    } catch (Exception $e) { error_log('[ETEL] offers index: ' . $e->getMessage()); }
}

// Pure: clamp admin input. seconds: 0 (off) or 15..300; attempts 1..10.
function etel_offer_clean_settings($seconds, $attempts) {
    $s = (int)$seconds; if ($s < 0) $s = 0; if ($s > 0 && $s < 15) $s = 15; if ($s > 300) $s = 300;
    $a = (int)$attempts; if ($a < 1) $a = 1; if ($a > 10) $a = 10;
    return array('seconds' => $s, 'max_attempts' => $a);
}

function etel_offer_settings($pdo) {
    etel_offers_ensure_schema($pdo);
    try {
        $r = $pdo->query('SELECT offer_seconds, max_attempts FROM courier_offer_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($r) return etel_offer_clean_settings($r['offer_seconds'], $r['max_attempts']);
    } catch (Exception $e) {}
    return array('seconds' => 0, 'max_attempts' => 3);
}

function etel_offer_save_settings($pdo, $seconds, $attempts) {
    etel_offers_ensure_schema($pdo);
    $c = etel_offer_clean_settings($seconds, $attempts);
    $pdo->prepare('INSERT INTO courier_offer_settings (id, offer_seconds, max_attempts) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE offer_seconds = VALUES(offer_seconds), max_attempts = VALUES(max_attempts)')
        ->execute(array($c['seconds'], $c['max_attempts']));
    return $c;
}

// A courier's app says "I can show Accept / Decline" (sent by the v5 courier app on every poll).
function etel_offer_mark_capable($pdo, $courier_id) {
    try { etel_offers_ensure_schema($pdo); $pdo->prepare('UPDATE couriers SET offer_capable_until = DATE_ADD(NOW(), INTERVAL 90 SECOND) WHERE id = ?')->execute(array((int)$courier_id)); }
    catch (Exception $e) { error_log('[ETEL] etel_offer_mark_capable: ' . $e->getMessage()); }
}

function etel_courier_offer_capable($pdo, $courier_id) {
    try {
        $s = $pdo->prepare('SELECT 1 FROM couriers WHERE id = ? AND offer_capable_until IS NOT NULL AND offer_capable_until > NOW()');
        $s->execute(array((int)$courier_id));
        return (bool)$s->fetchColumn();
    } catch (Exception $e) { return false; }
}

// Start the clock on an order that has just been assigned to $courier_id.
function etel_offer_start($pdo, $order_id, $courier_id, $seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return false;
    $pdo->prepare('UPDATE orders SET courier_offer_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), courier_accepted_at = NULL WHERE id = ? AND courier_id = ?')
        ->execute(array($seconds, (int)$order_id, (int)$courier_id));
    $pdo->prepare("INSERT INTO order_courier_offers (order_id, courier_id, offered_at, expires_at, response) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), 'pending')")
        ->execute(array((int)$order_id, (int)$courier_id, $seconds));
    return true;
}

// Called from auto-dispatch right after it assigned $courier_id. No-op unless offers are on and the courier is capable.
function etel_offer_after_dispatch($pdo, $order_id, $courier_id) {
    $st = etel_offer_settings($pdo);
    if ($st['seconds'] <= 0) return false;
    if (!etel_courier_offer_capable($pdo, $courier_id)) return false;
    return etel_offer_start($pdo, $order_id, $courier_id, $st['seconds']);
}

// The courier tapped Accept (or implicitly accepted by picking the order up). Idempotent.
function etel_offer_accept($pdo, $order_id, $courier_id) {
    etel_offers_ensure_schema($pdo);
    $u = $pdo->prepare("UPDATE orders SET courier_accepted_at = NOW(), courier_offer_expires_at = NULL
                        WHERE id = ? AND courier_id = ? AND courier_accepted_at IS NULL AND status = 'assigned'
                          AND courier_offer_expires_at IS NOT NULL AND courier_offer_expires_at >= NOW()");
    $u->execute(array((int)$order_id, (int)$courier_id));
    if ($u->rowCount() === 1) {
        $pdo->prepare("UPDATE order_courier_offers SET response = 'accepted', responded_at = NOW() WHERE order_id = ? AND courier_id = ? AND response = 'pending'")
            ->execute(array((int)$order_id, (int)$courier_id));
        return 'accepted';
    }
    $c = $pdo->prepare('SELECT courier_id, courier_accepted_at, courier_offer_expires_at FROM orders WHERE id = ?');
    $c->execute(array((int)$order_id));
    $o = $c->fetch(PDO::FETCH_ASSOC);
    if ($o && (int)$o['courier_id'] === (int)$courier_id && ($o['courier_accepted_at'] !== null || $o['courier_offer_expires_at'] === null)) return 'accepted';
    return 'gone';
}

// Implicit acceptance: the courier already moved the order forward (picked up...), so it can never expire under them.
function etel_offer_mark_accepted($pdo, $order_id) {
    try { $pdo->prepare('UPDATE orders SET courier_accepted_at = COALESCE(courier_accepted_at, NOW()), courier_offer_expires_at = NULL WHERE id = ?')->execute(array((int)$order_id)); }
    catch (Exception $e) { /* columns not there yet = offers never used */ }
}

function etel_offer_decline($pdo, $order_id, $courier_id) {
    etel_offers_ensure_schema($pdo);
    return etel_offer_release($pdo, $order_id, $courier_id, 'declined');
}

// Take the order back from a courier who did not accept, then offer it to the next one. Returns true if released.
function etel_offer_release($pdo, $order_id, $courier_id, $response) {
    $order_id = (int)$order_id; $courier_id = (int)$courier_id;
    // Atomic guard: only an order that is still assigned to this courier, not accepted, with an open offer.
    $u = $pdo->prepare("UPDATE orders SET courier_id = NULL, status = 'pending', courier_offer_expires_at = NULL
                        WHERE id = ? AND courier_id = ? AND courier_accepted_at IS NULL AND status = 'assigned' AND courier_offer_expires_at IS NOT NULL");
    $u->execute(array($order_id, $courier_id));
    if ($u->rowCount() !== 1) return false;
    $pdo->prepare('UPDATE order_courier_offers SET response = ?, responded_at = NOW() WHERE order_id = ? AND courier_id = ? AND response = ?')
        ->execute(array($response, $order_id, $courier_id, 'pending'));
    // The courier is free again unless they still have another live delivery.
    $pdo->prepare("UPDATE couriers SET status = 'online' WHERE id = ? AND status = 'busy'
                   AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.courier_id = couriers.id AND o.status IN ('assigned','picked_up','in_transit'))")
        ->execute(array($courier_id));
    try { if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id); } catch (Exception $e) {}
    etel_offer_next($pdo, $order_id);
    return true;
}

// Pure: distance in km between two lat/lng points (same Haversine as auto-dispatch).
function etel_offer_km($lat1, $lng1, $lat2, $lng2) {
    $dlat = deg2rad($lat2 - $lat1); $dlng = deg2rad($lng2 - $lng1);
    $a = sin($dlat / 2) * sin($dlat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) * sin($dlng / 2);
    return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Pure: nearest candidate row (each with id, last_lat, last_lng, zone) to the pickup point; couriers without a GPS
// fix rank by zone match (0.5 km) or 5 km, like auto-dispatch. Returns the row or null.
function etel_offer_pick_nearest($candidates, $lat, $lng, $zone) {
    $best = null; $bestd = PHP_INT_MAX;
    foreach ($candidates as $c) {
        if (!empty($c['last_lat']) && !empty($c['last_lng'])) $d = etel_offer_km((float)$lat, (float)$lng, (float)$c['last_lat'], (float)$c['last_lng']);
        else $d = (isset($c['zone']) && $c['zone'] === $zone) ? 0.5 : 5.0;
        if ($d < $bestd) { $bestd = $d; $best = $c; }
    }
    return $best;
}

// Offer the (now unassigned) order to the next nearest eligible courier, or leave it in the open job list.
function etel_offer_next($pdo, $order_id) {
    $order_id = (int)$order_id;
    $st = etel_offer_settings($pdo);
    if ($st['seconds'] <= 0) return false;
    $n = $pdo->prepare('SELECT COUNT(*) FROM order_courier_offers WHERE order_id = ?'); $n->execute(array($order_id));
    if ((int)$n->fetchColumn() >= $st['max_attempts']) return false;

    $o = $pdo->prepare("SELECT o.id, o.order_code, o.from_zone, o.to_zone, o.fare_total, o.status, o.courier_id,
                               sg.lat AS sg_lat, sg.lng AS sg_lng
                        FROM orders o LEFT JOIN order_assignments oa ON oa.order_id = o.id LEFT JOIN service_givers sg ON sg.id = oa.service_giver_id
                        WHERE o.id = ? LIMIT 1");
    $o->execute(array($order_id));
    $ord = $o->fetch(PDO::FETCH_ASSOC);
    if (!$ord || $ord['status'] !== 'pending' || $ord['courier_id']) return false;
    $lat = $ord['sg_lat'] ? (float)$ord['sg_lat'] : 7.0559; $lng = $ord['sg_lng'] ? (float)$ord['sg_lng'] : 38.4762; // Hawassa centre if the shop has no pin

    $cs = $pdo->prepare("SELECT c.id, c.user_id, c.last_lat, c.last_lng, c.zone FROM couriers c
                         WHERE c.status = 'online' AND c.active = 1 AND c.offer_capable_until IS NOT NULL AND c.offer_capable_until > NOW()
                           AND c.id NOT IN (SELECT courier_id FROM orders WHERE status IN ('assigned','picked_up','in_transit') AND courier_id IS NOT NULL)
                           AND c.id NOT IN (SELECT courier_id FROM order_courier_offers WHERE order_id = ?)");
    $cs->execute(array($order_id));
    $best = etel_offer_pick_nearest($cs->fetchAll(PDO::FETCH_ASSOC), $lat, $lng, $ord['from_zone']);
    if (!$best) return false;

    $a = $pdo->prepare("UPDATE orders SET courier_id = ?, status = 'assigned' WHERE id = ? AND courier_id IS NULL AND status = 'pending'");
    $a->execute(array((int)$best['id'], $order_id));
    if ($a->rowCount() !== 1) return false;
    $pdo->prepare("UPDATE couriers SET status = 'busy' WHERE id = ?")->execute(array((int)$best['id']));
    etel_offer_start($pdo, $order_id, (int)$best['id'], $st['seconds']);
    try { if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id); } catch (Exception $e) {}
    try {
        $title = 'New Delivery Job!';
        $body = 'Order ' . $ord['order_code'] . ' from ' . $ord['from_zone'] . ' to ' . $ord['to_zone'] . ' - ETB ' . number_format((float)$ord['fare_total'], 2) . ' - accept within ' . $st['seconds'] . 's';
        $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, data) VALUES (?,?,?,?,?)')
            ->execute(array((int)$best['user_id'], $title, $body, 'new_order', json_encode(array('order_id' => $order_id, 'order_code' => $ord['order_code']))));
        require_once __DIR__ . '/../push.php';
        etel_push_send($pdo, (int)$best['user_id'], $title, $body, array('type' => 'new_order', 'order_id' => $order_id, 'url' => '/delivery/courier/'));
    } catch (Exception $e) { error_log('[ETEL] offer notify: ' . $e->getMessage()); }
      catch (Throwable $e) { error_log('[ETEL] offer notify: ' . $e->getMessage()); }
    return true;
}

// Release every offer whose time ran out. Cheap when nothing is pending; safe to call often.
function etel_offers_sweep($pdo, $force = false) {
    static $ran = false;
    if ($ran && !$force) return 0;
    $ran = true;
    $n = 0;
    try {
        $s = $pdo->query("SELECT id, courier_id FROM orders WHERE courier_offer_expires_at IS NOT NULL AND courier_offer_expires_at < NOW()
                          AND courier_accepted_at IS NULL AND status = 'assigned' AND courier_id IS NOT NULL ORDER BY courier_offer_expires_at LIMIT 10");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            try { if (etel_offer_release($pdo, $r['id'], $r['courier_id'], 'expired')) $n++; }
            catch (Exception $e) { error_log('[ETEL] offer release #' . $r['id'] . ': ' . $e->getMessage()); }
        }
    } catch (Exception $e) { /* offers schema not created yet = nothing to sweep */ }
    return $n;
}

// Add offer_pending / offer_seconds_left to the courier's own order list.
function etel_offer_attach($pdo, $orders) {
    if (!is_array($orders) || !$orders) return $orders;
    $ids = array(); foreach ($orders as $o) { $ids[] = (int)$o['id']; }
    $map = array();
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT id, courier_accepted_at, courier_offer_expires_at, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), courier_offer_expires_at)) AS secs FROM orders WHERE id IN ($in)");
        $s->execute($ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['id']] = $r;
    } catch (Exception $e) { return $orders; }
    foreach ($orders as $i => $o) {
        $r = isset($map[(int)$o['id']]) ? $map[(int)$o['id']] : null;
        $pending = $r && $r['courier_offer_expires_at'] !== null && $r['courier_accepted_at'] === null && $o['status'] === 'assigned';
        $orders[$i]['offer_pending'] = $pending ? 1 : 0;
        $orders[$i]['offer_seconds_left'] = $pending ? (int)$r['secs'] : 0;
    }
    return $orders;
}
