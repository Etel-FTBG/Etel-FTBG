<?php
/**
 * Etel Madeya - service-to-service NFC role check.
 *
 * Called by EtelID (class-etelid-nfc.php) right after the scanning
 * person's own EtelID login verifies, so the NFC card page can show
 * or hide its "Register in Queue" button per person instead of
 * showing it to everyone - a phone is eligible if it belongs to an
 * active station controller OR an admin/manager, the exact same two
 * checks service_nfc_register.php itself makes before acting. This
 * endpoint changes nothing; it only answers "would that phone be
 * allowed to register a vehicle right now". Same service-key trust
 * boundary as service_nfc_register.php (see that file's header).
 *
 * Place at: EtelMadeya/api/service_nfc_check_role.php
 * Called as: POST https://ethelmarket.com/Etel-Madeya/api/service_nfc_check_role.php
 *            body: {"phone":"+251..."}
 * Header:    X-Etel-Service-Key: <same key as includes/config.php ETELID_SERVICE_KEY>
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/queue_logic.php';

function sncr_header($name) {
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

function sncr_err($message, $code = 400) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

function sncr_ok($data) {
    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(array('success' => true, 'data' => $data));
    exit;
}

if (!defined('ETELID_SERVICE_KEY') || !ETELID_SERVICE_KEY || strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0) {
    sncr_err('Service key not configured on Etel Madeya.', 500);
}
$provided = sncr_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$expected = trim((string) ETELID_SERVICE_KEY);
$provided = trim((string) $provided);
$match = function_exists('hash_equals') ? hash_equals($expected, $provided) : ($provided === $expected);
if (!$match) sncr_err('Invalid or missing service key.', 401);

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$phone = isset($body['phone']) ? trim($body['phone']) : '';
if (!$phone) sncr_err('Missing required fields.', 400);

$isEligible = (bool) (madeyaFindControllerByPhone($phone) || madeyaFindAdminByPhone($phone));

sncr_ok(array('is_eligible' => $isEligible));
