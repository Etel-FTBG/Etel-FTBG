<?php
// Test helper (CI / local throwaway database only): runs the courier offer + stale-assignment sweeps once, the same
// way api/cron_ride_monitor.php does, and prints a one-line JSON result.
//   php tests/stale_sweep_cli.php ensure   -> only make sure orders.courier_assigned_at exists
//   php tests/stale_sweep_cli.php sweep    -> run the sweeps
//   php tests/stale_sweep_cli.php jobs     -> order codes (STALE-%) the courier job list would show, using the same age filter
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
$pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
    $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
// No SET time_zone here on purpose: the test seeds rows with NOW() through the mysql client, so both must use the same (database default) zone.
if (!defined('APP_VER')) define('APP_VER', 'stale-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/push.php';
require_once __DIR__ . '/../api/lib/courier_offers.php';
require_once __DIR__ . '/../api/lib/courier_stale.php';
$mode = isset($argv[1]) ? $argv[1] : 'sweep';
if ($mode === 'ensure') {
    etel_offers_ensure_schema($pdo);
    echo json_encode(array('column' => etel_stale_has_col($pdo))), "\n";
    exit(0);
}
if ($mode === 'jobs') {
    $rows = $pdo->query("SELECT o.order_code FROM orders o WHERE o.order_code LIKE 'STALE-%' AND o.status IN ('pending','assigned') " . etel_job_age_filter($pdo) . ' ORDER BY o.order_code')->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(array('visible' => $rows, 'max_age' => etel_job_age_minutes($pdo))), "\n";
    exit(0);
}
$n = etel_offers_sweep($pdo, true);
echo json_encode(array('swept' => $n)), "\n";
