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

$r = etel_package_create($pdo, $user, $base);
$o = $r['ok'] ? $row($r['order_id']) : array();
check('a package is booked', $r['ok'] === true && strpos($r['order_code'], 'ETP-') === 0 && $o['order_kind'] === 'package');
check('the price is the server price for that vehicle', $r['total'] === $q['options'][2]['price'] && intval($o['fare_total']) === $r['total'] && intval($o['delivery_fee']) === $r['total'] && floatval($o['subtotal']) == 0);
check('pickup, drop-off, details and sender are saved', $o['from_zone'] === 'Tabor' && $o['to_zone'] === 'University' && $o['pickup_address'] === 'Tabor, Hawassa' && $o['recipient_address'] === 'University gate'
    && $o['vehicle_class'] === 'car' && $o['pkg_size'] === 'small' && floatval($o['pkg_weight_kg']) == 2.0 && $o['pkg_desc'] === 'Books' && $o['sender_name'] === 'Sender Sam' && $o['sender_phone'] === '+251998500000');
check('the recipient number is kept as it was typed once it passes the phone check', $o['recipient_phone'] === '0911223344');
check('two 4-digit pins are made', preg_match('/^\d{4}$/', $o['pickup_pin']) === 1 && preg_match('/^\d{4}$/', $o['delivery_pin']) === 1);
check('a stand-in assignment row exists and no shop owns it', $pdo->query("SELECT COUNT(*) FROM order_assignments WHERE order_id=" . $r['order_id'] . " AND service_giver_id=0 AND status='pending'")->fetchColumn() == 1);
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

$pdo->exec("DELETE FROM order_admin_actions WHERE order_id IN (SELECT id FROM orders WHERE order_kind='package')");
$pdo->exec("DELETE FROM order_assignments WHERE order_id IN (SELECT id FROM orders WHERE order_kind='package')");
$pdo->exec("DELETE FROM orders WHERE order_kind = 'package'");
try { $pdo->exec("DELETE FROM notifications WHERE user_id IN (" . implode(',', $ids) . ")"); } catch (Exception $x) {}
$pdo->exec("DELETE FROM couriers WHERE id IN ($cMoto, $cCar)"); $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', $ids) . ")");
foreach (etel_package_default_rates() as $dr) { $pdo->prepare('UPDATE package_rates SET base_fee=?, per_km=?, free_kg=?, per_kg_over=?, min_fee=?, max_weight_kg=?, active=1 WHERE vehicle_class=?')->execute(array($dr[3], $dr[4], $dr[5], $dr[6], $dr[7], $dr[8], $dr[0])); }

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
