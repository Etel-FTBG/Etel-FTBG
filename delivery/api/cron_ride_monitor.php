<?php
/**
 * ETEL Ride - trip watch (cron entry point).
 *
 * One cron does both jobs, so it can replace cron_close_stuck_rides.php:
 *   1. closes stuck trips (rides_sweep_stuck, with push messages), then
 *   2. looks for trips that are stuck RIGHT NOW (no driver, driver not at
 *      pickup, GPS silent, very long trip, unconfirmed arrival, unreviewed
 *      sweeper issue) and pushes ONE grouped alert to the admins for anything
 *      new (rides_monitor_run in lib/ride_monitor.php). Read-only: it never
 *      changes a ride itself beyond what the sweeper already does.
 *
 * cPanel -> Cron Jobs -> every 5 minutes:
 *   wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_ride_monitor.php?key=YOUR_CRON_KEY"
 * Same CRON_DISPATCH_KEY as the other crons. Add &push=0 to look without
 * alerting. The result (counts) is returned as JSON.
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function cs_reply($arr, $code = 200) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$_cs_env = etel_parse_env_file(__DIR__ . '/.env');
function cs_env($key, $default = '') {
    global $_cs_env;
    if (isset($_cs_env[$key]) && $_cs_env[$key] !== '') return $_cs_env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

$expectedKey = cs_env('CRON_DISPATCH_KEY', '');
$givenKey    = isset($_GET['key']) ? $_GET['key'] : '';
if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
    cs_reply(array('success' => false, 'message' => 'Missing or wrong ?key= - set CRON_DISPATCH_KEY in api/.env first'), 403);
}

try {
    $pdo = new PDO(
        'mysql:host=' . cs_env('DB_HOST', 'localhost') . ';dbname=' . cs_env('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        cs_env('DB_USER', ''), cs_env('DB_PASS', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    // Align MySQL's NOW() with PHP's Africa/Addis_Ababa clock (the host's
    // MySQL runs on server time, ~2 h behind), so every timestamp the app
    // writes and every PHP time()/strtotime() comparison use one clock.
    // Set ETEL_DB_TZ_SYNC=false in config to switch this off.
    try { if (!defined('ETEL_DB_TZ_SYNC') || ETEL_DB_TZ_SYNC) $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Exception $e) { /* keep the server zone */ }
} catch (Exception $e) {
    error_log('[ETEL][cron_ride_monitor] DB connect failed: ' . $e->getMessage());
    cs_reply(array('success' => false, 'message' => 'Server unavailable'), 503);
}

require_once __DIR__ . '/lib/schema_helpers.php';
require_once __DIR__ . '/lib/ride_fare.php';
require_once __DIR__ . '/lib/ride_sweeper.php';
require_once __DIR__ . '/lib/ride_monitor.php';
// push.php exits unless APP_VER is defined (same guard every controller uses).
if (!defined('APP_VER')) { define('APP_VER', 'cron-ride-monitor'); }
require_once __DIR__ . '/push.php';

// Keep the piggy-back throttle in step so both paths do not double up.
try { $pdo->exec("UPDATE ride_sweeper_state SET last_run=NOW() WHERE id=1"); } catch (Exception $e) { /* table not created yet */ }

$swept = rides_sweep_stuck($pdo, array('limit' => 50, 'push' => true));
$watch = rides_monitor_run($pdo, !isset($_GET['push']) || $_GET['push'] !== '0');
cs_reply(array('success' => true, 'swept' => $swept, 'watch' => $watch));
