<?php
/**
 * ETEL Delivery & Ride - Chapa webhook.
 * Set in the Chapa dashboard to https://ethelmarket.com/delivery/api/chapa-callback.php
 * Trust: HMAC signature, then Chapa's own verify API (chapa_settle).
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

header('Content-Type: application/json');
$raw = file_get_contents('php://input') ?: '';
$sig = isset($_SERVER['HTTP_CHAPA_SIGNATURE']) ? $_SERVER['HTTP_CHAPA_SIGNATURE']
     : (isset($_SERVER['HTTP_X_CHAPA_SIGNATURE']) ? $_SERVER['HTTP_X_CHAPA_SIGNATURE'] : '');
if (!chapa_enabled() || $sig === '') {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'message' => 'Chapa webhook not configured'));
    exit;
}
if (!hash_equals(hash_hmac('sha256', $raw, chapa_secret()), (string)$sig)) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'message' => 'Invalid signature'));
    exit;
}
$evt = json_decode($raw, true);
$ref = is_array($evt) && isset($evt['tx_ref']) ? (string)$evt['tx_ref'] : '';
if ($ref === '') {
    http_response_code(422);
    echo json_encode(array('ok' => false, 'message' => 'Missing tx_ref'));
    exit;
}
try {
    $pdo = new PDO('mysql:host=' . etel_env('DB_HOST', '', 'localhost') . ';dbname=' . etel_env('DB_NAME', '', 'etel_delivery') . ';charset=utf8mb4',
        etel_env('DB_USER', '', ''), etel_env('DB_PASS', '', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    @date_default_timezone_set('Africa/Addis_Ababa');
    try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Exception $tz) { /* keep the server zone */ }
    echo json_encode(array('ok' => true, 'result' => chapa_settle($pdo, $ref)));
} catch (Exception $e) {
    error_log('[Delivery-Chapa] webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'Server error'));
}
