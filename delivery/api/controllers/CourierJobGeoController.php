<?php
// ============================================================
// ETEL Delivery - courier_job_geo  { order_ids: [..], lat, lng }  -> { jobs: { id: { pickup, drop, pickup_km, drop_km, labels } } }
// For the jobs list: both distances (me -> pickup, pickup -> dropoff) and the two map points for the Navigate buttons. Courier login required.
// Only jobs the courier may see (not taken, not finished); never the customer's name or phone.
// ============================================================
require_once __DIR__ . '/../lib/courier_job_geo.php';

switch ($action) {
case 'courier_job_geo': {
    $pdo = db(); $courier_user = require_courier($pdo);
    $ids = array();
    if (isset($body['order_ids']) && is_array($body['order_ids'])) { foreach ($body['order_ids'] as $v) { $i = intval($v); if ($i > 0) $ids[$i] = $i; } }
    $ids = array_slice(array_values($ids), 0, 50);
    $me = etel_jobgeo_pt(isset($body['lat']) ? $body['lat'] : null, isset($body['lng']) ? $body['lng'] : null);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $c = $pdo->prepare("SELECT id FROM orders WHERE id IN ($ph) AND (courier_id IS NULL OR courier_id = 0 OR courier_id = ?) AND status NOT IN ('cancelled','delivered')");
        $args = $ids; $my = $pdo->prepare('SELECT id FROM couriers WHERE user_id = ?'); $my->execute(array($courier_user['id'])); $args[] = intval($my->fetchColumn());
        $c->execute($args); $ids = array_map('intval', $c->fetchAll(PDO::FETCH_COLUMN));
    }
    ok(array('jobs' => (object) etel_jobgeo_for_orders($pdo, $ids, $me)));
    break;
}
}
