<?php
// ============================================================
// ETEL TAXI - Chapa webhook. Set in the Chapa dashboard to
// https://ethelmarket.com/EtelTaxi/api/chapa-callback.php
// Trust comes from the HMAC signature AND Chapa's verify API.
// ============================================================
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/taxi_chapa.php';

$raw = file_get_contents('php://input') ?: '';
$sig = isset($_SERVER['HTTP_CHAPA_SIGNATURE']) ? $_SERVER['HTTP_CHAPA_SIGNATURE']
     : (isset($_SERVER['HTTP_X_CHAPA_SIGNATURE']) ? $_SERVER['HTTP_X_CHAPA_SIGNATURE'] : '');
if (!taxi_chapa_enabled() || $sig === '') {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'message' => 'Chapa webhook not configured'));
    exit;
}
if (!hash_equals(hash_hmac('sha256', $raw, taxi_chapa_secret()), (string)$sig)) {
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
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    echo json_encode(array('ok' => true, 'result' => taxi_chapa_settle($pdo, $ref)));
} catch (Exception $e) {
    error_log('[Taxi-Chapa] webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'Server error'));
}
