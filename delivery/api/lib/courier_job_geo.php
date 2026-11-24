<?php
/**
 * Where a job picks up and drops off, and how far each is, so the courier sees both distances BEFORE accepting.
 * Pickup = the shop's map pin (or the package pickup pin). Dropoff = the customer's pin. Km are road km (straight line x road factor).
 * Pure helpers here; the action is in controllers/CourierJobGeoController.php. Never shows the customer's name or phone.
 */
require_once __DIR__ . '/courier_pay.php';

function etel_jobgeo_pt($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) return null;
    $a = floatval($lat); $b = floatval($lng);
    if (($a == 0 && $b == 0) || abs($a) > 90 || abs($b) > 180) return null;
    return array('lat' => round($a, 6), 'lng' => round($b, 6));
}

/** Road km between two points (each array('lat','lng') or null). Null when a point is unknown. */
function etel_jobgeo_road_km($p, $q, $factor) {
    if (!$p || !$q) return null;
    return round(etel_cpay_km($p['lat'], $p['lng'], $q['lat'], $q['lng']) * $factor, 1);
}

/** One entry per order id: pickup, drop, pickup_km (courier -> pickup), drop_km (pickup -> dropoff). */
function etel_jobgeo_for_orders($pdo, $ids, $me) {
    $out = array();
    if (!$ids) return $out;
    $factor = 1.3;
    try { $s = etel_cpay_settings($pdo); if (!empty($s['road_factor'])) $factor = floatval($s['road_factor']); } catch (Exception $e) {}
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT o.id, o.delivery_lat, o.delivery_lng, o.pickup_lat, o.pickup_lng, o.pickup_address, o.to_zone, o.from_zone,
                               (SELECT sg.lat FROM order_assignments oa JOIN service_givers sg ON sg.id = oa.service_giver_id WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS sg_lat,
                               (SELECT sg.lng FROM order_assignments oa JOIN service_givers sg ON sg.id = oa.service_giver_id WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS sg_lng,
                               (SELECT sg.business_name FROM order_assignments oa JOIN service_givers sg ON sg.id = oa.service_giver_id WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS sg_name,
                               (SELECT sg.address FROM order_assignments oa JOIN service_givers sg ON sg.id = oa.service_giver_id WHERE oa.order_id = o.id ORDER BY oa.id DESC LIMIT 1) AS sg_address
                        FROM orders o WHERE o.id IN ($ph)");
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pick = etel_jobgeo_pt($r['pickup_lat'], $r['pickup_lng']);
        if (!$pick) $pick = etel_jobgeo_pt($r['sg_lat'], $r['sg_lng']);
        $drop = etel_jobgeo_pt($r['delivery_lat'], $r['delivery_lng']);
        $label = $r['sg_name'] ? $r['sg_name'] : ($r['pickup_address'] ? $r['pickup_address'] : $r['from_zone']);
        $out[intval($r['id'])] = array(
            'pickup' => $pick, 'drop' => $drop,
            'pickup_label' => (string) $label, 'pickup_address' => (string) ($r['sg_address'] ? $r['sg_address'] : $r['pickup_address']),
            'drop_label' => (string) $r['to_zone'],
            'pickup_km' => etel_jobgeo_road_km($me, $pick, $factor),
            'drop_km' => etel_jobgeo_road_km($pick, $drop, $factor),
        );
    }
    return $out;
}
