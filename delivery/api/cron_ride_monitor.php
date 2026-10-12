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
 * cPanel -> Cron Jobs -> every 5 minutes. Best (runs on the server itself, no
 * key, not affected by the host's web firewall):
 *   /usr/local/bin/php -q /home/ACCOUNT/public_html/delivery/api/cron_ride_monitor.php >/dev/null 2>&1
 * Or over the web (needs CRON_DISPATCH_KEY from api/.env):
 *   wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_ride_monitor.php?key=YOUR_CRON_KEY"
 * Add push=0 (CLI) or &push=0 (web) to look without alerting. The result
 * (counts) is returned as JSON.
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

// From the command line (cPanel cron running php directly) no key is needed: only
// the server itself can start it, and the web firewall never sees it. Over the
// web the key is still required.
$isCli = (PHP_SAPI === 'cli');
$expectedKey = cs_env('CRON_DISPATCH_KEY', '');
$givenKey    = isset($_GET['key']) ? $_GET['key'] : '';
if (!$isCli && ($expectedKey === '' || !hash_equals($expectedKey, $givenKey))) {
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

// schema_helpers.php and push.php refuse to load (blank 403) unless APP_VER is already
// defined, the same guard every controller satisfies - so define it BEFORE any require.
if (!defined('APP_VER')) { define('APP_VER', 'cron-ride-monitor'); }
require_once __DIR__ . '/lib/schema_helpers.php';
require_once __DIR__ . '/lib/ride_fare.php';
require_once __DIR__ . '/lib/ride_sweeper.php';
require_once __DIR__ . '/lib/ride_monitor.php';
require_once __DIR__ . '/lib/system_health.php';
require_once __DIR__ . '/push.php';

// Keep the piggy-back throttle in step so both paths do not double up.
try { $pdo->exec("UPDATE ride_sweeper_state SET last_run=NOW() WHERE id=1"); } catch (Exception $e) { /* table not created yet */ }

$swept = rides_sweep_stuck($pdo, array('limit' => 50, 'push' => true));
$noPush = $isCli ? (isset($argv) && in_array('push=0', $argv, true)) : (isset($_GET['push']) && $_GET['push'] === '0');
$watch = rides_monitor_run($pdo, !$noPush);
// Expire courier job offers even when nobody's screen is polling (offers are lazy otherwise).
try { require_once __DIR__ . '/lib/courier_offers.php'; etel_offers_sweep($pdo, true); } catch (Exception $e) {} catch (Throwable $e) {}
etel_health_heartbeat($pdo, 'ride_monitor', 'swept ' . count($swept['completed']) . ' closed, ' . $watch['findings'] . ' findings');
cs_reply(array('success' => true, 'swept' => $swept, 'watch' => $watch));
