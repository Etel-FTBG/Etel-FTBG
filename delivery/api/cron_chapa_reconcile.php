<?php
/**
 * ETEL Delivery & Ride - settle Chapa payments whose webhook or browser return never arrived.
 *
 * Why: a customer pays inside Chapa and closes the page before coming back, or the webhook is lost.
 * The money is received but the order or ride still shows unpaid. This job asks Chapa about every
 * payment still "pending" (older than 3 minutes, newer than 3 days) and settles the ones that were
 * paid, through the same chapa_settle() the webhook uses (verified status, ETB, exact amount, once only).
 *
 * cPanel -> Cron Jobs -> every 5 minutes:
 *   /usr/local/bin/php -q /home/ACCOUNT/public_html/delivery/api/cron_chapa_reconcile.php >/dev/null 2>&1
 * Or over the web (needs CRON_DISPATCH_KEY from api/.env):
 *   wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_chapa_reconcile.php?key=YOUR_CRON_KEY"
 */
if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

function cr_reply($arr, $code = 200) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$_cr_env = etel_parse_env_file(__DIR__ . '/.env');
function etel_env($key, $cfg_const, $default = '') {
    global $_cr_env;
    if (isset($_cr_env[$key]) && $_cr_env[$key] !== '') return $_cr_env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

$isCli = (PHP_SAPI === 'cli');
$expectedKey = etel_env('CRON_DISPATCH_KEY', '', '');
$givenKey = isset($_GET['key']) ? $_GET['key'] : '';
if (!$isCli && ($expectedKey === '' || !hash_equals($expectedKey, $givenKey))) {
    cr_reply(array('success' => false, 'message' => 'Missing or wrong ?key= - set CRON_DISPATCH_KEY in api/.env first'), 403);
}

if (!defined('APP_VER')) define('APP_VER', 'cron-chapa-reconcile'); // satisfies the lib/ direct-access guard
require_once __DIR__ . '/chapa_bridge.php';
if (!chapa_enabled()) cr_reply(array('success' => true, 'skipped' => 'Chapa is not configured (CHAPA_SECRET_KEY empty)'));

try {
    $pdo = new PDO(
        'mysql:host=' . etel_env('DB_HOST', '', 'localhost') . ';dbname=' . etel_env('DB_NAME', '', 'etel_delivery') . ';charset=utf8mb4',
        etel_env('DB_USER', '', ''), etel_env('DB_PASS', '', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Exception $e) { /* keep the server zone */ }
} catch (Exception $e) {
    error_log('[ETEL][cron_chapa_reconcile] DB connect failed: ' . $e->getMessage());
    cr_reply(array('success' => false, 'message' => 'Server unavailable'), 503);
}

require_once __DIR__ . '/lib/cron_lock.php';
if (!etel_cron_lock($pdo, 'chapa_reconcile')) cr_reply(array('success' => true, 'skipped' => 'the previous run of this job is still going'));

require_once __DIR__ . '/lib/system_health.php';
chapa_ensure_table($pdo);
$rows = $pdo->query("SELECT tx_ref FROM chapa_payments
    WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE) AND created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
    ORDER BY id ASC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);

$out = array('checked' => 0, 'completed' => 0, 'failed' => 0, 'still_pending' => 0);
foreach ($rows as $ref) {
    $out['checked']++;
    try { $r = chapa_settle($pdo, $ref); } catch (Exception $e) { $r = 'error'; error_log('[ETEL][cron_chapa_reconcile] ' . $ref . ': ' . $e->getMessage()); }
    if ($r === 'completed') $out['completed']++;
    elseif ($r === 'failed') $out['failed']++;
    else $out['still_pending']++;
}
etel_health_heartbeat($pdo, 'chapa_reconcile', 'checked ' . $out['checked'] . ', settled ' . $out['completed']);
cr_reply(array('success' => true, 'result' => $out));
