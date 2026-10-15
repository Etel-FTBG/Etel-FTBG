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
check('a car package goes to the car courier, not the motorbike one', intval($o['courier_id']) === $cCar && $o['status'] === 'assigned');

$moto2 = $base; $moto2['vehicle_class'] = 'moto';
$r2 = etel_package_create($pdo, $user, $moto2); $o2 = $row($r2['order_id']);
check('a motorbike package goes to the motorbike courier', intval($o2['courier_id']) === $cMoto);
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
check('but staff still see it in their list', count(etel_package_rates($pdo, false)) === 4);

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
$pdo->exec("UPDATE orders SET status='assigned' WHERE id=" . $r['order_id']);
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

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
