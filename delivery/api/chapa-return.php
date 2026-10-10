<?php
/**
 * ETEL Delivery & Ride - where Chapa sends the customer's browser back.
 * Verifies the payment with Chapa right away (don't wait for the webhook),
 * then redirects into the right app. The redirect itself proves nothing.
 */
require_once __DIR__ . '/env_loader.php';
$_ch_env = etel_parse_env_file(__DIR__ . '/.env');
function etel_env($key, $cfg_const, $default = '') {
    global $_ch_env;
    if (isset($_ch_env[$key]) && $_ch_env[$key] !== '') return $_ch_env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}
if (!defined('APP_VER')) define('APP_VER', 'chapa'); // satisfies the lib/ direct-access guard
require_once __DIR__ . '/chapa_bridge.php';

$ref  = isset($_GET['tx_ref']) ? trim($_GET['tx_ref']) : '';
$kind = (isset($_GET['kind']) && $_GET['kind'] === 'order') ? 'order' : 'ride';
$result = 'unknown';
if ($ref !== '' && chapa_enabled()) {
    try {
        $pdo = new PDO('mysql:host=' . etel_env('DB_HOST', '', 'localhost') . ';dbname=' . etel_env('DB_NAME', '', 'etel_delivery') . ';charset=utf8mb4',
            etel_env('DB_USER', '', ''), etel_env('DB_PASS', '', ''),
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
        // Same time zone as the main API (boot.php): without this NOW() ran in the
        // database server's zone and completed_at landed 3 hours before created_at.
        @date_default_timezone_set('Africa/Addis_Ababa');
        try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Exception $tz) { /* keep the server zone */ }
        $result = chapa_settle($pdo, $ref);
    } catch (Exception $e) {
        error_log('[Delivery-Chapa] return: ' . $e->getMessage());
    }
}
$page = $kind === 'order' ? 'customer.html' : 'ride/index.html';
header('Location: ' . chapa_base_url() . '/' . $page . '?chapa=' . rawurlencode($result) . '&tx_ref=' . rawurlencode($ref));
exit;
