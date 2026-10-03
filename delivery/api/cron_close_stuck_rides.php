<?php
/**
 * ETEL Ride - stuck-trip closer (optional cron entry point).
 *
 * The sweep already runs by itself, at most every 5 minutes, whenever a
 * driver opens their profile or a rider checks their active ride (see
 * rides_sweep_maybe in lib/ride_sweeper.php). Add this cron only if you want
 * it to run even when nobody has the apps open, or to also send push messages
 * (the piggy-back runs skip pushes to keep requests fast).
 *
 * cPanel -> Cron Jobs -> every 5 minutes:
 *   wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_close_stuck_rides.php?key=YOUR_CRON_KEY"
 * It reuses CRON_DISPATCH_KEY from api/.env (same key as cron_dispatch_scheduled.php).
 *
 * Standalone like cron_dispatch_scheduled.php: it does NOT include boot.php or
 * a controller (both re-run their own dispatch as a side effect).
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
} catch (Exception $e) {
    error_log('[ETEL][cron_close_stuck] DB connect failed: ' . $e->getMessage());
    cs_reply(array('success' => false, 'message' => 'Server unavailable'), 503);
}

require_once __DIR__ . '/lib/schema_helpers.php';
require_once __DIR__ . '/lib/ride_fare.php';
require_once __DIR__ . '/lib/ride_sweeper.php';
// push.php exits unless APP_VER is defined (same guard every controller uses).
if (!defined('APP_VER')) { define('APP_VER', 'cron-close-stuck'); }
require_once __DIR__ . '/push.php';

// Keep the piggy-back throttle in step so both paths do not double up.
try { $pdo->exec("UPDATE ride_sweeper_state SET last_run=NOW() WHERE id=1"); } catch (Exception $e) { /* table not created yet */ }

$result = rides_sweep_stuck($pdo, array('limit' => 50, 'push' => true));
cs_reply(array('success' => true, 'result' => $result));
