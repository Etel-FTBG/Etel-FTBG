<?php
// A shop owner or active staff login must not order from their own shop (api/lib/own_shop.php, used by delivery_execute_order_creation).
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
require_once __DIR__ . '/../api/lib/own_shop.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

$tag = 'OWN' . substr((string) time(), -6);
$mk = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role) VALUES (?, ?, 'x', ?)");
$ids = array();
foreach (array('owner' => 'service_giver', 'staff' => 'customer', 'oldstaff' => 'customer', 'other' => 'customer') as $n => $role) {
    $mk->execute(array($tag . $n, '+2519' . mt_rand(10000000, 99999999), $role)); $ids[$n] = (int) $pdo->lastInsertId();
}
$pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name) VALUES (?, ?, 'Own Shop Test')")->execute(array($ids['owner'], $tag . 'S1')); $shop = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name) VALUES (?, ?, 'Other Shop Test')")->execute(array($ids['other'], $tag . 'S2')); $shop2 = (int) $pdo->lastInsertId();
$hasStaff = true;
try {
    $pdo->prepare("INSERT INTO sg_staff (service_giver_id, user_id, staff_role, active) VALUES (?, ?, 'cashier', 1)")->execute(array($shop, $ids['staff']));
    $pdo->prepare("INSERT INTO sg_staff (service_giver_id, user_id, staff_role, active) VALUES (?, ?, 'cashier', 0)")->execute(array($shop, $ids['oldstaff']));
} catch (Exception $x) { $hasStaff = false; }

check('the owner runs their own shop', etel_user_runs_shop($pdo, $ids['owner'], $shop) === true);
check('the owner can order from another shop', etel_user_runs_shop($pdo, $ids['owner'], $shop2) === false);
check('a stranger runs nothing here', etel_user_runs_shop($pdo, $ids['other'], $shop) === false);
if ($hasStaff) {
    check('an active staff login runs the shop', etel_user_runs_shop($pdo, $ids['staff'], $shop) === true);
    check('an inactive staff login does not', etel_user_runs_shop($pdo, $ids['oldstaff'], $shop) === false);
    check('staff can order from another shop', etel_user_runs_shop($pdo, $ids['staff'], $shop2) === false);
}
check('bad ids are never "own shop"', etel_user_runs_shop($pdo, 0, $shop) === false && etel_user_runs_shop($pdo, $ids['owner'], 0) === false);

$in = implode(',', array_values($ids));
$pdo->exec("DELETE FROM sg_staff WHERE user_id IN ($in)");
$pdo->exec("DELETE FROM service_givers WHERE id IN ($shop, $shop2)");
$pdo->exec("DELETE FROM users WHERE id IN ($in)");
$done = true;
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
