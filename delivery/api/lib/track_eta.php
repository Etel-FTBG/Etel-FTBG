<?php
// Honest arrival estimate for the customer tracking screen.
//   live    : the courier is on the road and shared a fresh position -> straight-line distance x 1.3 (roads) at city pace.
//   typical : otherwise the average of the last 200 real deliveries (placed -> picked up, picked up -> delivered), less the
//             time this order has already used. Needs at least ETEL_ETA_MIN_SAMPLES orders; below that we show NO time.
// A courier whose last position is older than ETEL_ETA_FRESH_SEC is treated as "location paused": no pin, no live estimate.
// Never throws: tracking must keep working when an estimate cannot be made.

define('ETEL_ETA_FRESH_SEC', 180);
define('ETEL_ETA_MIN_SAMPLES', 5);
define('ETEL_ETA_KMH', 20);
define('ETEL_ETA_ROAD_FACTOR', 1.3);
define('ETEL_ETA_HANDOVER_MIN', 2);

function etel_eta_km($lat1, $lng1, $lat2, $lng2) {
    $rad = M_PI / 180;
    $dLat = ($lat2 - $lat1) * $rad; $dLng = ($lng2 - $lng1) * $rad;
    $h = sin($dLat / 2) * sin($dLat / 2) + cos($lat1 * $rad) * cos($lat2 * $rad) * sin($dLng / 2) * sin($dLng / 2);
    return 2 * 6371 * asin(min(1, sqrt($h)));
}

// Pure maths. $in keys: status, age_sec (since placed), pickup_age_sec (since picked up or null), km (courier -> customer or null),
// fresh (bool, courier position is recent), lead_min / trip_min (typical, or null when there is not enough history).
// Returns null (no honest estimate) or array(minutes, low, high, basis, km).
function etel_eta_estimate($in) {
    $st = isset($in['status']) ? $in['status'] : '';
    if ($st === 'delivered' || $st === 'cancelled' || $st === '') return null;
    $onRoad = ($st === 'picked_up' || $st === 'in_transit');
    $fresh = !empty($in['fresh']);
    $km = isset($in['km']) ? $in['km'] : null;
    $lead = isset($in['lead_min']) ? $in['lead_min'] : null;
    $trip = isset($in['trip_min']) ? $in['trip_min'] : null;
    $minutes = null; $basis = null;
    if ($onRoad && $fresh && $km !== null) {
        $minutes = (int) round($km * ETEL_ETA_ROAD_FACTOR / ETEL_ETA_KMH * 60) + ETEL_ETA_HANDOVER_MIN;
        $basis = 'live';
    } elseif ($onRoad && $trip !== null) {
        $used = isset($in['pickup_age_sec']) && $in['pickup_age_sec'] !== null ? $in['pickup_age_sec'] / 60 : 0;
        $minutes = (int) round(max(3, $trip - $used));
        $basis = 'typical';
    } elseif (!$onRoad && $lead !== null && $trip !== null) {
        $age = isset($in['age_sec']) ? $in['age_sec'] / 60 : 0;
        $minutes = (int) round(max(5, $lead - $age) + $trip);
        $basis = 'typical';
    }
    if ($minutes === null) return null;
    $minutes = max(1, $minutes);
    $spread = ($basis === 'live') ? array(0.85, 1.2) : array(0.8, 1.3);
    $low = max(1, (int) round($minutes * $spread[0]));
    $high = max($low + 1, (int) round($minutes * $spread[1]));
    return array('minutes' => $minutes, 'low' => $low, 'high' => $high, 'basis' => $basis, 'km' => ($km !== null ? round($km, 1) : null));
}

// Average minutes of the last 200 delivered orders that have both timestamps, or nulls when fewer than ETEL_ETA_MIN_SAMPLES.
function etel_eta_typical($pdo) {
    $out = array('lead_min' => null, 'trip_min' => null, 'n' => 0);
    try {
        $r = $pdo->query("SELECT COUNT(*) AS n,
                AVG(TIMESTAMPDIFF(MINUTE, placed_at, picked_up_at)) AS lead,
                AVG(TIMESTAMPDIFF(MINUTE, picked_up_at, delivered_at)) AS trip
            FROM (SELECT placed_at, picked_up_at, delivered_at FROM orders
                  WHERE status='delivered' AND picked_up_at IS NOT NULL AND delivered_at IS NOT NULL
                    AND TIMESTAMPDIFF(MINUTE, placed_at, picked_up_at) BETWEEN 0 AND 120
                    AND TIMESTAMPDIFF(MINUTE, picked_up_at, delivered_at) BETWEEN 1 AND 120
                  ORDER BY id DESC LIMIT 200) t")->fetch();
        if ($r && intval($r['n']) >= ETEL_ETA_MIN_SAMPLES) {
            $out['lead_min'] = floatval($r['lead']); $out['trip_min'] = floatval($r['trip']); $out['n'] = intval($r['n']);
        }
    } catch (Exception $e) { error_log('[ETEL] eta typical: ' . $e->getMessage()); }
    return $out;
}

// Adds 'eta' and 'courier_stale' to a track_order row and hides a courier position that is no longer fresh.
function etel_track_eta_apply($pdo, $order) {
    $order['eta'] = null; $order['courier_stale'] = false;
    try {
        $hasCourier = !empty($order['courier_name']);
        $seen = isset($order['courier_seen_sec']) && $order['courier_seen_sec'] !== null ? intval($order['courier_seen_sec']) : null;
        $hasPos = !empty($order['courier_lat']) && !empty($order['courier_lng']);
        $fresh = $hasPos && $seen !== null && $seen <= ETEL_ETA_FRESH_SEC;
        $st = isset($order['status']) ? $order['status'] : '';
        $active = ($st !== 'delivered' && $st !== 'cancelled');
        if ($hasCourier && $hasPos && !$fresh && $active) {
            $order['courier_stale'] = true;
            $order['courier_lat'] = null; $order['courier_lng'] = null;
        }
        $ages = array('age_sec' => null, 'pickup_age_sec' => null);
        try {
            $q = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, placed_at, NOW()) AS a, TIMESTAMPDIFF(SECOND, picked_up_at, NOW()) AS p FROM orders WHERE id=?");
            $q->execute(array($order['id']));
            $r = $q->fetch();
            if ($r) { $ages['age_sec'] = ($r['a'] !== null) ? intval($r['a']) : null; $ages['pickup_age_sec'] = ($r['p'] !== null) ? intval($r['p']) : null; }
        } catch (Exception $e) { /* picked_up_at column not there yet: typical estimate still works from placed_at */ }
        $km = null;
        if ($fresh && !empty($order['delivery_lat']) && !empty($order['delivery_lng'])) {
            $km = etel_eta_km(floatval($order['courier_lat']), floatval($order['courier_lng']), floatval($order['delivery_lat']), floatval($order['delivery_lng']));
        }
        $typ = $active ? etel_eta_typical($pdo) : array('lead_min' => null, 'trip_min' => null);
        $order['eta'] = etel_eta_estimate(array(
            'status' => $st, 'age_sec' => $ages['age_sec'], 'pickup_age_sec' => $ages['pickup_age_sec'],
            'km' => $km, 'fresh' => $fresh, 'lead_min' => $typ['lead_min'], 'trip_min' => $typ['trip_min']));
    } catch (Exception $e) { error_log('[ETEL] track eta: ' . $e->getMessage()); }
    return $order;
}
