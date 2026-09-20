<?php
/**
 * Etel Madeya - service-to-service NFC auto-register.
 *
 * Called by EtelID (class-etelid-nfc.php) after the controller's own
 * EtelID login verified on EtelID's own side - this endpoint trusts
 * that assertion the same way EtelPay's service_nfc_charge.php trusts
 * a valid ETELID_SERVICE_KEY caller. A bare NFC tap alone still can
 * never register a vehicle - it only identifies whose plate to look
 * up; the controller's own EtelID login on the card is what actually
 * authorizes the action before this is ever called.
 *
 * Place at: EtelMadeya/api/service_nfc_register.php
 * Called as: POST https://ethelmarket.com/Etel-Madeya/api/service_nfc_register.php
 *            body: {"controller_phone":"+251...","driver_phone":"+251...",
 *                    "driver_etel_id":"EG-2026-00001"}
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

function snr_header($name) {
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

function snr_err($message, $code = 400) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

function snr_ok($data) {
    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(array('success' => true, 'data' => $data));
    exit;
}

if (!defined('ETELID_SERVICE_KEY') || !ETELID_SERVICE_KEY || strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0) {
    snr_err('Service key not configured on Etel Madeya.', 500);
}
$provided = snr_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$expected = trim((string) ETELID_SERVICE_KEY);
$provided = trim((string) $provided);
$match = function_exists('hash_equals') ? hash_equals($expected, $provided) : ($provided === $expected);
if (!$match) snr_err('Invalid or missing service key.', 401);

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$controllerPhone = isset($body['controller_phone']) ? trim($body['controller_phone']) : '';
$driverPhone     = isset($body['driver_phone'])     ? trim($body['driver_phone'])     : '';
$driverEtelId    = isset($body['driver_etel_id'])   ? trim($body['driver_etel_id'])   : '';
$stationId       = isset($body['station_id'])       ? (int) $body['station_id']       : 0;

if (!$controllerPhone || !$driverPhone) snr_err('Missing required fields.', 400);

$controller = madeyaFindControllerByPhone($controllerPhone);

if (!$controller) {
    // Not a station controller - but an admin/manager can still act,
    // by picking which station's queue to register into (they have no
    // single station of their own the way a controller does).
    $admin = madeyaFindAdminByPhone($controllerPhone);
    if (!$admin) {
        snr_err('This EtelID account is not registered as an Etel Madeya controller.', 403);
    }

    if (!$stationId) {
        snr_ok(array(
            'needs_station' => true,
            'stations'      => madeyaActiveStations(),
        ));
    }

    $controller = db()->fetchOne("SELECT * FROM controllers WHERE id = ? AND is_active = 1", [$stationId]);
    if (!$controller) snr_err('Invalid station.', 400);
}

$driverLast9 = madeyaNormalizePhone($driverPhone);
if (!$driverLast9) snr_err('Invalid driver phone.', 400);

$vehicle = db()->fetchOne("SELECT * FROM driver_vehicles WHERE phone_last9 = ?", [$driverLast9]);
if (!$vehicle) {
    snr_err('No vehicle on file for this driver. Register their plate manually once first (with their phone number), then future taps will auto-register.', 404);
}

$result = madeyaRegisterVehicle(
    (int) $controller['id'],
    (int) $vehicle['vehicle_type_id'],
    $vehicle['plate_number'],
    $vehicle['driver_name'],
    $vehicle['phone']
);

if (empty($result['success'])) {
    snr_err($result['message'], isset($result['http_code']) ? $result['http_code'] : 400);
}

// Backfill etel_id now that we know it, for future reference/reporting.
if ($driverEtelId && empty($vehicle['etel_id'])) {
    db()->update('driver_vehicles', ['etel_id' => $driverEtelId], 'id = ?', [$vehicle['id']]);
}

$d = $result['data'];
snr_ok(array(
    'token'         => $d['token'],
    'position'      => $d['position'],
    'plate_number'  => $d['plate_number'],
    'station_name'  => $controller['station_name'],
    'message'       => 'Registered ' . $d['plate_number'] . ' - token ' . $d['token'] . ', position #' . $d['position'] . ' at ' . $controller['station_name'] . '.',
));
