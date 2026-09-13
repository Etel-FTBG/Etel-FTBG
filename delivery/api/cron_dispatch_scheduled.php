<?php
/**
 * ETEL Ride - scheduled ride dispatcher.
 *
 * Converts due `rides` rows (status='scheduled', scheduled_at <= now)
 * into live requests (status='searching'), exactly like a fresh book_ride
 * call would create, then pushes nearby online drivers the same way
 * book_ride does.
 *
 * Deliberately standalone - does NOT require boot.php or
 * controllers/RideController.php directly, since both re-run their own
 * dispatch/switch as a side effect of being included (the exact
 * "requiring a controller re-executes its switch dispatch" mistake this
 * codebase has hit before). It reuses api/lib/ride_fare.php instead,
 * which is a plain function library with no dispatch side effects.
 *
 * HOW TO RUN THIS (no SSH on this host, so pick one):
 *   1. cPanel -> Cron Jobs -> add a job every 1-2 minutes running:
 *        wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_dispatch_scheduled.php?key=YOUR_CRON_KEY"
 *      (cPanel Cron Jobs need no SSH access, just the cPanel UI.)
 *   2. Or call it manually from a browser tab with the same ?key=...
 *      whenever you want to force a dispatch pass while testing.
 *
 * Set CRON_DISPATCH_KEY in api/.env to any random string, then use that
 * same string as ?key=... here. Without a matching key this exits 403 -
 * never runs without one.
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

function cd_reply($arr, $code = 200) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/env_loader.php';
$_cd_env = etel_parse_env_file(__DIR__ . '/.env');
function cd_env($key, $default = '') {
    global $_cd_env;
    if (isset($_cd_env[$key]) && $_cd_env[$key] !== '') return $_cd_env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

$expectedKey = cd_env('CRON_DISPATCH_KEY', '');
$givenKey    = isset($_GET['key']) ? $_GET['key'] : '';
if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
    cd_reply(array('success' => false, 'message' => 'Missing or wrong ?key= - set CRON_DISPATCH_KEY in api/.env first'), 403);
}

$DB_HOST = cd_env('DB_HOST', 'localhost');
$DB_NAME = cd_env('DB_NAME', 'etel_delivery');
$DB_USER = cd_env('DB_USER', '');
$DB_PASS = cd_env('DB_PASS', '');

try {
    $pdo = new PDO(
        'mysql:host=' . $DB_HOST . ';dbname=' . $DB_NAME . ';charset=utf8mb4',
        $DB_USER, $DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) {
    error_log('[ETEL][cron_dispatch] DB connect failed: ' . $e->getMessage());
    cd_reply(array('success' => false, 'message' => 'Server unavailable'), 503);
}

require_once __DIR__ . '/lib/ride_fare.php';
// push.php direct-access-guards on APP_VER being defined (same pattern
// every controller uses) - this script is intentionally standalone and
// never goes through boot.php, so that constant was never set, and
// push.php was silently exiting with an empty 403 body the moment this
// require ran. Define it here first so push.php's guard passes.
if (!defined('APP_VER')) { define('APP_VER', 'cron-dispatch'); }
require_once __DIR__ . '/push.php';

$dispatched = array();
$errors = array();

try {
    $due = $pdo->prepare(
        "SELECT id, passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, vehicle_type
         FROM rides
         WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
         ORDER BY scheduled_at ASC
         LIMIT 50"
    );
    $due->execute();
    $rows = $due->fetchAll();
} catch (Exception $e) {
    error_log('[ETEL][cron_dispatch] select due failed: ' . $e->getMessage());
    cd_reply(array('success' => false, 'message' => 'Could not read scheduled rides'), 500);
}

foreach ($rows as $row) {
    $id = intval($row['id']);
    try {
        // Skip if this passenger already has a live ride in flight -
        // same "one active ride at a time" rule book_ride enforces.
        $active = $pdo->prepare(
            "SELECT id FROM rides WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived') LIMIT 1"
        );
        $active->execute(array($row['passenger_id']));
        if ($active->fetch()) {
            $errors[] = array('ride_id' => $id, 'reason' => 'passenger already has an active ride - left scheduled for retry');
            continue;
        }

        $dispatchStmt = $pdo->prepare(
            "UPDATE rides SET status='searching', expires_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE), dispatched_at=NOW() WHERE id=? AND status='scheduled'"
        );
        $dispatchStmt->execute(array($id));
        if ($dispatchStmt->rowCount() === 0) {
            // CONCURRENCY FIX: another (overlapping/duplicate) run of this
            // same cron already dispatched this ride between our SELECT
            // above and this UPDATE - the atomic WHERE status='scheduled'
            // correctly stopped a double-dispatch of the RIDE ITSELF, but
            // without this check we'd still fall through and send a
            // second round of duplicate push notifications to every
            // nearby driver and the passenger for a ride that was already
            // handled by the other run. Skip entirely instead.
            $errors[] = array('ride_id' => $id, 'reason' => 'already dispatched by a concurrent/overlapping run - skipped');
            continue;
        }

        $plat = floatval($row['pickup_lat']);
        $plng = floatval($row['pickup_lng']);
        $vtype = $row['vehicle_type'] ?: 'bajaj';

        $nearbyDrivers = $pdo->prepare(
            // Same vehicle-type filter fix as book_ride's own broadcast -
            // was pushing every online driver regardless of type.
            "SELECT user_id, lat, lng FROM ride_drivers WHERE online = 1 AND lat IS NOT NULL AND lng IS NOT NULL AND (vehicle_type = ? OR vehicle_type IS NULL)"
        );
        $nearbyDrivers->execute(array($vtype));
        foreach ($nearbyDrivers->fetchAll() as $rd) {
            $d = rides_haversine_km($plat, $plng, floatval($rd['lat']), floatval($rd['lng']));
            if ($d <= 8) {
                etel_push_send($pdo, intval($rd['user_id']), 'Scheduled ride request nearby!',
                    round($d, 1) . ' km away - a pre-booked ride just came due',
                    array('type' => 'new_ride_request', 'ride_id' => $id, 'url' => '/delivery/driver/'));
            }
        }

        etel_push_send($pdo, intval($row['passenger_id']), 'Finding your driver',
            'Your scheduled ride is now searching for a nearby driver',
            array('type' => 'ride_dispatched', 'ride_id' => $id, 'url' => '/delivery/ride/'));

        $dispatched[] = $id;
    } catch (Exception $e) {
        error_log('[ETEL][cron_dispatch] ride ' . $id . ' failed: ' . $e->getMessage());
        $errors[] = array('ride_id' => $id, 'reason' => 'dispatch error - see server log');
    }
}

cd_reply(array(
    'success'    => true,
    'checked'    => count($rows),
    'dispatched' => $dispatched,
    'skipped'    => $errors,
    'ts'         => date('Y-m-d H:i:s')
));
