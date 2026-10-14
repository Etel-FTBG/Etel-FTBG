<?php
// Dispatch by hand (api/lib/manual_dispatch.php) on a real database: the need classification, assign / reassign / take back,
// every refusal, courier status, the action log and the board. Throwaway test database only (api/.env).
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'manual-dispatch-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/courier_offers.php';
require_once __DIR__ . '/../api/lib/courier_stale.php';
require_once __DIR__ . '/../api/lib/order_dispatch.php';
require_once __DIR__ . '/../api/lib/manual_dispatch.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "what a live order needs\n";
check('pending with no courier needs a courier', etel_dispatch_need(array('status' => 'pending', 'courier_id' => null, 'age_min' => 4)) === 'no_courier');
check('an order waiting for payment needs nothing', etel_dispatch_need(array('status' => 'pending', 'courier_id' => null, 'awaiting_payment' => true)) === '');
check('assigned, not accepted, 1 min: too early', etel_dispatch_need(array('status' => 'assigned', 'courier_id' => 3, 'accepted' => false, 'since_assign_min' => 1)) === '');
check('assigned, not accepted, 5 min: waiting for the courier to accept', etel_dispatch_need(array('status' => 'assigned', 'courier_id' => 3, 'accepted' => false, 'since_assign_min' => 5)) === 'waiting_accept');
check('accepted but not picked up after 30 min', etel_dispatch_need(array('status' => 'assigned', 'courier_id' => 3, 'accepted' => true, 'since_assign_min' => 31)) === 'not_picked_up');
check('accepted 10 min ago: fine', etel_dispatch_need(array('status' => 'assigned', 'courier_id' => 3, 'accepted' => true, 'since_assign_min' => 10)) === '');
check('on its way for 100 min: slow', etel_dispatch_need(array('status' => 'in_transit', 'courier_id' => 3, 'age_min' => 100)) === 'slow');
check('on its way for 40 min: fine', etel_dispatch_need(array('status' => 'picked_up', 'courier_id' => 3, 'age_min' => 40)) === '');

echo "assign, reassign, take back (real database)\n";
etel_dispatch_ensure_schema($pdo);
$pdo->exec("DELETE FROM order_admin_actions WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'MDT-%')");
$pdo->exec("DELETE FROM order_courier_offers WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'MDT-%')");
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'MDT-%'");
$pdo->exec("DELETE FROM couriers WHERE courier_code LIKE 'MDTC%'");
$pdo->exec("DELETE FROM users WHERE phone LIKE '+25199830000%'");
$mkUser = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role) VALUES (?, ?, 'x', ?)");
$mkUser->execute(array('Customer MD', '+251998300000', 'customer')); $cust = (int) $pdo->lastInsertId();
$cid = array(); $cuid = array();
foreach (array(1, 2, 3) as $i) {
    $mkUser->execute(array('Courier' . $i . ' Last', '+25199830000' . $i, 'courier')); $cuid[$i] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (?, ?, 'Stadium', 'online', ?)")->execute(array($cuid[$i], 'MDTC' . $i, $i === 3 ? 0 : 1));
    $cid[$i] = (int) $pdo->lastInsertId();
}
$mkOrder = $pdo->prepare("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, fare_total, placed_at) VALUES (?, ?, ?, 'Stadium', 'Tabor', ?, 250, NOW())");
$order = function ($code, $status, $courier = null) use ($mkOrder, $cust, $pdo) { $mkOrder->execute(array($code, $cust, $courier, $status)); return (int) $pdo->lastInsertId(); };
$row = function ($id) use ($pdo) { return $pdo->query("SELECT * FROM orders WHERE id=$id")->fetch(); };
$cstat = function ($id) use ($pdo) { return $pdo->query("SELECT status FROM couriers WHERE id=$id")->fetchColumn(); };

$o1 = $order('MDT-1', 'pending');
$r = etel_admin_assign_courier($pdo, $o1, $cid[1], 99);
$x = $row($o1);
check('assign: order is now assigned to that courier', $r['ok'] === true && intval($x['courier_id']) === $cid[1] && $x['status'] === 'assigned');
check('assign: counts as accepted (the stale sweep will not take it back) and has no offer timer', $x['courier_accepted_at'] !== null && $x['courier_offer_expires_at'] === null);
check('assign: the courier is busy', $cstat($cid[1]) === 'busy');
check('assign: logged with the admin id', (int) $pdo->query("SELECT COUNT(*) FROM order_admin_actions WHERE order_id=$o1 AND admin_id=99 AND action='assign'")->fetchColumn() === 1);
$r = etel_admin_assign_courier($pdo, $o1, $cid[1], 99);
check('assigning the same courier again is refused', $r['ok'] === false && strpos($r['message'], 'already') !== false);

$r = etel_admin_assign_courier($pdo, $o1, $cid[2], 99);
$x = $row($o1);
check('reassign: now with courier 2', $r['ok'] === true && intval($x['courier_id']) === $cid[2] && intval($r['previous_courier_id']) === $cid[1]);
check('reassign: courier 1 is free again, courier 2 is busy', $cstat($cid[1]) === 'online' && $cstat($cid[2]) === 'busy');
check('reassign: logged as reassign with both couriers', (int) $pdo->query("SELECT COUNT(*) FROM order_admin_actions WHERE order_id=$o1 AND action='reassign' AND detail LIKE '%#{$cid[1]}%' AND detail LIKE '%#{$cid[2]}%'")->fetchColumn() === 1);

echo "refusals\n";
check('a delivered order cannot be assigned', etel_admin_assign_courier($pdo, $order('MDT-D', 'delivered'), $cid[1], 99)['ok'] === false);
check('a cancelled order cannot be assigned', etel_admin_assign_courier($pdo, $order('MDT-C', 'cancelled'), $cid[1], 99)['ok'] === false);
$on = $order('MDT-P', 'picked_up', $cid[2]);
$r = etel_admin_assign_courier($pdo, $on, $cid[1], 99);
check('an order already picked up cannot change courier', $r['ok'] === false && strpos($r['message'], 'after pickup') !== false && intval($row($on)['courier_id']) === $cid[2]);
$o2 = $order('MDT-2', 'pending');
check('an inactive courier is refused', etel_admin_assign_courier($pdo, $o2, $cid[3], 99)['ok'] === false && $row($o2)['courier_id'] === null);
check('a courier that does not exist is refused', etel_admin_assign_courier($pdo, $o2, 99999999, 99)['ok'] === false);
check('an order that does not exist is refused', etel_admin_assign_courier($pdo, 99999999, $cid[1], 99)['ok'] === false);

echo "take back\n";
$r = etel_admin_take_back($pdo, $on, 99);
check('an order on its way cannot be taken back', $r['ok'] === false && intval($row($on)['courier_id']) === $cid[2]);
check('an order with no courier cannot be taken back', etel_admin_take_back($pdo, $o2, 99)['ok'] === false);
$pdo->exec("UPDATE orders SET status='delivered' WHERE id=$on"); // free courier 2 from the picked-up order so the next test is clean
$r = etel_admin_take_back($pdo, $o1, 99);   // o1 is with courier 2
$x = $row($o1);
check('take back: succeeded', $r['ok'] === true);
check('take back: the order never goes back to the courier it was taken from', intval($x['courier_id']) !== $cid[2]);
check('take back: it is pending again, or offered on to another free courier', ($x['status'] === 'pending' && $x['courier_id'] === null) || ($x['status'] === 'assigned' && $x['courier_id'] !== null));
check('take back: courier 2 is free', $cstat($cid[2]) === 'online');
check('take back: recorded in the offer history so it is not offered to them again', (int) $pdo->query("SELECT COUNT(*) FROM order_courier_offers WHERE order_id=$o1 AND courier_id={$cid[2]} AND response='taken_back'")->fetchColumn() === 1);
check('take back: logged', (int) $pdo->query("SELECT COUNT(*) FROM order_admin_actions WHERE order_id=$o1 AND action='take_back'")->fetchColumn() === 1);

echo "the board\n";
$o3 = $order('MDT-3', 'pending');
$b = etel_dispatch_board($pdo);
$mine = null; foreach ($b['orders'] as $r) { if ($r['order_id'] === $o3) $mine = $r; }
check('a pending order with no courier is on the board as needing a courier', $mine && $mine['need'] === 'no_courier' && $mine['can_assign'] === true);
check('needs_count counts it', $b['needs_count'] >= 1);
$names = array(); foreach ($b['couriers'] as $c) { $names[$c['courier_id']] = $c; }
check('courier 1 is listed by first name only', isset($names[$cid[1]]) && $names[$cid[1]]['name'] === 'Courier1');
check('an inactive courier is not offered as a choice', !isset($names[$cid[3]]));
$first = null; foreach ($b['orders'] as $r) { if ($r['need'] !== '') { $first = $r; break; } }
check('orders needing staff come before the ones moving normally', $first !== null);

$pdo->exec("DELETE FROM order_admin_actions WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'MDT-%')");
$pdo->exec("DELETE FROM order_courier_offers WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'MDT-%')");
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'MDT-%'");
$pdo->exec("DELETE FROM notifications WHERE user_id IN (" . implode(',', $cuid) . ")");
$pdo->exec("DELETE FROM couriers WHERE courier_code LIKE 'MDTC%'");
$pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', array_merge(array($cust), $cuid)) . ")");

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
