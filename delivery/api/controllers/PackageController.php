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
//   admin_package_setting_save { key:'late_cancel_fee', value }  -> small settings, e.g. the cancel fee (orders.manage)
//   courier_package_pickup / courier_package_deliver { order_id, pin } -> the courier's PIN steps (courier)
//   admin_package_type_add    { label, icon, size_like, courier_names, base_fee, ... }
//                                                       -> add a vehicle type of our own, e.g. a Bajaj (orders.manage)
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
    // Every EtelID is also a customer (and a rider): a driver, courier or shop owner books from the same account.
    if (!in_array($u['role'], array('customer', 'driver', 'courier', 'service_giver', 'admin'), true)) fail('Sign in to book a package', 403);
    $body['_allow_chapa'] = true;   // the checkout follows up with chapa_init, like shop orders
    $r = etel_package_create($pdo, $u, $body);
    if (!$r['ok']) fail($r['message'], 400);
    ok($r);
    break;
}

case 'admin_package_rates_get': {
    $pdo = db();
    require_admin($pdo, 'orders.view');
    ok(array('rates' => etel_package_rates($pdo, false), 'settings' => etel_package_settings($pdo)));
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

case 'admin_package_type_add': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $r = etel_package_add_type($pdo, $body);
    if (!$r['ok']) fail($r['message'], 400);
    ok(array('message' => $r['message'], 'vehicle_class' => $r['vehicle_class'], 'rates' => etel_package_rates($pdo, false)));
    break;
}

case 'admin_package_setting_save': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $key = isset($body['key']) ? strtolower(trim((string) $body['key'])) : '';
    $r = etel_package_save_setting($pdo, $key, isset($body['value']) ? $body['value'] : '');
    if (!$r['ok']) fail($r['message'], 400);
    ok(array('message' => $r['message'], 'settings' => etel_package_settings($pdo)));
    break;
}

case 'courier_package_pickup':
case 'courier_package_deliver': {
    // POST order_id, pin -> the courier enters the sender's pickup PIN (pickup) or the recipient's delivery PIN (deliver).
    $pdo = db();
    $courier_user = require_courier($pdo);
    $courier = get_or_create_courier_profile($pdo, $courier_user['id']);
    if (!$courier) fail('Courier profile could not be created. Contact admin.', 500);
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $pin = isset($body['pin']) ? (string) $body['pin'] : '';
    if (!$order_id) fail('order_id is required', 400);
    if (!preg_match('/^\d{4}$/', preg_replace('/\s+/', '', $pin))) fail('Enter the 4-digit PIN.', 400);
    $r = etel_package_courier_step($pdo, $courier['id'], $order_id, $action === 'courier_package_pickup' ? 'pickup' : 'delivery', $pin);
    if (!$r['ok']) fail($r['message'], 400);
    ok(array('message' => $r['message'], 'status' => $r['status'], 'order_id' => $order_id));
    break;
}

default:
    fail('Unknown action: ' . $action, 404);
}
