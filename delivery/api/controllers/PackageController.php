<?php
// ============================================================
// ETEL Delivery - package delivery (send a parcel from A to B). See api/lib/package_orders.php.
//   customer_package_options  {}                        -> vehicles (with limits), package sizes, the prohibited-items notice
//   customer_package_quote    { pickup{}, drop{}, size, weight_kg }
//                                                       -> distance, time and a price for each vehicle that can carry it
//   customer_package_create   { pickup{}, drop{}, size, weight_kg, vehicle_class, description, recipient_name, recipient_phone,
//                               declared_value?, sender_name?, sender_phone?, notes?, payment_method?, ack_prohibited }
//                                                       -> books the package; only a courier with that vehicle gets the job
//   admin_package_rates_get   {}                        -> every vehicle's price rules (orders.view)
//   admin_package_rates_save  { vehicle_class, label, icon, base_fee, per_km, free_kg, per_kg_over, min_fee, max_weight_kg, active }
//                                                       -> change one vehicle's price rules (orders.manage)
// pickup{} / drop{}: { address, lat?, lng?, zone? } - a map point, or a known zone name when there is no map.
// ============================================================
require_once __DIR__ . '/../lib/package_orders.php';

switch ($action) {

case 'customer_package_options': {
    $pdo = db();
    require_auth($pdo);
    $vehicles = array();
    foreach (etel_package_rates($pdo, true) as $r) {
        $vehicles[] = array('vehicle_class' => $r['vehicle_class'], 'label' => $r['label'], 'icon' => $r['icon'], 'max_weight_kg' => floatval($r['max_weight_kg']));
    }
    $sizes = array();
    foreach (etel_package_sizes() as $k => $v) $sizes[] = array('size' => $k, 'label' => $v['label'], 'min_class' => $v['min_class']);
    ok(array('vehicles' => $vehicles, 'sizes' => $sizes,
        'prohibited' => 'No weapons, drugs, alcohol, live animals, cash or valuables (jewellery, phones, documents of value are sent at your own risk), flammable or dangerous goods.'));
    break;
}

case 'customer_package_quote': {
    $pdo = db();
    require_auth($pdo);
    $q = etel_package_quote($pdo, isset($body['pickup']) ? $body['pickup'] : null, isset($body['drop']) ? $body['drop'] : null, $body);
    if (!$q['ok']) fail($q['message'], 400);
    ok(array('distance_km' => $q['distance_km'], 'eta_min' => $q['eta_min'], 'options' => $q['options'],
        'pickup_zone' => $q['pickup']['zone'], 'drop_zone' => $q['drop']['zone']));
    break;
}

case 'customer_package_create': {
    $pdo = db();
    $u = require_auth($pdo);
    if ($u['role'] !== 'customer' && $u['role'] !== 'admin') fail('Customer access required', 403);
    $body['_allow_chapa'] = true;   // the checkout follows up with chapa_init, like shop orders
    $r = etel_package_create($pdo, $u, $body);
    if (!$r['ok']) fail($r['message'], 400);
    ok($r);
    break;
}

case 'admin_package_rates_get': {
    $pdo = db();
    require_admin($pdo, 'orders.view');
    ok(array('rates' => etel_package_rates($pdo, false)));
    break;
}

case 'admin_package_rates_save': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $class = isset($body['vehicle_class']) ? strtolower(trim((string) $body['vehicle_class'])) : '';
    $r = etel_package_save_rate($pdo, $class, $body);
    if (!$r['ok']) fail($r['message'], 400);
    ok(array('message' => $r['message'], 'rates' => etel_package_rates($pdo, false)));
    break;
}

default:
    fail('Unknown action: ' . $action, 404);
}
