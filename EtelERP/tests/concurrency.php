<?php
/**
 * Real parallel processes selling the same scarce item at the same moment.
 * Needs the same throwaway database as run.php. Checks: never oversold, invoice numbers unique and gapless.
 */
error_reporting(E_ALL);
date_default_timezone_set('Africa/Addis_Ababa');
$app = dirname(__DIR__) . '/app/';
foreach (array('ApiError', 'Env', 'Db', 'Money', 'Calc', 'Perm', 'Plans', 'RateLimit', 'Audit', 'EtelId', 'Auth', 'Businesses', 'Customers', 'Items', 'Payments', 'Invoices', 'Expenses', 'Reports', 'Router') as $c) require_once $app . $c . '.php';

function connect() {
    $pdo = new PDO('mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';dbname=' . (getenv('TEST_DB_NAME') ?: 'etelerp_test') . ';charset=utf8mb4',
        getenv('TEST_DB_USER') ?: 'etelerp', getenv('TEST_DB_PASS') ?: 'testpass',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
    $pdo->exec("SET time_zone = '+03:00'");
    Db::set($pdo);
    return $pdo;
}

if (isset($argv[1]) && $argv[1] === 'worker') {
    connect();
    $ctx = array('ip' => '', 'member_id' => 1, 'business_id' => 1, 'role' => 'owner');
    $mode = $argv[2];
    try {
        if ($mode === 'sell') {
            Invoices::sell($ctx, array('lines' => array(array('item_id' => 1, 'qty' => '1'))));
        } else {   // pay: everyone pays the same invoice in full
            Payments::add($ctx, array('invoice_id' => (int) $argv[3], 'amount' => '100', 'method' => 'cash'));
        }
        echo "OK\n";
    } catch (ApiError $e) { echo 'REFUSED ' . $e->status . "\n"; } catch (Exception $e) { echo 'ERROR ' . $e->getMessage() . "\n"; }
    exit;
}

$pdo = connect();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec(file_get_contents(dirname(__DIR__) . '/db/schema.sql'));
$pdo->exec("INSERT INTO members (id, etel_id, full_name, created_at) VALUES (1, 'ETL-1', 'Owner', NOW())");
$pdo->exec("INSERT INTO businesses (id, name, plan, created_by, created_at) VALUES (1, 'Race Shop', 'standard', 1, NOW())");
$pdo->exec("INSERT INTO items (id, business_id, name, price, track_stock, stock_qty, created_at) VALUES (1, 1, 'Last items', 100.00, 1, 3.000, NOW())");

function spawn($mode, $n, $arg = '') {
    $procs = array();
    for ($i = 0; $i < $n; $i++) {
        $p = proc_open(array(PHP_BINARY, __FILE__, 'worker', $mode, (string) $arg), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $procs[] = array($p, $pipes);
    }
    $out = array();
    foreach ($procs as $pp) { $out[] = trim(stream_get_contents($pp[1][1])); fclose($pp[1][1]); fclose($pp[1][2]); proc_close($pp[0]); }
    return $out;
}

$fail = 0;
function check($name, $cond) { global $fail; if (!$cond) { $fail++; echo "  FAIL  $name\n"; } else echo "  PASS  $name\n"; }

echo "10 simultaneous sales of an item with 3 in stock\n";
$res = spawn('sell', 10);
$ok = count(array_filter($res, function ($r) { return $r === 'OK'; }));
$err = array_filter($res, function ($r) { return strpos($r, 'ERROR') === 0; });
check('no unexpected errors (' . implode('|', $err) . ')', count($err) === 0);
check('exactly 3 sales succeeded', $ok === 3);
check('stock is exactly 0, never negative', $pdo->query('SELECT stock_qty FROM items WHERE id = 1')->fetchColumn() === '0.000');
$nums = $pdo->query("SELECT number FROM invoices WHERE status = 'issued' ORDER BY number")->fetchAll(PDO::FETCH_COLUMN);
check('3 issued invoices', count($nums) === 3);
check('numbers unique and gapless (INV-000001..3)', $nums === array('INV-000001', 'INV-000002', 'INV-000003'));
check('no half-made drafts left behind', (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'draft'")->fetchColumn() === 0);
check('exactly 3 stock moves', (int) $pdo->query("SELECT COUNT(*) FROM stock_moves WHERE reason = 'sale'")->fetchColumn() === 3);

echo "8 simultaneous full payments of the same 100.00 invoice\n";
$pdo->exec("UPDATE items SET price = 100.00 WHERE id = 1");
// Invoice ids are handed out when the draft is made, and the losing sales delete their drafts, so id 1 may not exist.
$payId = (int) $pdo->query("SELECT id FROM invoices WHERE status = 'issued' ORDER BY id LIMIT 1")->fetchColumn();
$res = spawn('pay', 8, $payId);
$ok = count(array_filter($res, function ($r) { return $r === 'OK'; }));
$err = array_filter($res, function ($r) { return strpos($r, 'ERROR') === 0; });
check('no unexpected errors (' . implode('|', $err) . ')', count($err) === 0);
check('exactly one payment accepted', $ok === 1);
check('invoice paid exactly once (100.00)', $pdo->query('SELECT paid_amount FROM invoices WHERE id = ' . $payId)->fetchColumn() === '100.00');
check('one payment row', (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE invoice_id = ' . $payId . ' AND voided = 0')->fetchColumn() === 1);

echo $fail ? "\n$fail failed\n" : "\nall concurrency checks passed\n";
exit($fail ? 1 : 0);
