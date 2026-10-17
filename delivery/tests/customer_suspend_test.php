<?php
// A suspended customer (users.active = 0) cannot place shop orders or book packages (api/lib/customer_suspend.php).
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
require_once __DIR__ . '/../api/lib/customer_suspend.php';
require_once __DIR__ . '/../api/lib/package_orders.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

$tag = 'SUS' . substr((string) time(), -6);
$pdo->prepare("INSERT INTO users (name, phone, password_hash, role, active) VALUES (?, ?, 'x', 'customer', 1)")->execute(array($tag, '+2519' . mt_rand(10000000, 99999999)));
$uid = (int) $pdo->lastInsertId();

check('an active customer is not suspended', etel_customer_suspended($pdo, $uid) === false);
$pdo->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute(array($uid));
check('active = 0 reads as suspended', etel_customer_suspended($pdo, $uid) === true);
$r = etel_package_create($pdo, array('id' => $uid, 'role' => 'customer'), array());
check('a suspended customer cannot book a package', $r['ok'] === false && $r['message'] === etel_customer_suspended_message());
$pdo->prepare('UPDATE users SET active = 1 WHERE id = ?')->execute(array($uid));
check('restoring ordering clears the block', etel_customer_suspended($pdo, $uid) === false);
$r = etel_package_create($pdo, array('id' => $uid, 'role' => 'customer'), array());
check('a restored customer reaches the normal checks', $r['ok'] === false && $r['message'] !== etel_customer_suspended_message());
check('an unknown id is not treated as suspended', etel_customer_suspended($pdo, 99999999) === false);

$pdo->prepare('DELETE FROM users WHERE id = ?')->execute(array($uid));
$done = true;
echo $fails ? "FAILED: $fails check(s)\n" : "All customer suspend checks passed\n";
exit($fails ? 1 : 0);
