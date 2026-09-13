<?php
/**
 * ETEL Ride - public trip-share endpoint.
 *
 * Deliberately standalone, NOT included via boot.php's switch dispatch.
 * This must be reachable with no login token at all (a trusted contact
 * clicking a link has no ETEL account), so it cannot sit behind
 * require_auth() like every other ride_* action. It connects to the
 * database itself using the same api/.env / config.php values boot.php
 * uses, but never requires boot.php directly - requiring boot.php would
 * re-run its own action-dispatch switch as a side effect and either exit
 * early or double-handle the request (the same "requiring a controller
 * re-executes its dispatch" mistake noted for other files in this
 * codebase).
 *
 * Exposes ONLY what a trusted contact needs to see someone is safe:
 * driver name/photo/plate/vehicle, live position, and coarse status.
 * Never exposes: phone numbers, fare, full addresses, or anything once
 * the trip is no longer active.
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ts_reply($arr, $code = 200) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/env_loader.php';
$_ts_env = etel_parse_env_file(__DIR__ . '/.env');
function ts_env($key, $default = '') {
    global $_ts_env;
    if (isset($_ts_env[$key]) && $_ts_env[$key] !== '') return $_ts_env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

$DB_HOST = ts_env('DB_HOST', 'localhost');
$DB_NAME = ts_env('DB_NAME', 'etel_delivery');
$DB_USER = ts_env('DB_USER', '');
$DB_PASS = ts_env('DB_PASS', '');

try {
    $pdo = new PDO(
        'mysql:host=' . $DB_HOST . ';dbname=' . $DB_NAME . ';charset=utf8mb4',
        $DB_USER, $DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) {
    error_log('[ETEL][trip_share] DB connect failed: ' . $e->getMessage());
    ts_reply(array('success' => false, 'message' => 'Server unavailable'), 503);
}

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
if ($token === '' || !preg_match('/^[a-f0-9]{10,40}$/', $token)) {
    ts_reply(array('success' => false, 'message' => 'Invalid share link'), 400);
}

try {
    $s = $pdo->prepare(
        "SELECT r.id, r.status, r.pickup_address, r.dropoff_address, r.created_at,
                d.name AS driver_name,
                rd.vehicle_plate, rd.vehicle_type, rd.vehicle_make, rd.vehicle_color,
                rd.lat AS driver_lat, rd.lng AS driver_lng, rd.selfie_url AS driver_photo,
                CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS driver_rating
         FROM rides r
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
         WHERE r.share_token = ? LIMIT 1"
    );
    $s->execute(array($token));
    $r = $s->fetch();
} catch (Exception $e) {
    error_log('[ETEL][trip_share] query failed: ' . $e->getMessage());
    ts_reply(array('success' => false, 'message' => 'Server error'), 500);
}

if (!$r) {
    ts_reply(array('success' => false, 'message' => 'This trip link is no longer valid'), 404);
}

$activeStates = array('searching', 'accepted', 'in_progress', 'arrived');
if (!in_array($r['status'], $activeStates, true)) {
    ts_reply(array('success' => false, 'message' => 'This trip has ended - the link is no longer active'), 410);
}

ts_reply(array(
    'success' => true,
    'data' => array(
        'status'          => $r['status'],
        'driver_name'     => $r['driver_name'],
        'driver_photo'    => $r['driver_photo'],
        'driver_rating'   => $r['driver_rating'],
        'vehicle_plate'   => $r['vehicle_plate'],
        'vehicle_type'    => $r['vehicle_type'],
        'vehicle_make'    => $r['vehicle_make'],
        'vehicle_color'   => $r['vehicle_color'],
        'driver_lat'      => $r['driver_lat'] !== null ? floatval($r['driver_lat']) : null,
        'driver_lng'      => $r['driver_lng'] !== null ? floatval($r['driver_lng']) : null,
        'pickup_address'  => $r['pickup_address'],
        'dropoff_address' => $r['dropoff_address'],
        'started_at'      => $r['created_at']
    )
));
