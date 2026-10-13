<?php
// Test helper (CI / throwaway database only).
//   php tests/courier_pay_cli.php ensure
//   php tests/courier_pay_cli.php save '<json settings>'
//   php tests/courier_pay_cli.php apply <order_id> <courier_id> <snapshot 0|1>   -> the order as the courier app would receive it
//   php tests/courier_pay_cli.php earnings <courier_id>
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
$pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
    $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
require_once __DIR__ . '/../api/lib/courier_earnings.php';
$mode = isset($argv[1]) ? $argv[1] : '';
if ($mode === 'ensure') { etel_ensure_order_pay_columns($pdo); echo json_encode(array('ok' => true)), "\n"; }
elseif ($mode === 'save') { echo json_encode(etel_cpay_save($pdo, json_decode($argv[2], true))), "\n"; }
elseif ($mode === 'apply') {
    $o = $pdo->prepare('SELECT id, delivery_fee FROM orders WHERE id = ?'); $o->execute(array(intval($argv[2])));
    $orders = array($o->fetch());
    etel_apply_courier_tip($pdo, $orders, intval($argv[3]), $argv[4] === '1');
    echo json_encode($orders[0]), "\n";
}
elseif ($mode === 'earnings') { echo json_encode(etel_courier_earnings($pdo, intval($argv[2]))), "\n"; }
