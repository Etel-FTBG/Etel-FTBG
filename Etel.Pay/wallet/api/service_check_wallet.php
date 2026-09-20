<?php
/**
 * EtelPay - service-to-service wallet status lookup, for the NFC
 * "driver details for traffic officer" check (same pattern as
 * etelid_member_detail.php - standalone script, no framework, same
 * shared ETELID_SERVICE_KEY every EtelPay<->EtelID/other-app service
 * call already uses).
 *
 * Place at: Etel.Pay/wallet/api/service_check_wallet.php
 * Called as: POST https://ethelmarket.com/Etel.Pay/wallet/api/service_check_wallet.php
 *            body: {"phone": "+251..."}
 * Header:    X-Etel-Service-Key: <same key as config.php ETELID_SERVICE_KEY>
 *            (also accepted as ?key=... if the header gets stripped by the host)
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';

function scw_header($name) {
    $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    if (function_exists('getallheaders')) {
        $hdrs = getallheaders();
        foreach ($hdrs as $k => $v) {
            if (strcasecmp($k, $name) === 0) return $v;
        }
    }
    return '';
}

function scw_err($message, $code = 400) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

function scw_ok($data) {
    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(array('success' => true, 'data' => $data));
    exit;
}

// config.php ships ETELID_SERVICE_KEY as the literal string 'CHANGE_ME'
// by default - reject that placeholder explicitly, same discipline as
// etelid_member_detail.php.
if (!defined('ETELID_SERVICE_KEY') || !ETELID_SERVICE_KEY || strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0) {
    scw_err('Service key not configured on EtelPay.', 500);
}

$provided = scw_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$expected = trim((string) ETELID_SERVICE_KEY);
$provided = trim((string) $provided);
$match = function_exists('hash_equals') ? hash_equals($expected, $provided) : ($provided === $expected);
if (!$match) {
    scw_err('Invalid or missing service key.', 401);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$phone = isset($body['phone']) ? trim($body['phone']) : '';
if (!$phone) scw_err('Missing phone.', 400);

$last9 = substr(preg_replace('/\D/', '', $phone), -9);
if (!$last9) scw_err('Invalid phone.', 400);

try {
    $pdo = new PDO(
        'mysql:host=' . EP_DB_HOST . ';dbname=' . EP_DB_NAME . ';charset=' . (defined('EP_DB_CHARSET') ? EP_DB_CHARSET : 'utf8mb4'),
        EP_DB_USER, EP_DB_PASS,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        )
    );
} catch (Exception $ex) {
    scw_err('Database connection failed.', 503);
}

try {
    // RIGHT() rather than a regex function, same reasoning as
    // EtelTaxi's ServiceController.php - older MySQL/MariaDB builds on
    // shared hosting may not have REGEXP_REPLACE, and phone numbers
    // here are stored without separators so the last 9 digits line up
    // regardless of "0"/"+251"/"251" prefix.
    //
    // Deliberately NOT selecting balance_etb/balance_usd - this
    // endpoint's only consumer is the traffic-officer NFC check, and a
    // driver's wallet balance has no bearing on a roadside compliance
    // check. Exposing it there would be a real privacy overreach, not
    // something any of the card's other fields do (they show
    // validity/status, never amounts).
    $s = $pdo->prepare('SELECT id, status, kyc_status FROM ' . EP_TBL_USERS . ' WHERE RIGHT(phone, 9) = ? LIMIT 1');
    $s->execute(array($last9));
    $user = $s->fetch();
} catch (Exception $e) {
    scw_err('Query failed.', 500);
}

if (!$user) {
    scw_ok(array('has_wallet' => false));
}

scw_ok(array(
    'has_wallet'  => true,
    'status'      => $user['status'],
    'kyc_status'  => $user['kyc_status'],
));
