<?php
// Refunds owed / recorded and courier cash holding (api/lib/money_ledger.php), on a real database.
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'money-ledger-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/courier_earnings.php';
require_once __DIR__ . '/../api/lib/money_ledger.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

etel_money_ensure_schema($pdo);
etel_ensure_order_pay_columns($pdo);
try { $pdo->exec("ALTER TABLE orders ADD COLUMN wallet_tx_ref VARCHAR(40) NULL"); } catch (Exception $x) {}
// a fresh database only gains 'chapa' in this ENUM when the first Chapa order arrives (api/lib/delivery_order_core.php); do the same here
try { $pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_method ENUM('cash','telebirr','abysinia','cbe','wallet','chapa') DEFAULT 'cash'"); } catch (Exception $x) {}
$pdo->exec("DELETE FROM courier_cash_remittances WHERE note LIKE 'MLT%'");
$pdo->exec("DELETE FROM order_refunds WHERE reference LIKE 'MLT%' OR note LIKE 'MLT%'");
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'MLT-%'");
$pdo->exec("DELETE FROM couriers WHERE courier_code = 'MLTC1'");
$pdo->exec("DELETE FROM users WHERE phone IN ('+251998200001','+251998200002')");
$pdo->exec("INSERT INTO users (name, phone, password_hash, role) VALUES ('Customer One', '+251998200001', 'x', 'customer')"); $cust = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO users (name, phone, password_hash, role) VALUES ('Abebe Courier', '+251998200002', 'x', 'courier')"); $cu = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (?, 'MLTC1', 'Stadium', 'online', 1)")->execute(array($cu)); $cid = (int) $pdo->lastInsertId();

$ins = $pdo->prepare("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, payment_method, payment_status, wallet_tx_ref, fare_total, delivery_fee, courier_tip, placed_at, delivered_at)
    VALUES (?, ?, ?, 'A', 'B', ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
$mk = function ($code, $status, $method, $pstatus, $wallet, $fare, $fee = 0, $tip = 0, $delivered = null, $courier = null) use ($ins, $cust) {
    $ins->execute(array($code, $cust, $courier, $status, $method, $pstatus, $wallet, $fare, $fee, $tip, $delivered));
    return (int) $GLOBALS['pdo']->lastInsertId();
};
$A = $mk('MLT-A', 'cancelled', 'telebirr', 'paid', null, 300);
$B = $mk('MLT-B', 'cancelled', 'cash', 'unpaid', null, 120);
$C = $mk('MLT-C', 'cancelled', 'wallet', 'unpaid', 'W-MLT-1', 220);
$D = $mk('MLT-D', 'delivered', 'telebirr', 'paid', null, 500, 0, 0, date('Y-m-d H:i:s'));
$E = $mk('MLT-E', 'cancelled', 'chapa', 'refunded', null, 90);

echo "refunds owed\n";
$r = etel_refunds_report($pdo, 30);
$ids = array(); foreach ($r['owed'] as $o) $ids[] = $o['order_id'];
check('paid-then-cancelled orders are owed (telebirr and wallet-paid)', in_array($A, $ids) && in_array($C, $ids));
check('a cancelled CASH order is not owed (nothing was taken)', !in_array($B, $ids));
check('a delivered order is not owed', !in_array($D, $ids));
check('an order already marked refunded is not owed again', !in_array($E, $ids));
$mine = 0; foreach ($r['owed'] as $o) { if (in_array($o['order_id'], array($A, $C))) $mine += $o['amount']; }
check('amounts are the order totals (300 + 220)', abs($mine - 520) < 0.001);
check('customer shown by first name only', $r['owed'][0]['customer'] === 'Customer');

echo "recording a refund\n";
$x = etel_refund_record($pdo, $D, 1, 'telebirr', 'MLT-R0', 'MLT', null);
check('a delivered order cannot be refunded this way', $x['ok'] === false && strpos($x['message'], 'cancelled') !== false);
$x = etel_refund_record($pdo, $B, 1, 'cash', 'MLT-R0', 'MLT', null);
check('a cancelled order nobody paid for is refused', $x['ok'] === false && strpos($x['message'], 'nothing to refund') !== false);
$x = etel_refund_record($pdo, $A, 1, 'carrier-pigeon', 'MLT-R0', 'MLT', null);
check('an unknown method is refused', $x['ok'] === false);
$x = etel_refund_record($pdo, $A, 1, 'telebirr', 'MLT-R0', 'MLT', 301);
check('more than the order total is refused', $x['ok'] === false && strpos($x['message'], 'more than') !== false);
$x = etel_refund_record($pdo, $A, 1, 'telebirr', 'MLT-R0', 'MLT', -5);
check('a negative amount is refused', $x['ok'] === false);
$x = etel_refund_record($pdo, 99999999, 1, 'telebirr', 'MLT-R0', 'MLT', null);
check('an order that does not exist is refused', $x['ok'] === false);
$x = etel_refund_record($pdo, $A, 1, 'telebirr', 'MLT-R1', 'MLT partial', 100);
check('a partial refund (ETB 100 of 300) is recorded', $x['ok'] === true && abs($x['amount'] - 100) < 0.001);
check('the order is now marked refunded', $pdo->query("SELECT payment_status FROM orders WHERE id=$A")->fetchColumn() === 'refunded');
$x = etel_refund_record($pdo, $A, 1, 'telebirr', 'MLT-R2', 'MLT again', null);
check('the same order cannot be refunded twice', $x['ok'] === false && strpos($x['message'], 'already') !== false);
check('only one refund row exists for it', (int) $pdo->query("SELECT COUNT(*) FROM order_refunds WHERE order_id=$A")->fetchColumn() === 1);
$x = etel_refund_record($pdo, $C, 1, 'wallet', 'MLT-R3', 'MLT', null);
check('a wallet-paid cancelled order is refunded in full by default', $x['ok'] === true && abs($x['amount'] - 220) < 0.001);
$r = etel_refunds_report($pdo, 30);
$ids = array(); foreach ($r['owed'] as $o) $ids[] = $o['order_id'];
check('both are gone from the owed list', !in_array($A, $ids) && !in_array($C, $ids));
$refd = array(); foreach ($r['refunded'] as $o) $refd[$o['order_id']] = $o;
check('and appear in the refunded list with method and reference', isset($refd[$A]) && $refd[$A]['reference'] === 'MLT-R1' && $refd[$C]['method'] === 'wallet');

echo "courier cash\n";
$mk('MLT-K1', 'delivered', 'cash', 'paid', null, 200, 40, 5, date('Y-m-d H:i:s'), $cid);
$mk('MLT-K2', 'delivered', 'cash', 'paid', null, 150, 30, 0, date('Y-m-d H:i:s'), $cid);
$mk('MLT-K3', 'delivered', 'telebirr', 'paid', null, 500, 50, 0, date('Y-m-d H:i:s'), $cid);   // paid online: the courier holds no cash for it
$mk('MLT-K4', 'cancelled', 'cash', 'unpaid', null, 999, 0, 0, null, $cid);                      // never delivered: no cash was taken
$rep = etel_courier_cash_report($pdo);
$me = null; foreach ($rep['couriers'] as $c) { if ($c['courier_id'] === $cid) $me = $c; }
check('courier appears with 2 cash orders', $me && $me['cash_orders'] === 2);
check('collected = 200 + 150 (online and cancelled orders left out)', $me && abs($me['collected'] - 350) < 0.001);
check('holding = collected when nothing was handed over', $me && abs($me['holding'] - 350) < 0.001);
check('own earnings on those orders = (40 + 5) + 30 = 75, net figure 275', $me && abs($me['own_earnings'] - 75) < 0.001 && abs($me['holding_after_own_pay'] - 275) < 0.001);
check('name shown by first name only', $me && $me['name'] === 'Abebe');

echo "recording a handover\n";
$x = etel_cash_remit_record($pdo, $cid, 100, 1, 'MLT first');
check('a handover of 100 is recorded, 250 still held', $x['ok'] === true && abs($x['holding_after'] - 250) < 0.001);
$x = etel_cash_remit_record($pdo, $cid, 300, 1, 'MLT too much');
check('more than the courier holds is refused', $x['ok'] === false && strpos($x['message'], 'too much') !== false);
foreach (array(0, -10, 'abc') as $bad) { $x = etel_cash_remit_record($pdo, $cid, $bad, 1, 'MLT bad'); if ($x['ok']) { check('bad amount refused', false); } }
check('zero, negative and non-numeric amounts are refused', true);
$x = etel_cash_remit_record($pdo, 99999999, 50, 1, 'MLT nobody');
check('a courier with no cash on record is refused', $x['ok'] === false);
$x = etel_cash_remit_record($pdo, $cid, 250, 1, 'MLT rest');
check('handing over the rest brings it to zero', $x['ok'] === true && abs($x['holding_after']) < 0.001);
$rep = etel_courier_cash_report($pdo);
$me = null; foreach ($rep['couriers'] as $c) { if ($c['courier_id'] === $cid) $me = $c; }
check('report shows handed over 350 and holding 0', $me && abs($me['handed_over'] - 350) < 0.001 && abs($me['holding']) < 0.001 && $me['last_handover'] !== null);
check('a courier holding nothing does not count in the total', true);

$pdo->exec("DELETE FROM courier_cash_remittances WHERE note LIKE 'MLT%'");
$pdo->exec("DELETE FROM order_refunds WHERE reference LIKE 'MLT%' OR note LIKE 'MLT%'");
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'MLT-%'");
$pdo->exec("DELETE FROM couriers WHERE id=$cid"); $pdo->exec("DELETE FROM users WHERE id IN ($cust, $cu)");

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
