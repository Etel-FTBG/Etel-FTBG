<?php
/**
 * ETEL Ride - Field Agents earnings job (cron entry point).
 *
 * READ-ONLY on rides and users: it only looks at completed trips and writes to the fa_* tables. Safe to run as often
 * as you like (idempotent), and the admin screen has a "Run now" button that calls the same code.
 * Does nothing while the programme is switched off (fa_settings program_enabled = 0).
 *
 * cPanel -> Cron Jobs -> every 15 minutes. Best (runs on the server itself, no key):
 *   /usr/local/bin/php -q /home/ACCOUNT/public_html/delivery/api/cron_field_agents.php >/dev/null 2>&1
 * Or over the web (needs CRON_DISPATCH_KEY from api/.env):
 *   wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_field_agents.php?key=YOUR_CRON_KEY"
 */
if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

function fc_reply($arr, $code = 200) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$_fc_env = etel_parse_env_file(__DIR__ . '/.env');
function fc_env($key, $default = '') {
    global $_fc_env;
    if (isset($_fc_env[$key]) && $_fc_env[$key] !== '') return $_fc_env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

$isCli = (PHP_SAPI === 'cli');
$expectedKey = fc_env('CRON_DISPATCH_KEY', '');
$givenKey = isset($_GET['key']) ? $_GET['key'] : '';
if (!$isCli && ($expectedKey === '' || !hash_equals($expectedKey, $givenKey))) {
    fc_reply(array('success' => false, 'message' => 'Missing or wrong ?key= - set CRON_DISPATCH_KEY in api/.env first'), 403);
}

try {
    $pdo = new PDO(
        'mysql:host=' . fc_env('DB_HOST', 'localhost') . ';dbname=' . fc_env('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        fc_env('DB_USER', ''), fc_env('DB_PASS', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    try { if (!defined('ETEL_DB_TZ_SYNC') || ETEL_DB_TZ_SYNC) $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Exception $e) {}
} catch (Exception $e) {
    error_log('[ETEL][cron_field_agents] DB connect failed: ' . $e->getMessage());
    fc_reply(array('success' => false, 'message' => 'Server unavailable'), 503);
}

if (!defined('APP_VER')) { define('APP_VER', 'cron-field-agents'); }
require_once __DIR__ . '/lib/fa_earnings.php';
try {
    $res = fa_run_earnings($pdo);
    if (!empty($res['enabled'])) fa_audit($pdo, 'system', 0, 'earnings_job_run', 'job', 0, '', $res);
    if (!empty($res['enabled'])) { try { require_once __DIR__ . '/lib/fa_insights.php'; $res['digest_id'] = fa_digest_store($pdo); } catch (Exception $e) { error_log('[ETEL][cron_field_agents] digest: ' . $e->getMessage()); } }
    fc_reply(array('success' => true, 'result' => $res));
} catch (Exception $e) {
    error_log('[ETEL][cron_field_agents] ' . $e->getMessage());
    fc_reply(array('success' => false, 'message' => 'Job failed - see the server error log'), 500);
}
