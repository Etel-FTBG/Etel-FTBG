<?php
// Package delivery (api/lib/package_orders.php): pricing, which vehicles can carry what, booking, who gets the job, cancel rules.
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'package-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/courier_offers.php';
require_once __DIR__ . '/../api/lib/courier_stale.php';
require_once __DIR__ . '/../api/lib/order_cancel.php';
require_once __DIR__ . '/../api/lib/money_ledger.php';
require_once __DIR__ . '/../api/lib/package_orders.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "money and distance (pure)\n";
$moto = array('base_fee' => 60, 'per_km' => 12, 'free_kg' => 5, 'per_kg_over' => 6, 'min_fee' => 80, 'max_weight_kg' => 25);
$bike = array('base_fee' => 40, 'per_km' => 8, 'free_kg' => 3, 'per_kg_over' => 5, 'min_fee' => 50, 'max_weight_kg' => 5);
check('base + per km', etel_package_price($moto, 5, 3) === 120);
check('extra weight above the free kg is charged', etel_package_price($moto, 5, 10) === 150);
check('never below the minimum fee', etel_package_price($bike, 1, 1) === 50);
check('rounded to whole birr', etel_package_price(array('base_fee' => 10, 'per_km' => 3.3, 'free_kg' => 0, 'per_kg_over' => 0, 'min_fee' => 0), 3, 0) === 20);
check('road distance is the straight line x 1.3, at least 1 km', etel_package_distance_km(7.05, 38.47, 7.05, 38.47) === 1.0 && etel_package_distance_km(7.0, 38.0, 7.1, 38.0) > 14 && etel_package_distance_km(7.0, 38.0, 7.1, 38.0) < 15);
check('time = 10 minutes handling + 20 km/h', etel_package_eta_min(10) === 40 && etel_package_eta_min(0) === 10);
check('courier vehicle names map to a class', etel_vehicle_class_of('Motorcycle') === 'moto' && etel_vehicle_class_of('') === 'moto' && etel_vehicle_class_of(null) === 'moto'
    && etel_vehicle_class_of('bicycle') === 'bike' && etel_vehicle_class_of('on_foot') === 'bike' && etel_vehicle_class_of('Car') === 'car' && etel_vehicle_class_of('PICKUP') === 'van');
check('an unknown vehicle gets no package jobs', etel_vehicle_class_of('retail') === '');
$vs = etel_vehicle_sql('moto', 'c'); $vc = etel_vehicle_sql('car', 'c'); $vx = etel_vehicle_sql('boat', 'c');
check('the vehicle filter lists the right names, moto also takes an empty vehicle', $vs[0] !== '' && in_array('motorcycle', $vs[1], true) && strpos($vs[0], "= ''") !== false && in_array('car', $vc[1], true) && strpos($vc[0], "= ''") === false && strpos($vx[0], '1 = 0') !== false);

echo "places and details (pure)\n";
$p = etel_package_point(array('address' => 'Piassa', 'lat' => 7.06, 'lng' => 38.48), 'pickup');
check('a map point is used and gets its nearest zone', is_array($p) && $p['zone'] !== '' && abs($p['lat'] - 7.06) < 0.0001);
$p = etel_package_point(array('address' => 'Near the stadium', 'zone' => 'Stadium'), 'pickup');
check('a zone name works when there is no map point', is_array($p) && $p['zone'] === 'Stadium' && $p['lat'] > 7);
check('no address is refused', is_string(etel_package_point(array('lat' => 7.06, 'lng' => 38.48), 'pickup')));
check('no point and no zone is refused', is_string(etel_package_point(array('address' => 'x'), 'drop-off')));
check('a point outside Ethiopia with no zone is refused', is_string(etel_package_point(array('address' => 'x', 'lat' => 51.5, 'lng' => 0.1), 'pickup')));
check('an unknown zone is refused', is_string(etel_package_point(array('address' => 'x', 'zone' => 'Atlantis'), 'pickup')));
check('details: size and weight are required and sane', is_string(etel_package_details(array('size' => 'huge', 'weight_kg' => 2)))
    && is_string(etel_package_details(array('size' => 'small'))) && is_string(etel_package_details(array('size' => 'small', 'weight_kg' => 0)))
    && is_string(etel_package_details(array('size' => 'small', 'weight_kg' => 5000))) && is_array(etel_package_details(array('size' => 'Small', 'weight_kg' => '2.5'))));

echo "cancel rule for packages (pure)\n";
check('a customer may cancel a package until the courier has it', etel_cancel_rule('customer', 'assigned', 'assigned', 'package') === '' && etel_cancel_rule('customer', 'pending', 'pending', 'package') === '');
check('but not once it is picked up', etel_cancel_rule('customer', 'picked_up', 'picked_up', 'package') !== '');
check('a shop order in the same state is still refused', etel_cancel_rule('customer', 'assigned', 'assigned', 'shop') !== '');

echo "rates, quotes and booking (real database)\n";
etel_package_ensure_schema($pdo);
foreach (etel_package_default_rates() as $r) {
    $pdo->prepare('UPDATE package_rates SET label=?, icon=?, base_fee=?, per_km=?, free_kg=?, per_kg_over=?, min_fee=?, max_weight_kg=?, sort_order=?, active=1 WHERE vehicle_class=?')->execute(array($r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9], $r[0]));
}
// Other smoke tests that ran before this one may have added vehicle types (the Bajaj / Qute type is added the first time Admin or the cart lists vehicles).
// This test is about the four built-in vehicles: switch any other type off for the run.
$pdo->exec("UPDATE package_rates SET active = 0 WHERE vehicle_class NOT IN ('bike','moto','car','van')");
check('the four starting rates exist', count(etel_package_rates($pdo, true)) === 4);
$A = array('address' => 'Tabor, Hawassa', 'zone' => 'Tabor'); $B = array('address' => 'University gate', 'zone' => 'University');
$opts = function ($size, $kg) use ($pdo, $A, $B) { $q = etel_package_quote($pdo, $A, $B, array('size' => $size, 'weight_kg' => $kg)); if (!$q['ok']) return $q['message']; $o = array(); foreach ($q['options'] as $x) $o[] = $x['vehicle_class']; return $o; };
check('a 2 kg small package: every vehicle', $opts('small', 2) === array('bike', 'moto', 'car', 'van'));
check('a medium package: not a bicycle', $opts('medium', 3) === array('moto', 'car', 'van'));
check('a large package: car or van only', $opts('large', 10) === array('car', 'van'));
check('8 kg is too heavy for a bicycle even if small', $opts('small', 8) === array('moto', 'car', 'van'));
check('300 kg: only the van', $opts('small', 300) === array('van'));
check('2000 kg: nothing can carry it', is_string($opts('small', 999)) === false && is_string(etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 1000.5))['message']));
$far = etel_package_quote($pdo, $A, array('address' => 'Addis Ababa', 'lat' => 9.03, 'lng' => 38.74), array('size' => 'small', 'weight_kg' => 1));
check('a trip over 60 km is refused with the distance', $far['ok'] === false && strpos($far['message'], '60 km') !== false);
etel_package_save_setting($pdo, 'tier_on', 0);   // the next checks are about each vehicle's own rates (the tiered price is tested at the end)
$q = etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 2));
check('each option has a price from the rates table', $q['ok'] && $q['options'][1]['vehicle_class'] === 'moto' && $q['options'][1]['price'] === etel_package_price(array('base_fee' => 60, 'per_km' => 12, 'free_kg' => 5, 'per_kg_over' => 6, 'min_fee' => 80), $q['distance_km'], 2));
check('bigger vehicles cost more', $q['options'][0]['price'] < $q['options'][1]['price'] && $q['options'][1]['price'] < $q['options'][2]['price'] && $q['options'][2]['price'] < $q['options'][3]['price']);

$pdo->exec("DELETE FROM order_assignments WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'ETP-%')");
$pdo->exec("DELETE FROM orders WHERE order_kind = 'package' AND recipient_name LIKE 'PKG Test%'");
$pdo->exec("DELETE FROM couriers WHERE courier_code IN ('PKGC1','PKGC2','PKGC3')");
$pdo->exec("DELETE FROM users WHERE phone LIKE '+25199850000%'");
$mkUser = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role) VALUES (?, ?, 'x', ?)");
$ids = array();
foreach (array(array('Sender Sam', '+251998500000', 'customer'), array('Cust Two', '+251998500001', 'customer'), array('Moto Mo', '+251998500002', 'courier'), array('Car Cal', '+251998500003', 'courier'), array('Shop Sh', '+251998500004', 'service_giver')) as $i => $u) {
    $mkUser->execute($u); $ids[$i] = (int) $pdo->lastInsertId();
}
$mkC = $pdo->prepare("INSERT INTO couriers (user_id, courier_code, vehicle_type, zone, status, active, last_seen) VALUES (?, ?, ?, 'Tabor', ?, 1, NOW())");
$mkC->execute(array($ids[2], 'PKGC1', 'motorcycle', 'online')); $cMoto = (int) $pdo->lastInsertId();
$mkC->execute(array($ids[3], 'PKGC2', 'car', 'online')); $cCar = (int) $pdo->lastInsertId();
$pdo->exec("UPDATE couriers SET status='offline' WHERE id NOT IN ($cMoto, $cCar)");   // nobody else may grab the jobs below
$user = array('id' => $ids[0], 'name' => 'Sender Sam', 'phone' => '+251998500000', 'role' => 'customer');
$base = array('pickup' => $A, 'drop' => $B, 'size' => 'small', 'weight_kg' => 2, 'vehicle_class' => 'car', 'description' => 'Books', 'recipient_name' => 'PKG Test Rita', 'recipient_phone' => '0911223344', 'ack_prohibited' => true);
$row = function ($id) use ($pdo) { return $pdo->query("SELECT * FROM orders WHERE id=$id")->fetch(); };

$rn = etel_package_create($pdo, $user, $base);
check('no courier whose app can run packages: the package waits instead of going to an old app', $rn['ok'] === true && $row($rn['order_id'])['courier_id'] === null && $row($rn['order_id'])['status'] === 'pending');
$pdo->exec("UPDATE orders SET status='cancelled' WHERE id=" . $rn['order_id']);
$pdo->exec("UPDATE couriers SET package_capable_until = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id IN ($cMoto, $cCar)");   // their apps send pkg=1
$r = etel_package_create($pdo, $user, $base);
$o = $r['ok'] ? $row($r['order_id']) : array();
check('a package is booked', $r['ok'] === true && strpos($r['order_code'], 'ETP-') === 0 && $o['order_kind'] === 'package');
check('the price is the server price for that vehicle', $r['total'] === $q['options'][2]['price'] && intval($o['fare_total']) === $r['total'] && intval($o['delivery_fee']) === $r['total'] && floatval($o['subtotal']) == 0);
check('pickup, drop-off, details and sender are saved', $o['from_zone'] === 'Tabor' && $o['to_zone'] === 'University' && $o['pickup_address'] === 'Tabor, Hawassa' && $o['recipient_address'] === 'University gate'
    && $o['vehicle_class'] === 'car' && $o['pkg_size'] === 'small' && floatval($o['pkg_weight_kg']) == 2.0 && $o['pkg_desc'] === 'Books' && $o['sender_name'] === 'Sender Sam' && $o['sender_phone'] === '+251998500000');
check('the recipient number is kept as it was typed once it passes the phone check', $o['recipient_phone'] === '0911223344');
check('two 4-digit pins are made', preg_match('/^\d{4}$/', $o['pickup_pin']) === 1 && preg_match('/^\d{4}$/', $o['delivery_pin']) === 1);
check('no assignment row is made: that table points at a shop (a foreign key on the live database) and a package has none', (int) $pdo->query("SELECT COUNT(*) FROM order_assignments WHERE order_id=" . $r['order_id'])->fetchColumn() === 0);
check('package_type holds a word the live ENUM(document, parcel, food, bulk) accepts; the real size is in pkg_size', $o['package_type'] === 'parcel' && $o['pkg_size'] === 'small');
$docr = etel_package_create($pdo, $user, array_merge($base, array('size' => 'document', 'vehicle_class' => 'van', 'weight_kg' => 1)));
check('a document is stored as document', $docr['ok'] && $row($docr['order_id'])['package_type'] === 'document');
check('a package is not handed to one courier: it stays open (pending, nobody assigned)', $o['courier_id'] === null && $o['status'] === 'pending');
$notified = function ($orderId, $userId) use ($pdo) { $q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'new_order' AND data LIKE ?"); $q->execute(array($userId, '%"order_id":' . intval($orderId) . ',%')); return (int) $q->fetchColumn(); };
check('the car courier is told about the car package, the motorbike courier is not', $notified($r['order_id'], $ids[3]) === 1 && $notified($r['order_id'], $ids[2]) === 0);
check('first courier to claim it gets it', etel_claim_order($pdo, $r['order_id'], $cCar) === true && intval($row($r['order_id'])['courier_id']) === $cCar && $row($r['order_id'])['status'] === 'assigned');
check('a second courier cannot take it any more', etel_claim_order($pdo, $r['order_id'], $cMoto) === false && intval($row($r['order_id'])['courier_id']) === $cCar);
$pdo->exec("UPDATE orders SET courier_id = NULL, status = 'pending' WHERE id = " . $r['order_id']);   // back to open for the checks below

$moto2 = $base; $moto2['vehicle_class'] = 'moto';
$r2 = etel_package_create($pdo, $user, $moto2); $o2 = $row($r2['order_id']);
check('a motorbike package is shown to the motorbike courier, still open', $o2['courier_id'] === null && $o2['status'] === 'pending' && $notified($r2['order_id'], $ids[2]) === 1 && $notified($r2['order_id'], $ids[3]) === 0);
$pdo->exec("UPDATE orders SET courier_id = $cMoto, status = 'assigned' WHERE id = " . $r2['order_id']);   // the motorbike courier took it, as before
$van = $base; $van['vehicle_class'] = 'van';
$r3 = etel_package_create($pdo, $user, $van); $o3 = $row($r3['order_id']);
check('a van package with no van courier online waits, unassigned', $r3['ok'] && $o3['courier_id'] === null && $o3['status'] === 'pending');
$pdo->prepare("UPDATE couriers SET status='online' WHERE id=?")->execute(array($cMoto));
$pdo->exec("UPDATE orders SET status='delivered' WHERE id=" . $r2['order_id']);
$bike = $base; $bike['vehicle_class'] = 'bike';
$r4 = etel_package_create($pdo, $user, $bike); $o4 = $row($r4['order_id']);
check('a bicycle package is not given to a motorbike courier', $r4['ok'] && $o4['courier_id'] === null);

check('refused without the prohibited-items tick', etel_package_create($pdo, $user, array_merge($base, array('ack_prohibited' => false)))['ok'] === false);
check('refused when the vehicle cannot carry it', etel_package_create($pdo, $user, array_merge($base, array('vehicle_class' => 'bike', 'weight_kg' => 8)))['ok'] === false);
check('refused when no vehicle is chosen', etel_package_create($pdo, $user, array_merge($base, array('vehicle_class' => '')))['ok'] === false);
check('refused without a description', etel_package_create($pdo, $user, array_merge($base, array('description' => ' ')))['ok'] === false);
check('refused with a bad recipient phone', etel_package_create($pdo, $user, array_merge($base, array('recipient_phone' => '123')))['ok'] === false);
check('a client price is ignored', etel_package_create($pdo, $user, array_merge($base, array('price' => 1, 'total' => 1, 'vehicle_class' => 'moto')))['total'] !== 1);

echo "changing prices in Admin\n";
$s = etel_package_save_rate($pdo, 'moto', array('label' => 'Motorbike', 'icon' => 'M', 'base_fee' => 100, 'per_km' => 12, 'free_kg' => 5, 'per_kg_over' => 6, 'min_fee' => 80, 'max_weight_kg' => 25, 'active' => 1));
$q2 = etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 2));
check('a saved rate changes the next quote', $s['ok'] === true && $q2['options'][1]['price'] === $q['options'][1]['price'] + 40);
check('a negative or missing number is refused', etel_package_save_rate($pdo, 'moto', array('label' => 'x', 'base_fee' => -1, 'per_km' => 1, 'free_kg' => 0, 'per_kg_over' => 0, 'min_fee' => 0, 'max_weight_kg' => 5))['ok'] === false
    && etel_package_save_rate($pdo, 'moto', array('label' => 'x', 'per_km' => 1))['ok'] === false);
check('an unknown vehicle or a zero max weight is refused', etel_package_save_rate($pdo, 'boat', array())['ok'] === false
    && etel_package_save_rate($pdo, 'moto', array('label' => 'x', 'base_fee' => 1, 'per_km' => 1, 'free_kg' => 0, 'per_kg_over' => 0, 'min_fee' => 0, 'max_weight_kg' => 0))['ok'] === false);
etel_package_save_rate($pdo, 'van', array('label' => 'Van / pickup', 'icon' => 'V', 'base_fee' => 350, 'per_km' => 45, 'free_kg' => 100, 'per_kg_over' => 3, 'min_fee' => 450, 'max_weight_kg' => 1000, 'active' => 0));
check('switching a vehicle off removes it from the options', etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 2))['options'][2]['vehicle_class'] === 'car' && count(etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 2))['options']) === 3);
check('but staff still see it in their list', count(array_filter(etel_package_rates($pdo, false), function ($r) { return in_array($r['vehicle_class'], array('bike', 'moto', 'car', 'van'), true); })) === 4);

echo "adding a vehicle type of our own (Admin -> Add)\n";
$pdo->exec("DELETE FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')"); etel_package_custom_defs(null, true);
$base = array('label' => 'Bajaj', 'icon' => 'B', 'size_like' => 'moto', 'courier_names' => 'Bajaj, Tuk-Tuk', 'base_fee' => 90, 'per_km' => 15, 'free_kg' => 10, 'per_kg_over' => 4, 'min_fee' => 120, 'max_weight_kg' => 200, 'active' => 1);
$add = etel_package_add_type($pdo, $base);
check('a new type is added with a short key', $add['ok'] === true && $add['vehicle_class'] === 'x1');
check('it counts as a class, sitting at the size of a motorbike', etel_package_classes($pdo)['x1'] === 2);
check('couriers saying bajaj or tuk-tuk (any case) map to it', etel_vehicle_class_of('BAJAJ', $pdo) === 'x1' && etel_vehicle_class_of('tuk-tuk', $pdo) === 'x1' && etel_vehicle_class_of('car', $pdo) === 'car');
$vx1 = etel_vehicle_sql('x1', 'c', $pdo); $vmo = etel_vehicle_sql('moto', 'c', $pdo);
check('its job filter lists only its own words, and does not take the empty-vehicle default', in_array('bajaj', $vx1[1], true) && in_array('tuk-tuk', $vx1[1], true) && strpos($vx1[0], "= ''") === false && !in_array('bajaj', $vmo[1], true));
$qx = etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 2));
$keys = array(); foreach ($qx['options'] as $o) $keys[] = $o['vehicle_class'];
check('customers are offered it with its own price, in size order', in_array('x1', $keys, true) && array_search('x1', $keys) > array_search('bike', $keys));
$p1 = 0; foreach ($qx['options'] as $o) { if ($o['vehicle_class'] === 'x1') $p1 = $o['price']; }
check('price uses its own rates (90 + 15/km, min 120)', $p1 >= 120 && $p1 === etel_package_price(array('base_fee' => 90, 'per_km' => 15, 'free_kg' => 10, 'per_kg_over' => 4, 'min_fee' => 120), $qx['distance_km'], 2));
$ks = function ($q) { return empty($q['options']) ? array() : array_map(function ($o) { return $o['vehicle_class']; }, $q['options']); };
check('a parcel over its limit does not offer it (200 kg is fine, 201 kg is not)', in_array('x1', $ks(etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 200))), true) && !in_array('x1', $ks(etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 201))), true));
check('a large parcel (needs a car) does not offer a motorbike-size type', !in_array('x1', $ks(etel_package_quote($pdo, $A, $B, array('size' => 'large', 'weight_kg' => 5))), true));
check('a word that already means another vehicle is refused', etel_package_add_type($pdo, array_merge($base, array('label' => 'Truck2', 'courier_names' => 'truck')))['ok'] === false
    && etel_package_add_type($pdo, array_merge($base, array('label' => 'Again', 'courier_names' => 'bajaj')))['ok'] === false);
check('no words, bad words, no size are refused', etel_package_add_type($pdo, array_merge($base, array('courier_names' => '')))['ok'] === false
    && etel_package_add_type($pdo, array_merge($base, array('courier_names' => 'a')))['ok'] === false
    && etel_package_add_type($pdo, array_merge($base, array('courier_names' => 'x<y', 'label' => 'Q')))['ok'] === false
    && etel_package_add_type($pdo, array_merge($base, array('size_like' => 'boat', 'courier_names' => 'boat')))['ok'] === false);
check('a missing or bad price is refused', etel_package_add_type($pdo, array_merge($base, array('courier_names' => 'rickshaw', 'base_fee' => -5)))['ok'] === false);
$ed = etel_package_save_rate($pdo, 'x1', array_merge($base, array('label' => 'Bajaj Plus', 'size_like' => 'car', 'courier_names' => 'bajaj, auto', 'per_km' => 20)));
check('an added type can be edited: new size and words', $ed['ok'] === true && etel_package_classes($pdo)['x1'] === 3 && etel_vehicle_class_of('auto', $pdo) === 'x1' && etel_vehicle_class_of('tuk-tuk', $pdo) === '');
check('built-in vehicles ignore size and words if sent', etel_package_save_rate($pdo, 'moto', array('label' => 'Motorbike', 'icon' => 'M', 'base_fee' => 100, 'per_km' => 12, 'free_kg' => 5, 'per_kg_over' => 6, 'min_fee' => 80, 'max_weight_kg' => 25, 'active' => 1, 'size_like' => 'van', 'courier_names' => 'x1word'))['ok'] === true
    && etel_package_classes($pdo)['moto'] === 2 && etel_vehicle_class_of('x1word', $pdo) === '');
$bk = etel_package_add_type($pdo, array_merge($base, array('label' => 'Mini truck', 'size_like' => 'van', 'courier_names' => 'mini truck')));
check('a second type gets the next key and its words work with a space', $bk['ok'] === true && $bk['vehicle_class'] === 'x2' && etel_vehicle_class_of('Mini Truck', $pdo) === 'x2');
$pdo->exec("DELETE FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')"); etel_package_custom_defs(null, true);
check('after removal the words mean nothing again', etel_vehicle_class_of('bajaj', $pdo) === '');

echo "cancelling a package\n";
$pdo->exec("UPDATE orders SET status='assigned', courier_id=$cCar WHERE id=" . $r['order_id']);   // a courier took it
$pdo->exec("UPDATE order_assignments SET status='assigned' WHERE order_id=" . $r['order_id']);
$pdo->exec("UPDATE couriers SET status='busy' WHERE id=$cCar");
check('another customer cannot cancel it', etel_cancel_order($pdo, $r['order_id'], 'customer', $ids[1], 'changed_mind', '', 0)['ok'] === false);
check('no shop can decline it', etel_cancel_order($pdo, $r['order_id'], 'shop', $ids[4], 'too_busy', '', 1)['ok'] === false && $row($r['order_id'])['status'] === 'assigned');
$c = etel_cancel_order($pdo, $r['order_id'], 'customer', $ids[0], 'changed_mind', '', 0);
check('the sender cancels while the courier is on the way to pick it up', $c['ok'] === true && $row($r['order_id'])['status'] === 'cancelled');
check('the courier is freed', $pdo->query("SELECT status FROM couriers WHERE id=$cCar")->fetchColumn() === 'online' && $row($r['order_id'])['courier_id'] === null);
$pdo->exec("UPDATE orders SET status='picked_up' WHERE id=" . $r3['order_id']);
check('once picked up the sender cannot cancel', etel_cancel_order($pdo, $r3['order_id'], 'customer', $ids[0], 'changed_mind', '', 0)['ok'] === false);

echo "cancel fee after a courier accepted (paid online only)\n";
require_once __DIR__ . '/../api/lib/money_ledger.php';
$oid = $r3['order_id'];
$pdo->exec("UPDATE orders SET status='assigned', courier_id=$cCar, courier_accepted_at=NOW(), payment_method='chapa', payment_status='paid', fare_total=200 WHERE id=$oid");
$pdo->exec("UPDATE order_assignments SET status='assigned' WHERE order_id=$oid");
check('default fee is ETB 30', etel_package_setting($pdo, 'late_cancel_fee') === 30.0 && etel_cancel_fee_for($pdo, $oid) === 30.0);
$pdo->exec("UPDATE orders SET payment_method='cash', payment_status='unpaid' WHERE id=$oid");
check('a cash package is never charged', etel_cancel_fee_for($pdo, $oid) === 0.0);
$pdo->exec("UPDATE orders SET payment_method='chapa', payment_status='paid', courier_accepted_at=NULL WHERE id=$oid");
check('no fee before a courier has accepted', etel_cancel_fee_for($pdo, $oid) === 0.0);
$pdo->exec("UPDATE orders SET courier_accepted_at=NOW() WHERE id=$oid");
$noConfirm = etel_cancel_order($pdo, $oid, 'customer', $ids[0], 'changed_mind', '', 0);
check('cancelling without agreeing to the fee is refused and tells the amount', $noConfirm['ok'] === false && $noConfirm['fee'] === 30.0 && strpos($noConfirm['message'], '30') !== false && $row($oid)['status'] === 'assigned');
$yes = etel_cancel_order($pdo, $oid, 'customer', $ids[0], 'changed_mind', '', 0, true);
check('agreeing cancels it and records the fee', $yes['ok'] === true && $yes['fee'] === 30.0 && floatval($pdo->query("SELECT cancel_fee FROM orders WHERE id=$oid")->fetchColumn()) === 30.0);
$rep = etel_refunds_report($pdo); $mine = null; foreach ($rep['owed'] as $x) { if ($x['order_id'] == $oid) $mine = $x; }
check('the refund owed is the total less the fee (170) and shows the fee kept', $mine && $mine['amount'] === 170.0 && $mine['fee_kept'] === 30.0);
check('a refund above that is refused', etel_refund_record($pdo, $oid, 1, 'cash', '', '', 180)['ok'] === false);
$rr = etel_refund_record($pdo, $oid, 1, 'cash', '', '');
check('the default refund is the net amount', $rr['ok'] === true && $rr['amount'] === 170.0);
$pdo->exec("UPDATE orders SET status='assigned', cancel_fee=0, payment_status='paid', courier_id=$cCar, courier_accepted_at=NOW() WHERE id=$oid");
check('with the setting back at 30 it is charged again', etel_cancel_fee_for($pdo, $oid) === 30.0);
etel_package_save_setting($pdo, 'late_cancel_fee', 0);
check('a fee of 0 means always free', etel_cancel_fee_for($pdo, $oid) === 0.0);
etel_package_save_setting($pdo, 'late_cancel_fee', 500);
check('the fee never exceeds the order total', etel_cancel_fee_for($pdo, $oid) === 200.0);
check('a bad fee is refused', etel_package_save_setting($pdo, 'late_cancel_fee', -1)['ok'] === false && etel_package_save_setting($pdo, 'late_cancel_fee', 'abc')['ok'] === false && etel_package_save_setting($pdo, 'nope', 5)['ok'] === false);
etel_package_save_setting($pdo, 'late_cancel_fee', 30);
$pdo->exec("DELETE FROM order_refunds WHERE order_id=$oid");

echo "the courier's side: app capability and the PIN steps\n";
$pdo->exec("UPDATE couriers SET package_capable_until = NULL WHERE id IN ($cMoto, $cCar)");
check('a courier whose app never sent pkg=1 cannot take packages', etel_package_courier_capable($pdo, $cCar) === false);
etel_package_mark_capable($pdo, $cCar);
check('after pkg=1 the courier counts as capable, a courier who did not is still not', etel_package_courier_capable($pdo, $cCar) === true && etel_package_courier_capable($pdo, $cMoto) === false);
check('the dispatch filter is only for couriers who are capable', strpos(etel_package_capable_sql('c'), 'package_capable_until > NOW()') !== false);
$pdo->exec("UPDATE couriers SET package_capable_until = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id = $cCar");
check('the capability expires (an app that stopped polling is not capable)', etel_package_courier_capable($pdo, $cCar) === false);
$oid = $r3['order_id'];
$pdo->exec("UPDATE orders SET status='assigned', courier_id=$cCar, courier_accepted_at=NOW(), cancel_fee=0 WHERE id=$oid");
$pins = $pdo->query("SELECT pickup_pin, delivery_pin FROM orders WHERE id=$oid")->fetch();
check('both PINs exist and are 4 digits', preg_match('/^\d{4}$/', $pins['pickup_pin']) && preg_match('/^\d{4}$/', $pins['delivery_pin']));
check('the PIN check ignores spaces and compares exactly', etel_package_pin_matches('0420', '0 4 2 0') === true && etel_package_pin_matches('0420', '420') === false && etel_package_pin_matches('0420', '0421') === false && etel_package_pin_matches('', '') === false);
check('another courier cannot use the PIN steps on it', etel_package_courier_step($pdo, $cMoto, $oid, 'pickup', $pins['pickup_pin'])['ok'] === false);
check('delivering before pickup is refused', etel_package_courier_step($pdo, $cCar, $oid, 'delivery', $pins['delivery_pin'])['ok'] === false && $row($oid)['status'] === 'assigned');
$bad = ($pins['pickup_pin'] === '0000') ? '1111' : '0000';
$w = etel_package_courier_step($pdo, $cCar, $oid, 'pickup', $bad);
check('a wrong pickup PIN is refused and the package stays put', $w['ok'] === false && $row($oid)['status'] === 'assigned');
for ($i = 0; $i < 4; $i++) etel_package_courier_step($pdo, $cCar, $oid, 'pickup', $bad);
$locked = etel_package_courier_step($pdo, $cCar, $oid, 'pickup', $pins['pickup_pin']);
check('5 wrong PINs lock the step, even the right PIN is then refused', $locked['ok'] === false && strpos($locked['message'], '15 minutes') !== false && $row($oid)['status'] === 'assigned');
$pdo->exec("UPDATE package_pin_tries SET locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE order_id=$oid AND kind='pickup'");
$ok1 = etel_package_courier_step($pdo, $cCar, $oid, 'pickup', $pins['pickup_pin']);
check('after the lock ends the right PIN picks it up', $ok1['ok'] === true && $ok1['status'] === 'picked_up' && $row($oid)['status'] === 'picked_up');
check('the wrong-PIN counter is cleared after success', (int) $pdo->query("SELECT COUNT(*) FROM package_pin_tries WHERE order_id=$oid AND kind='pickup'")->fetchColumn() === 0);
check('the pickup PIN cannot be used twice', etel_package_courier_step($pdo, $cCar, $oid, 'pickup', $pins['pickup_pin'])['ok'] === false);
if ($pins['pickup_pin'] !== $pins['delivery_pin']) check('the sender\'s pickup PIN does not deliver it', etel_package_courier_step($pdo, $cCar, $oid, 'delivery', $pins['pickup_pin'])['ok'] === false && $row($oid)['status'] === 'picked_up');
$ok2 = etel_package_courier_step($pdo, $cCar, $oid, 'delivery', $pins['delivery_pin']);
check('the recipient\'s PIN delivers it, with a delivery time', $ok2['ok'] === true && $ok2['status'] === 'delivered' && $row($oid)['status'] === 'delivered' && $pdo->query("SELECT delivered_at FROM orders WHERE id=$oid")->fetchColumn() !== null);
check('a delivered package cannot be delivered again', etel_package_courier_step($pdo, $cCar, $oid, 'delivery', $pins['delivery_pin'])['ok'] === false);

$pdo->exec("DELETE FROM order_admin_actions WHERE order_id IN (SELECT id FROM orders WHERE order_kind='package')");
$pdo->exec("DELETE FROM order_assignments WHERE order_id IN (SELECT id FROM orders WHERE order_kind='package')");
$pdo->exec("DELETE FROM orders WHERE order_kind = 'package'");
try { $pdo->exec("DELETE FROM notifications WHERE user_id IN (" . implode(',', $ids) . ")"); } catch (Exception $x) {}
$pdo->exec("DELETE FROM couriers WHERE id IN ($cMoto, $cCar)"); $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', $ids) . ")");
foreach (etel_package_default_rates() as $dr) { $pdo->prepare('UPDATE package_rates SET base_fee=?, per_km=?, free_kg=?, per_kg_over=?, min_fee=?, max_weight_kg=?, active=1 WHERE vehicle_class=?')->execute(array($dr[3], $dr[4], $dr[5], $dr[6], $dr[7], $dr[8], $dr[0])); }

echo "tiered price (distance band + weight band)\n";
$T = etel_package_setting_defaults();
check('up to 5 km is 100 ETB (light parcel)', etel_package_tier_price($T, 4.9, 1) === 100 && etel_package_tier_price($T, 5, 5) === 100);
check('up to 10 km is 200 ETB', etel_package_tier_price($T, 5.1, 1) === 200 && etel_package_tier_price($T, 10, 1) === 200);
check('up to 25 km is 300 ETB', etel_package_tier_price($T, 10.1, 1) === 300 && etel_package_tier_price($T, 25, 1) === 300);
check('each km beyond 25 adds the per-km fee', etel_package_tier_price($T, 26, 1) === 315 && etel_package_tier_price($T, 30, 1) === 375);
check('weight bands add their fee (5 kg free, 10 kg +50, 20 kg +100, 50 kg +200)', etel_package_tier_price($T, 3, 5.5) === 150 && etel_package_tier_price($T, 3, 15) === 200 && etel_package_tier_price($T, 3, 30) === 300);
check('beyond the last weight band each kg adds the per-kg fee', etel_package_tier_price($T, 3, 52) === 100 + 200 + 20);
etel_package_save_setting($pdo, 'tier_on', 1);
$qt = etel_package_quote($pdo, $A, $B, array('size' => 'small', 'weight_kg' => 2));
$pt = array(); foreach ($qt['options'] as $o) $pt[] = $o['price'];
check('a bigger vehicle costs more in a tiered quote (bicycle, motorbike, car, van)', $qt['ok'] && count($pt) === 4 && $pt[0] <= $pt[1] && $pt[1] < $pt[2] && $pt[2] < $pt[3], json_encode($pt));
check('the van costs 2.5 times the bicycle for the same distance and weight', $qt['ok'] && abs($pt[3] - round($pt[0] * 2.5)) <= 1 && in_array($pt[0], array(100, 200, 300), true), json_encode($pt));
check('the vehicle multiplier scales only the distance fee, not the weight fee', etel_package_tier_price($T, 4.9, 1, 4) === 250 && etel_package_tier_price($T, 3, 5.5, 4) === 250 + 50 && etel_package_tier_price($T, 3, 5.5, 1) === 150);
check('an unknown or missing size rank counts as 1', etel_package_tier_price($T, 4.9, 1, 0) === 100 && etel_package_tier_price($T, 4.9, 1, 9) === 100);
check('a van multiplier below the car one is refused', etel_package_save_settings($pdo, array('mult_van' => 1.2))['ok'] === false);
check('a multiplier outside 0.5 to 10 is refused', etel_package_save_settings($pdo, array('mult_van' => 11))['ok'] === false);
check('staff can change the van multiplier', etel_package_save_settings($pdo, array('mult_van' => 3))['ok'] === true && etel_package_setting($pdo, 'mult_van') == 3);
$sv = etel_package_save_settings($pdo, array('d1_fee' => 120, 'w2_fee' => 60));
check('staff can change a band price', $sv['ok'] === true && etel_package_setting($pdo, 'd1_fee') == 120);
check('bands that do not grow are refused', etel_package_save_settings($pdo, array('d1_km' => 30))['ok'] === false && etel_package_save_settings($pdo, array('d1_fee' => 400))['ok'] === false);
// Express delivery (api/lib/package_speed.php)
require_once __DIR__ . '/../api/lib/package_speed.php';
check('speed: only the word express means express', etel_package_speed_clean('express') === 'express' && etel_package_speed_clean(' EXPRESS ') === 'express' && etel_package_speed_clean('fast') === 'standard' && etel_package_speed_clean(null) === 'standard' && etel_package_speed_clean(array('express')) === 'standard');
check('express extra: 30 percent of 120 is 36', etel_package_express_extra(120, 30, 20) === 36);
check('express extra: never less than the smallest extra', etel_package_express_extra(40, 30, 20) === 20);
check('express extra: 0 percent and 0 minimum is no extra', etel_package_express_extra(120, 0, 0) === 0);
check('express time: a quarter shorter, never under 10 minutes', etel_package_express_eta_min(40) === 30 && etel_package_express_eta_min(11) === 10);
check('speed rules read the defaults (on, 30 percent, 20 ETB) and booking is open', etel_package_speed_rules($pdo) == array('on' => true, 'pct' => 30.0, 'min' => 20.0) && etel_package_booking_open($pdo) === true);
etel_package_save_settings($pdo, array('booking_on' => 0, 'express_on' => 0));
check('the admin switches express off and pauses booking', etel_package_booking_open($pdo) === false && etel_package_speed_rules($pdo)['on'] === false);
$qopts = array(array('price' => 100));
$qb = etel_package_quote_speed($pdo, $qopts, 20);
check('with express off a quote gets no express price', $qb['on'] === false && !isset($qopts[0]['express_price']));
etel_package_save_settings($pdo, array('booking_on' => 1, 'express_on' => 1));
$qb = etel_package_quote_speed($pdo, $qopts, 20);
check('with express on a quote gets the express price and time', $qb['on'] === true && $qb['eta_min'] === 15 && $qopts[0]['express_price'] === 130 && $qopts[0]['express_extra'] === 30);
check('express settings out of range are refused', etel_package_save_settings($pdo, array('express_on' => 2))['ok'] === false && etel_package_save_settings($pdo, array('express_pct' => 250))['ok'] === false);
// Public tracking link (api/lib/package_public.php)
require_once __DIR__ . '/../api/lib/package_public.php';
$tk = etel_package_track_token();
check('track token: 12 hex digits and not the same twice', preg_match('/^[0-9a-f]{12}$/', $tk) === 1 && $tk !== etel_package_track_token());
check('first name: first word only, empty stays empty', etel_package_first_name('  Abebe Kebede Alemu ') === 'Abebe' && etel_package_first_name('') === '' && etel_package_first_name(null) === '');
$pv = etel_package_public_view(array('order_code' => 'ETP-1', 'status' => 'in_transit', 'from_zone' => 'A', 'to_zone' => 'B', 'package_type' => 'document', 'pkg_speed' => 'express',
    'vehicle_class' => 'moto', 'courier_name' => 'Abebe Kebede', 'placed_at' => '2026-11-27 09:00:00', 'delivered_at' => '2026-11-27 10:00:00',
    'recipient_phone' => '0911', 'delivery_pin' => '1234', 'pickup_address' => 'secret street', 'fare_total' => 99, 'track_token' => 'aaaaaaaaaaaa'));
check('public view: the fields the page needs', $pv['order_code'] === 'ETP-1' && $pv['status'] === 'in_transit' && $pv['package_type'] === 'document' && $pv['speed'] === 'express' && $pv['courier_first_name'] === 'Abebe');
check('public view: delivered time only once delivered', $pv['delivered_at'] === '');
$pv2 = etel_package_public_view(array('order_code' => 'ETP-2', 'status' => 'delivered', 'delivered_at' => '2026-11-27 10:00:00'));
check('public view: delivered shows the time, defaults are parcel and standard', $pv2['delivered_at'] === '2026-11-27 10:00:00' && $pv2['package_type'] === 'parcel' && $pv2['speed'] === 'standard');
check('public view: never carries a phone, PIN, address, price or token', strpos(json_encode($pv), '0911') === false && strpos(json_encode($pv), '1234') === false && strpos(json_encode($pv), 'secret') === false && strpos(json_encode($pv), '99') === false && strpos(json_encode($pv), 'aaaaaaaaaaaa') === false);
check('public view: an accepted or preparing order counts as the courier being on the way', etel_package_public_view(array('order_code' => 'x', 'status' => 'accepted'))['status'] === 'assigned');
check('public lookup: a token that is not 12 hex digits is refused before any query', etel_package_public_lookup($pdo, 'ETP-1', 'nope') === null && etel_package_public_lookup($pdo, '', str_repeat('a', 12)) === null);
// My packages (api/lib/package_history.php)
require_once __DIR__ . '/../api/lib/package_history.php';
check('status group: delivered, cancelled, everything else is active', etel_package_status_group('delivered') === 'delivered' && etel_package_status_group('cancelled') === 'cancelled' && etel_package_status_group('pending') === 'active' && etel_package_status_group('in_transit') === 'active' && etel_package_status_group('assigned') === 'active');
$hr = etel_package_history_row(array('id' => '7', 'order_code' => 'ETP-1', 'status' => 'pending', 'pickup_address' => 'Piassa', 'pickup_lat' => '7.0500000', 'pickup_lng' => '38.4900000', 'from_zone' => 'A',
    'recipient_address' => 'Tabor', 'delivery_lat' => null, 'delivery_lng' => '', 'to_zone' => 'B', 'recipient_name' => 'Hana', 'recipient_phone' => '0911', 'pkg_size' => 'small', 'pkg_weight_kg' => '2.50', 'pkg_desc' => 'Books', 'vehicle_class' => 'moto', 'pkg_speed' => 'express', 'fare_total' => '156.00'));
check('history row: ids and numbers are real numbers, missing coordinates are null', $hr['order_id'] === 7 && $hr['pickup']['lat'] === 7.05 && $hr['drop']['lat'] === null && $hr['drop']['lng'] === null && $hr['weight_kg'] === 2.5 && $hr['fare_total'] === 156.0);
check('history row: the fields Send again fills', $hr['recipient_name'] === 'Hana' && $hr['size'] === 'small' && $hr['desc'] === 'Books' && $hr['vehicle_class'] === 'moto' && $hr['speed'] === 'express' && $hr['group'] === 'active');
check('history row: a row with almost nothing does not break', etel_package_history_row(array('id' => 1, 'order_code' => 'x', 'status' => 'delivered'))['group'] === 'delivered');
// Photo proof (api/lib/package_proof.php)
require_once __DIR__ . '/../api/lib/package_proof.php';
check('proof photo: empty and non-text are refused', etel_package_proof_check_photo('')['ok'] === false && etel_package_proof_check_photo(null)['ok'] === false && etel_package_proof_check_photo(array('x'))['ok'] === false);
check('proof photo: plain text and a wrong type are refused', etel_package_proof_check_photo('hello')['ok'] === false && etel_package_proof_check_photo('data:image/gif;base64,R0lGODlhAQABAAAAACw=')['ok'] === false && etel_package_proof_check_photo('data:text/html;base64,PGI+')['ok'] === false);
check('proof photo: bytes that are not a picture are refused', etel_package_proof_check_photo('data:image/png;base64,' . base64_encode('this is not an image'))['ok'] === false);
check('proof photo: a picture too big for the limit is refused', etel_package_proof_check_photo('data:image/png;base64,' . str_repeat('A', ETEL_PROOF_MAX_CHARS))['ok'] === false);
if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
    $im = imagecreatetruecolor(40, 40); ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
    check('proof photo: a real 40x40 PNG is accepted', etel_package_proof_check_photo('data:image/png;base64,' . base64_encode($png))['ok'] === true);
    check('proof photo: the same bytes sent as a JPEG are refused (type must match)', etel_package_proof_check_photo('data:image/jpeg;base64,' . base64_encode($png))['ok'] === false);
    $sm = imagecreatetruecolor(8, 8); ob_start(); imagepng($sm); $tiny = ob_get_clean(); imagedestroy($sm);
    check('proof photo: a picture under 16 pixels is refused', etel_package_proof_check_photo('data:image/png;base64,' . base64_encode($tiny))['ok'] === false);
}
// Address book (api/lib/package_contacts.php)
require_once __DIR__ . '/../api/lib/package_contacts.php';
$cc = etel_contacts_clean(array('name' => '  Hana  ', 'address' => ' Tabor ', 'label' => str_repeat('L', 60), 'lat' => '7.06', 'lng' => '38.49', 'zone' => 'Tabor'), '+251911556677');
check('contact clean: trims, cuts the label to 40, keeps a good map point', is_array($cc) && $cc['name'] === 'Hana' && $cc['address'] === 'Tabor' && strlen($cc['label']) === 40 && $cc['lat'] === 7.06 && $cc['lng'] === 38.49 && $cc['phone'] === '+251911556677');
check('contact clean: no name, no phone or no address is refused with a message', is_string(etel_contacts_clean(array('name' => '', 'address' => 'x'), '+251911556677')) && is_string(etel_contacts_clean(array('name' => 'a', 'address' => 'x'), false)) && is_string(etel_contacts_clean(array('name' => 'a', 'address' => ''), '+251911556677')));
$c2 = etel_contacts_clean(array('name' => 'a', 'address' => 'x', 'lat' => '999', 'lng' => '38'), '+251911556677');
check('contact clean: an impossible or 0,0 map point becomes none', $c2['lat'] === null && $c2['lng'] === null && etel_contacts_clean(array('name' => 'a', 'address' => 'x', 'lat' => 0, 'lng' => 0), '+251911556677')['lat'] === null);
$cr = etel_contacts_row(array('id' => '5', 'label' => 'Mom', 'name' => 'Hana', 'phone' => '+2519', 'address' => 'Tabor', 'lat' => '7.0600000', 'lng' => null, 'zone' => 'Tabor'));
check('contact row: id is a number, a missing coordinate is null', $cr['id'] === 5 && $cr['lat'] === 7.06 && $cr['lng'] === null);
// Express first in the courier's list (api/lib/package_speed.php)
check('express first: an empty or non-list input comes back unchanged', etel_package_express_first($pdo, array()) === array() && etel_package_express_first($pdo, null) === null);
$ef = etel_package_express_first($pdo, array(array('id' => 900000001), array('id' => 900000002)));
check('express first: orders that are not in the database stay in order and say express false', $ef[0]['id'] === 900000001 && $ef[1]['id'] === 900000002 && $ef[0]['express'] === false && $ef[1]['express'] === false);
$pdo->exec("DELETE FROM package_settings WHERE setting_key <> 'late_cancel_fee'");

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
