<?php
// Cancelling an order (api/lib/order_cancel.php): who may cancel what, the reason rules, and everything a cancel must clean up.
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'order-cancel-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/courier_offers.php';
require_once __DIR__ . '/../api/lib/courier_stale.php';
require_once __DIR__ . '/../api/lib/order_cancel.php';
require_once __DIR__ . '/../api/lib/money_ledger.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "who may cancel what (pure rules)\n";
check('customer, shop not started: allowed', etel_cancel_rule('customer', 'pending', 'pending') === '');
check('customer, courier already assigned but shop not started: allowed', etel_cancel_rule('customer', 'assigned', 'pending') === '');
check('an empty assignment status counts as not started', etel_cancel_rule('customer', 'pending', '') === '' && etel_cancel_rule('customer', 'pending', null) === '');
check('customer, shop is preparing: refused', etel_cancel_rule('customer', 'pending', 'preparing') !== '');
check('customer, shop says ready: refused', etel_cancel_rule('customer', 'assigned', 'accepted') !== '');
check('customer, on its way: refused', etel_cancel_rule('customer', 'in_transit', 'accepted') !== '' && etel_cancel_rule('customer', 'picked_up', 'pending') !== '');
check('shop may decline while pending, preparing or ready', etel_cancel_rule('shop', 'pending', 'pending') === '' && etel_cancel_rule('shop', 'pending', 'preparing') === '' && etel_cancel_rule('shop', 'assigned', 'accepted') === '');
check('shop may not cancel once it is on its way', etel_cancel_rule('shop', 'picked_up', 'accepted') !== '');
check('admin may cancel any live order, even on its way', etel_cancel_rule('admin', 'in_transit', 'accepted') === '' && etel_cancel_rule('admin', 'pending', 'preparing') === '');
check('nobody can cancel a delivered or cancelled order', etel_cancel_rule('admin', 'delivered', 'delivered') !== '' && etel_cancel_rule('admin', 'cancelled', 'cancelled') !== '' && etel_cancel_rule('customer', 'cancelled', 'pending') !== '');
check('an unknown role cannot cancel', etel_cancel_rule('courier', 'pending', 'pending') !== '');
check('each role has its own reason list, courier has none', count(etel_cancel_reasons('customer')) === 4 && isset(etel_cancel_reasons('shop')['out_of_stock']) && isset(etel_cancel_reasons('admin')['fraud']) && etel_cancel_reasons('courier') === array());

echo "cancelling (real database)\n";
etel_cancel_ensure_columns($pdo);
try { $pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_method ENUM('cash','telebirr','abysinia','cbe','wallet','chapa') DEFAULT 'cash'"); } catch (Exception $x) {}
$pdo->exec("DELETE FROM order_admin_actions WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'OCT-%')");
$pdo->exec("DELETE FROM order_assignments WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'OCT-%')");
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'OCT-%'");
$pdo->exec("DELETE FROM couriers WHERE courier_code = 'OCTC1'");
$pdo->exec("DELETE FROM service_givers WHERE service_giver_code IN ('OCTS1','OCTS2')");
$pdo->exec("DELETE FROM users WHERE phone LIKE '+25199840000%'");
$mkUser = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role) VALUES (?, ?, 'x', ?)");
$ids = array();
foreach (array(array('Cust One', '+251998400000', 'customer'), array('Cust Two', '+251998400001', 'customer'), array('Courier OCT', '+251998400002', 'courier'), array('Shop One', '+251998400003', 'service_giver'), array('Shop Two', '+251998400004', 'service_giver')) as $i => $u) {
    $mkUser->execute($u === null ? null : array($u[0], $u[1], $u[2])); $ids[$i] = (int) $pdo->lastInsertId();
}
$pdo->prepare("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (?, 'OCTC1', 'Stadium', 'busy', 1)")->execute(array($ids[2])); $cour = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name) VALUES (?, 'OCTS1', 'Shop One')")->execute(array($ids[3])); $s1 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name) VALUES (?, 'OCTS2', 'Shop Two')")->execute(array($ids[4])); $s2 = (int) $pdo->lastInsertId();
$mk = function ($code, $status, $assign, $courier = null, $method = 'cash', $pstatus = 'unpaid', $shop = null) use ($pdo, $ids, $s1) {
    $pdo->prepare("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, payment_method, payment_status, fare_total, placed_at) VALUES (?, ?, ?, 'A', 'B', ?, ?, ?, 300, NOW())")
        ->execute(array($code, $ids[0], $courier, $status, $method, $pstatus));
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_assignments (order_id, service_giver_id, status) VALUES (?, ?, ?)")->execute(array($id, $shop ? $shop : $s1, $assign));
    return $id;
};
$row = function ($id) use ($pdo) { return $pdo->query("SELECT * FROM orders WHERE id=$id")->fetch(); };
$cstat = function () use ($pdo, $cour) { return $pdo->query("SELECT status FROM couriers WHERE id=$cour")->fetchColumn(); };

$a = $mk('OCT-A', 'assigned', 'pending', $cour);
$r = etel_cancel_order($pdo, $a, 'customer', $ids[0], 'changed_mind', 'sorry', 0);
$x = $row($a);
check('customer cancels their own order', $r['ok'] === true && $x['status'] === 'cancelled');
check('reason, who and when are stored', $x['cancel_reason'] === 'changed_mind' && $x['cancelled_by'] === 'customer' && $x['cancel_note'] === 'sorry' && $x['cancelled_at'] !== null);
check('the courier is released from the order and freed', $x['courier_id'] === null && $cstat() === 'online');
check('the shop row is closed', $pdo->query("SELECT status FROM order_assignments WHERE order_id=$a")->fetchColumn() === 'cancelled');
check('logged as a cancel by the customer', (int) $pdo->query("SELECT COUNT(*) FROM order_admin_actions WHERE order_id=$a AND action='cancel' AND detail LIKE 'customer: changed_mind%'")->fetchColumn() === 1);
check('a cash order is not flagged for refund', $r['refund_due'] === false);
$r = etel_cancel_order($pdo, $a, 'customer', $ids[0], 'changed_mind', '', 0);
check('cancelling twice is refused', $r['ok'] === false && strpos($r['message'], 'already cancelled') !== false);

$b = $mk('OCT-B', 'pending', 'pending', null, 'telebirr', 'paid');
$r = etel_cancel_order($pdo, $b, 'customer', $ids[0], 'wrong_order', '', 0);
check('a PAID order cancelled by the customer is flagged for refund', $r['ok'] === true && $r['refund_due'] === true);
$rep = etel_refunds_report($pdo, 30); $owed = array(); foreach ($rep['owed'] as $o) $owed[] = $o['order_id'];
check('and it now shows on the Money > Refunds list', in_array($b, $owed));

$c = $mk('OCT-C', 'pending', 'pending');
check('another customer cannot cancel it (looks like not found)', etel_cancel_order($pdo, $c, 'customer', $ids[1], 'changed_mind', '', 0)['message'] === 'Order not found.' && $row($c)['status'] === 'pending');
check('no reason is refused', etel_cancel_order($pdo, $c, 'customer', $ids[0], '', '', 0)['ok'] === false);
check('a reason from another role\'s list is refused', etel_cancel_order($pdo, $c, 'customer', $ids[0], 'out_of_stock', '', 0)['ok'] === false && $row($c)['status'] === 'pending');
$d = $mk('OCT-D', 'pending', 'preparing');
$r = etel_cancel_order($pdo, $d, 'customer', $ids[0], 'changed_mind', '', 0);
check('customer cannot cancel once the shop is preparing', $r['ok'] === false && strpos($r['message'], 'shop has already started') !== false && $row($d)['status'] === 'pending');

$r = etel_cancel_order($pdo, $d, 'shop', $ids[3], 'out_of_stock', 'no injera', $s1);
check('the shop declines an order it is preparing', $r['ok'] === true && $row($d)['status'] === 'cancelled' && $row($d)['cancelled_by'] === 'shop');
$f = $mk('OCT-F', 'pending', 'pending', null, 'cash', 'unpaid', $s1);
check('another shop cannot decline it', etel_cancel_order($pdo, $f, 'shop', $ids[4], 'too_busy', '', $s2)['ok'] === false && $row($f)['status'] === 'pending');
check('a shop with no shop id cannot cancel', etel_cancel_order($pdo, $f, 'shop', $ids[3], 'too_busy', '', 0)['ok'] === false);

$g = $mk('OCT-G', 'in_transit', 'accepted', $cour);
$pdo->exec("UPDATE couriers SET status='busy' WHERE id=$cour");
check('the shop cannot cancel an order that is on its way', etel_cancel_order($pdo, $g, 'shop', $ids[3], 'too_busy', '', $s1)['ok'] === false && $row($g)['status'] === 'in_transit');
check('the customer cannot cancel an order that is on its way', etel_cancel_order($pdo, $g, 'customer', $ids[0], 'changed_mind', '', 0)['ok'] === false);
$r = etel_cancel_order($pdo, $g, 'admin', 99, 'shop_problem', 'food ruined', 0);
$x = $row($g);
check('admin can cancel an order on its way', $r['ok'] === true && $x['status'] === 'cancelled' && $x['cancelled_by'] === 'admin');
check('admin cancel releases and frees the courier', $x['courier_id'] === null && $cstat() === 'online' && intval($r['courier_released']) === $cour);
$h = $mk('OCT-H', 'delivered', 'delivered', $cour);
check('a delivered order cannot be cancelled, even by admin', etel_cancel_order($pdo, $h, 'admin', 99, 'other', '', 0)['ok'] === false && $row($h)['status'] === 'delivered');
check('a very long note is cut to 200 characters', strlen($pdo->query("SELECT cancel_note FROM orders WHERE id=$g")->fetchColumn()) <= 200 && etel_cancel_order($pdo, $mk('OCT-I', 'pending', 'pending'), 'admin', 99, 'other', str_repeat('x', 500), 0)['ok'] === true);

$pdo->exec("DELETE FROM order_admin_actions WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'OCT-%')");
$pdo->exec("DELETE FROM order_assignments WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'OCT-%')");
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'OCT-%'");
try { $pdo->exec("DELETE FROM notifications WHERE user_id IN ({$ids[2]}, {$ids[0]})"); } catch (Exception $x) {}
$pdo->exec("DELETE FROM couriers WHERE id=$cour"); $pdo->exec("DELETE FROM service_givers WHERE id IN ($s1, $s2)"); $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', $ids) . ")");

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
