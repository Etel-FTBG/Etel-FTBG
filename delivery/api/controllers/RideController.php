<?php
/**
 * ETEL Delivery - Ride Controller (ETEL Ride integration)
 * Pure PHP/MySQL . cPanel compatible . PHP 5.6+
 *
 * Implements the passenger + driver actions used by ride.html / driver.html:
 *   Passenger: book_ride, ride_status, my_active_ride, cancel_ride,
 *              rate_ride, ride_history
 *   Driver:    driver_go_online, driver_go_offline, driver_update_location,
 *              driver_get_nearby_requests, driver_get_profile,
 *              driver_accept_ride, driver_start_ride, driver_complete_ride,
 *              driver_cancel_ride, passenger_get_ride_history
 *
 * Tables are auto-created on first use (CREATE TABLE IF NOT EXISTS),
 * so no manual SQL import is required.
 */

if (!defined('APP_VER')) { http_response_code(403); exit; } // direct access guard

/* Web Push helper (see api/webpush.php for the real implementation) */
require_once __DIR__ . '/../push.php';

/* rides_add_missing_cols() lives in lib/schema_helpers.php - it's also
   called from AdminController.php, which doesn't otherwise load this
   file. See that file's header comment for why. */
require_once __DIR__ . '/../lib/schema_helpers.php';

/* -- Schema (self-healing) ------------------------------------ */
function rides_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $sentinel = __DIR__ . '/../../api/.schema_done';
    if (file_exists($sentinel)) { $done = true; return; }

    /* If a legacy rides table exists (from the old ETEL Ride build) with
       UNIQUE indexes we do not manage (uq_ride_code, uq_request, ...),
       its constraints will keep rejecting our inserts. Preserve it under
       rides_legacy_<timestamp> and start with a clean table. */
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'rides'")->fetch();
        if ($exists) {
            $legacyUnique = false;
            foreach ($pdo->query("SHOW INDEX FROM `rides`")->fetchAll() as $ix) {
                if (intval($ix['Non_unique']) === 0 && $ix['Key_name'] !== 'PRIMARY') {
                    $legacyUnique = true; break;
                }
            }
            if ($legacyUnique) {
                $bak = 'rides_legacy_' . date('YmdHi');
                $pdo->exec("RENAME TABLE `rides` TO `$bak`");
                error_log('[ETEL][rides] legacy rides table preserved as ' . $bak);
            }
        }
    } catch (Exception $e) {
        error_log('[ETEL][rides] legacy check: ' . $e->getMessage());
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS rides (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        passenger_id INT UNSIGNED NOT NULL,
        driver_id INT UNSIGNED DEFAULT NULL,
        pickup_lat DECIMAL(10,7) DEFAULT NULL,
        pickup_lng DECIMAL(10,7) DEFAULT NULL,
        dropoff_lat DECIMAL(10,7) DEFAULT NULL,
        dropoff_lng DECIMAL(10,7) DEFAULT NULL,
        pickup_address VARCHAR(255) DEFAULT '',
        dropoff_address VARCHAR(255) DEFAULT '',
        distance_km DECIMAL(8,2) DEFAULT 0,
        fare_estimate DECIMAL(10,2) DEFAULT 0,
        fare_final DECIMAL(10,2) DEFAULT 0,
        fare_paid DECIMAL(10,2) DEFAULT 0,
        payment_method VARCHAR(20) DEFAULT 'cash',
        status ENUM('searching','accepted','in_progress','completed','cancelled') DEFAULT 'searching',
        rating TINYINT DEFAULT NULL,
        rating_comment VARCHAR(500) DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        assigned_at DATETIME DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_passenger (passenger_id, status),
        KEY idx_driver (driver_id, status),
        KEY idx_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Migrate pre-existing tables: add any columns this controller needs */
    rides_add_missing_cols($pdo, 'rides', array(
        'passenger_id'    => "passenger_id INT UNSIGNED NOT NULL DEFAULT 0",
        'driver_id'       => "driver_id INT UNSIGNED DEFAULT NULL",
        'pickup_lat'      => "pickup_lat DECIMAL(10,7) DEFAULT NULL",
        'pickup_lng'      => "pickup_lng DECIMAL(10,7) DEFAULT NULL",
        'dropoff_lat'     => "dropoff_lat DECIMAL(10,7) DEFAULT NULL",
        'dropoff_lng'     => "dropoff_lng DECIMAL(10,7) DEFAULT NULL",
        'pickup_address'  => "pickup_address VARCHAR(255) DEFAULT ''",
        'dropoff_address' => "dropoff_address VARCHAR(255) DEFAULT ''",
        'distance_km'     => "distance_km DECIMAL(8,2) DEFAULT 0",
        'fare_estimate'   => "fare_estimate DECIMAL(10,2) DEFAULT 0",
        'fare_final'      => "fare_final DECIMAL(10,2) DEFAULT 0",
        'fare_paid'       => "fare_paid DECIMAL(10,2) DEFAULT 0",
        'payment_method'  => "payment_method VARCHAR(20) DEFAULT 'cash'",
        'status'          => "status VARCHAR(20) DEFAULT 'searching'",
        'rating'          => "rating TINYINT DEFAULT NULL",
        'rating_comment'  => "rating_comment VARCHAR(500) DEFAULT NULL",
        'expires_at'      => "expires_at DATETIME DEFAULT NULL",
        'assigned_at'     => "assigned_at DATETIME DEFAULT NULL",
        'started_at'      => "started_at DATETIME DEFAULT NULL",
        'completed_at'    => "completed_at DATETIME DEFAULT NULL",
        'cancelled_at'    => "cancelled_at DATETIME DEFAULT NULL",
        'created_at'      => "created_at DATETIME DEFAULT NULL"
    ));
    /* Status must be VARCHAR so new states (e.g. 'arrived') fit - widen any ENUM */
    try {
        $col = $pdo->query("SHOW COLUMNS FROM `rides` LIKE 'status'")->fetch();
        if ($col && stripos($col['Type'], 'enum') === 0) {
            $pdo->exec("ALTER TABLE `rides` MODIFY COLUMN status VARCHAR(20) DEFAULT 'searching'");
        }
    } catch (Exception $e) { error_log('[ETEL][rides] status widen: ' . $e->getMessage()); }

    /* Open-meter street-hail columns (GPS-measured trips) */
    rides_add_missing_cols($pdo, 'rides', array(
        'open_trip'   => "open_trip TINYINT(1) NOT NULL DEFAULT 0",
        'traveled_km' => "traveled_km DECIMAL(8,3) NOT NULL DEFAULT 0",
        'last_lat'    => "last_lat DECIMAL(10,7) DEFAULT NULL",
        'last_lng'    => "last_lng DECIMAL(10,7) DEFAULT NULL",
        'arrived_at'  => "arrived_at DATETIME DEFAULT NULL"
    ));

    /* Two-way ratings (driver rates rider back), trip sharing (public
       no-login link), and scheduled/pre-booked rides - see migration 033
       for the matching indexes this self-heal doesn't add. */
    rides_add_missing_cols($pdo, 'rides', array(
        'driver_rating'         => "driver_rating TINYINT DEFAULT NULL",
        'driver_rating_comment' => "driver_rating_comment VARCHAR(500) DEFAULT NULL",
        'share_token'           => "share_token VARCHAR(40) DEFAULT NULL",
        'scheduled_at'          => "scheduled_at DATETIME DEFAULT NULL",
        'is_scheduled'          => "is_scheduled TINYINT(1) NOT NULL DEFAULT 0",
        'dispatched_at'         => "dispatched_at DATETIME DEFAULT NULL"
    ));
    rides_add_missing_cols($pdo, 'users', array(
        'rider_rating_sum'   => "rider_rating_sum INT UNSIGNED DEFAULT 0",
        'rider_rating_count' => "rider_rating_count INT UNSIGNED DEFAULT 0"
    ));

    $pdo->exec("CREATE TABLE IF NOT EXISTS ride_drivers (
        user_id INT UNSIGNED NOT NULL,
        online TINYINT(1) DEFAULT 0,
        lat DECIMAL(10,7) DEFAULT NULL,
        lng DECIMAL(10,7) DEFAULT NULL,
        vehicle_plate VARCHAR(30) DEFAULT '',
        total_earnings DECIMAL(12,2) DEFAULT 0,
        total_paid_out DECIMAL(12,2) DEFAULT 0,
        total_rides INT UNSIGNED DEFAULT 0,
        rating_sum INT UNSIGNED DEFAULT 0,
        rating_count INT UNSIGNED DEFAULT 0,
        last_seen DATETIME DEFAULT NULL,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'online'         => "online TINYINT(1) DEFAULT 0",
        'lat'            => "lat DECIMAL(10,7) DEFAULT NULL",
        'lng'            => "lng DECIMAL(10,7) DEFAULT NULL",
        'vehicle_plate'  => "vehicle_plate VARCHAR(30) DEFAULT ''",
        'vehicle_type'   => "vehicle_type VARCHAR(40) DEFAULT 'Bajaj'",
        'vehicle_make'   => "vehicle_make VARCHAR(60) DEFAULT ''",
        'vehicle_color'  => "vehicle_color VARCHAR(40) DEFAULT ''",
        'vehicle_year'   => "vehicle_year SMALLINT UNSIGNED DEFAULT 0",
        'license_number' => "license_number VARCHAR(60) DEFAULT ''",
        'license_name'   => "license_name VARCHAR(100) DEFAULT ''",
        'license_dob'    => "license_dob DATE DEFAULT NULL",
        'identity_match' => "identity_match ENUM('unverified','matched','mismatch') DEFAULT 'unverified'",
        'selfie_url'         => "selfie_url VARCHAR(500) DEFAULT ''",
        'license_photo_url'  => "license_photo_url VARCHAR(500) DEFAULT ''",
        'onboarded'      => "onboarded TINYINT(1) DEFAULT 0",
        'priority_score' => "priority_score INT DEFAULT 100",
        'total_earnings' => "total_earnings DECIMAL(12,2) DEFAULT 0",
        'total_paid_out' => "total_paid_out DECIMAL(12,2) DEFAULT 0",
        'total_rides'    => "total_rides INT UNSIGNED DEFAULT 0",
        'rating_sum'     => "rating_sum INT UNSIGNED DEFAULT 0",
        'rating_count'   => "rating_count INT UNSIGNED DEFAULT 0",
        'last_seen'      => "last_seen DATETIME DEFAULT NULL"
    ));
    @file_put_contents($sentinel, date('Y-m-d H:i:s'));
    $done = true;
}

/* -- EtelID integration: shared bridge (also used by etelid_login SSO) */
require_once __DIR__ . '/../etelid_bridge.php';
function rides_etelid_lookup($identifier) {
    return etelid_bridge_find($identifier);
}

/* rides_haversine_km moved to api/lib/ride_fare.php - required in below via
   the fare functions' require_once, kept here as just a marker comment. */

/* Dynamic fare engine v3.0 - admin-configurable per vehicle type.
   Rates used to be hardcoded here (one flat base/per-km/wait rate plus a
   per-vehicle-type multiplier: bajaj=1.0, moto=1.15, car=1.5,
   minibus=1.3). They now live in the ride_fare_rules table (see migration
   007_ride_fare_rules.sql) so an admin can change them per vehicle type
   from the panel without a code deploy - e.g. raise moto's per-km rate
   for a fuel price change without touching bajaj or car. */

/* Cached per-request (static array) so a single request that calls this
   several times (e.g. re-pricing on every driver_update_location tick)
   doesn't re-query the rates table each time. */
/* Fare calc functions (rides_fare_rule, rides_surge_multiplier,
   rides_calc_fare, rides_calc_fare_simple) moved to api/lib/ride_fare.php
   so AgentController.php (EtelAI's booking connector) can use the exact
   same pricing without requiring this whole file - this file's own
   switch/default and unconditional require_auth() call below would
   break a request meant for a different controller. */
require_once __DIR__ . '/../lib/ride_fare.php';
require_once __DIR__ . '/../lib/ride_sweeper.php';
require_once __DIR__ . '/../lib/ride_offer.php';
require_once __DIR__ . '/../lib/ride_promo.php';
require_once __DIR__ . '/../lib/ride_tip.php';
require_once __DIR__ . '/../lib/ride_statement.php';
require_once __DIR__ . '/../lib/ride_price_offer.php';

/* True only if $url points at a file THIS user uploaded through upload.php for
   the given type: upload.php names files "<prefix><userId>_<time>_<hex>.<ext>"
   inside uploads/<folder>/, so the user id in the name plus the file existing
   on disk proves ownership. Stops onboarding with made-up or someone else's
   photo URLs. */
function rides_owned_upload_url($url, $uid, $folder, $prefix) {
    $url = trim((string)$url);
    if ($url === '') return false;
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return false;
    if (!preg_match('#/uploads/' . preg_quote($folder, '#') . '/(' . preg_quote($prefix, '#') . intval($uid) . '_\d+_[0-9a-f]{8}\.(?:jpg|png|gif|webp))$#', $path, $m)) return false;
    return is_file(dirname(dirname(__DIR__)) . '/uploads/' . $folder . '/' . $m[1]);
}

function rides_driver_profile($pdo, $uid) {
    $pdo->prepare("INSERT IGNORE INTO ride_drivers (user_id, online, last_seen) VALUES (?, 0, NOW())")
        ->execute(array($uid));
    $s = $pdo->prepare("SELECT * FROM ride_drivers WHERE user_id=?");
    $s->execute(array($uid));
    $d = $s->fetch();
    $d['rating'] = ($d && $d['rating_count'] > 0) ? round($d['rating_sum'] / $d['rating_count'], 1) : 0;
    if ($d) {
        /* PAYOUT FIX: available_balance must not include cash rides -
           see customer_confirm_arrival for the full explanation. */
        $d['available_balance'] = round(floatval($d['total_earnings']) - floatval($d['total_paid_out']), 2);
        $d['commission_owed']   = round(floatval(isset($d['commission_owed']) ? $d['commission_owed'] : 0), 2);
        $d['cash_fares_total']  = round(floatval(isset($d['cash_fares_total']) ? $d['cash_fares_total'] : 0), 2);
        $d['etel_money_balance'] = round(floatval(isset($d['etel_money_balance']) ? $d['etel_money_balance'] : 0), 2);
    }
    return $d;
}

/* INSA audit follow-up: driver-privileged actions (going online, seeing
   nearby requests, accepting/running a ride, hailing a customer, and both
   cash-flow endpoints) used to be reachable by ANY authenticated role -
   including a plain, freshly-registered 'customer' account - with no
   check that this user has actually completed driver onboarding
   (vehicle info + selfie + license photo, cross-checked against EtelID
   and confirmed by an admin if it didn't auto-match). ride_drivers.
   onboarded only ever gets set to 1 by driver_update_profile once all of
   that is done (see the onboarded=1 write a few hundred lines down) -
   this is the enforcement point. A user with no ride_drivers row at all
   (never even started onboarding) is treated the same as onboarded=0. */
function rides_require_onboarded($pdo, $uid) {
    $s = $pdo->prepare("SELECT onboarded FROM ride_drivers WHERE user_id=?");
    $s->execute(array($uid));
    $row = $s->fetch();
    if (!$row || intval($row['onboarded']) !== 1) {
        fail('Your driver account is not fully verified yet. Finish onboarding (vehicle info, selfie, license photo) before you can go online, accept rides, or move money as a driver.', 403);
    }
}

/* The driver's own registered vehicle_type (ride_drivers.vehicle_type) -
   used as the fallback when a ride row's own vehicle_type is empty
   (older rides created before Street Hail started storing it, or any
   other path that ever misses it) instead of silently defaulting to
   'bajaj' regardless of what the driver actually drives. Cached
   per-request since driver_update_location calls this on every 5s tick. */
/* rides_driver_own_vtype() and rides_get_active_for_driver() both moved
   to lib/ride_fare.php (already required a few lines below) so
   boot.php's sse_driver_stream can call the exact same active-ride
   logic instead of maintaining its own duplicate query. That
   duplication caused two separate bugs already (missing vehicle-type
   filter, then passenger_name/passenger_phone aliased differently and
   silently overwriting the correct data every SSE tick). One shared
   pair of functions now, so there's only one place left to drift. */
$pdo = db();
try {
    rides_ensure_schema($pdo);
} catch (Exception $e) {
    error_log('[ETEL][rides] schema create failed: ' . $e->getMessage());
    fail_ex($e, 'Ride system setup failed', 500);
}
$u = require_auth($pdo);

try {
/* Loose match for EtelID identity cross-check: case-insensitive, ignores
   extra whitespace and Ethiopian naming order variance (people commonly
   type "First Father" vs EtelID's full "First Father Grandfather"), so a
   mismatch only fires on genuinely different names, not formatting. */
/* Trip-share link token - not a security secret in the strong sense
   (anyone with the link can view live status), just needs to be
   unguessable. 28 hex chars from random_bytes(). */
function rides_gen_share_token() {
    try {
        return bin2hex(random_bytes(14));
    } catch (Exception $e) {
        return substr(md5(uniqid(mt_rand(), true)), 0, 28);
    }
}

function rides_names_roughly_match($a, $b) {
    $norm = function($s) {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return preg_replace('/\s+/', ' ', $s);
    };
    $a = $norm($a); $b = $norm($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $aParts = explode(' ', $a); $bParts = explode(' ', $b);
    return $aParts[0] === $bParts[0] && (count($aParts) < 2 || count($bParts) < 2 || $aParts[1] === $bParts[1]);
}

// Cached check for whether migration 025 (rides.vehicle_type) has run on
// this install. Used everywhere the code wants to read/write that column,
// so a not-yet-migrated install degrades gracefully (falls back to
// 'bajaj') instead of throwing an uncaught SQL error that surfaces to the
// user as the generic "Ride action failed - please try again or contact
// support" banner - which is exactly what happened before this existed:
// book_ride hardcoded vehicle_type into its INSERT column list, so every
// booking failed outright on any install where the column was missing.
function rides_has_vehicle_type_column($pdo) {
    static $has = null;
    if ($has !== null) return $has;
    $has = false;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            if (strtolower($c['Field']) === 'vehicle_type') { $has = true; break; }
        }
    } catch (Exception $e) { /* stay false - treat as not migrated yet */ }
    return $has;
}

// Same pattern for migration 026 (rides.surge_applied) - lets the app
// show an honest "⚡ Peak surge applied" badge using the surge status
// AT THE TIME the fare was actually calculated, rather than whatever
// the current time happens to be when someone later looks at the ride.
function rides_has_surge_column($pdo) {
    static $has = null;
    if ($has !== null) return $has;
    $has = false;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            if (strtolower($c['Field']) === 'surge_applied') { $has = true; break; }
        }
    } catch (Exception $e) { /* stay false - treat as not migrated yet */ }
    return $has;
}

// Same pattern for migration 040 (rides.cancel_reason). Without this,
// the reason a passenger picks when cancelling (js/ride.js's
// showCancelReasons/doCancel already sends it as body.reason) was
// silently discarded - the cancel UPDATE never wrote it anywhere, so a
// cluster of same-day cancellations had no way to be explained after
// the fact.
function rides_has_cancel_reason_column($pdo) {
    static $has = null;
    if ($has !== null) return $has;
    $has = false;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            if (strtolower($c['Field']) === 'cancel_reason') { $has = true; break; }
        }
    } catch (Exception $e) { /* stay false - treat as not migrated yet */ }
    return $has;
}

/* ── Driver self-submitted compliance documents ─────────────────────
   A driver can submit their own insurance/technical inspection/road
   fund expiry date + a photo of the sticker directly from their own
   app, instead of only an admin entering it. Same pattern this
   codebase already uses for anything a driver self-reports that other
   people then rely on (driver_vehicle_change_requests,
   admin_get_flagged_identities): goes into a pending queue an admin
   must approve before it becomes the live, Traffic-Officer-visible
   value - a self-reported "valid until 2030" with no review would
   defeat the entire point of an officer trusting this data. */
if (!function_exists('rides_ensure_compliance_requests_table')) {
    function rides_ensure_compliance_requests_table($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS driver_compliance_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            driver_user_id INT UNSIGNED NOT NULL,
            doc_type ENUM('insurance','technical_inspection','road_fund') NOT NULL,
            requested_expiry_date DATE NOT NULL,
            requested_receipt_url VARCHAR(500) NOT NULL DEFAULT '',
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reject_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by_user_id INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY idx_driver_status (driver_user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

switch ($action) {

// ── ACTIVE VEHICLE TYPES (driver onboarding / vehicle-info screens) ──
// Previously the "Choose a service" step and the vehicle-type dropdown
// were a hardcoded 4-option list (Bajaj/Motorcycle/Car/Minibus) baked
// into driver/index.html - adding a new type via Admin -> Pricing (like
// "Qute") updated fare calculation everywhere but drivers could never
// actually select it, since the picker itself never looked at
// ride_fare_rules. This is what the driver app now calls to build that
// list dynamically.
case 'ride_get_vehicle_types': {
    $rows = array();
    try {
        $stmt = $pdo->query("SELECT vehicle_type, base_fare FROM ride_fare_rules WHERE active=1 ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* fall through to default below */ }
    if (!$rows) {
        // Same fallback set/order as the driver picker's own default,
        // with the same base fares rides_calc_fare() would use if
        // ride_fare_rules is empty/missing.
        $rows = array(
            array('vehicle_type' => 'bajaj',   'base_fare' => 120.00),
            array('vehicle_type' => 'moto',    'base_fare' => 138.00),
            array('vehicle_type' => 'car',     'base_fare' => 180.00),
            array('vehicle_type' => 'minibus', 'base_fare' => 156.00),
        );
    }
    $types = array();
    $baseFares = array();
    foreach ($rows as $row) {
        $types[] = $row['vehicle_type'];
        $baseFares[$row['vehicle_type']] = floatval($row['base_fare']);
    }
    /* Availability per type: how many drivers of that type are online
       right now. Lets the booking screen grey out a type with nobody
       to serve it, instead of letting a customer book a ride that will
       just sit in 'searching' forever - which is exactly what happens
       today if e.g. only a Qute driver is registered and a customer
       books Bajaj: the ride is created, no Bajaj driver exists to see
       it, and the customer just watches "Connecting..." with no
       explanation. Counts ONLINE drivers specifically (not just
       registered), since a registered-but-offline driver can't accept
       anything either.

       BUG FIX: this must seed every known type at 0 first. The GROUP BY
       query only returns rows for types that HAVE at least one online
       driver right now - a type with zero online drivers (or zero
       drivers ever registered) simply never appears in that result set
       at all. The client's availability check originally treated "not
       in the list" as "unknown, so allow" (a safe default for when the
       whole feature fails) - but that made every type with truly zero
       drivers look exactly like "data unavailable," so nothing ever
       actually got disabled. Seeding zeros up front means a genuinely
       absent type reads as 0, not missing. */
    $availability = array();
    foreach ($types as $t) { $availability[$t] = 0; }
    $availabilityKnown = true;
    try {
        $avStmt = $pdo->query("SELECT LOWER(vehicle_type) AS vehicle_type, COUNT(*) AS n FROM ride_drivers WHERE online=1 GROUP BY LOWER(vehicle_type)");
        foreach ($avStmt->fetchAll(PDO::FETCH_ASSOC) as $av) {
            $availability[$av['vehicle_type']] = intval($av['n']);
        }
    } catch (Exception $e) {
        $availabilityKnown = false; /* query itself failed - tell the client so it can
                                        fall back to "allow everything" rather than
                                        reading the seeded zeros as real unavailability */
    }
    /* Per-type rates, so the booking screen can show what a trip will cost for EACH vehicle
       without keeping its own copy of the numbers (the client's built-in table drifts whenever an
       admin edits a rate). Same source the fare engine uses, fallbacks included. */
    $rates = array();
    foreach ($types as $t) {
        $fr = rides_fare_rule($pdo, strtolower($t));
        $rates[$t] = array('base' => $fr['base_fare'], 'per_km' => $fr['per_km_rate'], 'per_km_open' => $fr['per_km_rate_open'],
                           'min_fare' => $fr['min_fare'], 'min_fare_short' => $fr['min_fare_short_trip']);
    }
    /* Pickup-to-nearest-driver estimate per type (only when the caller sends the pickup point).
       Same freshness rule as the rider's nearby-drivers map: online and seen in the last 3 minutes.
       About 20 km/h through town plus a minute to get moving; shown to riders as "~N min". */
    $eta = array();
    $plat = isset($_GET['pickup_lat']) ? floatval($_GET['pickup_lat']) : (isset($body['pickup_lat']) ? floatval($body['pickup_lat']) : 0);
    $plng = isset($_GET['pickup_lng']) ? floatval($_GET['pickup_lng']) : (isset($body['pickup_lng']) ? floatval($body['pickup_lng']) : 0);
    if ($plat && $plng) {
        try {
            $ds = $pdo->query("SELECT LOWER(vehicle_type) AS vt, lat, lng FROM ride_drivers
                               WHERE online=1 AND lat IS NOT NULL AND lng IS NOT NULL
                                 AND last_seen >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)");
            $nearest = array();
            foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $km = rides_haversine_km($plat, $plng, floatval($d['lat']), floatval($d['lng']));
                if (!isset($nearest[$d['vt']]) || $km < $nearest[$d['vt']]) $nearest[$d['vt']] = $km;
            }
            foreach ($nearest as $vt => $km) {
                if ($km <= 15) $eta[$vt] = max(1, (int)ceil($km * 3) + 1);
            }
        } catch (Exception $e) { /* no ETA: the screen just omits it */ }
    }
    ok(array('vehicle_types' => $types, 'base_fares' => $baseFares, 'rates' => $rates, 'eta_min' => $eta,
             'driver_availability' => $availability, 'availability_known' => $availabilityKnown));
    break;
}

// ── FARE ESTIMATE (used by the vehicle-type picker) ─────────────────
// Previously the booking screen's estimated fare was computed entirely
// client-side with one hardcoded formula (120 + 30*km) that never
// changed no matter which vehicle type (Bajaj/Moto/Car) was selected -
// switching types only changed the badge icon, not the number. Real
// pricing lives in ride_fare_rules (admin-configurable per vehicle
// type, plus peak-hour surge) via rides_calc_fare() - this endpoint is
// the one source of truth for both the live estimate shown here and
// the actual bill at the end of the ride, so they can never drift
// apart the way two separate hardcoded formulas eventually would.
/* ---------------------------------------------------------------------------------------------------------------
   RIDE TIPS (lib/ride_tip.php). The rider sends the tip to an Etel bank account and enters the reference; an admin
   approves it once the money has arrived; then the driver gets all of it (no commission).
   --------------------------------------------------------------------------------------------------------------- */
case 'ride_tip_info': {
    require_once __DIR__ . '/../chapa_bridge.php';
    // POST: ride_id -> can this rider tip, the bank accounts to pay into, and the tip already left (if any)
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    if ($rid <= 0) fail('ride_id required', 400);
    $e = rides_tip_eligible($pdo, $rid, $u['id']);
    $q = $pdo->prepare("SELECT amount, status, payment_ref FROM ride_tips WHERE ride_id=? LIMIT 1");
    $q->execute(array($rid));
    $existing = $q->fetch(PDO::FETCH_ASSOC);
    $accounts = array();
    try { $accounts = $pdo->query("SELECT bank_key, display_name, account_name, account_number FROM bank_accounts WHERE active=1")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $ex) { /* none set up */ }
    $driverName = '';
    if ($e['ride'] && $e['ride']['driver_id']) {
        $dn = $pdo->prepare("SELECT name FROM users WHERE id=?");
        $dn->execute(array($e['ride']['driver_id']));
        $driverName = (string)$dn->fetchColumn();
    }
    ok(array('eligible' => $e['ok'] && !$existing, 'reason' => $existing ? 'You already left a tip for this trip' : $e['error'],
             'existing' => $existing ? array('amount' => floatval($existing['amount']), 'status' => $existing['status']) : null,
             'accounts' => $accounts, 'chapa' => chapa_enabled(), 'max' => rides_tip_max($pdo), 'presets' => array(10, 20, 50, 100), 'driver_name' => $driverName));
    break;
}

case 'ride_tip_chapa_init': {
    // POST: ride_id, amount   Start a Chapa checkout for a tip. The driver is paid when Chapa confirms (chapa_settle).
    require_once __DIR__ . '/../chapa_bridge.php';
    if (!chapa_enabled()) fail('Chapa is not set up on this server yet. Please tip by bank transfer.', 503);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $amount = isset($body['amount']) ? round(floatval($body['amount']), 2) : 0;
    if ($rid <= 0) fail('ride_id required', 400);
    $max = rides_tip_max($pdo);
    if ($amount < 5 || $amount > $max) fail('A tip must be between ETB 5 and ETB ' . intval($max), 400);
    rate_limit_check($pdo, 'ride_tip_chapa:' . intval($u['id']), 10, 3600, 3600);
    rate_limit_fail($pdo, 'ride_tip_chapa:' . intval($u['id']));
    $e = rides_tip_eligible($pdo, $rid, $u['id']);
    if (!$e['ok']) fail($e['error'], 409);
    $x = $pdo->prepare("SELECT 1 FROM ride_tips WHERE ride_id=? LIMIT 1");
    $x->execute(array($rid));
    if ($x->fetchColumn()) fail('You already left a tip for this trip', 409);
    chapa_ensure_table($pdo);
    $txRef = 'EDT-' . $rid . '-' . strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare("INSERT INTO chapa_payments (tx_ref, kind, target_id, user_id, amount) VALUES (?,?,?,?,?)")
        ->execute(array($txRef, 'tip', $rid, $u['id'], $amount));
    $base = chapa_base_url();
    $url = chapa_initialize($txRef, $amount, $u,
        $base . '/api/chapa-return.php?tx_ref=' . rawurlencode($txRef) . '&kind=tip',
        $base . '/api/chapa-callback.php');
    if (!$url) {
        $pdo->prepare("UPDATE chapa_payments SET status='failed' WHERE tx_ref=?")->execute(array($txRef));
        fail('Could not reach Chapa right now. Please try again or tip by bank transfer.', 502);
    }
    ok(array('checkout_url' => $url, 'tx_ref' => $txRef, 'amount' => $amount));
    break;
}

case 'hub_counts': {
    // POST: (no fields)   Small live counts for the ETEL app's golden light (trips, new requests, deliveries).
    // Every count is its own guarded query, so a missing table or column reads as 0 instead of failing the call.
    $one = function ($sql, $params) use ($pdo) {
        try { $q = $pdo->prepare($sql); $q->execute($params); return intval($q->fetchColumn()); }
        catch (Exception $e) { return 0; } catch (Throwable $e) { return 0; }
    };
    $uid = intval($u['id']);
    $role = isset($u['role']) ? $u['role'] : '';
    $trips = $one("SELECT COUNT(*) FROM rides WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived')", array($uid));
    $requests = 0; $orders = 0; $deliveries = 0;

    if ($role === 'driver') {
        $trips += $one("SELECT COUNT(*) FROM rides WHERE driver_id=? AND status IN ('accepted','in_progress','arrived')", array($uid));
        $vt = 'bajaj';
        try { $me = rides_driver_profile($pdo, $uid); if ($me && !empty($me['vehicle_type'])) $vt = $me['vehicle_type']; } catch (Exception $e) {}
        $requests = $one("SELECT COUNT(*) FROM rides WHERE status='searching' AND passenger_id != ? AND (expires_at IS NULL OR expires_at > NOW()) AND (LOWER(vehicle_type)=LOWER(?) OR vehicle_type IS NULL)", array($uid, $vt));
    } elseif ($role === 'courier') {
        $cid = $one("SELECT id FROM couriers WHERE user_id=? LIMIT 1", array($uid));
        if ($cid) {
            $orders = $one("SELECT COUNT(*) FROM order_courier_offers WHERE courier_id=? AND response='pending' AND expires_at > NOW()", array($cid));
            $deliveries += $one("SELECT COUNT(*) FROM orders WHERE courier_id=? AND status IN ('assigned','picked_up','in_transit')", array($cid));
        }
    } elseif ($role === 'service_giver') {
        $sid = $one("SELECT id FROM service_givers WHERE user_id=? LIMIT 1", array($uid));
        if ($sid) {
            $orders = $one("SELECT COUNT(*) FROM order_assignments WHERE service_giver_id=? AND status='pending'", array($sid));
            $deliveries += $one("SELECT COUNT(*) FROM order_assignments WHERE service_giver_id=? AND status IN ('accepted','preparing','assigned','picked_up')", array($sid));
        }
    }
    // Anyone can also be a customer: orders they placed that are still on their way.
    $deliveries += $one("SELECT COUNT(*) FROM orders WHERE customer_id=? AND status IN ('pending','accepted','assigned','picked_up','in_transit')", array($uid));

    ok(array('trips' => $trips, 'requests' => $requests, 'orders' => $orders, 'deliveries' => $deliveries,
             'total' => $trips + $requests + $orders + $deliveries));
    break;
}

case 'ride_tip_give': {
    // POST: ride_id, amount, bank_key?, payment_ref (the transfer reference), note?
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $amount = isset($body['amount']) ? round(floatval($body['amount']), 2) : 0;
    $ref = isset($body['payment_ref']) ? trim($body['payment_ref']) : '';
    $bank = isset($body['bank_key']) ? substr(preg_replace('/[^a-z0-9_-]/i', '', $body['bank_key']), 0, 30) : '';
    $note = isset($body['note']) ? mb_substr(trim($body['note']), 0, 255) : '';
    if ($rid <= 0) fail('ride_id required', 400);
    $max = rides_tip_max($pdo);
    if ($amount < 5 || $amount > $max) fail('A tip must be between ETB 5 and ETB ' . intval($max), 400);
    if (strlen($ref) < 4 || strlen($ref) > 60) fail('Enter the transfer reference from your bank (4-60 characters)', 400);
    rate_limit_check($pdo, 'ride_tip:' . intval($u['id']), 10, 3600, 3600);
    $e = rides_tip_eligible($pdo, $rid, $u['id']);
    if (!$e['ok']) fail($e['error'], 409);
    try {
        $pdo->prepare("INSERT INTO ride_tips (ride_id, rider_id, driver_id, amount, bank_key, payment_ref, note, status) VALUES (?,?,?,?,?,?,?, 'pending')")
            ->execute(array($rid, $u['id'], intval($e['ride']['driver_id']), $amount, $bank, $ref, $note));
    } catch (PDOException $ex) {
        rate_limit_fail($pdo, 'ride_tip:' . intval($u['id']));
        if ($ex->getCode() === '23000') {
            $m = (string)$ex->getMessage();
            if (strpos($m, 'uq_ride_tip_ride') !== false) fail('You already left a tip for this trip', 409);
            fail('That transfer reference was already used for another tip', 409);
        }
        fail_ex($ex, 'Could not save the tip', 500);
    }
    ok(array('id' => intval($pdo->lastInsertId()), 'status' => 'pending',
             'message' => 'Thank you! The tip reaches your driver once Etel confirms the transfer.'));
    break;
}

/* ========== PRICE OFFER AND COUNTER-OFFERS (lib/ride_price_offer.php) ========== */

case 'ride_offer_bounds': {
    // POST: fare   What a rider may offer for a trip quoted at this fare (the Book screen asks before showing the offer box).
    $fare = isset($body['fare']) ? floatval($body['fare']) : 0;
    if ($fare <= 0) fail('fare is required', 400);
    $b = rides_offer_bounds($pdo, $fare);
    ok(array('enabled' => rides_offer_price_enabled($pdo), 'min' => $b['min'], 'max' => $b['max']));
    break;
}

case 'driver_counter_offer': {
    // POST: request_id, price   One counter per driver per ride, above the rider's offer and inside the allowed band.
    rides_require_onboarded($pdo, $u['id']);
    rate_limit_check($pdo, 'counter:' . $u['id'], 40, 300, 300);
    rate_limit_fail($pdo, 'counter:' . $u['id']);
    rides_offer_price_ensure($pdo);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    $price = isset($body['price']) ? round(floatval($body['price'])) : 0;
    if ($rid <= 0 || $price <= 0) fail('request_id and price are required', 400);
    if (rides_get_active_for_driver($pdo, $u['id'])) fail('Finish your current ride first', 409);
    $q = $pdo->prepare("SELECT id, passenger_id, status, vehicle_type, rider_offer, fare_estimate, fare_final FROM rides WHERE id=? LIMIT 1");
    $q->execute(array($rid));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['status'] !== 'searching') fail('Ride was already taken or cancelled', 409);
    if (floatval($r['rider_offer']) <= 0) fail('This rider did not ask for price offers', 409);
    if (intval($r['passenger_id']) === intval($u['id'])) fail('You cannot answer your own request', 400);
    rides_ensure_exclusions_table($pdo);
    $ex = $pdo->prepare("SELECT 1 FROM ride_driver_exclusions WHERE ride_id=? AND driver_id=? LIMIT 1");
    $ex->execute(array($rid, $u['id']));
    if ($ex->fetchColumn()) fail('Ride was already taken or cancelled', 409);
    $me = rides_driver_profile($pdo, $u['id']);
    $myV = (!empty($me['vehicle_type'])) ? $me['vehicle_type'] : 'bajaj';
    if (!empty($r['vehicle_type']) && strtolower($r['vehicle_type']) !== strtolower($myV)) fail('This request is for another vehicle type', 409);
    $bnd = rides_offer_bounds($pdo, floatval($r['fare_estimate']) > 0 ? $r['fare_estimate'] : $r['fare_final']);
    if ($price <= floatval($r['rider_offer'])) fail('A counter-offer must be higher than the rider\'s offer. To take the rider\'s price, accept the ride.', 422);
    if ($price > $bnd['max']) fail('The most you can ask for this trip is ETB ' . $bnd['max'], 422);
    $mine = $pdo->prepare("SELECT status FROM ride_counter_offers WHERE ride_id=? AND driver_id=? LIMIT 1");
    $mine->execute(array($rid, $u['id']));
    $prev = $mine->fetchColumn();
    if ($prev === 'declined') fail('The rider declined your offer for this trip', 409);
    if ($prev === false) {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM ride_counter_offers WHERE ride_id=? AND status='pending'");
        $cnt->execute(array($rid));
        if (intval($cnt->fetchColumn()) >= max(1, intval(rides_rule_value($pdo, 'ride_counter_max', 5)))) fail('This rider already has enough offers', 409);
    }
    $pdo->prepare("INSERT INTO ride_counter_offers (ride_id, driver_id, price, status, updated_at) VALUES (?,?,?,'pending',NOW())
                   ON DUPLICATE KEY UPDATE price=VALUES(price), status='pending', updated_at=NOW()")->execute(array($rid, $u['id'], $price));
    try {
        etel_push_send($pdo, intval($r['passenger_id']), 'New price offer',
            'A driver offers ETB ' . number_format($price, 0) . ' for your trip. Open the app to choose.',
            array('action' => 'ride_counter', 'ride_id' => $rid, 'url' => '/delivery/ride/'));
    } catch (Exception $e) { /* push is best effort */ }
    ok(array('request_id' => $rid, 'price' => $price, 'status' => 'pending'));
    break;
}

case 'ride_counter_list': {
    // POST: ride_id   The rider's own searching trip: the offer, the allowed band and the drivers' counters.
    rides_offer_price_ensure($pdo);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $q = $pdo->prepare("SELECT id, status, rider_offer, fare_estimate, fare_final, pickup_lat, pickup_lng FROM rides WHERE id=? AND passenger_id=? LIMIT 1");
    $q->execute(array($rid, $u['id']));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Ride not found', 404);
    $quote = floatval($r['fare_estimate']) > 0 ? $r['fare_estimate'] : $r['fare_final'];
    $bnd = rides_offer_bounds($pdo, $quote);
    $out = array();
    if ($r['status'] === 'searching' && floatval($r['rider_offer']) > 0) {
        $ttl = max(20, intval(rides_rule_value($pdo, 'ride_counter_ttl_secs', 90)));
        $c = $pdo->prepare(
            "SELECT c.id, c.price, TIMESTAMPDIFF(SECOND, c.updated_at, NOW()) AS age_s, u.name AS driver_name,
                    rd.vehicle_type, rd.vehicle_make, rd.vehicle_color, rd.vehicle_plate, rd.selfie_url AS driver_photo, rd.lat, rd.lng,
                    CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS driver_rating
             FROM ride_counter_offers c
             JOIN users u ON u.id = c.driver_id
             LEFT JOIN ride_drivers rd ON rd.user_id = c.driver_id
             WHERE c.ride_id=? AND c.status='pending' AND c.updated_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY c.price ASC, c.id ASC LIMIT 10");
        $c->execute(array($rid, $ttl));
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $km = ($row['lat'] !== null && $row['lng'] !== null && $r['pickup_lat'] !== null)
                ? round(rides_haversine_km(floatval($r['pickup_lat']), floatval($r['pickup_lng']), floatval($row['lat']), floatval($row['lng'])), 1) : null;
            $out[] = array('id' => intval($row['id']), 'price' => floatval($row['price']), 'age_s' => intval($row['age_s']), 'ttl_s' => $ttl,
                'driver_name' => $row['driver_name'], 'driver_rating' => floatval($row['driver_rating']), 'vehicle_type' => $row['vehicle_type'],
                'vehicle' => trim($row['vehicle_make'] . ' ' . $row['vehicle_color']), 'plate' => $row['vehicle_plate'],
                'driver_photo' => $row['driver_photo'], 'km_away' => $km);
        }
    }
    ok(array('ride_id' => $rid, 'status' => $r['status'], 'rider_offer' => floatval($r['rider_offer']) > 0 ? floatval($r['rider_offer']) : null,
             'min' => $bnd['min'], 'max' => $bnd['max'], 'counters' => $out));
    break;
}

case 'ride_counter_accept': {
    // POST: ride_id, counter_id   The rider picks a driver's price: the driver gets the trip and the price is fixed.
    rides_offer_price_ensure($pdo);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $cid = isset($body['counter_id']) ? intval($body['counter_id']) : 0;
    $q = $pdo->prepare("SELECT c.id, c.driver_id, c.price, c.status, c.updated_at FROM ride_counter_offers c
                        JOIN rides r ON r.id = c.ride_id WHERE c.id=? AND c.ride_id=? AND r.passenger_id=? LIMIT 1");
    $q->execute(array($cid, $rid, $u['id']));
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) fail('Offer not found', 404);
    if ($c['status'] !== 'pending') fail('That offer is no longer open', 409);
    $ttl = max(20, intval(rides_rule_value($pdo, 'ride_counter_ttl_secs', 90)));
    if (!$c['updated_at'] || strtotime($c['updated_at']) < time() - $ttl - 5) fail('That offer has expired', 409);
    if (rides_get_active_for_driver($pdo, intval($c['driver_id']))) {
        $pdo->prepare("UPDATE ride_counter_offers SET status='declined', updated_at=NOW() WHERE id=?")->execute(array($cid));
        fail('That driver is no longer available. Choose another offer.', 409);
    }
    rides_ensure_exclusions_table($pdo);
    $claim = $pdo->prepare(
        "UPDATE rides SET driver_id=?, status='accepted', assigned_at=NOW(), agreed_fare=?, fare_final=?
         WHERE id=? AND passenger_id=? AND status='searching'
           AND NOT EXISTS (SELECT 1 FROM ride_driver_exclusions x WHERE x.ride_id = rides.id AND x.driver_id = ?)");
    $claim->execute(array(intval($c['driver_id']), $c['price'], $c['price'], $rid, $u['id'], intval($c['driver_id'])));
    if (!$claim->rowCount()) fail('This trip was already taken or cancelled', 409);
    $pdo->prepare("UPDATE ride_counter_offers SET status = IF(id=?, 'accepted', 'declined'), updated_at=NOW() WHERE ride_id=? AND status='pending'")
        ->execute(array($cid, $rid));
    try {
        etel_push_send($pdo, intval($c['driver_id']), 'Your price was accepted',
            'The rider accepted ETB ' . number_format(floatval($c['price']), 0) . '. Head to the pickup.',
            array('action' => 'ride_accepted', 'ride_id' => $rid, 'url' => '/delivery/driver/'));
    } catch (Exception $e) { /* push is best effort */ }
    ok(array('ride_id' => $rid, 'driver_id' => intval($c['driver_id']), 'agreed_fare' => floatval($c['price'])));
    break;
}

case 'ride_counter_decline': {
    // POST: ride_id, counter_id
    rides_offer_price_ensure($pdo);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $cid = isset($body['counter_id']) ? intval($body['counter_id']) : 0;
    $d = $pdo->prepare("UPDATE ride_counter_offers c JOIN rides r ON r.id = c.ride_id SET c.status='declined', c.updated_at=NOW()
                        WHERE c.id=? AND c.ride_id=? AND r.passenger_id=? AND c.status='pending'");
    $d->execute(array($cid, $rid, $u['id']));
    if (!$d->rowCount()) fail('Offer not found', 404);
    ok(array('declined' => true));
    break;
}

case 'ride_raise_offer': {
    // POST: ride_id, offer   The rider raises their own offer while still searching (never lowers it).
    rides_offer_price_ensure($pdo);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $offer = isset($body['offer']) ? round(floatval($body['offer'])) : 0;
    $q = $pdo->prepare("SELECT id, status, rider_offer, fare_estimate, fare_final FROM rides WHERE id=? AND passenger_id=? LIMIT 1");
    $q->execute(array($rid, $u['id']));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Ride not found', 404);
    if ($r['status'] !== 'searching' || floatval($r['rider_offer']) <= 0) fail('You can only raise the offer while drivers are being found', 409);
    $bnd = rides_offer_bounds($pdo, floatval($r['fare_estimate']) > 0 ? $r['fare_estimate'] : $r['fare_final']);
    if ($offer <= floatval($r['rider_offer'])) fail('The new offer must be higher than your current offer', 422);
    if ($offer > $bnd['max']) fail('The most you can offer for this trip is ETB ' . $bnd['max'], 422);
    $up = $pdo->prepare("UPDATE rides SET rider_offer=?, fare_final=? WHERE id=? AND passenger_id=? AND status='searching'");
    $up->execute(array($offer, $offer, $rid, $u['id']));
    if (!$up->rowCount()) fail('You can only raise the offer while drivers are being found', 409);
    // a counter at or below the new offer is moot: the driver can simply accept the ride at the new price
    $pdo->prepare("UPDATE ride_counter_offers SET status='expired', updated_at=NOW() WHERE ride_id=? AND status='pending' AND price <= ?")->execute(array($rid, $offer));
    ok(array('ride_id' => $rid, 'rider_offer' => $offer));
    break;
}

case 'driver_get_statement': {
    // POST: week_start? (any date; the week is Monday to Sunday, default this week, up to 12 weeks back)
    // The driver's own weekly statement: every trip with Etel's 5% shown, totals, tips and hours online.
    $today = date('Y-m-d');
    $want = isset($body['week_start']) ? trim($body['week_start']) : $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $want) || rides_statement_monday($want) === null) fail('Invalid date format, expected YYYY-MM-DD', 400);
    $thisMonday = rides_statement_monday($today);
    $monday = rides_statement_monday($want);
    if ($monday > $thisMonday) $monday = $thisMonday;
    $oldest = date('Y-m-d', strtotime($thisMonday . ' -84 days'));
    if ($monday < $oldest) fail('Statements go back 12 weeks', 400);
    $st = rides_statement_week($pdo, $u['id'], $monday);
    $st['prev_week'] = $monday > $oldest ? date('Y-m-d', strtotime($monday . ' -7 days')) : null;
    $st['next_week'] = $monday < $thisMonday ? date('Y-m-d', strtotime($monday . ' +7 days')) : null;
    ok($st);
    break;
}

case 'driver_get_tips': {
    // the driver's own tips: approved ones are already in total_earnings
    rides_tip_ensure($pdo);
    $q = $pdo->prepare("SELECT t.ride_id, t.amount, t.created_at, t.reviewed_at FROM ride_tips t WHERE t.driver_id=? AND t.status='approved' ORDER BY t.id DESC LIMIT 50");
    $q->execute(array($u['id']));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $tot = 0.0; foreach ($rows as $r) $tot += floatval($r['amount']);
    ok(array('tips' => $rows, 'total' => round($tot, 2)));
    break;
}

case 'admin_ride_tip_list': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    rides_tip_ensure($pdo);
    $st = isset($body['status']) ? $body['status'] : '';
    $where = in_array($st, array('pending', 'approved', 'rejected'), true) ? "WHERE t.status = " . $pdo->quote($st) : '';
    $rows = $pdo->query("SELECT t.*, r.name AS rider_name, r.phone AS rider_phone, d.name AS driver_name, d.phone AS driver_phone
                         FROM ride_tips t LEFT JOIN users r ON r.id=t.rider_id LEFT JOIN users d ON d.id=t.driver_id
                         $where ORDER BY FIELD(t.status,'pending','approved','rejected'), t.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = intval($pdo->query("SELECT COUNT(*) FROM ride_tips WHERE status='pending'")->fetchColumn());
    ok(array('tips' => $rows, 'pending_count' => $pendingCount));
    break;
}

case 'admin_ride_tip_review': {
    // POST: id, decision (approve|reject), note?   approve = the money arrived: the driver gets all of it
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    rides_tip_ensure($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $decision = isset($body['decision']) ? $body['decision'] : '';
    $note = isset($body['note']) ? mb_substr(trim($body['note']), 0, 255) : null;
    if ($id <= 0 || !in_array($decision, array('approve', 'reject'), true)) fail('id and a decision (approve or reject) are required', 400);
    $q = $pdo->prepare("SELECT * FROM ride_tips WHERE id=?");
    $q->execute(array($id));
    $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$t) fail('Tip not found', 404);
    if ($t['status'] !== 'pending') fail('This tip was already decided', 409);
    $pdo->beginTransaction();
    try {
        // the status flip is the claim: a double click credits the driver once
        $c = $pdo->prepare("UPDATE ride_tips SET status=?, reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=? AND status='pending'");
        $c->execute(array($decision === 'approve' ? 'approved' : 'rejected', $u['id'], $note, $id));
        if ($c->rowCount() < 1) { $pdo->rollBack(); fail('This tip was already decided', 409); }
        if ($decision === 'approve') {
            rides_add_missing_cols($pdo, 'ride_drivers', array('total_earnings' => "total_earnings DECIMAL(12,2) DEFAULT 0"));
            $pdo->prepare("UPDATE ride_drivers SET total_earnings = total_earnings + ? WHERE user_id=?")->execute(array($t['amount'], $t['driver_id']));
        }
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($ex, 'Could not save the decision', 500);
    }
    ok(array('id' => $id, 'status' => $decision === 'approve' ? 'approved' : 'rejected'));
    break;
}

/* ---------------------------------------------------------------------------------------------------------------
   RIDE PROMO CODES (lib/ride_promo.php). Anyone signed in can propose a code; it works only after an admin approves it.
   --------------------------------------------------------------------------------------------------------------- */
case 'ride_promo_check': {
    // POST: code, fare (the fare shown on the Book screen, used only for the preview) -> what the code would take off
    rate_limit_check($pdo, 'ride_promo:' . intval($u['id']), 20, 600, 600);
    $r = rides_promo_check($pdo, isset($body['code']) ? $body['code'] : '', $u['id'], isset($body['fare']) ? floatval($body['fare']) : 0);
    if (!$r['ok']) { rate_limit_fail($pdo, 'ride_promo:' . intval($u['id'])); fail($r['error'], 422); }
    ok(array('code' => $r['promo']['code'], 'discount' => $r['discount'], 'type' => $r['promo']['discount_type'],
             'value' => floatval($r['promo']['value']), 'description' => $r['promo']['description'],
             'min_fare' => floatval($r['promo']['min_fare'])));
    break;
}

case 'ride_promo_propose': {
    // POST: code, discount_type (percent|fixed), value, max_discount?, min_fare?, expires_at?, max_uses?, per_rider_limit?, description?
    rides_promo_ensure($pdo);
    $code = rides_promo_normalize(isset($body['code']) ? $body['code'] : '');
    if (strlen($code) < 3 || strlen($code) > 20) fail('Code must be 3-20 letters or numbers', 400);
    $type = isset($body['discount_type']) ? $body['discount_type'] : 'percent';
    if (!in_array($type, array('percent', 'fixed'), true)) fail('Discount must be a percent or a fixed amount', 400);
    $value = isset($body['value']) ? floatval($body['value']) : 0;
    if ($type === 'percent' && ($value < 1 || $value > 50)) fail('A proposed percent must be between 1 and 50', 400);
    if ($type === 'fixed' && ($value < 1 || $value > 300)) fail('A proposed amount must be between ETB 1 and ETB 300', 400);
    $max = (isset($body['max_discount']) && $body['max_discount'] !== '') ? floatval($body['max_discount']) : null;
    if ($max !== null && ($max < 1 || $max > 300)) fail('Largest discount must be between ETB 1 and ETB 300', 400);
    $minFare = (isset($body['min_fare']) && $body['min_fare'] !== '') ? floatval($body['min_fare']) : 0;
    if ($minFare < 0 || $minFare > 2000) fail('Minimum fare must be between 0 and 2000', 400);
    $exp = (isset($body['expires_at']) && $body['expires_at'] !== '') ? trim($body['expires_at']) : null;
    if ($exp !== null) { $ts = strtotime($exp); if ($ts === false || $ts < time()) fail('Expiry date must be in the future', 400); $exp = date('Y-m-d H:i:s', $ts); }
    $maxUses = (isset($body['max_uses']) && $body['max_uses'] !== '') ? intval($body['max_uses']) : 50;
    if ($maxUses < 1 || $maxUses > 1000) fail('Number of uses must be between 1 and 1000', 400);
    $perRider = (isset($body['per_rider_limit']) && $body['per_rider_limit'] !== '') ? intval($body['per_rider_limit']) : 1;
    if ($perRider < 1 || $perRider > 5) fail('Uses per rider must be between 1 and 5', 400);
    $desc = isset($body['description']) ? mb_substr(trim($body['description']), 0, 255) : null;
    $pend = $pdo->prepare("SELECT COUNT(*) FROM ride_promo_codes WHERE created_by=? AND status='pending'");
    $pend->execute(array($u['id']));
    if (intval($pend->fetchColumn()) >= 5) fail('You already have 5 codes waiting for approval. Wait for a decision first.', 429);
    try {
        $pdo->prepare("INSERT INTO ride_promo_codes (code, description, discount_type, value, max_discount, min_fare, expires_at, max_uses, per_rider_limit, status, created_by, created_by_role)
                       VALUES (?,?,?,?,?,?,?,?,?, 'pending', ?, ?)")
            ->execute(array($code, $desc, $type, $value, $max, $minFare, $exp, $maxUses, $perRider, $u['id'], $u['role']));
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') fail('That code already exists - pick another', 409);
        fail_ex($e, 'Could not save the promo code', 500);
    }
    ok(array('id' => intval($pdo->lastInsertId()), 'code' => $code, 'status' => 'pending',
             'message' => 'Sent for approval. It works once an admin approves it.'));
    break;
}

case 'ride_promo_mine': {
    rides_promo_ensure($pdo);
    $q = $pdo->prepare("SELECT id, code, description, discount_type, value, max_discount, min_fare, expires_at, max_uses, used_count, per_rider_limit, status, review_note, created_at
                        FROM ride_promo_codes WHERE created_by=? ORDER BY id DESC LIMIT 50");
    $q->execute(array($u['id']));
    ok(array('promos' => $q->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'admin_ride_promo_list': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    rides_promo_ensure($pdo);
    $st = isset($body['status']) ? $body['status'] : (isset($_GET['status']) ? $_GET['status'] : '');
    $where = in_array($st, array('pending', 'approved', 'rejected', 'disabled'), true) ? "WHERE p.status = " . $pdo->quote($st) : '';
    $rows = $pdo->query("SELECT p.*, u.name AS creator_name, u.phone AS creator_phone,
                                COALESCE((SELECT SUM(r.discount) FROM ride_promo_redemptions r WHERE r.promo_id=p.id),0) AS total_discount
                         FROM ride_promo_codes p LEFT JOIN users u ON u.id = p.created_by
                         $where ORDER BY FIELD(p.status,'pending','approved','disabled','rejected'), p.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = intval($pdo->query("SELECT COUNT(*) FROM ride_promo_codes WHERE status='pending'")->fetchColumn());
    ok(array('promos' => $rows, 'pending_count' => $pendingCount));
    break;
}

case 'admin_ride_promo_review': {
    // POST: id, decision (approve|reject|disable|enable), note?, and on approve optional adjustments:
    //       value, max_discount, min_fare, expires_at, max_uses, per_rider_limit
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    rides_promo_ensure($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $decision = isset($body['decision']) ? $body['decision'] : '';
    $note = isset($body['note']) ? mb_substr(trim($body['note']), 0, 255) : null;
    if ($id <= 0 || !in_array($decision, array('approve', 'reject', 'disable', 'enable'), true)) fail('id and a decision (approve, reject, disable, enable) are required', 400);
    $q = $pdo->prepare("SELECT * FROM ride_promo_codes WHERE id=?");
    $q->execute(array($id));
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) fail('Promo code not found', 404);
    if ($decision === 'approve' && $p['status'] !== 'pending') fail('Only a code waiting for approval can be approved', 409);
    if ($decision === 'reject' && $p['status'] !== 'pending') fail('Only a code waiting for approval can be rejected', 409);
    if ($decision === 'disable' && $p['status'] !== 'approved') fail('Only an approved code can be disabled', 409);
    if ($decision === 'enable' && $p['status'] !== 'disabled') fail('Only a disabled code can be enabled', 409);
    $newStatus = array('approve' => 'approved', 'reject' => 'rejected', 'disable' => 'disabled', 'enable' => 'approved');
    $set = "status=?, reviewed_by=?, reviewed_at=NOW(), review_note=?";
    $args = array($newStatus[$decision], $u['id'], $note);
    if ($decision === 'approve') {
        // the admin may tighten or correct the proposal when approving
        if (isset($body['value']) && $body['value'] !== '') {
            $v = floatval($body['value']);
            if ($v <= 0 || ($p['discount_type'] === 'percent' && $v > 80)) fail('Percent must be between 1 and 80', 400);
            $set .= ", value=?"; $args[] = $v;
        }
        if (isset($body['max_discount'])) { $set .= ", max_discount=?"; $args[] = ($body['max_discount'] === '' ? null : max(0, floatval($body['max_discount']))); }
        if (isset($body['min_fare']) && $body['min_fare'] !== '') { $set .= ", min_fare=?"; $args[] = max(0, floatval($body['min_fare'])); }
        if (isset($body['max_uses']) && $body['max_uses'] !== '') { $set .= ", max_uses=?"; $args[] = max(1, intval($body['max_uses'])); }
        if (isset($body['per_rider_limit']) && $body['per_rider_limit'] !== '') { $set .= ", per_rider_limit=?"; $args[] = max(1, intval($body['per_rider_limit'])); }
        if (isset($body['expires_at'])) {
            $e = trim($body['expires_at']);
            if ($e === '') { $set .= ", expires_at=?"; $args[] = null; }
            else { $ts = strtotime($e); if ($ts === false) fail('Invalid expiry date', 400); $set .= ", expires_at=?"; $args[] = date('Y-m-d H:i:s', $ts); }
        }
    }
    $args[] = $id;
    $pdo->prepare("UPDATE ride_promo_codes SET $set WHERE id=?")->execute($args);
    ok(array('id' => $id, 'status' => $newStatus[$decision]));
    break;
}

case 'admin_ride_promo_save': {
    // POST: code, discount_type, value, max_discount?, min_fare?, starts_at?, expires_at?, max_uses?, per_rider_limit?, description?  -> approved at once
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    rides_promo_ensure($pdo);
    $code = rides_promo_normalize(isset($body['code']) ? $body['code'] : '');
    if (strlen($code) < 3 || strlen($code) > 40) fail('Code must be 3-40 letters or numbers', 400);
    $type = isset($body['discount_type']) ? $body['discount_type'] : 'percent';
    if (!in_array($type, array('percent', 'fixed'), true)) fail('Discount must be a percent or a fixed amount', 400);
    $value = isset($body['value']) ? floatval($body['value']) : 0;
    if ($type === 'percent' && ($value < 1 || $value > 80)) fail('Percent must be between 1 and 80', 400);
    if ($type === 'fixed' && $value < 1) fail('Amount must be at least ETB 1', 400);
    $nn = function ($k) use ($body) { return (isset($body[$k]) && $body[$k] !== '' && $body[$k] !== null) ? $body[$k] : null; };
    $max = $nn('max_discount') !== null ? max(0, floatval($body['max_discount'])) : null;
    $minFare = $nn('min_fare') !== null ? max(0, floatval($body['min_fare'])) : 0;
    $starts = $nn('starts_at'); $exp = $nn('expires_at');
    foreach (array($starts, $exp) as $dt) { if ($dt !== null && strtotime($dt) === false) fail('Invalid date', 400); }
    if ($starts !== null) $starts = date('Y-m-d H:i:s', strtotime($starts));
    if ($exp !== null) $exp = date('Y-m-d H:i:s', strtotime($exp));
    $maxUses = $nn('max_uses') !== null ? max(1, intval($body['max_uses'])) : null;
    $perRider = max(1, intval($nn('per_rider_limit') !== null ? $body['per_rider_limit'] : 1));
    $desc = isset($body['description']) ? mb_substr(trim($body['description']), 0, 255) : null;
    try {
        $pdo->prepare("INSERT INTO ride_promo_codes (code, description, discount_type, value, max_discount, min_fare, starts_at, expires_at, max_uses, per_rider_limit, status, created_by, created_by_role, reviewed_by, reviewed_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?, 'approved', ?, 'admin', ?, NOW())")
            ->execute(array($code, $desc, $type, $value, $max, $minFare, $starts, $exp, $maxUses, $perRider, $u['id'], $u['id']));
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') fail('That code already exists', 409);
        fail_ex($e, 'Could not save the promo code', 500);
    }
    ok(array('id' => intval($pdo->lastInsertId()), 'code' => $code, 'status' => 'approved'));
    break;
}

case 'ride_estimate_fare': {
    $km = isset($body['distance_km']) ? floatval($body['distance_km'])
        : (isset($_GET['distance_km']) ? floatval($_GET['distance_km']) : 0);
    if ($km <= 0) fail('distance_km is required');
    if ($km > 100) $km = 100;
    $vtype = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type']))
        : (isset($_GET['vehicle_type']) ? strtolower(trim($_GET['vehicle_type'])) : 'bajaj');
    $vtype = rides_normalize_vehicle_type($pdo, $vtype);
    $openTrip = !empty($body['open_trip']) || !empty($_GET['open_trip']);
    $result = rides_calc_fare($pdo, $km, $vtype, $openTrip);
    /* PRICE-LOCK: package this exact quote (fare + the inputs that
       produced it) into a signed, short-lived token the client can hand
       back to book_ride. Without this, book_ride independently
       recomputes rides_calc_fare() a second time at the moment the
       user actually confirms - and since surge is evaluated live
       against the current clock, a peak window starting in the gap
       between "customer is looking at the quote" and "customer taps
       confirm" silently changes the price with no warning, so what the
       driver sees can differ from what the customer was shown. Signed
       with the same secret used for auth tokens so it can't be forged
       client-side to request a cheaper fare than what was actually
       quoted. 90s expiry: long enough for a real confirm, short enough
       that surge windows can't be gamed by holding a quote open.
       distance_km and vehicle_type are baked into the signature too,
       so the token can't be replayed against a different route or
       vehicle type than the one it was quoted for. */
    $quoteExp = time() + 90;
    $quotePayload = round($km, 4) . '|' . $vtype . '|' . ($openTrip ? 1 : 0) . '|' . $result['fare'] . '|' . $quoteExp;
    $quoteSig = hash_hmac('sha256', $quotePayload, TOKEN_SECRET_FINAL);
    $quoteToken = base64_encode($quotePayload . '|' . $quoteSig);
    ok(array('fare' => $result['fare'], 'vehicle_type' => $vtype,
             'distance_km' => round($km, 2), 'surge' => $result['surge'],
             /* Actual live multiplier, not just the boolean - the quote
                screen's surge badge used to hardcode "1.3x" in the HTML
                regardless of what's really active, which is wrong for
                any admin-configured window using a different number. */
             'surge_mult' => $result['breakdown']['surge_mult'],
             'quote_token' => $quoteToken, 'quote_expires_in' => 90));
    break;
}

/* ========== PASSENGER ========== */

// ── NEARBY ONLINE DRIVERS (rider's map, before booking) ──────────────
// Shows online drivers near the pickup point as icons on the Book
// screen's map, filtered to the currently-selected vehicle type by
// default (or all types, via the "See All Types" toggle) - this is
// purely a "here's who's around" display and is unrelated to actually
// matching/assigning a driver, which still happens via book_ride.
case 'passenger_get_nearby_drivers': {
    $lat = isset($body['lat']) ? floatval($body['lat']) : (isset($_GET['lat']) ? floatval($_GET['lat']) : 0);
    $lng = isset($body['lng']) ? floatval($body['lng']) : (isset($_GET['lng']) ? floatval($_GET['lng']) : 0);
    if (!$lat || !$lng) fail('lat/lng required');
    $radius = isset($body['radius_km']) ? floatval($body['radius_km']) : (isset($_GET['radius_km']) ? floatval($_GET['radius_km']) : 5);
    if ($radius <= 0 || $radius > 20) $radius = 5;
    $vtype = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type']))
        : (isset($_GET['vehicle_type']) ? strtolower(trim($_GET['vehicle_type'])) : 'all');

    // Hex-grid pre-filter: narrows the candidate set to drivers in the
    // right neighborhood of cells before computing any distance at
    // all, using the (hex_q, hex_r) index instead of scanning every
    // online driver city-wide. The exact Haversine check below still
    // runs on the result - this is purely a cheap first pass to
    // shrink how many rows need one.
    list($hexWhere, $hexParams) = hexgrid_radius_where($lat, $lng, $radius);

    $sql = "SELECT lat, lng, vehicle_type FROM ride_drivers
            WHERE online = 1 AND lat IS NOT NULL AND lng IS NOT NULL
              AND last_seen >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
              AND $hexWhere";
    $params = $hexParams;
    if ($vtype !== 'all' && $vtype === rides_normalize_vehicle_type($pdo, $vtype)) {
        // ride_drivers.vehicle_type stores "Bajaj"/"Motorcycle"/"Car"/"Minibus"
        // (see driver onboarding's <select>), matched loosely here since
        // that's the same normalization driverIconForVehicleType() uses
        // client-side.
        $needle = ($vtype === 'moto') ? 'moto' : $vtype;
        $sql .= " AND LOWER(vehicle_type) LIKE ?";
        $params[] = '%' . $needle . '%';
    }
    $sql .= " LIMIT 200";
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $out = array();
    foreach ($s->fetchAll() as $r) {
        // Exact distance check - the hex pre-filter above can include
        // a few extra candidates near its bounding-box corners (see
        // hexgrid_radius_where's docblock), so this is what actually
        // enforces the true radius, same as before.
        $dist = rides_haversine_km($lat, $lng, floatval($r['lat']), floatval($r['lng']));
        if ($dist > $radius) continue;
        // Deliberately NOT returning user_id/name/phone here - riders
        // only need to see "a driver is here", not who specifically,
        // before any ride is actually booked/assigned.
        $out[] = array('lat' => floatval($r['lat']), 'lng' => floatval($r['lng']), 'vehicle_type' => $r['vehicle_type']);
    }
    ok(array('drivers' => $out));
    break;
}

case 'book_ride': {
    /* SECURITY: rate-limit booking attempts per passenger, reusing the
       same sliding-window mechanism already built for login/register
       brute-force protection (boot.php's rate_limit_check/_fail). This
       is a different concern from the GET_LOCK below - the lock closes
       an exact-duplicate race between two near-simultaneous requests,
       this catches a SUSTAINED burst of sequential requests (a script
       hammering the endpoint), which the lock alone wouldn't stop since
       each request would acquire+release the lock fine one after
       another. 10 attempts per 5 minutes is generous for legitimate
       retries/double-taps while still blocking real abuse. */
    rate_limit_check($pdo, 'book_ride:' . $u['id'], 10, 300, 300);
    rate_limit_fail($pdo, 'book_ride:' . $u['id']);

    /* CONCURRENCY FIX: "one active ride at a time" was a plain
       SELECT-then-INSERT with no transaction/constraint - two book_ride
       calls milliseconds apart (double-tap, or a client retry after a
       slow response) could both pass the check before either INSERT
       lands, creating two live rides for the same passenger. MySQL
       named lock (GET_LOCK) scoped to this passenger's ID serializes
       concurrent requests from the SAME passenger without touching
       other passengers or needing a schema change. GET_LOCK() is
       session-scoped and auto-releases the moment this request's DB
       connection closes - including via fail()'s/ok()'s internal
       exit() calls, which skip PHP's finally but still tear down the
       connection - so most paths below don't need an explicit
       RELEASE_LOCK for correctness, only for promptness. */
    $bookLockKey = 'book_ride_' . intval($u['id']);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lockStmt->execute(array($bookLockKey));
    if (!$lockStmt->fetchColumn()) {
        fail('Still processing your previous request - please wait a moment and try again', 429);
    }
    try {
    $plat = isset($body['pickup_lat'])  ? floatval($body['pickup_lat'])  : 0;
    $plng = isset($body['pickup_lng'])  ? floatval($body['pickup_lng'])  : 0;
    $dlat = isset($body['dropoff_lat']) ? floatval($body['dropoff_lat']) : 0;
    $dlng = isset($body['dropoff_lng']) ? floatval($body['dropoff_lng']) : 0;
    $mkm  = isset($body['manual_km'])   ? floatval($body['manual_km'])   : 0;
    if (!$plat || !$plng) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey)); fail('Pickup location is required'); }
    if ((!$dlat || !$dlng) && $mkm <= 0) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey)); fail('Set a drop-off place, or choose a distance in km'); }
    $paddr = isset($body['pickup_addr'])  ? mb_substr(trim($body['pickup_addr']), 0, 255)  : '';
    $daddr = isset($body['dropoff_addr']) ? mb_substr(trim($body['dropoff_addr']), 0, 255) : '';
    $pm    = isset($body['payment_method']) ? substr(preg_replace('/[^a-z_]/', '', strtolower($body['payment_method'])), 0, 20) : 'cash';
    if ($pm === 'wallet' && !rides_wallet_enabled($pdo)) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey)); fail(RIDES_WALLET_SOON_MSG, 400); }
    $vtype = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : 'bajaj';
    $vtype = rides_normalize_vehicle_type($pdo, $vtype);

    /* One active ride at a time */
    $s = $pdo->prepare(
        "SELECT id, status, driver_id, created_at FROM rides
         WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived')
         ORDER BY id DESC LIMIT 1"
    );
    $s->execute(array($u['id']));
    $existing = $s->fetch();
    if ($existing) {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey));
        /* Return the existing ride's ID so the client can offer a "cancel & rebook" action */
        fail(
            'You already have an active ride. Cancel it first.',
            409,
            array('existing_ride_id' => intval($existing['id']), 'existing_ride_status' => $existing['status'])
        );
    }

    /* PRICE-LOCK: check for a valid, unexpired signed quote token
       BEFORE doing any fresh distance/fare computation. This must come
       first and fully substitute for the block below when valid -
       not run afterward as a "does the fresh recompute happen to
       agree" check. An earlier version of this fix verified the token
       but only applied it if the freshly recomputed distance matched
       the token's distance within 0.05km - but GPS/route recalculation
       naturally drifts by more than that between the quote and the
       actual tap on a real device, so that version silently fell back
       to a fresh (and possibly surged) recompute almost every time,
       defeating the whole point. The token itself is what the customer
       was shown and agreed to pay - once its signature and expiry are
       verified, there's nothing left to "double check" against a new
       GPS reading. Trusting it outright is what actually guarantees
       the driver sees the exact number the rider was quoted. */
    $lockedFare = null; $lockedKm = null;
    if (!empty($body['quote_token'])) {
        $decoded = base64_decode((string)$body['quote_token'], true);
        if ($decoded !== false) {
            $parts = explode('|', $decoded);
            if (count($parts) === 6) {
                list($qKm, $qVtype, $qOpenTrip, $qFare, $qExp, $qSig) = $parts;
                $qPayload = $qKm . '|' . $qVtype . '|' . $qOpenTrip . '|' . $qFare . '|' . $qExp;
                $expectedSig = hash_hmac('sha256', $qPayload, TOKEN_SECRET_FINAL);
                $sigOk = hash_equals($expectedSig, $qSig);
                $notExpired = intval($qExp) >= time();
                $vtypeMatches = $qVtype === $vtype;
                if ($sigOk && $notExpired && $vtypeMatches) {
                    $lockedFare = floatval($qFare);
                    $lockedKm = floatval($qKm);
                }
                @file_put_contents(__DIR__ . '/../../pricelock_debug.log',
                    date('c') . " TOKEN_PARSED sigOk=" . ($sigOk?1:0) .
                    " notExpired=" . ($notExpired?1:0) . " vtypeMatch=" . ($vtypeMatches?1:0) .
                    " qKm=$qKm qVtype=$qVtype bookVtype=$vtype qExp=$qExp now=" . time() . "\n",
                    FILE_APPEND);
            } else {
                @file_put_contents(__DIR__ . '/../../pricelock_debug.log',
                    date('c') . " TOKEN_BAD_PARTS count=" . count($parts) . "\n", FILE_APPEND);
            }
        } else {
            @file_put_contents(__DIR__ . '/../../pricelock_debug.log',
                date('c') . " TOKEN_BASE64_DECODE_FAILED\n", FILE_APPEND);
        }
    }

    if ($mkm > 0 && (!$dlat || !$dlng)) {
        /* Ride by distance: customer pays by km, tells driver where to go */
        $dist = min(100, $mkm);
        $dlat = null; $dlng = null;
        if ($daddr === '') $daddr = 'By distance: ' . $dist . ' km (driver directed on the way)';
    } elseif ($lockedKm !== null) {
        /* Valid locked quote - use exactly what was quoted, skip the
           haversine/route_km reconciliation entirely. */
        $dist = $lockedKm;
    } else {
        /* FARE-QUOTE MISMATCH FIX: this used to always recompute distance
           server-side via straight-line haversine, completely ignoring
           the real road-route distance (from Google Directions) the
           client already computed and used to show the customer their
           fare quote before they confirmed. Straight-line distance is
           essentially always shorter than the real route (roads don't
           go through buildings), so the quoted and charged fares could
           diverge - a customer books expecting ETB209 and the driver's
           side shows ETB287 for the exact same trip. Now uses the
           client-supplied route_km when it's present and passes a basic
           sanity check (must be at least the straight-line distance,
           the true minimum possible, and not absurdly larger than it -
           guards against a tampered value being used to under- or
           over-charge). Falls back to haversine only if route_km is
           missing entirely (e.g. an older cached client). */
        $haversineDist = rides_haversine_km($plat, $plng, $dlat, $dlng);
        if ($haversineDist > 100) fail('Trip distance too large');
        $routeKm = isset($body['route_km']) && $body['route_km'] !== '' ? floatval($body['route_km']) : null;
        if ($routeKm !== null && $routeKm >= $haversineDist && $routeKm <= $haversineDist * 3 + 5) {
            $dist = $routeKm;
        } else {
            $dist = $haversineDist;
        }
    }
    $fareResult = rides_calc_fare($pdo, $dist, $vtype);
    $fare = ($lockedFare !== null) ? $lockedFare : $fareResult['fare'];
    $fare_surge = $fareResult['surge'] ? 1 : 0;

    /* PROMO CODE (lib/ride_promo.php): checked now so the rider hears about a bad code before booking; the real
       discount is worked out on the final fare when the trip ends. */
    $promoRow = null;
    $promoIn = isset($body['promo_code']) ? rides_promo_normalize($body['promo_code']) : '';
    if ($promoIn !== '') {
        $pc = rides_promo_check($pdo, $promoIn, $u['id'], $fare);
        if (!$pc['ok']) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey)); fail($pc['error'], 422); }
        $promoRow = $pc['promo'];
    }

    /* PRICE OFFER (lib/ride_price_offer.php): the rider may name their own price for a trip with a destination. It must
       sit inside the allowed band around the quote; drivers then accept it or counter it. */
    $offerIn = (isset($body['offer_fare']) && $body['offer_fare'] !== '') ? round(floatval($body['offer_fare'])) : 0;
    if ($offerIn > 0) {
        if (!rides_offer_price_enabled($pdo)) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey)); fail('Price offers are not available right now', 422); }
        $bnd = rides_offer_bounds($pdo, $fare);
        if ($offerIn < $bnd['min'] || $offerIn > $bnd['max']) {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey));
            fail('Your offer must be between ETB ' . $bnd['min'] . ' and ETB ' . $bnd['max'], 422);
        }
        rides_offer_price_ensure($pdo);
    }

    /* TEMP DIAGNOSTIC v2 (remove once confirmed): full picture in one
       line - was a token sent at all, did it validate, what route_km
       arrived, what distance actually got used, and the final fare. */
    @file_put_contents(__DIR__ . '/../../pricelock_debug.log',
        date('c') .
        " tokenInBody=" . (!empty($body['quote_token']) ? 1 : 0) .
        " lockedKm=" . var_export($lockedKm, true) .
        " lockedFare=" . var_export($lockedFare, true) .
        " raw_route_km=" . var_export(isset($body['route_km']) ? $body['route_km'] : null, true) .
        " manual_km=" . var_export($mkm, true) .
        " finalDist=" . var_export($dist, true) .
        " finalFare=" . var_export($fare, true) .
        " vtype=$vtype\n",
        FILE_APPEND);

    /* CACHE-PROOF SURGE GUARD: if a surge window is active but the client
       sent no valid price-lock token, the customer was almost certainly
       shown a stale un-surged quote by an older cached bundle - booking
       now would charge the surged fare against a price they never agreed
       to. Rather than silently overcharge, reject with a specific signal
       the client can act on (re-quote and show the real price). Skips
       this only when a valid token IS present (customer already saw and
       accepted the real number) or when no surge is active (quote and
       charge match anyway). This enforces price honesty even if the
       device is running an outdated ride-bundle.js that never sends a
       token - the one failure mode static client fixes can't cover. */
    if ($lockedFare === null && $fareResult['surge'] && ($mkm <= 0)) {
        fail('Peak-hour pricing is now active. Please review the updated fare and confirm again.', 409);
    }

    /* Legacy-table support: if the pre-existing rides table has unique
       code columns (ride_code, request_code, code, tracking_code),
       populate them with a generated unique value so inserts do not
       collide on '' (1062 Duplicate entry for key uq_ride_code). */
    $extraCols = array(); $extraVals = array();
    $have = array();
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            $have[strtolower($c['Field'])] = true;
        }
        foreach (array('ride_code', 'request_code', 'code', 'tracking_code', 'order_code') as $cc) {
            if (isset($have[$cc])) {
                $extraCols[] = "`$cc`";
                $extraVals[] = 'R' . date('ymdHis') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5));
            }
        }
    } catch (Exception $e) { /* non-fatal */ }

    $cols = "passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng,
             pickup_address, dropoff_address, distance_km, fare_estimate, fare_final,
             payment_method, status, expires_at, created_at";
    $ph   = "?,?,?,?,?,?,?,?,?,?,?, 'searching', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW()";
    $vals = array($u['id'], $plat, $plng, $dlat, $dlng, $paddr, $daddr,
                  round($dist, 2), $fare, $fare, $pm);
    // vehicle_type only added if migration 025 has actually run on this
    // install - previously this was hardcoded into the fixed column list
    // above, which meant EVERY booking (any vehicle type) threw an
    // uncaught "Unknown column 'vehicle_type'" SQL error and surfaced as
    // the generic "Ride action failed" banner on any install where that
    // migration hadn't been applied yet.
    if (isset($have['vehicle_type'])) {
        $cols .= ', vehicle_type';
        $ph   .= ', ?';
        $vals[] = $vtype;
    }
    if (rides_has_surge_column($pdo)) {
        $cols .= ', surge_applied';
        $ph   .= ', ?';
        $vals[] = $fare_surge;
    }
    if ($extraCols) {
        $cols .= ', ' . implode(', ', $extraCols);
        $ph   .= str_repeat(', ?', count($extraCols));
        $vals  = array_merge($vals, $extraVals);
    }
    if ($promoRow) {
        $cols .= ', promo_id, promo_code';
        $ph   .= ', ?, ?';
        $vals[] = intval($promoRow['id']);
        $vals[] = $promoRow['code'];
    }
    $pdo->prepare("INSERT INTO rides ($cols) VALUES ($ph)")->execute($vals);
    $new_ride_id = intval($pdo->lastInsertId());
    if ($offerIn > 0) {
        // fare_final carries the offer, so every driver screen already shows the offered price; fare_estimate keeps the quote
        $pdo->prepare("UPDATE rides SET rider_offer=?, fare_final=? WHERE id=?")->execute(array($offerIn, $offerIn, $new_ride_id));
        $fare = $offerIn;
    }

    /* Push nearby online drivers - this is what actually wakes a driver up
       even if the app is backgrounded or the phone is asleep, unlike the
       in-app poll/SSE + ringtone, which only work while the app is open
       and in the foreground. Best-effort: a driver with no push
       subscription yet, or a push send failure, doesn't block booking. */
    try {
        /* BUG FIX: was pushing every online driver regardless of vehicle
           type - a Moto driver would get a "new ride request nearby"
           push for a Car booking they can't even see or accept in their
           own request list (now fixed above). Filtering to the
           requested vehicle type here too, so drivers only get woken up
           for requests actually meant for them. */
        $nearbyDrivers = $pdo->prepare(
            "SELECT user_id, lat, lng FROM ride_drivers WHERE online = 1 AND lat IS NOT NULL AND lng IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND (LOWER(vehicle_type) = LOWER(?) OR vehicle_type IS NULL)"
        );
        $nearbyDrivers->execute(array($vtype));
        $inRange = array();
        foreach ($nearbyDrivers->fetchAll() as $rd) {
            $d = rides_haversine_km($plat, $plng, floatval($rd['lat']), floatval($rd['lng']));
            if ($d <= 8) { $rd['km'] = $d; $inRange[] = $rd; }
        }
        // Offer waves: wake the nearest drivers now; the rest are woken once the ride has been open a wave length
        // (rides_offer_escalate, from the passenger's status poll).
        foreach (rides_offer_first_push_targets($pdo, $inRange, $plat, $plng) as $rd) {
            etel_push_send($pdo, intval($rd['user_id']), 'New ride request nearby!',
                'ETB ' . number_format($fare, 0) . ' \u2022 ' . round($rd['km'], 1) . ' km away',
                array('type' => 'new_ride_request', 'ride_id' => $new_ride_id, 'url' => '/delivery/driver/'));
        }
    } catch (Exception $e) { error_log('[ETEL][push] nearby-driver push failed: ' . $e->getMessage()); }

    ok(array('request_id' => $new_ride_id, 'fare' => $fare, 'distance_km' => round($dist, 2), 'surge' => (bool)$fare_surge));
    } catch (Exception $bookEx) {
        /* Release promptly on a genuine unexpected exception, and show a
           clean message instead of the raw 500 fail_ex would otherwise
           give. The lock would auto-release anyway once this request's
           DB connection tears down, but this is faster and clearer. */
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($bookLockKey));
        fail_ex($bookEx, 'Could not book ride', 500);
    }
    break;
}

case 'get_etelpay_merchant': {
    // Requires login (same as every other action in this file), but the
    // phone number itself isn't sensitive -- equivalent to a business's
    // public payment QR code. Tells the frontend where to send fares.
    require_once __DIR__ . '/../etel_pay_bridge.php';
    $phone = etel_pay_merchant_phone();
    if (!$phone) fail('Etel Pay is not set up yet for this app (no admin linked to company_slug=etel-delivery in Etel-ID)', 503);
    ok(array('phone' => $phone));
    break;
}

case 'get_ride_path': {
    // Returns the actual recorded breadcrumb trail for a trip, so "View
    // Route" can draw the real path driven instead of a straight line
    // from pickup to dropoff. Only the ride's own driver or passenger
    // can fetch this - same access-control shape used everywhere else a
    // ride is looked up by id in this file.
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    if (!$rid) fail('ride_id required');
    $s = $pdo->prepare("SELECT id, driver_id, passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng,
        pickup_address, dropoff_address, distance_km, traveled_km, fare_final, fare_paid, created_at, completed_at
        FROM rides WHERE id=? LIMIT 1");
    $s->execute(array($rid));
    $ride = $s->fetch();
    if (!$ride) fail('Ride not found', 404);
    if (intval($ride['driver_id']) !== intval($u['id']) && intval($ride['passenger_id']) !== intval($u['id'])) {
        fail('Not your ride', 403);
    }
    $points = array();
    try {
        $p = $pdo->prepare("SELECT lat, lng FROM ride_path_points WHERE ride_id=? ORDER BY id ASC");
        $p->execute(array($rid));
        foreach ($p->fetchAll() as $row) {
            $points[] = array('lat' => floatval($row['lat']), 'lng' => floatval($row['lng']));
        }
    } catch (Exception $e) { /* table may not exist yet on older installs */ }
    // Total distance actually recorded along the breadcrumb trail itself
    // (haversine sum between consecutive GPS points) - shown alongside
    // the ride's official billed distance_km, since GPS noise/dropouts
    // mean the two can legitimately differ a little and both numbers are
    // meaningful: one is "what the map traced", the other is "what was
    // actually charged".
    $pathKm = 0.0;
    for ($i = 1; $i < count($points); $i++) {
        $pathKm += rides_haversine_km($points[$i-1]['lat'], $points[$i-1]['lng'], $points[$i]['lat'], $points[$i]['lng']);
    }
    ok(array(
        'ride_id'        => $rid,
        'points'         => $points,
        'pickup_lat'     => $ride['pickup_lat']  !== null ? floatval($ride['pickup_lat'])  : null,
        'pickup_lng'     => $ride['pickup_lng']  !== null ? floatval($ride['pickup_lng'])  : null,
        'dropoff_lat'    => $ride['dropoff_lat'] !== null ? floatval($ride['dropoff_lat']) : null,
        'dropoff_lng'    => $ride['dropoff_lng'] !== null ? floatval($ride['dropoff_lng']) : null,
        'pickup_address'  => $ride['pickup_address'],
        'dropoff_address' => $ride['dropoff_address'],
        'distance_km'    => $ride['distance_km']  !== null ? floatval($ride['distance_km'])  : (floatval($ride['traveled_km']) ?: null),
        'path_distance_km' => round($pathKm, 2),
        'fare_final'     => $ride['fare_final'] !== null ? floatval($ride['fare_final']) : null,
        'fare_paid'      => $ride['fare_paid']  !== null ? floatval($ride['fare_paid'])  : null,
        'created_at'     => $ride['created_at'],
        'completed_at'   => $ride['completed_at'],
    ));
    break;
}

case 'ride_status': {
    $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($body['id']) ? intval($body['id']) : 0);
    if (!$id) fail('Ride id required');
    $s = $pdo->prepare(
        "SELECT r.*, r.vehicle_type AS ride_booked_vehicle_type,
                d.name AS driver_name, d.phone AS driver_phone,
                rd.vehicle_plate, rd.vehicle_type, rd.vehicle_make, rd.vehicle_color,
                rd.lat AS driver_lat, rd.lng AS driver_lng,
                rd.selfie_url AS driver_photo,
                CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS driver_rating
         FROM rides r
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
         WHERE r.id=? AND r.passenger_id=?");
    $s->execute(array($id, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Ride not found', 404);
    /* Expire stale searching requests */
    if ($r['status'] === 'searching' && $r['expires_at'] && strtotime($r['expires_at']) < time()) {
        $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=? AND status='searching'")
            ->execute(array($id));
        $r['status'] = 'cancelled';
    }
    if ($r['status'] === 'searching') rides_offer_escalate($pdo, $id);   // wake the wider wave of drivers (once per ride)
    /* Same live-meter fix as the driver side (rides_get_active_for_driver)
       - the customer's active-ride screen was falling back to the static
       fare_estimate before the first real GPS tick too. */
    if ($r['status'] === 'in_progress') {
        rides_ensure_wait_columns($pdo);
        try {
            /* FARE-MISMATCH FIX: r.* and rd.vehicle_type collided under
               the same unaliased name, so PDO silently let the driver's
               CURRENT live vehicle type overwrite the ride's own frozen
               one - fine for display, wrong for fare calc if a driver's
               vehicle changed mid-ride. Using the separately-aliased
               ride_booked_vehicle_type instead. */
            $vtype = !empty($r['ride_booked_vehicle_type']) ? $r['ride_booked_vehicle_type'] : rides_driver_own_vtype($pdo, $r['driver_id']);
            $waitMinutes = rides_current_wait_minutes($r);
            $isOpenTrip = intval($r['open_trip']) === 1;
            $liveFareCalc = rides_calc_fare($pdo, floatval($r['traveled_km']), $vtype, $isOpenTrip, $waitMinutes);
            if ($isOpenTrip) {
                $r['meter_fare'] = $liveFareCalc['fare'];
                $r['meter_surge'] = $liveFareCalc['surge'];
            }
            // Full itemized breakdown (base/distance/wait/surge/total) for
            // every in-progress ride - this is the "both interfaces show
            // it live, for evidence" piece: the customer sees the exact
            // same base/distance/wait/surge/total the driver's own screen
            // shows, updated on every poll, not just a single opaque
            // number they have to trust.
            $r = array_merge($r, rides_fare_breakdown_for_ui($liveFareCalc));
            $r['wait_active'] = (bool) $r['wait_active'];
            $r['wait_minutes'] = round($waitMinutes, 2);
        } catch (Exception $e) { error_log('[ETEL][meter] ride_status fare calc: ' . $e->getMessage()); }
    } elseif (in_array($r['status'], array('arrived', 'completed'), true) && !empty($r['fare_breakdown_json'])) {
        // The exact breakdown persisted at driver_arrived time - not a
        // recompute, which could disagree with what was actually charged
        // if a surge window boundary has passed since arrival. This is
        // what lets the customer's "Trip Complete" screen show the same
        // itemized base/distance/wait/surge/total the driver already
        // sees, instead of a single unexplained total.
        $decoded = json_decode($r['fare_breakdown_json'], true);
        if (is_array($decoded)) $r = array_merge($r, $decoded);
    }
    $r['ride_id'] = $r['id'];
    ok($r);
    break;
}

case 'my_active_ride': {
    // Close stuck trips (at most one sweep per 5 min across all requests; no cron needed).
    rides_sweep_maybe($pdo);
    $s = $pdo->prepare(
        "SELECT r.*, r.vehicle_type AS ride_booked_vehicle_type,
                d.name AS driver_name, d.phone AS driver_phone,
                rd.vehicle_plate, rd.vehicle_type, rd.vehicle_make, rd.vehicle_color,
                rd.lat AS driver_lat, rd.lng AS driver_lng,
                rd.selfie_url AS driver_photo,
                CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS driver_rating
         FROM rides r
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
         WHERE r.passenger_id=? AND r.status IN ('searching','accepted','in_progress','arrived')
         ORDER BY r.id DESC LIMIT 1");
    $s->execute(array($u['id']));
    $r = $s->fetch() ?: null;
    if ($r && $r['status'] === 'in_progress') {
        rides_ensure_wait_columns($pdo);
        try {
            /* Same fare-mismatch fix as ride_status. */
            $vtype = !empty($r['ride_booked_vehicle_type']) ? $r['ride_booked_vehicle_type'] : rides_driver_own_vtype($pdo, $r['driver_id']);
            $waitMinutes = rides_current_wait_minutes($r);
            $isOpenTrip = intval($r['open_trip']) === 1;
            $liveFareCalc = rides_calc_fare($pdo, floatval($r['traveled_km']), $vtype, $isOpenTrip, $waitMinutes);
            if ($isOpenTrip) {
                $r['meter_fare'] = $liveFareCalc['fare'];
                $r['meter_surge'] = $liveFareCalc['surge'];
            }
            $r = array_merge($r, rides_fare_breakdown_for_ui($liveFareCalc));
            $r['wait_active'] = (bool) $r['wait_active'];
            $r['wait_minutes'] = round($waitMinutes, 2);
        } catch (Exception $e) { error_log('[ETEL][meter] my_active_ride fare calc: ' . $e->getMessage()); }
    } elseif ($r && $r['status'] === 'arrived' && !empty($r['fare_breakdown_json'])) {
        // Same persisted-breakdown read as ride_status - see that case for
        // why this reads the stored value rather than recomputing it.
        $decoded = json_decode($r['fare_breakdown_json'], true);
        if (is_array($decoded)) $r = array_merge($r, $decoded);
    }
    ok($r);
    break;
}

case 'cancel_ride': {
    $id = isset($body['id']) ? intval($body['id']) : (isset($body['ride_id']) ? intval($body['ride_id']) : 0);
    if (!$id) fail('Ride id required');
    $cancelReason = isset($body['reason']) ? trim(substr($body['reason'], 0, 255)) : '';

    /* MID-TRIP CANCEL FIX: previously any cancel (including one on an
       already-in-progress ride, meter running, driver already carrying
       the passenger) just flipped status to 'cancelled' and left
       fare_final untouched - meaning a passenger who started a trip
       quoted at, say, ETB300 to the full destination and then asked to
       be let out halfway either got billed nothing (driver unfairly
       eats the trip) or, wherever fare_final got read downstream,
       could show the full destination price for a trip that was never
       finished. Now: if the ride is actually in_progress (real
       distance was covered), a "cancel" from here behaves like an
       early "Arrived" - it bills the real GPS-tracked distance so
       far, using the exact same traveled-km fare logic as a normal
       completion, and moves to 'arrived' so the usual payment-confirm
       screen picks it up. A ride that hasn't started moving yet
       (searching/accepted, nothing traveled) still cancels for free -
       nothing was actually provided. */
    $chk = $pdo->prepare("SELECT status FROM rides WHERE id=? AND passenger_id=?");
    $chk->execute(array($id, $u['id']));
    $chkRow = $chk->fetch();
    if (!$chkRow) fail('Ride not found');
    if (in_array($chkRow['status'], array('completed', 'cancelled', 'arrived'), true)) {
        fail('Ride cannot be cancelled - the driver has already arrived and billed this trip. Please confirm payment instead.');
    }

    if ($chkRow['status'] === 'in_progress') {
        $vtypeCol = rides_has_vehicle_type_column($pdo) ? ', vehicle_type' : '';
        rides_ensure_wait_columns($pdo);
        $s = $pdo->prepare("SELECT id, driver_id, open_trip, traveled_km, distance_km, fare_final, wait_active, wait_started_at, wait_seconds_total" . $vtypeCol . "
                            FROM rides WHERE id=? AND passenger_id=? AND status='in_progress'");
        $s->execute(array($id, $u['id']));
        $r = $s->fetch();
        if (!$r) fail('Ride not found');
        $vtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $r['driver_id']);
        if (intval($r['wait_active']) === 1) {
            $elapsed = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
            $r['wait_seconds_total'] = intval($r['wait_seconds_total']) + $elapsed;
            $r['wait_active'] = 0;
            $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=?")
                ->execute(array($r['wait_seconds_total'], $id));
        }
        $waitMinutes = rides_current_wait_minutes($r);
        $isOpenTrip = intval($r['open_trip']) === 1;
        $traveled = round(floatval($r['traveled_km']), 2);
        $finalSurge = null;
        if ($traveled > 0.1) {
            $km = $traveled;
            $fareResult = rides_calc_fare($pdo, $km, $vtype, $isOpenTrip, $waitMinutes);
            $fare = $fareResult['fare'];
            $finalSurge = $fareResult['surge'] ? 1 : 0;
            $waitEtb = $fareResult['breakdown']['wait_etb'];
            $breakdownForUi = rides_fare_breakdown_for_ui($fareResult);
        } else {
            // Cancelled almost immediately after starting - essentially
            // nothing was travelled, so this is a free cancel too rather
            // than billing a minimum fare for a few metres.
            if ($cancelReason !== '' && rides_has_cancel_reason_column($pdo)) {
                $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW(), cancel_reason=? WHERE id=?")
                    ->execute(array($cancelReason, $id));
            } else {
                $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute(array($id));
            }
            ok(array('cancelled' => true));
            break;
        }
        $km = $traveled;
        $breakdownJson = json_encode($breakdownForUi);
        if ($finalSurge !== null && rides_has_surge_column($pdo)) {
            $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), distance_km=?, fare_final=?, surge_applied=?, fare_breakdown_json=? WHERE id=?")
                ->execute(array($km, $fare, $finalSurge, $breakdownJson, $id));
        } else {
            $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), distance_km=?, fare_final=?, fare_breakdown_json=? WHERE id=?")
                ->execute(array($km, $fare, $breakdownJson, $id));
        }
        rides_flag_fare_review($pdo, $id, $u['id'], $fare, $km);
        $fare = rides_promo_apply_to_arrived($pdo, $id);
        ok(array('cancelled' => true, 'ended_early' => true, 'fare_final' => $fare,
                 'message' => 'Trip ended early - billed for the ' . $km . ' km actually travelled (ETB ' . round($fare, 2) . '), not the full planned distance.'));
        break;
    }

    if ($cancelReason !== '' && rides_has_cancel_reason_column($pdo)) {
        $n = $pdo->prepare(
            "UPDATE rides SET status='cancelled', cancelled_at=NOW(), cancel_reason=?
             WHERE id=? AND passenger_id=? AND status NOT IN ('completed','cancelled','arrived')");
        $n->execute(array($cancelReason, $id, $u['id']));
    } else {
        $n = $pdo->prepare(
            "UPDATE rides SET status='cancelled', cancelled_at=NOW()
             WHERE id=? AND passenger_id=? AND status NOT IN ('completed','cancelled','arrived')");
        $n->execute(array($id, $u['id']));
    }
    if (!$n->rowCount()) fail('Ride cannot be cancelled - the driver has already arrived and billed this trip. Please confirm payment instead.');
    ok(array('cancelled' => true));
    break;
}

case 'rate_ride': {
    $id     = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $rating = isset($body['rating'])  ? intval($body['rating'])  : 0;
    $cmt    = isset($body['comment']) ? mb_substr(trim($body['comment']), 0, 500) : '';
    if (!$id || $rating < 1 || $rating > 5) fail('Valid ride_id and rating (1-5) required');
    $s = $pdo->prepare("SELECT driver_id, rating FROM rides WHERE id=? AND passenger_id=? AND status='completed'");
    $s->execute(array($id, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Completed ride not found', 404);
    if ($r['rating']) fail('Ride already rated');
    $pdo->prepare("UPDATE rides SET rating=?, rating_comment=? WHERE id=?")
        ->execute(array($rating, $cmt, $id));
    if ($r['driver_id']) {
        $pdo->prepare("UPDATE ride_drivers SET rating_sum=rating_sum+?, rating_count=rating_count+1 WHERE user_id=?")
            ->execute(array($rating, $r['driver_id']));
    }
    ok(array('rated' => true));
    break;
}

case 'rate_rider': {
    /* Driver rates the passenger back - mirrors rate_ride above, but
       written to a separate driver_rating/driver_rating_comment column
       pair on the same ride row (so a completed ride can independently
       show "rider rated: yes/no" and "driver rated: yes/no"), and
       accumulated onto users.rider_rating_sum/rider_rating_count rather
       than ride_drivers, since the person being rated here is not
       necessarily a driver account. */
    $id     = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $rating = isset($body['rating'])  ? intval($body['rating'])  : 0;
    $cmt    = isset($body['comment']) ? mb_substr(trim($body['comment']), 0, 500) : '';
    if (!$id || $rating < 1 || $rating > 5) fail('Valid ride_id and rating (1-5) required');
    $s = $pdo->prepare("SELECT passenger_id, driver_rating FROM rides WHERE id=? AND driver_id=? AND status='completed'");
    $s->execute(array($id, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Completed ride not found', 404);
    if ($r['driver_rating']) fail('You already rated this rider');
    $pdo->prepare("UPDATE rides SET driver_rating=?, driver_rating_comment=? WHERE id=?")
        ->execute(array($rating, $cmt, $id));
    if ($r['passenger_id']) {
        $pdo->prepare("UPDATE users SET rider_rating_sum=rider_rating_sum+?, rider_rating_count=rider_rating_count+1 WHERE id=?")
            ->execute(array($rating, $r['passenger_id']));
    }
    ok(array('rated' => true));
    break;
}

case 'ride_safety_get': case 'ride_safety_save': {
    /* The rider's trusted contacts (up to 3: name + phone) and whether trip
       safety check-ins are on. One row per rider, contacts kept as JSON.
       The contacts are only ever used by the rider's own phone (a one-tap
       SMS / WhatsApp message with the live trip link); the server never
       sends them anything. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS ride_safety_settings (
        user_id      INT NOT NULL PRIMARY KEY,
        contacts_json TEXT NULL,
        checkins     TINYINT(1) NOT NULL DEFAULT 1,
        updated_at   DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($action === 'ride_safety_save') {
        $in = isset($body['contacts']) && is_array($body['contacts']) ? $body['contacts'] : array();
        $clean = array();
        foreach ($in as $c) {
            if (count($clean) >= 3) break;
            if (!is_array($c)) continue;
            $nm = isset($c['name']) ? trim(preg_replace('/[\x00-\x1F<>]/', '', (string) $c['name'])) : '';
            $ph = isset($c['phone']) ? preg_replace('/[^0-9+]/', '', (string) $c['phone']) : '';
            if ($nm === '' && $ph === '') continue;
            if ($nm === '') fail('Give each contact a name', 400);
            $digits = preg_replace('/\D/', '', $ph);
            if (strlen($digits) < 9 || strlen($digits) > 15) fail('Phone number for ' . $nm . ' does not look right', 400);
            $clean[] = array('name' => (function_exists('mb_substr') ? mb_substr($nm, 0, 40) : substr($nm, 0, 40)), 'phone' => $ph);
        }
        $chk = (!isset($body['checkins']) || $body['checkins']) ? 1 : 0;
        $pdo->prepare("INSERT INTO ride_safety_settings (user_id, contacts_json, checkins, updated_at) VALUES (?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE contacts_json=VALUES(contacts_json), checkins=VALUES(checkins), updated_at=NOW()")
            ->execute(array(intval($u['id']), json_encode($clean), $chk));
    }
    $q = $pdo->prepare('SELECT contacts_json, checkins FROM ride_safety_settings WHERE user_id=?');
    $q->execute(array(intval($u['id'])));
    $row = $q->fetch();
    $contacts = $row ? json_decode((string) $row['contacts_json'], true) : array();
    ok(array('contacts' => is_array($contacts) ? $contacts : array(), 'checkins' => $row ? intval($row['checkins']) === 1 : true));
    break;
}

case 'ride_safety_checkin_missed': {
    /* The rider's phone asked "Are you OK?" during a trip and got no answer
       for a minute. Raises ONE open 'safety_checkin_missed' issue per ride
       (shows in Ride issue reports and, as critical, in the Live trip watch)
       and pushes the admins straight away. Only for the rider's own live trip. */
    $id = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    if (!$id) fail('ride_id required');
    $s = $pdo->prepare("SELECT r.id, r.status, p.name AS pname, p.phone AS pphone FROM rides r JOIN users p ON p.id = r.passenger_id
                        WHERE r.id=? AND r.passenger_id=? AND r.status IN ('accepted','in_progress','arrived')");
    $s->execute(array($id, intval($u['id'])));
    $r = $s->fetch();
    if (!$r) fail('Ride not found', 404);
    $d = $pdo->prepare("SELECT id FROM ride_issues WHERE ride_id=? AND category='safety_checkin_missed' AND status='open' LIMIT 1");
    $d->execute(array($id));
    if ($d->fetch()) ok(array('recorded' => true, 'duplicate' => true));
    $pdo->prepare("INSERT INTO ride_issues (ride_id, reporter_id, category, details, status) VALUES (?, ?, 'safety_checkin_missed', ?, 'open')")
        ->execute(array($id, intval($u['id']),
            'Rider did not answer the safety check-in during the trip. Call them: ' . ($r['pphone'] ? $r['pphone'] : 'no phone on file') . '.'));
    if (function_exists('etel_push_send')) {
        try {
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $aid) {
                etel_push_send($pdo, intval($aid), 'Safety check not answered',
                    '#' . $id . ' ' . ($r['pname'] ? $r['pname'] : 'Rider') . ' did not answer "Are you OK?" - call ' . ($r['pphone'] ? $r['pphone'] : 'them'),
                    array('action' => 'ride_safety', 'ride_id' => $id, 'url' => '/delivery/admin.html'));
            }
        } catch (Exception $e) { /* best-effort */ }
    }
    ok(array('recorded' => true));
    break;
}

case 'ride_get_share_link': {
    /* Generates (or returns the existing) public share token for an
       active ride, and the full URL to hand to a trusted contact. The
       viewer at share.html + api/trip_share.php is intentionally NOT
       behind require_auth() - whoever holds the link can watch the trip
       without an ETEL account, same as Uber/Bolt "share status" links.
       Only works while the ride is actually live, so a leaked old link
       stops being useful once the trip ends. */
    $id = isset($body['ride_id']) ? intval($body['ride_id']) : (isset($_GET['ride_id']) ? intval($_GET['ride_id']) : 0);
    if (!$id) fail('ride_id required');
    $s = $pdo->prepare("SELECT id, share_token, status FROM rides WHERE id=? AND passenger_id=?");
    $s->execute(array($id, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Ride not found', 404);
    if (!in_array($r['status'], array('searching', 'accepted', 'in_progress', 'arrived'), true)) {
        fail('Trip sharing is only available while a ride is active');
    }
    $token = $r['share_token'];
    if (!$token) {
        $token = rides_gen_share_token();
        $pdo->prepare("UPDATE rides SET share_token=? WHERE id=?")->execute(array($token, $id));
    }
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'ethelmarket.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base   = $scheme . '://' . $host . preg_replace('#/api/?$#', '', dirname($_SERVER['SCRIPT_NAME'] ?? '/delivery/api'));
    // dirname(api) -> /delivery ; build .../delivery/ride/share.html?t=TOKEN
    $url = $scheme . '://' . $host . '/delivery/ride/share.html?t=' . $token;
    ok(array('share_token' => $token, 'share_url' => $url));
    break;
}

case 'ride_stop_sharing': {
    /* Immediately invalidates the current share link (e.g. rider changes
       their mind mid-trip) without waiting for the ride to end. */
    $id = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    if (!$id) fail('ride_id required');
    $pdo->prepare("UPDATE rides SET share_token=NULL WHERE id=? AND passenger_id=?")
        ->execute(array($id, $u['id']));
    ok(array('stopped' => true));
    break;
}

case 'ride_schedule': {
    /* SECURITY: same rate-limit reasoning as book_ride above. */
    rate_limit_check($pdo, 'ride_schedule:' . $u['id'], 10, 300, 300);
    rate_limit_fail($pdo, 'ride_schedule:' . $u['id']);

    /* Pre-booked ride: stored as status='scheduled' (not 'searching', so
       it never shows up to drivers or in "my active ride" until it's
       actually due), converted to a real live request by
       api/cron_dispatch_scheduled.php once scheduled_at arrives. Reuses
       the same fare/validation rules as book_ride so the quote shown at
       scheduling time matches what dispatch will charge.

       CONCURRENCY NOTE: two DIFFERENT passengers scheduling the same
       time slot is NOT a race condition here - driver matching is
       deferred to dispatch time (the cron), which uses the same safe
       broadcast + atomic-accept pattern as instant booking, so there's
       no shared resource contested at schedule-time between different
       passengers. The only real race is the SAME passenger double-
       tapping fast enough to slip past the 5-scheduled-rides limit
       below - same lock pattern as book_ride, lower stakes (a soft
       spam-prevention limit, not a safety/money issue), but cheap to
       close while already in this exact code shape. */
    $schedLockKey = 'schedule_ride_' . intval($u['id']);
    $schedLockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $schedLockStmt->execute(array($schedLockKey));
    if (!$schedLockStmt->fetchColumn()) {
        fail('Still processing your previous request - please wait a moment and try again', 429);
    }
    try {
    $plat = isset($body['pickup_lat'])  ? floatval($body['pickup_lat'])  : 0;
    $plng = isset($body['pickup_lng'])  ? floatval($body['pickup_lng'])  : 0;
    $dlat = isset($body['dropoff_lat']) ? floatval($body['dropoff_lat']) : 0;
    $dlng = isset($body['dropoff_lng']) ? floatval($body['dropoff_lng']) : 0;
    if (!$plat || !$plng) fail('Pickup location is required');
    if (!$dlat || !$dlng) fail('Drop-off location is required for a scheduled ride');
    $paddr = isset($body['pickup_addr'])  ? mb_substr(trim($body['pickup_addr']), 0, 255)  : '';
    $daddr = isset($body['dropoff_addr']) ? mb_substr(trim($body['dropoff_addr']), 0, 255) : '';
    $pm    = isset($body['payment_method']) ? substr(preg_replace('/[^a-z_]/', '', strtolower($body['payment_method'])), 0, 20) : 'cash';
    if ($pm === 'wallet' && !rides_wallet_enabled($pdo)) fail(RIDES_WALLET_SOON_MSG, 400);
    $vtype = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : 'bajaj';
    $vtype = rides_normalize_vehicle_type($pdo, $vtype);

    $when = isset($body['scheduled_at']) ? trim($body['scheduled_at']) : '';
    $ts   = $when !== '' ? strtotime($when) : false;
    if (!$ts) fail('A valid scheduled date/time is required');
    if ($ts < time() + 600) fail('Please schedule at least 10 minutes from now');
    if ($ts > time() + 7 * 86400) fail('Rides can only be scheduled up to 7 days ahead');

    $cnt = $pdo->prepare("SELECT COUNT(*) c FROM rides WHERE passenger_id=? AND status='scheduled'");
    $cnt->execute(array($u['id']));
    if (intval($cnt->fetch()['c']) >= 5) fail('You already have 5 scheduled rides - cancel one before adding another');

    $dist = rides_haversine_km($plat, $plng, $dlat, $dlng);
    if ($dist > 100) fail('Trip distance too large');
    /* Same fare-quote mismatch fix as book_ride - use the real route
       distance the client already quoted the customer with, when given
       and sane, instead of blindly recomputing via straight-line
       distance. */
    $routeKmSched = isset($body['route_km']) && $body['route_km'] !== '' ? floatval($body['route_km']) : null;
    if ($routeKmSched !== null && $routeKmSched >= $dist && $routeKmSched <= $dist * 3 + 5) {
        $dist = $routeKmSched;
    }
    $fareResult = rides_calc_fare($pdo, $dist, $vtype);

    $have = array();
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            $have[strtolower($c['Field'])] = true;
        }
    } catch (Exception $e) { /* non-fatal */ }

    $cols = "passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng,
             pickup_address, dropoff_address, distance_km, fare_estimate, fare_final,
             payment_method, status, scheduled_at, is_scheduled, created_at";
    $ph   = "?,?,?,?,?,?,?,?,?,?,?, 'scheduled', ?, 1, NOW()";
    $vals = array($u['id'], $plat, $plng, $dlat, $dlng, $paddr, $daddr,
                  round($dist, 2), $fareResult['fare'], $fareResult['fare'], $pm,
                  date('Y-m-d H:i:s', $ts));
    if (isset($have['vehicle_type'])) {
        $cols .= ', vehicle_type';
        $ph   .= ', ?';
        $vals[] = $vtype;
    }
    $pdo->prepare("INSERT INTO rides ($cols) VALUES ($ph)")->execute($vals);
    $new_id = intval($pdo->lastInsertId());
    ok(array(
        'scheduled_ride_id' => $new_id,
        'scheduled_at'      => date('Y-m-d H:i:s', $ts),
        'fare_estimate'     => $fareResult['fare'],
        'distance_km'       => round($dist, 2)
    ));
    } catch (Exception $schedEx) {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(array($schedLockKey));
        fail_ex($schedEx, 'Could not schedule ride', 500);
    }
    break;
}

case 'ride_get_scheduled': {
    $s = $pdo->prepare(
        "SELECT id, pickup_address, dropoff_address, vehicle_type,
                fare_estimate, scheduled_at, created_at
         FROM rides WHERE passenger_id=? AND status='scheduled'
         ORDER BY scheduled_at ASC"
    );
    $s->execute(array($u['id']));
    ok(array('scheduled' => $s->fetchAll()));
    break;
}

case 'ride_cancel_scheduled': {
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id required');
    $n = $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=? AND passenger_id=? AND status='scheduled'");
    $n->execute(array($id, $u['id']));
    if (!$n->rowCount()) fail('Scheduled ride not found', 404);
    ok(array('cancelled' => true));
    break;
}

case 'ride_history': {
    $s = $pdo->prepare(
        "SELECT r.id, r.pickup_address, r.dropoff_address, r.distance_km, r.fare_paid, r.fare_final,
                r.status, r.rating, r.created_at, r.completed_at, r.assigned_at,
                r.payment_method, r.open_trip, r.traveled_km,
                d.name AS driver_name, d.phone AS driver_phone,
                rd.vehicle_type, rd.vehicle_plate, rd.vehicle_color
         FROM rides r
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
         WHERE r.passenger_id=? AND r.status IN ('completed','cancelled')
         ORDER BY r.id DESC LIMIT 30");
    $s->execute(array($u['id']));
    $rows = $s->fetchAll();
    /* A cancelled trip is never charged (there is no cancellation fee), but fare_final keeps the
       estimate from booking time. Showing it made riders think they were billed, so report zero. */
    foreach ($rows as &$row) {
        if ($row['status'] === 'cancelled') { $row['fare_final'] = 0; $row['fare_paid'] = 0; $row['not_charged'] = true; }
    }
    unset($row);
    ok(array('rides' => $rows));
    break;
}

case 'ride_receipt': {
    /* A receipt for a completed ride the caller took (rider) or drove (driver). No phone numbers, no coordinates. */
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('Ride id required');
    $s = $pdo->prepare(
        "SELECT r.id, r.status, r.pickup_address, r.dropoff_address, r.distance_km, r.traveled_km, r.fare_paid, r.fare_final,
                r.payment_method, r.created_at, r.completed_at, r.fare_breakdown_json, r.driver_id,
                d.name AS driver_name, p.name AS passenger_name, rd.vehicle_plate, rd.vehicle_type, rd.vehicle_color
         FROM rides r
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN users p ON p.id = r.passenger_id
         LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
         WHERE r.id=? AND (r.passenger_id=? OR r.driver_id=?) LIMIT 1");
    $s->execute(array($id, $u['id'], $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Ride not found', 404);
    if ($r['status'] !== 'completed') fail('A receipt is only available for completed rides', 409);
    $b = !empty($r['fare_breakdown_json']) ? json_decode($r['fare_breakdown_json'], true) : null;
    if (!is_array($b)) $b = array();
    $lines = array();
    $add = function ($label, $v) use (&$lines) { if ($v !== null && floatval($v) != 0) $lines[] = array('label' => $label, 'etb' => round(floatval($v), 2)); };
    $add('Base fare', isset($b['fare_base']) ? $b['fare_base'] : null);
    $add('Distance', isset($b['fare_distance']) ? $b['fare_distance'] : null);
    $add('Waiting time', isset($b['fare_wait']) ? $b['fare_wait'] : null);
    $add('Peak-hour surge', isset($b['fare_surge_etb']) ? $b['fare_surge_etb'] : null);
    $add('Minimum fare top-up', isset($b['fare_floor_topup']) ? $b['fare_floor_topup'] : null);
    // a price agreed with the driver (offer / counter-offer): the trip is billed at that price, not the meter's
    if (isset($b['agreed_fare']) && isset($b['metered_fare'])) {
        $add('Price agreed with driver', floatval($b['agreed_fare']) - floatval($b['metered_fare']));
    }
    $total = floatval($r['fare_paid']) > 0 ? floatval($r['fare_paid']) : floatval($r['fare_final']);
    $promo = rides_promo_info($pdo, $id);
    $note = null;
    if ($promo) {
        $lines[] = array('label' => 'Promo ' . $promo['promo_code'], 'etb' => -1 * $promo['promo_discount']);
        if (intval($r['driver_id']) === intval($u['id'])) $note = 'The promo discount was paid by Etel. Your earnings are not reduced.';
        else $note = 'The promo discount was paid by Etel.';
    }
    ok(array(
        'note'       => $note,
        'receipt_no' => 'ETEL-R' . str_pad((string) intval($r['id']), 6, '0', STR_PAD_LEFT),
        'ride_id'    => intval($r['id']),
        'rider'      => $r['passenger_name'],
        'completed_at' => $r['completed_at'],
        'pickup'     => $r['pickup_address'],
        'dropoff'    => $r['dropoff_address'],
        'distance_km' => round(floatval($r['distance_km'] ? $r['distance_km'] : $r['traveled_km']), 2),
        'driver'     => $r['driver_name'],
        'vehicle'    => trim(($r['vehicle_color'] ? $r['vehicle_color'] . ' ' : '') . ($r['vehicle_type'] ? $r['vehicle_type'] : '')),
        'plate'      => $r['vehicle_plate'],
        'payment'    => $r['payment_method'] ? $r['payment_method'] : 'cash',
        'lines'      => $lines,
        'total'      => round($total, 2),
        'currency'   => 'ETB',
    ));
    break;
}

case 'driver_get_ride_history': {
    /* Driver-side equivalent of ride_history above - no such endpoint
       existed for drivers before (only the passenger direction), so the
       native app's Earnings/Trip History list had nothing real to read.
       Mirrors that query exactly, just filtered by driver_id and joined
       against the passenger instead of the driver. */
    $s = $pdo->prepare(
        "SELECT r.id, r.pickup_address, r.dropoff_address, r.distance_km, r.fare_paid, r.fare_final,
                r.status, r.rating, r.created_at, r.completed_at, r.assigned_at,
                r.payment_method, r.open_trip, r.traveled_km, r.promo_code, r.promo_discount, " . rides_statement_ref_col($pdo) . ",
                p.name AS passenger_name, p.phone AS passenger_phone
         FROM rides r
         LEFT JOIN users p ON p.id = r.passenger_id
         WHERE r.driver_id=? AND r.status IN ('completed','cancelled')
         ORDER BY r.id DESC LIMIT 30");
    $s->execute(array($u['id']));
    $rows = $s->fetchAll();
    foreach ($rows as &$row) { // cancelled trips earn nothing; do not show the booking-time estimate (see ride_history)
        if ($row['status'] === 'cancelled') { $row['fare_final'] = 0; $row['fare_paid'] = 0; $row['not_charged'] = true; }
        else $row['money'] = rides_trip_money($row);   // fare, promo, Etel's 5% and what the driver keeps
        unset($row['wallet_tx_ref']);
    }
    unset($row);
    ok(array('rides' => $rows));
    break;
}

/* ========== DRIVER ========== */

case 'driver_go_online': {
    $lat = isset($body['lat']) ? floatval($body['lat']) : null;
    $lng = isset($body['lng']) ? floatval($body['lng']) : null;
    rides_require_onboarded($pdo, $u['id']);
    rides_driver_profile($pdo, $u['id']);
    // The registration screen tells a driver with a license name/DOB
    // mismatch "your account has been flagged for admin review before
    // you can go online" - but nothing here ever actually checked that
    // flag, so it was purely cosmetic. Real enforcement added.
    $idChk = $pdo->prepare("SELECT identity_match FROM ride_drivers WHERE user_id=?");
    $idChk->execute(array($u['id']));
    $idRow = $idChk->fetch();
    if ($idRow && $idRow['identity_match'] === 'mismatch') {
        fail('Your account is flagged for admin review (license details did not match your EtelID record). You cannot go online until an admin approves it.', 403);
    }
    list($hexQ, $hexR) = hexgrid_cell_for($lat, $lng);
    $pdo->prepare("UPDATE ride_drivers SET online=1, lat=?, lng=?, last_seen=NOW(), hex_q=?, hex_r=? WHERE user_id=?")
        ->execute(array($lat, $lng, $hexQ, $hexR, $u['id']));
    // Real shift tracking, needed for an honest "net ETB per working
    // hour" KPI - previously nothing recorded how long a driver was
    // actually online. Guard against a duplicate open shift (e.g. the
    // app calling go_online again after a relaunch without an
    // intervening go_offline) by only opening a new one if none is
    // already open for this driver.
    try {
        $openChk = $pdo->prepare("SELECT id FROM driver_shifts WHERE driver_user_id=? AND ended_at IS NULL LIMIT 1");
        $openChk->execute(array($u['id']));
        if (!$openChk->fetch()) {
            $pdo->prepare("INSERT INTO driver_shifts (driver_user_id, started_at) VALUES (?, NOW())")
                ->execute(array($u['id']));
        }
    } catch (Exception $e) { /* shift tracking is additive - never block going online over it */ }
    ok(array('online' => true));
    break;
}

case 'driver_go_offline': {
    rides_driver_profile($pdo, $u['id']);
    $pdo->prepare("UPDATE ride_drivers SET online=0, last_seen=NOW() WHERE user_id=?")
        ->execute(array($u['id']));
    try {
        $pdo->prepare("UPDATE driver_shifts SET ended_at=NOW(), online_seconds=TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE driver_user_id=? AND ended_at IS NULL")
            ->execute(array($u['id']));
    } catch (Exception $e) { /* shift tracking is additive - never block going offline over it */ }
    ok(array('online' => false));
    break;
}

case 'driver_update_location': {
    $lat = isset($body['lat']) ? floatval($body['lat']) : null;
    $lng = isset($body['lng']) ? floatval($body['lng']) : null;
    $accuracy = isset($body['accuracy']) ? floatval($body['accuracy']) : null;
    $explicitRideId = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    if ($lat === null || $lng === null) fail('lat/lng required');
    rides_driver_profile($pdo, $u['id']);
    list($hexQ, $hexR) = hexgrid_cell_for($lat, $lng);
    $pdo->prepare("UPDATE ride_drivers SET lat=?, lng=?, last_seen=NOW(), hex_q=?, hex_r=? WHERE user_id=?")
        ->execute(array($lat, $lng, $hexQ, $hexR, $u['id']));

    /* GPS meter: while a ride is in progress, accumulate traveled km.
       Ignore micro-jitter (<5 m) and GPS glitches (>2 km between pings).
       Also ignore any fix the device itself reports as low-accuracy
       for METER purposes specifically - a single noisy fix can easily
       exceed the 5 m jitter floor on its own (observed: up to ~150 m of
       phantom "movement" accumulating over several pings while genuinely
       stationary, e.g. indoors or under cover). A poor-accuracy fix here
       does NOT update last_lat/last_lng either, so the next GOOD fix
       still computes its delta from the last trustworthy reference point
       instead of drifting from noise - same "catch up the whole gap in
       one step" pattern already used for offline/backgrounded gaps, just
       applied to accuracy instead of time.

       Threshold was 30 m, confirmed live (driver screenshot + the
       "Weak GPS signal" badge showing on-screen) to sit permanently
       unmet on ordinary driving in Addis/Hawassa traffic - dense
       building cover plus mid-range Android GPS chipsets routinely
       report 30-60 m even with a real, moving fix, not just indoors or
       stationary. That left the meter frozen at 0.00 km/base-fare-only
       for an entire trip, every time, on exactly the phones most drivers
       actually carry - not a rare edge case. Raised to 75 m: still well
       short of the several-hundred-to-thousand-meter errors a WiFi/cell-
       tower-only fix produces (which is the failure mode this guard
       actually exists to catch), but wide enough that real driving with
       a real GPS fix stops being silently discarded. */
    $meter = null;
    try {
        rides_ensure_wait_columns($pdo);
        $vtypeCol = rides_has_vehicle_type_column($pdo) ? ', vehicle_type' : '';
        // Scoped to an explicit ride_id when the client sends one (every
        // other ride action - toggle_wait, driver_arrived - already
        // targets an explicit id; this endpoint was the one exception,
        // silently picking "whichever in_progress ride comes back first"
        // via LIMIT 1 with no ride_id at all. If a driver ever has more
        // than one row stuck in in_progress (an abandoned test ride, a
        // ride that failed to close out cleanly), this could pick the
        // WRONG one - updating a stale ride's traveled_km while the
        // driver's actual current-ride screen keeps showing 0.00 km,
        // because the meter is silently accumulating onto a ride nobody
        // is looking at. Falls back to the old implicit lookup only when
        // an older cached client doesn't send ride_id yet.
        if ($explicitRideId > 0) {
            $s = $pdo->prepare("SELECT id, last_lat, last_lng, last_location_at, traveled_km, open_trip, wait_active, wait_started_at, wait_seconds_total" . $vtypeCol . "
                                FROM rides WHERE id=? AND driver_id=? AND status='in_progress' LIMIT 1");
            $s->execute(array($explicitRideId, $u['id']));
        } else {
            $s = $pdo->prepare("SELECT id, last_lat, last_lng, last_location_at, traveled_km, open_trip, wait_active, wait_started_at, wait_seconds_total" . $vtypeCol . "
                                FROM rides WHERE driver_id=? AND status='in_progress' LIMIT 1");
            $s->execute(array($u['id']));
        }
        $r = $s->fetch();
        if ($r) {
            $lowAccuracy = ($accuracy !== null && $accuracy > 75);

            // Wait-clock auto-stop used to live ONLY inside the "good
            // accuracy" branch below, sharing that branch's 75m gate -
            // so a device stuck reporting network-only fixes (seen live:
            // 2000m, meaning the OS handed the browser a WiFi/cell
            // position with no real GPS chip fix at all, not just a
            // noisy one) never ran the auto-stop check either, no matter
            // how far the vehicle actually drove. Distance and "is the
            // vehicle moving at all" are different questions with
            // different error tolerances - crediting 2000m-accuracy
            // fixes to the BILLED distance would be genuinely wrong (the
            // fare needs real precision), but requiring the same
            // precision just to notice the car left is not. This block
            // now always runs when there's a prior point to compare
            // against, before the meter-only branch below decides
            // whether to bill that same raw distance.
            $d = null; $isFirstPoint = ($r['last_lat'] === null || $r['last_lng'] === null);
            // Plausible-distance ceiling = a 120 km/h drive over the time since
            // the last accepted point, plus 50 m of slack. This used to be
            // floored at a flat 2.0 km, which is ~1400 km/h for a normal 5 s
            // ping - so a phone whose position wandered by up to 2 km (WiFi/
            // cell fixes, or a stationary phone bouncing between two
            // providers) had every wander billed as real driving. 2.0 km is
            // kept only as the cap when there is no timestamp to judge by.
            $maxPlausibleKm = 0.5;
            if (!empty($r['last_location_at'])) {
                $elapsedSeconds = max(1, time() - strtotime($r['last_location_at']));
                $maxPlausibleKm = 0.05 + $elapsedSeconds * (120.0 / 3600.0);
            }
            // Smallest hop worth crediting: the larger of 20 m and 3x this
            // fix's own reported error radius. Simulated: a parked phone's
            // jitter at 1x the radius still billed ~14 km/hour of phantom
            // driving; at 3x, with the reference point held fixed (see
            // refMoves below), it bills ~0 while real driving is unaffected.
            $creditFloorKm = max(0.02, 3.0 * ($accuracy !== null ? $accuracy : 0) / 1000.0);
            if (!$isFirstPoint) {
                $d = rides_haversine_km(floatval($r['last_lat']), floatval($r['last_lng']), $lat, $lng);
            }
            $justMoved = false;
            if ($d !== null && $d < $maxPlausibleKm) {
                // Good fix: the original tight 20m bar (a clear "actually
                // moving" signal, comfortably above ordinary GPS jitter).
                // Poor fix: raw GPS jitter isn't the noise floor to beat
                // any more - a WiFi/cell-tower position can itself drift
                // tens to low-hundreds of meters while the phone doesn't
                // move at all, so the bar scales with how bad THIS fix's
                // own reported accuracy is (half the accuracy radius,
                // floored at 150m) - comfortably past that device's own
                // noise band, while still far below what a genuine drive-
                // off covers within a few 5s pings at any real speed.
                $moveFloorKm = $lowAccuracy ? max(0.15, ($accuracy / 1000.0) * 0.5) : 0.02;
                if ($d >= $moveFloorKm) $justMoved = true;
            }
            $waitAutoStopped = false;
            if ($justMoved && intval($r['wait_active']) === 1) {
                // Auto-stop a wait clock the driver forgot to stop, the
                // instant real movement is detected - mirrors the existing
                // auto-close-on-arrived safeguard in driver_arrived below,
                // just triggered by driving off instead of tapping Arrived.
                // Without this, a driver who simply pulls away without
                // remembering to tap "Stop Waiting" keeps silently billing
                // the passenger for waiting time for the rest of the trip -
                // exactly the class of bug the fare breakdown feature exists
                // to surface, not something that should still be possible.
                $elapsed = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
                $r['wait_seconds_total'] = intval($r['wait_seconds_total']) + $elapsed;
                $r['wait_active'] = 0;
                $r['wait_started_at'] = null;
                $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=?")
                    ->execute(array($r['wait_seconds_total'], $r['id']));
                $waitAutoStopped = true;
            }
            // A wait clock that has already run past the billable cap earns
            // nothing more, so stop it (the driver sees it stop) instead of
            // leaving it ticking for hours.
            $waitCapReached = false;
            $capMin = rides_wait_cap_minutes($pdo);
            if ($capMin > 0 && intval($r['wait_active']) === 1 && rides_current_wait_minutes($r) >= $capMin) {
                $elapsedCap = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
                $r['wait_seconds_total'] = intval($r['wait_seconds_total']) + $elapsedCap;
                $r['wait_active'] = 0;
                $r['wait_started_at'] = null;
                $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=?")
                    ->execute(array($r['wait_seconds_total'], $r['id']));
                $waitCapReached = true;
            }
            $waitMinutes = rides_current_wait_minutes($r);
            $rideVtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $u['id']);

            // Weak-but-real fix (75-150 m): used to be discarded outright, so a
            // phone that never reports better than ~100 m (common in city
            // traffic) froze the meter at 0.00 km for the whole trip. Now
            // credited once the movement from the last trusted point clearly
            // exceeds 3x the fix's own error radius, which a stationary
            // phone's drift can't produce. Anything worse than 150 m is
            // still never credited - see the shortfall floor in driver_arrived.
            $weakCreditable = $lowAccuracy && $accuracy <= 150 && $d !== null
                && $d < $maxPlausibleKm && $d > $creditFloorKm;
            if ($lowAccuracy && !$weakCreditable) {
                // Distance itself still is NOT credited from a fix this
                // poor - see the restructuring note above for why that
                // stays separate from the wait-auto-stop check just run.
                // last_lat/last_lng also stay untouched, so the next GOOD
                // fix still computes its delta from the last trustworthy
                // reference point instead of drifting from noise - same
                // "catch up the whole gap in one step" pattern already
                // used for offline/backgrounded gaps, just applied to
                // accuracy instead of time.
                $liveFareCalc = rides_calc_fare($pdo, floatval($r['traveled_km']), $rideVtype, intval($r['open_trip']) === 1, $waitMinutes);
                $meter = array_merge(array('ride_id' => intval($r['id']),
                               'traveled_km' => round(floatval($r['traveled_km']), 2),
                               'open_trip' => intval($r['open_trip']),
                               'surge' => (bool) $liveFareCalc['surge'],
                               'meter_fare' => $liveFareCalc['fare'],
                               'wait_active' => (bool) $r['wait_active'],
                               'wait_minutes' => round($waitMinutes, 2),
                               'wait_auto_stopped' => $waitAutoStopped,
                               'wait_cap_reached' => $waitCapReached,
                               'low_accuracy' => true), rides_fare_breakdown_for_ui($liveFareCalc));
            } else {
                $add = 0;
                // Ceiling (see $maxPlausibleKm above) scales with the time
                // since the last accepted point, so a long background gap
                // still catches up while a 5 s ping can't claim kilometres.
                if ($d !== null && $d > $creditFloorKm && $d < $maxPlausibleKm) $add = $d;
                $newKm = floatval($r['traveled_km']) + $add;
                // The reference point only advances on real movement (or the
                // first point / a rejected glitch jump). Previously it
                // advanced on every ping, so crawling in traffic at <5 m per
                // 5 s ping never accumulated: each tiny hop was discarded AND
                // forgotten. Now sub-floor hops pile up against the unchanged
                // reference until they cross the credit floor.
                // last_location_at is stamped with PHP's clock, not MySQL
                // NOW(): it is read back with time()/strtotime() above, and
                // the two zones differ on this host (same bug already fixed
                // for wait_started_at), which inflated the plausible-distance
                // ceiling to hundreds of km and disabled the glitch guard.
                $refMoves = $isFirstPoint || $add > 0 || ($d !== null && $d >= $maxPlausibleKm);
                if ($refMoves) {
                    $pdo->prepare("UPDATE rides SET last_lat=?, last_lng=?, last_location_at=?, traveled_km=? WHERE id=?")
                        ->execute(array($lat, $lng, date('Y-m-d H:i:s'), $newKm, $r['id']));
                }

                // Record this point in the trip's breadcrumb trail - either
                // real movement was detected ($add>0, same jitter floor as
                // the meter itself) or this is the very first ping of the
                // trip (establishing where the path starts), so a parked
                // driver doesn't spam the table with hundreds of near-
                // identical points every 5s, but the path also doesn't skip
                // its own starting point. This is what lets "View Route"
                // show the actual path driven instead of a straight line
                // from pickup to dropoff.
                if ($add > 0 || $isFirstPoint) {
                    try {
                        $pdo->prepare("INSERT INTO ride_path_points (ride_id, lat, lng) VALUES (?, ?, ?)")
                            ->execute(array($r['id'], $lat, $lng));
                    } catch (Exception $e) { /* table may not exist yet on older installs - non-fatal */ }
                }

                // Live running fare for every in-progress ride, not just
                // "open trip" ones - the final bill (driver_arrived, below)
                // now always uses this same real GPS distance, so showing it
                // live here means nothing surprises the driver or passenger
                // at arrival. Also surfaces live surge status (whether the
                // 1.3x peak-hour multiplier is currently active) so a
                // higher-than-expected running fare doesn't look like a bug -
                // see driver.js's meter box and ride.js's active-ride view.
                // Full itemized breakdown (base/distance/wait/surge/total) is
                // included the same way so a waiting-fee charge - or any
                // other part of the fare - is visible live on both sides,
                // broken down line by line, not just discovered as one
                // opaque total at the final bill.
                $liveFareCalc = rides_calc_fare($pdo, $newKm, $rideVtype, intval($r['open_trip']) === 1, $waitMinutes);
                $meter = array_merge(array('ride_id' => intval($r['id']),
                               'traveled_km' => round($newKm, 2),
                               'open_trip' => intval($r['open_trip']),
                               'surge' => (bool) $liveFareCalc['surge'],
                               'meter_fare' => $liveFareCalc['fare'],
                               'wait_active' => (bool) $r['wait_active'],
                               'wait_minutes' => round($waitMinutes, 2),
                               'wait_auto_stopped' => $waitAutoStopped,
                               'wait_cap_reached' => $waitCapReached), rides_fare_breakdown_for_ui($liveFareCalc));
            }
        }
    } catch (Exception $e) { error_log('[ETEL][meter] ' . $e->getMessage()); }
    ok(array('updated' => true, 'meter' => $meter));
    break;
}

/* Lightweight "still here" ping sent via navigator.sendBeacon right when a
   driver's tab/app goes to the background. sendBeacon cannot set a custom
   Authorization header, so the token travels in the query string instead
   (get_bearer() in boot.php only allows this for the narrow *_beacon_ping
   action name - see there for why that's safe). No response body is read
   by the client (sendBeacon is fire-and-forget), so keep this cheap: just
   refresh last_seen (and lat/lng if provided) without touching the meter
   or profile-repair logic that driver_update_location runs on every call. */
case 'driver_beacon_ping': {
    $lat = isset($body['lat']) ? floatval($body['lat']) : null;
    $lng = isset($body['lng']) ? floatval($body['lng']) : null;
    if ($lat !== null && $lng !== null) {
        list($hexQ, $hexR) = hexgrid_cell_for($lat, $lng);
        $pdo->prepare("UPDATE ride_drivers SET lat=?, lng=?, last_seen=NOW(), hex_q=?, hex_r=? WHERE user_id=?")
            ->execute(array($lat, $lng, $hexQ, $hexR, $u['id']));
    } else {
        $pdo->prepare("UPDATE ride_drivers SET last_seen=NOW() WHERE user_id=?")
            ->execute(array($u['id']));
    }
    ok(array('pinged' => true));
    break;
}

/* 
   ADMIN RIDE MANAGEMENT ACTIONS
    */

case 'admin_get_rides': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $status  = isset($body['status'])  ? $body['status']  : '';
    $limit   = min(100, max(1, intval($body['limit']  ?? 50)));
    $offset  = max(0, intval($body['offset'] ?? 0));
    $search  = isset($body['search']) ? '%' . trim($body['search']) . '%' : '';

    $where = "WHERE 1=1";
    $vals  = array();
    if ($status && $status !== 'all') { $where .= " AND r.status=?"; $vals[] = $status; }
    if ($search) { $where .= " AND (p.name LIKE ? OR p.phone LIKE ? OR d.name LIKE ? OR rd.vehicle_plate LIKE ?)"; $vals = array_merge($vals, [$search,$search,$search,$search]); }

    try {
        $count = $pdo->prepare("SELECT COUNT(*) FROM rides r
            LEFT JOIN users p ON p.id = r.passenger_id
            LEFT JOIN users d ON d.id = r.driver_id
            LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
            $where");
        $count->execute($vals);
        $total = $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT r.id, r.status, r.created_at, r.started_at, r.completed_at,
            r.pickup_address, r.dropoff_address, r.distance_km, r.fare_estimate, r.fare_final,
            r.payment_method, r.fare_paid, r.open_trip, r.traveled_km,
            p.name AS passenger_name, p.phone AS passenger_phone,
            d.name AS driver_name, d.phone AS driver_phone,
            COALESCE(rd.vehicle_plate, '') AS vehicle_plate,
            rd.vehicle_type, rd.total_rides AS driver_total_rides,
            CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS driver_rating
            FROM rides r
            LEFT JOIN users p ON p.id = r.passenger_id
            LEFT JOIN users d ON d.id = r.driver_id
            LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
            $where ORDER BY r.id DESC LIMIT ? OFFSET ?");
        $vals[] = $limit; $vals[] = $offset;
        $stmt->execute($vals);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        fail_ex($e, 'admin_get_rides query failed', 500);
    }
    ok(array('rides' => $rows, 'total' => intval($total), 'limit' => $limit, 'offset' => $offset));
    break;
}

case 'admin_backup_download': {
    /* The newest database backup as a file, so a copy can be kept off the server.
       Owner-level admins only (the new 'system.backup' permission is held by full
       admins; managed staff would need it listed). Every download is logged. */
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'system.backup')) fail('Owner admins only', 403);
    require_once __DIR__ . '/../lib/system_health.php';
    $bk = etel_backup_latest();
    if (!$bk) fail('No backup found yet', 404);
    error_log('[ETEL][backup] admin ' . intval($u['id']) . ' downloaded ' . $bk['name']);
    etel_health_heartbeat($pdo, 'backup_download', 'admin #' . intval($u['id']) . ' ' . $bk['name']);   // the health card reminds owners to keep fetching copies
    if (!etel_backup_send($bk)) fail('The backup file does not match its checksum - do not use it. Run a new backup.', 500);
    break;
}

case 'admin_system_health': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    require_once __DIR__ . '/../lib/ride_monitor.php';
    require_once __DIR__ . '/../lib/totp.php';
    require_once __DIR__ . '/../lib/system_health.php';
    ok(etel_system_health($pdo, intval($u['id'])));
    break;
}

case 'admin_client_errors': {
    // The browser errors the rider and driver apps reported (api/client-error.php), newest first. Read-only.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $days = isset($body['days']) ? max(1, min(30, intval($body['days']))) : 7;
    try {
        $q = $pdo->prepare("SELECT id, app, kind, page, message, source, line_no, rid, ua, user_id, hits, first_seen, last_seen
                            FROM client_errors WHERE last_seen >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY last_seen DESC LIMIT 100");
        $q->execute(array($days));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = array(); }   // table not created yet: nothing has been reported
    ok(array('errors' => $rows, 'days' => $days));
    break;
}

case 'admin_client_errors_clear': {
    // POST: id? (one error) or nothing (all of them). Needs rides.manage.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    try {
        if (!empty($body['id'])) $pdo->prepare("DELETE FROM client_errors WHERE id=?")->execute(array(substr(preg_replace('/[^a-f0-9]/', '', (string)$body['id']), 0, 32)));
        else $pdo->exec("DELETE FROM client_errors");
    } catch (Exception $e) { /* nothing to clear */ }
    ok(array('cleared' => true));
    break;
}

case 'ride_pin_get': {
    /* The rider's own trip PIN (only while a driver has accepted and not yet started). */
    require_once __DIR__ . '/../lib/ride_pin.php';
    if (!etel_ride_pin_required($pdo)) ok(array('required' => false));
    $s = $pdo->prepare("SELECT id, pickup_address FROM rides WHERE passenger_id=? AND status='accepted' ORDER BY id DESC LIMIT 1");
    $s->execute(array($u['id']));
    $r = $s->fetch();
    if (!$r || $r['pickup_address'] === 'Street hail') ok(array('required' => true, 'pin' => null));
    ok(array('required' => true, 'pin' => etel_ride_pin($r['id'], $u['id']), 'ride_id' => intval($r['id'])));
    break;
}

case 'admin_set_ride_pin_required': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'system.backup')) fail('Owner admins only', 403);
    $on = !empty($body['required']) ? 1 : 0;
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_pin_required', ?, 'Driver must type the rider 4-digit trip PIN to start a trip (1 = required)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array((string) $on));
    error_log('[ETEL][pin] admin ' . intval($u['id']) . ' set ride_pin_required=' . $on);
    ok(array('required' => $on));
    break;
}

case 'admin_get_2fa_rule': {
    /* Read-only, any admin: is two-step sign-in required for admins, and is it on for me? Used by the Settings card. */
    if ($u['role'] !== 'admin') fail('Admin only', 403);
    require_once __DIR__ . '/../lib/totp.php';
    $req = 0;
    try { $req = intval($pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='require_admin_2fa' LIMIT 1")->fetchColumn()); } catch (Exception $e) {}
    $mine = false;
    try { $mine = (bool) etel_2fa_is_enabled($pdo, intval($u['id'])); } catch (Exception $e) {}
    ok(array('required' => $req === 1 ? 1 : 0, 'mine' => $mine ? 1 : 0, 'can_change' => user_has_capability($pdo, $u, 'system.backup') ? 1 : 0));
    break;
}

case 'admin_set_2fa_required': {
    /* Owner-level admins only. Turning it ON needs the caller's own 2FA to be on (no self lock-out). */
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'system.backup')) fail('Owner admins only', 403);
    require_once __DIR__ . '/../lib/totp.php';
    $on = !empty($body['required']) ? 1 : 0;
    if ($on && !etel_2fa_is_enabled($pdo, intval($u['id']))) fail('Turn on your own two-step sign-in first, then require it for everyone.', 409);
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('require_admin_2fa', ?, 'Admins must have two-step sign-in on to use the admin pages (1 = required)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array((string) $on));
    error_log('[ETEL][2fa] admin ' . intval($u['id']) . ' set require_admin_2fa=' . $on);
    ok(array('required' => $on));
    break;
}

case 'admin_ride_watch': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    require_once __DIR__ . '/../lib/ride_monitor.php';
    ok(rides_monitor_scan($pdo));
    break;
}

case 'admin_get_ride_stats': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $today = date('Y-m-d');
    $week  = date('Y-m-d', strtotime('-7 days'));

    $stats = array();

    /* Status counts */
    $sc = $pdo->query("SELECT status, COUNT(*) as n FROM rides GROUP BY status");
    $byStatus = array();
    foreach ($sc->fetchAll() as $r) $byStatus[$r['status']] = intval($r['n']);
    $stats['by_status'] = $byStatus;

    /* Today's rides */
    $stmtTd = $pdo->prepare("SELECT COUNT(*) as n, COALESCE(SUM(fare_paid),0) as rev FROM rides WHERE DATE(created_at)=? AND status='completed'");
    $stmtTd->execute(array($today));
    $tdRow = $stmtTd->fetch();
    $stats['today_completed'] = intval($tdRow['n']);
    $stats['today_revenue']   = round(floatval($tdRow['rev']), 2);

    /* This week */
    $stmtWk = $pdo->prepare("SELECT COUNT(*) as n, COALESCE(SUM(fare_paid),0) as rev FROM rides WHERE created_at>=? AND status='completed'");
    $stmtWk->execute(array($week));
    $wkRow = $stmtWk->fetch();
    $stats['week_completed'] = intval($wkRow['n']);
    $stats['week_revenue']   = round(floatval($wkRow['rev']), 2);

    /* Active right now */
    $ac = $pdo->query("SELECT COUNT(*) FROM rides WHERE status IN ('searching','accepted','in_progress','arrived')");
    $stats['active_now'] = intval($ac->fetchColumn());

    /* Online drivers */
    $od = $pdo->query("SELECT COUNT(*) FROM ride_drivers WHERE online=1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stats['online_drivers'] = intval($od->fetchColumn());

    /* Total all time */
    $tot = $pdo->query("SELECT COUNT(*) as n, COALESCE(SUM(fare_paid),0) as rev FROM rides WHERE status='completed'");
    $totRow = $tot->fetch();
    $stats['total_completed'] = intval($totRow['n']);
    $stats['total_revenue']   = round(floatval($totRow['rev']), 2);

    ok($stats);
    break;
}

case 'admin_cancel_ride': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = intval($body['ride_id'] ?? 0);
    if (!$rid) fail('ride_id required');
    $n = $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=? AND status IN ('searching','accepted')");
    $n->execute(array($rid));
    if (!$n->rowCount()) fail('Ride not cancellable (already in progress or done)');
    ok(array('cancelled' => $rid));
    break;
}

case 'admin_manual_complete_ride': {
    // Rescue path for a trip that can't complete itself: the driver's
    // phone died, lost signal, or the app got killed mid-trip, so
    // driver_update_location never accumulated a usable traveled_km and
    // driver_arrived has nothing real to bill from. The driver calls
    // support, reads out the pickup point and where they actually
    // dropped the passenger, and an admin/support agent enters both
    // here as lat/lng (from the same Google Places-autocomplete map
    // every other booking screen in this app already uses, not a raw
    // address string - there's no server-side geocoder available on
    // this code path, see AgentController.php's agent_geocode_place for
    // why that one lives only there). Computes the real distance/fare
    // from those two points and completes the trip immediately - waiting
    // on driver_arrived/customer_confirm_arrival isn't realistic here
    // since the driver's own device is the reason this is needed in the
    // first place - through rides_complete_and_pay() (lib/ride_fare.php),
    // the exact same payout path every other completed ride uses.
    // Logged to ride_issues (already admin-visible under Ride Issues) so
    // every manual override has a permanent audit trail.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = intval($body['ride_id'] ?? 0);
    if (!$rid) fail('ride_id required');
    $plat = isset($body['pickup_lat'])  ? floatval($body['pickup_lat'])  : 0;
    $plng = isset($body['pickup_lng'])  ? floatval($body['pickup_lng'])  : 0;
    $dlat = isset($body['dropoff_lat']) ? floatval($body['dropoff_lat']) : 0;
    $dlng = isset($body['dropoff_lng']) ? floatval($body['dropoff_lng']) : 0;
    if (!$plat || !$plng || !$dlat || !$dlng) fail('pickup_lat/pickup_lng/dropoff_lat/dropoff_lng are all required');
    $pickupAddr  = isset($body['pickup_address'])  ? trim($body['pickup_address'])  : '';
    $dropoffAddr = isset($body['dropoff_address']) ? trim($body['dropoff_address']) : '';
    // Optional stops between pickup and drop-off (e.g. the passenger had
    // the driver swing by a second location) - same call-and-read-out
    // origin as pickup/dropoff above, just more than two points. Each
    // must carry real lat/lng (a raw address string alone can't be
    // distanced); anything malformed is dropped rather than failing the
    // whole request, since a stop is a refinement, not a required field.
    $waypoints = array();
    if (isset($body['waypoints']) && is_array($body['waypoints'])) {
        foreach ($body['waypoints'] as $wp) {
            if (!is_array($wp) || !isset($wp['lat']) || !isset($wp['lng'])) continue;
            $wlat = floatval($wp['lat']); $wlng = floatval($wp['lng']);
            if (!$wlat || !$wlng) continue;
            $waypoints[] = array('lat' => $wlat, 'lng' => $wlng, 'address' => isset($wp['address']) ? trim($wp['address']) : '');
        }
    }
    $pm = isset($body['payment_method']) ? substr(preg_replace('/[^a-z_]/', '', strtolower($body['payment_method'])), 0, 20) : 'cash';
    // Wallet needs an independently-verified transaction reference
    // (etel_pay_verify_tx) - there's no such reference to check here,
    // just a phone call, so wallet can't be trusted as "already paid"
    // through this path the way customer_confirm_arrival's wallet
    // branch can.
    if ($pm === 'wallet') fail('Wallet payment cannot be confirmed on a support call - use cash or another off-app method for a manually-closed trip.', 400);
    $note = isset($body['note']) ? trim($body['note']) : '';

    rides_ensure_wait_columns($pdo);
    $vtypeCol = rides_has_vehicle_type_column($pdo) ? ', vehicle_type' : '';
    // 'arrived' included alongside 'in_progress': driver_arrived already
    // computes a distance/fare from whatever GPS was tracked up to that
    // point, but if the same call/connection that killed live tracking
    // also stops the passenger from ever reaching customer_confirm_arrival,
    // the trip is stuck exactly the same way - waiting on a device that
    // isn't coming back. Admin re-entering the real pickup/drop-off points
    // here and recomputing the distance is the same trusted-override path
    // either way, not just for 'in_progress'.
    $s = $pdo->prepare("SELECT id, driver_id, open_trip, wait_active, wait_started_at, wait_seconds_total" . $vtypeCol . "
                        FROM rides WHERE id=? AND status IN ('in_progress','arrived')");
    $s->execute(array($rid));
    $r = $s->fetch();
    if (!$r) fail('Ride not found, or not currently in progress/arrived', 404);
    if (!$r['driver_id']) fail('This ride has no assigned driver', 400);

    // Same auto-close-forgotten-wait-clock safeguard as driver_arrived.
    if (intval($r['wait_active']) === 1) {
        $elapsed = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
        $r['wait_seconds_total'] = intval($r['wait_seconds_total']) + $elapsed;
        $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=?")
            ->execute(array($r['wait_seconds_total'], $rid));
    }
    $waitMinutes = rides_current_wait_minutes($r);

    $vtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $r['driver_id']);
    $isOpenTrip = intval($r['open_trip']) === 1;
    // Distance is summed leg-by-leg across pickup -> each stop in order ->
    // drop-off, not a single point-to-point line, so a trip with stops
    // bills for the real route the driver actually drove rather than the
    // straight-line shortcut between just the first and last point.
    $chain = array_merge(array(array('lat' => $plat, 'lng' => $plng)), $waypoints, array(array('lat' => $dlat, 'lng' => $dlng)));
    $km = 0;
    for ($i = 1; $i < count($chain); $i++) {
        $km += rides_haversine_km($chain[$i - 1]['lat'], $chain[$i - 1]['lng'], $chain[$i]['lat'], $chain[$i]['lng']);
    }
    if ($km <= 0) fail('Pickup and drop-off cannot be the same point', 400);

    $fareResult = rides_calc_fare($pdo, $km, $vtype, $isOpenTrip, $waitMinutes);
    $fare = $fareResult['fare'];
    $breakdown = rides_fare_breakdown_for_ui($fareResult);
    $breakdown['manual_completion']  = true;
    $breakdown['completed_by_admin'] = $u['name'];
    if ($note !== '') $breakdown['admin_note'] = $note;
    if ($waypoints) $breakdown['manual_waypoints'] = $waypoints;
    $breakdownJson = json_encode($breakdown);

    $sets = array("distance_km=?", "traveled_km=?", "fare_breakdown_json=?");
    $params = array($km, $km, $breakdownJson);
    if ($pickupAddr !== '') { $sets[] = "pickup_lat=?"; $sets[] = "pickup_lng=?"; $sets[] = "pickup_address=?"; array_push($params, $plat, $plng, $pickupAddr); }
    if ($dropoffAddr !== '') { $sets[] = "dropoff_lat=?"; $sets[] = "dropoff_lng=?"; $sets[] = "dropoff_address=?"; array_push($params, $dlat, $dlng, $dropoffAddr); }
    if (rides_has_surge_column($pdo)) { $sets[] = "surge_applied=?"; $params[] = $fareResult['surge'] ? 1 : 0; }
    $params[] = $rid;
    $pdo->prepare("UPDATE rides SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);

    rides_complete_and_pay($pdo, $rid, $r['driver_id'], $fare, $pm);

    try {
        $details = 'Manually completed by ' . $u['name'] . ' - distance ' . round($km, 2) . ' km'
            . ($waypoints ? ' via ' . count($waypoints) . ' stop(s)' : '') . ', fare ETB ' . number_format($fare, 2)
            . ($note !== '' ? '. Note: ' . $note : '');
        $pdo->prepare("INSERT INTO ride_issues (ride_id, reporter_id, category, details, status, resolved_at) VALUES (?, ?, 'manual_completion', ?, 'resolved', NOW())")
            ->execute(array($rid, $u['id'], $details));
    } catch (Exception $e) {
        // Audit trail only - never let a logging failure undo a
        // trip that already completed and paid out successfully above.
        error_log('[ETEL][admin_manual_complete_ride] audit log insert failed for ride ' . $rid . ': ' . $e->getMessage());
    }

    ok(array_merge(array('completed' => true, 'ride_id' => $rid, 'distance_km' => round($km, 2),
             'fare' => $fare, 'payment_method' => $pm), $breakdown));
    break;
}

case 'admin_get_drivers': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    // Guarantee these columns exist even if no ride has completed yet
    // since deploy (they're otherwise only lazily created inside
    // customer_confirm_arrival) - this query would throw "Unknown
    // column" on a fresh install/deploy without this.
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'commission_owed'    => "commission_owed DECIMAL(12,2) DEFAULT 0",
        'cash_fares_total'   => "cash_fares_total DECIMAL(12,2) DEFAULT 0",
        'etel_money_balance' => "etel_money_balance DECIMAL(12,2) DEFAULT 0"
    ));

    // Optional search-by name/phone, used by any admin screen where a
    // driver needs to be picked from a list (e.g. the manual top-up
    // form) - matches on either field so admins can type whichever one
    // they have on hand. Accepted from JSON body ('q') or query string
    // ('q'/'search') to cover both POST and GET callers. Digits-only
    // phone matching so "+251 91..." vs "091..." vs spaced/dashed input
    // all still find the same driver.
    $q = isset($body['q']) ? trim($body['q'])
       : (isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['search']) ? trim($_GET['search']) : ''));

    $where = '';
    $params = array();
    if ($q !== '') {
        $qDigits = preg_replace('/[^0-9]/', '', $q);
        if ($qDigits !== '') {
            // Looks phone-ish - match against the phone with all
            // non-digits stripped too, so formatting differences don't
            // cause a miss. Still also checks name in case of a
            // numeric-looking name/nickname.
            $where = "WHERE (u.name LIKE ? OR REPLACE(REPLACE(REPLACE(u.phone,'+',''),' ',''),'-','') LIKE ?)";
            $params = array('%' . $q . '%', '%' . $qDigits . '%');
        } else {
            $where = "WHERE u.name LIKE ? OR u.phone LIKE ?";
            $params = array('%' . $q . '%', '%' . $q . '%');
        }
    }

    $stmt = $pdo->prepare("SELECT u.id, u.name, u.phone, u.created_at,
        rd.online, rd.vehicle_type, rd.vehicle_make, rd.vehicle_color, rd.vehicle_plate,
        rd.license_number, rd.license_name, rd.license_dob, rd.identity_match,
        rd.selfie_url, rd.license_photo_url,
        rd.onboarded, rd.total_earnings, rd.total_rides,
        rd.commission_owed, rd.etel_money_balance,
        rd.last_seen,
        CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS rating
        FROM users u
        JOIN ride_drivers rd ON rd.user_id = u.id
        $where
        ORDER BY (rd.identity_match = 'mismatch') DESC, rd.online DESC, rd.last_seen DESC");
    $stmt->execute($params);
    ok(array('drivers' => $stmt->fetchAll()));
    break;
}

case 'admin_get_live_drivers': {
    // Feeds admin's live driver map (admin-growth-charts.js's
    // pdRefreshDrivers, polled every 20s). "Online" is the driver's own
    // toggle (see driver_go_online/driver_go_offline) - last_seen alone
    // isn't checked here since a driver marked online is presumed
    // online until they explicitly go offline or the app's own
    // heartbeat logic decides otherwise elsewhere; this endpoint just
    // reports current state, it doesn't second-guess it.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    $lat = isset($body['lat']) ? floatval($body['lat']) : null;
    $lng = isset($body['lng']) ? floatval($body['lng']) : null;
    $stmt = $pdo->prepare("SELECT u.id AS user_id, u.name, u.phone,
        rd.lat, rd.lng, rd.vehicle_type, rd.vehicle_plate, rd.last_seen
        FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
        WHERE rd.online = 1 AND rd.lat IS NOT NULL AND rd.lng IS NOT NULL");
    $stmt->execute();
    $drivers = $stmt->fetchAll();
    // PDO returns DECIMAL columns as PHP strings, which json_encode then
    // serializes as JSON strings ("38.4723000") instead of numbers - the
    // frontend's google.maps.Marker({position: {lat, lng}}) strictly
    // requires actual numbers and throws "not a number" on a string,
    // even a numeric-looking one. Cast explicitly before it ever reaches
    // json_encode.
    foreach ($drivers as &$d) {
        $d['lat'] = floatval($d['lat']);
        $d['lng'] = floatval($d['lng']);
    }
    unset($d);
    if ($lat !== null && $lng !== null) {
        foreach ($drivers as &$d) {
            $d['distance_km'] = round(6371 * acos(min(1.0, cos(deg2rad($lat))
                * cos(deg2rad($d['lat']))
                * cos(deg2rad($d['lng']) - deg2rad($lng))
                + sin(deg2rad($lat)) * sin(deg2rad($d['lat'])))), 2);
        }
        unset($d);
    }
    ok(array('drivers' => $drivers));
    break;
}

case 'admin_get_suspended_drivers': {
    // Feeds the suspended-drivers panel on the growth dashboard
    // (growthLoadSuspendedDrivers). No auto-suspend trigger exists yet -
    // this is the read side plus the two actions below to manually
    // suspend/unsuspend from a ride issue report. Auto-suspend-on-N-
    // complaints (mentioned as a planned feature) is a separate,
    // not-yet-built piece - this just makes the existing frontend work
    // instead of 404ing.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $stmt = $pdo->prepare("SELECT u.id AS user_id, u.name, u.phone,
        rd.suspended_at, rd.suspended_reason,
        (SELECT ri.id FROM ride_issues ri WHERE ri.reporter_id != u.id
            AND ri.ride_id IN (SELECT id FROM rides WHERE driver_id = u.id)
            ORDER BY ri.id DESC LIMIT 1) AS latest_issue_id
        FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
        WHERE rd.suspended_at IS NOT NULL
        ORDER BY rd.suspended_at DESC");
    $stmt->execute();
    ok(array('suspended' => $stmt->fetchAll()));
    break;
}

case 'admin_suspend_driver': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    $reason = isset($body['reason']) ? trim(substr($body['reason'], 0, 255)) : '';
    if (!$did) fail('user_id is required');
    $pdo->prepare("UPDATE ride_drivers SET suspended_at=NOW(), suspended_reason=?, suspended_by=?, online=0 WHERE user_id=?")
        ->execute(array($reason, $u['id'], $did));
    etel_bump_token_version($pdo, $did); // force any active session to re-auth
    ok(array('suspended' => true));
    break;
}

case 'admin_unsuspend_driver': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$did) fail('user_id is required');
    $pdo->prepare("UPDATE ride_drivers SET suspended_at=NULL, suspended_reason=NULL, suspended_by=NULL WHERE user_id=?")
        ->execute(array($did));
    ok(array('unsuspended' => true));
    break;
}

/* ── Identity mismatch review ──────────────────────────────────────
   Registration already flags a driver as identity_match='mismatch' and
   tells them their account needs admin review before they can go
   online (now actually enforced in driver_go_online above - it never
   was before). These three actions are the missing other half: a way
   for an admin to actually see and act on that flag. Modeled directly
   on the pending-vehicle-change review pattern already built and
   working in admin-rides.js/admin_get_pending_vehicle_changes. */

case 'admin_get_flagged_identities': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.phone, u.created_at,
        rd.license_name, rd.license_number, rd.license_dob,
        rd.selfie_url, rd.license_photo_url, rd.vehicle_type, rd.vehicle_plate
        FROM users u JOIN ride_drivers rd ON rd.user_id = u.id
        WHERE rd.identity_match = 'mismatch'
        ORDER BY u.created_at DESC");
    $stmt->execute();
    ok(array('drivers' => $stmt->fetchAll()));
    break;
}

case 'admin_approve_driver_identity': {
    // The license details are confirmed correct on manual review (e.g.
    // a middle name/nickname mismatch, a transliteration difference) -
    // clears the flag and lets the driver go online normally. Must also
    // finish onboarding and promote users.role to 'driver' here, the
    // same two things driver_update_profile does on a CLEAN match -
    // otherwise an admin-approved driver would still be stuck as
    // onboarded=0 (invisible to their own app's normal flow) and still
    // carrying whatever role they had before (usually 'customer'),
    // which is the exact bug this whole review panel was built to fix
    // in the first place, just recreated via the approval path instead
    // of the auto-match path.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$did) fail('user_id is required');
    $row = $pdo->prepare("SELECT vehicle_type, vehicle_plate, selfie_url, license_photo_url FROM ride_drivers WHERE user_id=?");
    $row->execute(array($did));
    $d = $row->fetch();
    $canFullyOnboard = $d && $d['vehicle_type'] && $d['vehicle_plate'] && $d['selfie_url'] && $d['license_photo_url'];
    $sql = "UPDATE ride_drivers SET identity_match='matched'" . ($canFullyOnboard ? ", onboarded=1" : "") . " WHERE user_id=?";
    $pdo->prepare($sql)->execute(array($did));
    if ($canFullyOnboard) {
        $pdo->prepare("UPDATE users SET role='driver' WHERE id=?")->execute(array($did));
    }
    ok(array('approved' => true, 'fully_onboarded' => (bool) $canFullyOnboard));
    break;
}

case 'admin_reject_driver_identity': {
    // A genuine identity mismatch, not a false positive - suspends the
    // account outright (reusing the same suspend mechanism/columns as
    // admin_suspend_driver) rather than leaving it in limbo.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    $reason = isset($body['reason']) ? trim(substr($body['reason'], 0, 255)) : 'License identity did not match EtelID record.';
    if (!$did) fail('user_id is required');
    $pdo->prepare("UPDATE ride_drivers SET suspended_at=NOW(), suspended_reason=?, suspended_by=?, online=0 WHERE user_id=?")
        ->execute(array($reason, $u['id'], $did));
    etel_bump_token_version($pdo, $did);
    ok(array('rejected' => true));
    break;
}

/* ── Driver compliance (association dues / insurance) ──────────────
   Feeds the Traffic Officer NFC "Driver Info" check (see
   NfcController.php's nfc_driver_status). Admin sets each status after
   confirming payment offline; the receipt photo is uploaded through the
   existing generic api/upload.php the same way selfie_url/
   license_photo_url already are, then its returned URL is submitted
   here alongside the status. */
case 'admin_list_driver_compliance': {
    // Association dues are no longer managed here at all - see
    // nfc_driver_status in NfcController.php, which now checks
    // EtelTaxi's own live database (via taxi_bridge.php) instead of a
    // manually-toggled Delivery-side field, since EtelTaxi is the
    // actual system of record for that data. This panel only manages
    // the three physical documents Delivery has no other source for:
    // insurance, technical inspection, and Road Fund sticker - each
    // tracked by real expiry date (matching what's printed on the
    // actual certificate/sticker) rather than a manually-flipped
    // paid/unpaid switch an admin could forget to update.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'insurance_expiry_date'              => "insurance_expiry_date DATE NULL",
        'insurance_receipt_url'              => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date'   => "technical_inspection_expiry_date DATE NULL",
        'technical_inspection_receipt_url'   => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date'              => "road_fund_expiry_date DATE NULL",
        'road_fund_receipt_url'              => "road_fund_receipt_url VARCHAR(500) DEFAULT ''",
    ));
    $stmt = $pdo->prepare("SELECT u.id AS user_id, u.name, u.phone,
        rd.vehicle_type, rd.vehicle_plate, rd.onboarded,
        rd.insurance_expiry_date, rd.insurance_receipt_url,
        rd.technical_inspection_expiry_date, rd.technical_inspection_receipt_url,
        rd.road_fund_expiry_date, rd.road_fund_receipt_url
        FROM users u JOIN ride_drivers rd ON rd.user_id = u.id
        WHERE rd.onboarded = 1
        ORDER BY u.name ASC");
    $stmt->execute();
    ok(array('drivers' => $stmt->fetchAll()));
    break;
}

case 'admin_update_driver_compliance': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'insurance_expiry_date'              => "insurance_expiry_date DATE NULL",
        'insurance_receipt_url'              => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date'   => "technical_inspection_expiry_date DATE NULL",
        'technical_inspection_receipt_url'   => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date'              => "road_fund_expiry_date DATE NULL",
        'road_fund_receipt_url'              => "road_fund_receipt_url VARCHAR(500) DEFAULT ''",
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$did) fail('user_id is required');

    $dateFields = array('insurance_expiry_date', 'technical_inspection_expiry_date', 'road_fund_expiry_date');
    $urlFields  = array('insurance_receipt_url', 'technical_inspection_receipt_url', 'road_fund_receipt_url');

    $sets = array(); $vals = array();
    foreach ($dateFields as $f) {
        if (!isset($body[$f])) continue;
        $v = trim($body[$f]);
        if ($v === '') { $sets[] = "$f=NULL"; continue; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) fail("$f must be a YYYY-MM-DD date or empty");
        $sets[] = "$f=?"; $vals[] = $v;
    }
    foreach ($urlFields as $f) {
        if (!isset($body[$f])) continue;
        $sets[] = "$f=?"; $vals[] = substr(trim($body[$f]), 0, 500);
    }
    if (empty($sets)) fail('No valid fields provided');

    $vals[] = $did;
    $pdo->prepare("UPDATE ride_drivers SET " . implode(',', $sets) . " WHERE user_id=?")->execute($vals);
    ok(array('updated' => true));
    break;
}

case 'driver_get_compliance_status': {
    // The driver's own view of the three documents: the current LIVE
    // value (same valid/expired computation NfcController.php's
    // nfc_driver_status uses) plus whatever they have pending review,
    // if anything.
    rides_ensure_compliance_requests_table($pdo);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'insurance_expiry_date'              => "insurance_expiry_date DATE NULL",
        'insurance_receipt_url'              => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date'   => "technical_inspection_expiry_date DATE NULL",
        'technical_inspection_receipt_url'   => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date'              => "road_fund_expiry_date DATE NULL",
        'road_fund_receipt_url'              => "road_fund_receipt_url VARCHAR(500) DEFAULT ''",
    ));

    $s = $pdo->prepare("SELECT
        insurance_expiry_date, insurance_receipt_url,
        technical_inspection_expiry_date, technical_inspection_receipt_url,
        road_fund_expiry_date, road_fund_receipt_url
        FROM ride_drivers WHERE user_id = ?");
    $s->execute(array($u['id']));
    $d = $s->fetch();

    $ps = $pdo->prepare("SELECT doc_type, requested_expiry_date, status, reject_reason, created_at
        FROM driver_compliance_requests
        WHERE driver_user_id = ? AND status = 'pending'
        ORDER BY id DESC");
    $ps->execute(array($u['id']));
    $pending = array();
    foreach ($ps->fetchAll() as $row) { $pending[$row['doc_type']] = $row; }

    $today = date('Y-m-d');
    $docTypes = array('insurance', 'technical_inspection', 'road_fund');
    $out = array();
    foreach ($docTypes as $t) {
        $expiry = $d ? $d[$t . '_expiry_date'] : null;
        $status = 'unknown';
        if (!empty($expiry)) $status = ($expiry >= $today) ? 'valid' : 'expired';
        $out[$t] = array(
            'status'      => $status,
            'expiry_date' => $expiry,
            'receipt_url' => $d ? $d[$t . '_receipt_url'] : '',
            'pending'     => isset($pending[$t]) ? array(
                'requested_expiry_date' => $pending[$t]['requested_expiry_date'],
                'created_at'            => $pending[$t]['created_at'],
            ) : null,
        );
    }
    ok(array('documents' => $out));
    break;
}

case 'driver_submit_compliance_document': {
    // POST: doc_type ('insurance'|'technical_inspection'|'road_fund'),
    // expiry_date (YYYY-MM-DD), receipt_url (required - a self-reported
    // expiry with no photo of the actual document isn't verifiable).
    rides_ensure_compliance_requests_table($pdo);

    $docType = isset($body['doc_type']) ? trim($body['doc_type']) : '';
    if (!in_array($docType, array('insurance', 'technical_inspection', 'road_fund'), true)) {
        fail('doc_type must be insurance, technical_inspection, or road_fund');
    }
    $expiry = isset($body['expiry_date']) ? trim($body['expiry_date']) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) fail('expiry_date must be a YYYY-MM-DD date');
    $receiptUrl = isset($body['receipt_url']) ? trim($body['receipt_url']) : '';
    if ($receiptUrl === '') fail('A photo of the document is required.');

    $pending = $pdo->prepare("SELECT id FROM driver_compliance_requests WHERE driver_user_id=? AND doc_type=? AND status='pending' LIMIT 1");
    $pending->execute(array($u['id'], $docType));
    if ($pending->fetch()) fail('You already have a ' . $docType . ' submission awaiting admin review.');

    $pdo->prepare("INSERT INTO driver_compliance_requests
        (driver_user_id, doc_type, requested_expiry_date, requested_receipt_url)
        VALUES (?,?,?,?)")
        ->execute(array($u['id'], $docType, $expiry, substr($receiptUrl, 0, 500)));

    ok(array('submitted' => true, 'message' => 'Submitted for admin review.'));
    break;
}

case 'admin_get_pending_compliance_requests': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_ensure_compliance_requests_table($pdo);
    $stmt = $pdo->prepare("SELECT r.id, r.driver_user_id, r.doc_type, r.requested_expiry_date,
        r.requested_receipt_url, r.created_at, us.name, us.phone
        FROM driver_compliance_requests r JOIN users us ON us.id = r.driver_user_id
        WHERE r.status = 'pending'
        ORDER BY r.created_at ASC");
    $stmt->execute();
    ok(array('requests' => $stmt->fetchAll()));
    break;
}

case 'admin_approve_compliance_document': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_ensure_compliance_requests_table($pdo);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id is required');

    $r = $pdo->prepare("SELECT * FROM driver_compliance_requests WHERE id=? AND status='pending'");
    $r->execute(array($rid));
    $req = $r->fetch();
    if (!$req) fail('Request not found or already reviewed.', 404);

    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'insurance_expiry_date'              => "insurance_expiry_date DATE NULL",
        'insurance_receipt_url'              => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date'   => "technical_inspection_expiry_date DATE NULL",
        'technical_inspection_receipt_url'   => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date'              => "road_fund_expiry_date DATE NULL",
        'road_fund_receipt_url'              => "road_fund_receipt_url VARCHAR(500) DEFAULT ''",
    ));
    $expiryCol  = $req['doc_type'] . '_expiry_date';
    $receiptCol = $req['doc_type'] . '_receipt_url';
    $pdo->prepare("UPDATE ride_drivers SET $expiryCol=?, $receiptCol=? WHERE user_id=?")
        ->execute(array($req['requested_expiry_date'], $req['requested_receipt_url'], $req['driver_user_id']));
    $pdo->prepare("UPDATE driver_compliance_requests SET status='approved', reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=?")
        ->execute(array($u['id'], $rid));
    ok(array('approved' => true));
    break;
}

case 'admin_reject_compliance_document': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_ensure_compliance_requests_table($pdo);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id is required');
    $reason = isset($body['reason']) ? trim(substr($body['reason'], 0, 255)) : '';
    $n = $pdo->prepare("UPDATE driver_compliance_requests SET status='rejected', reject_reason=?, reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=? AND status='pending'");
    $n->execute(array($reason, $u['id'], $rid));
    if ($n->rowCount() === 0) fail('Request not found or already reviewed.', 404);
    ok(array('rejected' => true));
    break;
}

case 'driver_get_demand': {
    /* "Hot areas" for drivers: where ride requests have been piling up in the last
       45 minutes compared with how many idle drivers are there. Only counts per
       area (about 700 m cells) and the area's centre - never a rider, address or
       phone. An area is worth showing when it has 2+ requests or someone is
       waiting right now. Score = (requests + 2 x waiting) / (idle drivers + 1). */
    if ($u['role'] !== 'driver' && ($u['role'] !== 'admin' || etel_is_ride_only(db(), $u))) fail('Drivers only', 403);
    $size = 700.0;
    $myLat = isset($body['lat']) && is_numeric($body['lat']) ? floatval($body['lat']) : null;
    $myLng = isset($body['lng']) && is_numeric($body['lng']) ? floatval($body['lng']) : null;
    if ($myLat === null) {
        try {
            $m = $pdo->prepare('SELECT lat, lng FROM ride_drivers WHERE user_id=?');
            $m->execute(array(intval($u['id'])));
            $mr = $m->fetch();
            if ($mr && $mr['lat'] !== null) { $myLat = floatval($mr['lat']); $myLng = floatval($mr['lng']); }
        } catch (Exception $e) {}
    }
    $cells = array();
    $idleDrivers = array();
    try {
        $q = $pdo->query("SELECT status, pickup_lat, pickup_lng FROM rides
                          WHERE created_at > DATE_SUB(NOW(), INTERVAL 45 MINUTE)
                            AND pickup_lat IS NOT NULL AND pickup_lng IS NOT NULL AND pickup_lat <> 0
                            AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                          LIMIT 2000");
        foreach ($q->fetchAll() as $r) {
            list($cq, $cr) = hexgrid_cell_for($r['pickup_lat'], $r['pickup_lng'], $size);
            $k = $cq . ':' . $cr;
            if (!isset($cells[$k])) $cells[$k] = array('n' => 0, 'waiting' => 0, 'idle' => 0, 'sl' => 0.0, 'sg' => 0.0);
            $cells[$k]['n']++;
            $cells[$k]['sl'] += floatval($r['pickup_lat']);
            $cells[$k]['sg'] += floatval($r['pickup_lng']);
            if ($r['status'] === 'searching') $cells[$k]['waiting']++;
        }
        $dq = $pdo->query("SELECT d.lat, d.lng FROM ride_drivers d
                           WHERE d.online=1 AND d.lat IS NOT NULL AND d.lng IS NOT NULL
                             AND d.last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                             AND NOT EXISTS (SELECT 1 FROM rides r WHERE r.driver_id=d.user_id AND r.status IN ('accepted','in_progress','arrived'))
                           LIMIT 500");
        $idleDrivers = $dq->fetchAll(); // counted per area below, by distance, so a driver just across a cell edge still counts
    } catch (Exception $e) {
        fail_ex($e, 'Could not load demand', 500);
    }
    $out = array();
    foreach ($cells as $c) {
        if ($c['n'] < 2 && $c['waiting'] < 1) continue;
        $lat = $c['sl'] / $c['n']; $lng = $c['sg'] / $c['n'];
        $c['idle'] = 0; // free drivers within 600 m of the area's centre
        foreach ($idleDrivers as $d) {
            $dl = deg2rad(floatval($d['lat']) - $lat); $dg = deg2rad(floatval($d['lng']) - $lng);
            $h = sin($dl / 2) * sin($dl / 2) + cos(deg2rad($lat)) * cos(deg2rad(floatval($d['lat']))) * sin($dg / 2) * sin($dg / 2);
            if (6371 * 2 * atan2(sqrt($h), sqrt(1 - $h)) <= 0.6) $c['idle']++;
        }
        $score = ($c['n'] + 2 * $c['waiting']) / ($c['idle'] + 1);
        $level = ($score >= 4 || ($c['waiting'] >= 1 && $c['idle'] == 0)) ? 'high' : ($score >= 2 ? 'med' : 'low');
        $km = null;
        if ($myLat !== null) {
            $dLat = deg2rad($lat - $myLat); $dLng = deg2rad($lng - $myLng);
            $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($myLat)) * cos(deg2rad($lat)) * sin($dLng / 2) * sin($dLng / 2);
            $km = round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
        }
        $out[] = array('lat' => round($lat, 5), 'lng' => round($lng, 5), 'requests' => $c['n'], 'waiting' => $c['waiting'],
                       'idle_drivers' => $c['idle'], 'score' => round($score, 1), 'level' => $level, 'distance_km' => $km);
    }
    usort($out, function ($a, $b) {
        if ($a['score'] != $b['score']) return $a['score'] < $b['score'] ? 1 : -1;
        if ($a['distance_km'] === null || $b['distance_km'] === null || $a['distance_km'] == $b['distance_km']) return 0;
        return $a['distance_km'] < $b['distance_km'] ? -1 : 1;
    });
    ok(array('areas' => array_slice($out, 0, 8), 'window_min' => 45));
    break;
}

case 'driver_get_nearby_requests': {
    $radius = isset($body['radius_km']) ? floatval($body['radius_km']) : 8;
    if ($radius <= 0 || $radius > 50) $radius = 8;
    rides_require_onboarded($pdo, $u['id']);
    $me = rides_driver_profile($pdo, $u['id']);

    $pdo->exec("UPDATE rides SET status='cancelled', cancelled_at=NOW()
                WHERE status='searching' AND expires_at IS NOT NULL AND expires_at < NOW()");

    /* BUG FIX: this query had NO vehicle_type filtering at all - any
       online driver could see and accept ANY request regardless of
       what vehicle type the customer actually selected and is paying
       for (Bajaj/Moto/Car/Minibus/Qute are different rates). A Bajaj
       driver could accept a Car request, show up in the wrong vehicle
       entirely. Now strictly matches the driver's own assigned vehicle
       type against the ride's requested one - a driver only ever sees
       requests for their own vehicle type, same as the pricing model
       already implies. Falls back to 'bajaj' if the driver's own
       vehicle_type is somehow unset, matching book_ride's own default. */
    $myVtype = ($me && !empty($me['vehicle_type'])) ? $me['vehicle_type'] : 'bajaj';
    rides_ensure_exclusions_table($pdo);
    rides_offer_price_ensure($pdo);
    $s = $pdo->prepare(
        "SELECT r.id, r.pickup_lat, r.pickup_lng, r.dropoff_lat, r.dropoff_lng,
                r.pickup_address, r.dropoff_address, r.distance_km, r.fare_final, r.rider_offer, r.fare_estimate,
                (SELECT c.price FROM ride_counter_offers c WHERE c.ride_id = r.id AND c.driver_id = ? AND c.status = 'pending') AS my_counter,
                r.expires_at, r.created_at, TIMESTAMPDIFF(SECOND, r.created_at, NOW()) AS age_s, u.name AS passenger_name
         FROM rides r JOIN users u ON u.id = r.passenger_id
         WHERE r.status='searching' AND r.passenger_id != ?
               AND (LOWER(r.vehicle_type) = LOWER(?) OR r.vehicle_type IS NULL)
               AND NOT EXISTS (SELECT 1 FROM ride_driver_exclusions x WHERE x.ride_id = r.id AND x.driver_id = ?)
         ORDER BY r.id DESC LIMIT 30");
    $s->execute(array($u['id'], $u['id'], $myVtype, $u['id']));
    $rows = $s->fetchAll();

    // Hex pre-filter: this candidate set is already small (status +
    // vehicle_type filtered, LIMIT 30), so there's no indexed DB query
    // to optimize here the way passenger_get_nearby_drivers has - but
    // a cheap integer bounding-box check still lets us skip the
    // trig-heavy Haversine call entirely for anything obviously out of
    // range, computed once per candidate instead of always paying for
    // the full distance formula.
    $myHexQ = null; $myHexR = null; $ringK = null;
    if ($me && $me['lat'] !== null && $me['lng'] !== null) {
        list($myHexQ, $myHexR) = hexgrid_cell_for(floatval($me['lat']), floatval($me['lng']));
        $ringK = hexgrid_rings_for_radius($radius * 1000.0);
    }

    $out = array();
    foreach ($rows as $r) {
        $dist = null;
        if ($me && $me['lat'] !== null && $r['pickup_lat'] !== null) {
            if ($myHexQ !== null) {
                list($rideHexQ, $rideHexR) = hexgrid_cell_for(floatval($r['pickup_lat']), floatval($r['pickup_lng']));
                if ($rideHexQ !== null
                    && (abs($rideHexQ - $myHexQ) > $ringK || abs($rideHexR - $myHexR) > $ringK)) {
                    continue; // clearly outside the search radius - skip the exact distance calc entirely
                }
            }
            $dist = rides_haversine_km(floatval($me['lat']), floatval($me['lng']),
                                       floatval($r['pickup_lat']), floatval($r['pickup_lng']));
            if ($dist > $radius) continue;
        }
        $r['distance_from_driver_km'] = $dist !== null ? round($dist, 2) : 0;
        if (floatval($r['rider_offer']) > 0) {   // the most a driver may ask for this trip (price offers, lib/ride_price_offer.php)
            $ob = rides_offer_bounds($pdo, floatval($r['fare_estimate']) > 0 ? $r['fare_estimate'] : $r['fare_final']);
            $r['offer_max'] = $ob['max'];
        }
        $out[] = $r;
    }
    // Offer waves (lib/ride_offer.php): the nearest drivers see a new request first, the rest a few seconds later.
    if ($me && $me['lat'] !== null && $me['lng'] !== null) {
        $out = rides_offer_filter($pdo, $u['id'], $me['lat'], $me['lng'], $myVtype, $out);
    }
    // Nearest pickup first (the list used to be newest first, which put a far request above a close one).
    usort($out, function ($a, $b) {
        if ($a['distance_from_driver_km'] == $b['distance_from_driver_km']) return intval($b['id']) - intval($a['id']);
        return ($a['distance_from_driver_km'] < $b['distance_from_driver_km']) ? -1 : 1;
    });
    ok(array('requests' => $out));
    break;
}

case 'driver_get_goal_tiers': {
    rides_ensure_goal_tiers_table($pdo);
    // Real per-driver average fare, not a fixed admin-set bonus - "what
    // you'd actually earn for N trips" based on this driver's own real
    // numbers, which naturally changes day to day as their trip mix
    // changes. 30-day window first (recent and representative); falls
    // back to all-time average for a driver too new to have 30 days of
    // history yet; falls back to 0 (shown as "-" client-side) for a
    // driver with no completed rides at all.
    $avgFare = 0;
    try {
        $s30 = $pdo->prepare("SELECT AVG(fare_paid) AS avg_fare, COUNT(*) AS n FROM rides WHERE driver_id=? AND status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $s30->execute(array($u['id']));
        $r30 = $s30->fetch();
        if ($r30 && intval($r30['n']) >= 3) {
            $avgFare = round(floatval($r30['avg_fare']), 2);
        } else {
            $sAll = $pdo->prepare("SELECT AVG(fare_paid) AS avg_fare FROM rides WHERE driver_id=? AND status='completed'");
            $sAll->execute(array($u['id']));
            $rAll = $sAll->fetch();
            $avgFare = $rAll && $rAll['avg_fare'] !== null ? round(floatval($rAll['avg_fare']), 2) : 0;
        }
    } catch (Exception $e) { $avgFare = 0; }
    try {
        $rows = $pdo->query("SELECT trips_required FROM driver_goal_tiers WHERE active=1 ORDER BY sort_order, trips_required")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) $rows = array(array('trips_required'=>2), array('trips_required'=>4), array('trips_required'=>6), array('trips_required'=>8), array('trips_required'=>10), array('trips_required'=>12));
    } catch (Exception $e) {
        $rows = array(array('trips_required'=>2), array('trips_required'=>4), array('trips_required'=>6), array('trips_required'=>8), array('trips_required'=>10), array('trips_required'=>12));
    }
    $tiers = array();
    foreach ($rows as $row) {
        $trips = intval($row['trips_required']);
        $tiers[] = array('trips_required' => $trips, 'projected_etb' => round($trips * $avgFare, 2));
    }
    ok(array('tiers' => $tiers, 'avg_fare' => $avgFare));
    break;
}

case 'driver_get_profile': {
    // Close stuck trips (at most one sweep per 5 min across all requests; no cron needed).
    rides_sweep_maybe($pdo);
    $d = rides_driver_profile($pdo, $u['id']);
    $usr = $pdo->prepare("SELECT name, phone FROM users WHERE id=?");
    $usr->execute(array($u['id']));
    $urow = $usr->fetch();
    if ($urow && $d) {
        $d['name']  = !empty($d['name'])  ? $d['name']  : $urow['name'];
        $d['phone'] = !empty($d['phone']) ? $d['phone'] : $urow['phone'];
    }
    // Real trip count/earnings for TODAY specifically - the daily goal
    // widget (driver/index.html's "2 trips goal" tiles) previously had
    // no real data behind it at all: the tier chips were just static
    // demo numbers with a client-side "selected" state and no actual
    // progress tracking against trips the driver has genuinely completed
    // today.
    if ($d) {
        try {
            $todayStmt = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(fare_paid),0) AS rev FROM rides WHERE driver_id=? AND status='completed' AND DATE(completed_at)=CURDATE()");
            $todayStmt->execute(array($u['id']));
            $today = $todayStmt->fetch();
            $d['trips_today'] = intval($today['n']);
            $d['earnings_today'] = round(floatval($today['rev']), 2);
        } catch (Exception $e) {
            $d['trips_today'] = 0;
            $d['earnings_today'] = 0;
        }
    }
    ok(array('driver' => $d, 'active_ride' => rides_get_active_for_driver($pdo, $u['id'])));
    break;
}

case 'driver_get_earnings_for_date': {
    // Powers the tappable date strip on the Money page (driver/index.html's
    // 7-pip row) - was pure decoration before this: no click handler
    // existed anywhere in the frontend, and the only earnings-by-date
    // query anywhere in the backend was driver_get_profile's CURDATE()-only
    // one above (today specifically, always). Same query, generalized to
    // accept any of the last 7 days so tapping a past pip means something.
    $dateStr = isset($body['date']) ? trim($body['date']) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) fail('Invalid date format, expected YYYY-MM-DD', 400);
    $reqDate = DateTime::createFromFormat('Y-m-d', $dateStr);
    $today = new DateTime('today');
    if (!$reqDate || $reqDate > $today) fail('Date cannot be in the future', 400);
    $daysAgo = (int) $today->diff($reqDate)->format('%a');
    if ($daysAgo > 6) fail('Only the last 7 days are available', 400);
    $s = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(fare_paid),0) AS rev FROM rides WHERE driver_id=? AND status='completed' AND DATE(completed_at)=?");
    $s->execute(array($u['id'], $dateStr));
    $row = $s->fetch();
    ok(array('date' => $dateStr, 'trips' => intval($row['n']), 'earnings' => round(floatval($row['rev']), 2)));
    break;
}

case 'driver_get_kpi_summary': {
    // Real driver KPI dashboard - per the recruitment playbook's
    // recommendation, net ETB per working hour is the headline number,
    // since drivers care about take-home money relative to time and
    // fuel spent, not raw trip count. Everything here is computed from
    // real rows (rides.fare_paid, rides.distance_km, driver_shifts
    // online_seconds) - nothing here is a projection or estimate.
    $period = isset($body['period']) ? $body['period'] : 'today';
    if (!in_array($period, array('today', 'week', 'month'), true)) fail('period must be today, week, or month', 400);
    $sinceExpr = $period === 'today' ? 'CURDATE()' : ($period === 'week' ? 'DATE_SUB(CURDATE(), INTERVAL 6 DAY)' : 'DATE_SUB(CURDATE(), INTERVAL 29 DAY)');

    $s = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(fare_paid),0) AS gross, COALESCE(SUM(distance_km),0) AS km
        FROM rides WHERE driver_id=? AND status='completed' AND completed_at >= $sinceExpr");
    $s->execute(array($u['id']));
    $r = $s->fetch();
    $completedRides = intval($r['n']);
    $gross = round(floatval($r['gross']), 2);
    $km = round(floatval($r['km']), 2);

    // Same 5% rate as the live commission logic (RideController's
    // driver_arrived case) - shown to the driver as a transparent
    // breakdown, not a separate/different number than what's actually
    // charged.
    $platformFee = round($gross * 0.05, 2);
    $netEarnings = round($gross - $platformFee, 2);

    // Online seconds: closed shifts fully within the window, plus the
    // in-progress portion of a still-open shift if the driver is
    // online right now - otherwise a driver mid-shift would show 0
    // hours until they went offline, undercounting real time.
    $shiftSql = "SELECT COALESCE(SUM(
            CASE WHEN ended_at IS NOT NULL THEN online_seconds
                 ELSE TIMESTAMPDIFF(SECOND, started_at, NOW()) END
        ), 0) AS secs
        FROM driver_shifts WHERE driver_user_id=? AND started_at >= $sinceExpr";
    $shiftStmt = $pdo->prepare($shiftSql);
    $shiftStmt->execute(array($u['id']));
    $onlineSeconds = intval($shiftStmt->fetch()['secs']);
    $onlineHours = round($onlineSeconds / 3600, 2);
    $netPerHour = $onlineHours > 0 ? round($netEarnings / $onlineHours, 2) : null;

    // Real fuel cost, if the driver has logged at least 2 odometer
    // readings (so a real distance-driven figure exists); otherwise
    // fall back to the recruitment playbook's ~8 ETB/km estimate
    // applied to this period's distance. Net-after-fuel is the
    // number that actually answers "how much am I really keeping" -
    // net_earnings alone only accounts for the platform's 5%, not the
    // driver's single biggest real operating cost.
    $fuelCostPerKm = 8.0;
    $usingRealFuelRate = false;
    try {
        $odo = $pdo->prepare('SELECT odometer_km, logged_at FROM driver_fuel_logs WHERE driver_user_id=? AND odometer_km IS NOT NULL ORDER BY logged_at ASC');
        $odo->execute(array($u['id']));
        $odoRows = $odo->fetchAll(PDO::FETCH_ASSOC);
        if (count($odoRows) >= 2) {
            $kmSpan = floatval($odoRows[count($odoRows) - 1]['odometer_km']) - floatval($odoRows[0]['odometer_km']);
            if ($kmSpan > 0) {
                $costSpan = $pdo->prepare('SELECT COALESCE(SUM(cost_etb),0) AS c FROM driver_fuel_logs WHERE driver_user_id=? AND logged_at BETWEEN ? AND ?');
                $costSpan->execute(array($u['id'], $odoRows[0]['logged_at'], $odoRows[count($odoRows) - 1]['logged_at']));
                $fuelCostPerKm = round(floatval($costSpan->fetch()['c']) / $kmSpan, 2);
                $usingRealFuelRate = true;
            }
        }
    } catch (Exception $e) { /* fuel table may not exist yet on older installs - flat estimate stands */ }
    $estimatedFuelCost = round($km * $fuelCostPerKm, 2);
    $netAfterFuel = round($netEarnings - $estimatedFuelCost, 2);
    $netPerHourAfterFuel = $onlineHours > 0 ? round($netAfterFuel / $onlineHours, 2) : null;

    ok(array(
        'period' => $period,
        'completed_rides' => $completedRides,
        'gross_fare' => $gross,
        'platform_fee' => $platformFee,
        'net_earnings' => $netEarnings,
        'distance_km' => $km,
        'online_hours' => $onlineHours,
        'net_etb_per_hour' => $netPerHour,
        'fuel_cost_per_km' => $fuelCostPerKm,
        'using_real_fuel_rate' => $usingRealFuelRate,
        'estimated_fuel_cost' => $estimatedFuelCost,
        'net_after_fuel' => $netAfterFuel,
        'net_etb_per_hour_after_fuel' => $netPerHourAfterFuel,
        'note' => $onlineHours > 0 ? null : 'No tracked online time in this period yet - go online to start tracking net ETB/hour.',
    ));
    break;
}

case 'driver_get_announcements': {
    /* Unifies what were three separate toast-only stubs in driver.html
       ("Pro news", "Support notifications", "Partner messages") - all
       really the same pattern (admin posts, driver reads), so one table
       and one action serves all three views.

       BUG FIX: all three driver-app tabs used to call this with no
       category filter at all, so every tab showed the exact same
       unfiltered mix of every announcement regardless of which category
       an admin posted it under. Now filters by category when one is
       given; omitting it still returns everything (used nowhere
       currently, but kept for a future "All" view). */
    $filterCat = isset($body['category']) ? trim($body['category']) : (isset($_GET['category']) ? trim($_GET['category']) : '');
    if ($filterCat !== '' && !in_array($filterCat, array('news', 'notification', 'partner'), true)) $filterCat = '';
    $list = null;
    foreach (array(" AND a.audience IN ('drivers','all')", '') as $audSql) { // '' = fallback before the audience column exists
        try {
            $sql = 'SELECT a.id, a.category, a.title, a.body, a.created_at,
                    (r.id IS NOT NULL) AS is_read
             FROM driver_announcements a
             LEFT JOIN driver_announcement_reads r ON r.announcement_id = a.id AND r.user_id = ?
             WHERE a.active = 1' . $audSql;
            $params = array($u['id']);
            if ($filterCat !== '') { $sql .= ' AND a.category = ?'; $params[] = $filterCat; }
            $sql .= ' ORDER BY a.created_at DESC LIMIT 100';
            $rows = $pdo->prepare($sql);
            $rows->execute($params);
            $list = $rows->fetchAll(PDO::FETCH_ASSOC);
            break;
        } catch (Exception $e) { $list = null; }
    }
    if ($list === null) fail('Could not load announcements', 500);
    foreach ($list as &$row) { $row['is_read'] = (bool) intval($row['is_read']); }
    ok(array('announcements' => $list));
    break;
}

case 'rider_get_announcements': {
    /* Announcements an admin addressed to riders (audience riders/all), newest first. */
    try {
        $st = $pdo->prepare("SELECT a.id, a.category, a.title, a.body, a.created_at, (r.id IS NOT NULL) AS is_read
             FROM driver_announcements a
             LEFT JOIN driver_announcement_reads r ON r.announcement_id = a.id AND r.user_id = ?
             WHERE a.active = 1 AND a.audience IN ('riders','all') ORDER BY a.created_at DESC LIMIT 20");
        $st->execute(array($u['id']));
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $list = array(); } // audience column not created yet = nothing addressed to riders
    foreach ($list as &$row) { $row['is_read'] = (bool) intval($row['is_read']); }
    ok(array('announcements' => $list));
    break;
}

case 'rider_mark_announcement_read': {
    $aid = isset($body['announcement_id']) ? intval($body['announcement_id']) : 0;
    if (!$aid) fail('announcement_id required', 400);
    try {
        $pdo->prepare('INSERT IGNORE INTO driver_announcement_reads (announcement_id, user_id) VALUES (?, ?)')->execute(array($aid, $u['id']));
        ok(array('message' => 'Marked read'));
    } catch (Exception $e) { fail_ex($e, 'Could not mark as read', 500); }
    break;
}

case 'driver_mark_announcement_read': {
    $aid = isset($body['announcement_id']) ? intval($body['announcement_id']) : 0;
    if (!$aid) fail('announcement_id required', 400);
    try {
        $ins = $pdo->prepare('INSERT IGNORE INTO driver_announcement_reads (announcement_id, user_id) VALUES (?, ?)');
        $ins->execute(array($aid, $u['id']));
        ok(array('message' => 'Marked read'));
    } catch (Exception $e) { fail_ex($e, 'Could not mark as read', 500); }
    break;
}

case 'driver_get_referral_info': {
    /* Generate a code on first request rather than at registration time -
       simpler migration (no need to backfill every existing row), and a
       driver who never opens the Invite screen never needs one anyway. */
    $row = $pdo->prepare('SELECT referral_code FROM couriers WHERE user_id=? LIMIT 1');
    $row->execute(array($u['id']));
    $existing = $row->fetch();
    $code = $existing ? $existing['referral_code'] : null;
    if (!$code) {
        // Short, shareable, unambiguous alphabet (no 0/O/1/I confusion)
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = '';
            for ($i = 0; $i < 6; $i++) { $candidate .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
            try {
                $upd = $pdo->prepare('UPDATE couriers SET referral_code=? WHERE user_id=? AND referral_code IS NULL');
                $upd->execute(array($candidate, $u['id']));
                if ($upd->rowCount() > 0) { $code = $candidate; break; }
                // Someone already had a code by the time we checked - re-read it.
                $row->execute(array($u['id']));
                $existing = $row->fetch();
                if ($existing && $existing['referral_code']) { $code = $existing['referral_code']; break; }
            } catch (Exception $e) { /* unique collision - loop and retry a new candidate */ }
        }
    }
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM couriers WHERE referred_by = (SELECT referral_code FROM couriers WHERE user_id=? LIMIT 1) AND referred_by IS NOT NULL');
    $cnt->execute(array($u['id']));
    $referred_count = $code ? intval($cnt->fetchColumn()) : 0;
    ok(array('referral_code' => $code, 'referred_count' => $referred_count));
    break;
}

case 'submit_ride_issue': {
    /* Replaces the showRideIssues() "coming in Phase 2" toast stub. Works
       whether or not there's a current ride (ride_id nullable) - a rider
       reporting something before a driver's even assigned still needs
       somewhere real to send it. */
    $category = isset($body['category']) ? trim($body['category']) : '';
    $allowed = array('no_show', 'wrong_route', 'safety', 'fare_dispute', 'driver_behavior', 'other');
    if (!in_array($category, $allowed, true)) fail('Invalid issue category', 400);
    $ride_id = isset($body['ride_id']) ? intval($body['ride_id']) : null;
    if ($ride_id === 0) $ride_id = null;
    $details = isset($body['details']) ? trim(substr($body['details'], 0, 2000)) : '';
    try {
        $s = $pdo->prepare('INSERT INTO ride_issues (ride_id, reporter_id, category, details, status) VALUES (?, ?, ?, ?, "open")');
        $s->execute(array($ride_id, $u['id'], $category, $details));
        ok(array('message' => 'Report submitted - our team will follow up', 'id' => intval($pdo->lastInsertId())));
    } catch (Exception $e) {
        fail_ex($e, 'Could not submit report', 500);
    }
    break;
}

case 'sos_trigger': {
    /* Driver or rider panic button. Fires from an active-trip screen (or
       the driver's general dashboard) straight to admin - no confirmation
       step here, the confirm dialog lives client-side before this call is
       ever made. Self-heals its own table so no manual migration step is
       required to start using it immediately. */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sos_alerts (
            id INT(11) NOT NULL AUTO_INCREMENT,
            ride_id INT(11) DEFAULT NULL,
            reporter_id INT(11) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'rider',
            lat DECIMAL(10,7) DEFAULT NULL,
            lng DECIMAL(10,7) DEFAULT NULL,
            message VARCHAR(500) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            resolved_by INT(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ride (ride_id),
            KEY idx_reporter (reporter_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ride_id = isset($body['ride_id']) ? intval($body['ride_id']) : null;
    if ($ride_id === 0) $ride_id = null;
    $role = ($u['role'] === 'driver' || $u['role'] === 'courier') ? 'driver' : 'rider';
    $lat = isset($body['lat']) && is_numeric($body['lat']) ? floatval($body['lat']) : null;
    $lng = isset($body['lng']) && is_numeric($body['lng']) ? floatval($body['lng']) : null;
    $message = isset($body['message']) ? trim(substr($body['message'], 0, 500)) : '';
    try {
        $s = $pdo->prepare('INSERT INTO sos_alerts (ride_id, reporter_id, role, lat, lng, message, status) VALUES (?, ?, ?, ?, ?, ?, "open")');
        $s->execute(array($ride_id, $u['id'], $role, $lat, $lng, $message));
        ok(array('message' => 'Alert sent to ETEL support', 'id' => intval($pdo->lastInsertId())));
    } catch (Exception $e) {
        fail_ex($e, 'Could not send alert', 500);
    }
    break;
}

case 'driver_cashout': {
    /* Pay out (part of) a driver's available earnings to their own Etel
       Pay wallet. Uses a DB-enforced idempotent partner_ref -- a network
       retry never double-credits, and a failed request never falls
       through to a false "success" toast. */
    rides_require_onboarded($pdo, $u['id']);
    $amount = etel_validate_amount(isset($body['amount']) ? $body['amount'] : null);
    if ($amount === false) fail('amount must be a valid amount greater than 0');

    $usr = $pdo->prepare("SELECT phone FROM users WHERE id=?");
    $usr->execute(array($u['id']));
    $urow = $usr->fetch();
    if (!$urow || !$urow['phone']) fail('No phone number on file for your account');

    // DDL first, outside the transaction (MySQL implicitly commits on DDL
    // anyway, so doing it inside the transaction below would be misleading).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS driver_payouts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            partner_ref VARCHAR(80) NOT NULL,
            tx_ref VARCHAR(40) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_partner_ref (partner_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->beginTransaction();
    try {
        $d = $pdo->prepare("SELECT total_earnings, total_paid_out FROM ride_drivers WHERE user_id=? FOR UPDATE");
        $d->execute(array($u['id']));
        $row = $d->fetch();
        if (!$row) { $pdo->rollBack(); fail('Driver profile not found', 404); }

        $available = round(floatval($row['total_earnings']) - floatval($row['total_paid_out']), 2);
        if ($amount > $available + 0.01) {
            $pdo->rollBack();
            fail('Amount exceeds your available balance (ETB ' . number_format($available, 2) . ')');
        }

        // Reserve the payout locally BEFORE calling Etel Pay -- if the
        // Etel Pay call fails, we roll this back too, so total_paid_out
        // never drifts ahead of what was actually credited.
        $partner_ref = 'delivery-cashout-' . $u['id'] . '-' . time() . '-' . bin2hex(random_bytes(4));
        $pdo->prepare("UPDATE ride_drivers SET total_paid_out = total_paid_out + ? WHERE user_id=?")
            ->execute(array($amount, $u['id']));
        $pdo->prepare("INSERT INTO driver_payouts (user_id, amount, partner_ref, status) VALUES (?, ?, ?, 'pending')")
            ->execute(array($u['id'], $amount, $partner_ref));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Cashout failed', 500);
    }

    require_once __DIR__ . '/../etel_pay_bridge.php';
    $result = etel_pay_partner_credit($urow['phone'], $amount, $partner_ref, 'ETEL Delivery driver payout');

    if (isset($result['error']) && !empty($result['uncertain'])) {
        // Outcome unknown (timeout / unreachable / garbled reply): Etel Pay may
        // already have credited this payout. Rolling the balance back here let a
        // driver retry with a new reference and be paid twice, so the amount stays
        // reserved and the payout is parked as 'unknown' for an admin to settle
        // against Etel Pay using the same partner_ref.
        $pdo->prepare("UPDATE driver_payouts SET status='unknown' WHERE partner_ref=?")->execute(array($partner_ref));
        error_log('[ETEL][cashout] outcome unknown for ' . $partner_ref . ' (user ' . $u['id'] . ', ETB ' . $amount . '): ' . $result['error']);
        fail('Your cashout is being confirmed with Etel Pay. Please check your Etel Pay wallet in a few minutes and do not try again yet. If it has not arrived within an hour, contact support with reference ' . $partner_ref . '.', 202);
    }
    if (isset($result['error'])) {
        // Etel Pay explicitly refused -- the money never actually moved, so release the reservation.
        $pdo->prepare("UPDATE ride_drivers SET total_paid_out = total_paid_out - ? WHERE user_id=?")
            ->execute(array($amount, $u['id']));
        $pdo->prepare("UPDATE driver_payouts SET status='failed' WHERE partner_ref=?")->execute(array($partner_ref));
        fail('Cashout to Etel Pay failed: ' . $result['error'], 502);
    }

    $pdo->prepare("UPDATE driver_payouts SET status='completed', tx_ref=? WHERE partner_ref=?")
        ->execute(array($result['tx_ref'], $partner_ref));

    ok(array(
        'tx_ref'    => $result['tx_ref'],
        'amount'    => $amount,
        'available_balance' => round($available - $amount, 2),
    ));
    break;
}

case 'driver_topup_etel_money': {
    /* IMPORTANT: Etel Pay is the shared payment rail used across all of
       Etel Group's products, not something scoped to this Ride/Delivery
       product specifically. Verifying an Etel Pay transaction confirms
       the driver's money genuinely moved SOMEWHERE - it is a separate
       question from whether it should be recognized against THIS
       driver's etel_money_balance (the Ride/Delivery-specific ledger
       that the 5% commission is deducted from). So this only verifies
       the payment and files a PENDING request - it does NOT credit the
       balance itself. An admin has to explicitly approve it
       (admin_approve_etel_topup) before the balance actually moves.
       This mirrors driver_cashout/wallet-ride-payment in verifying with
       Etel Pay first and never trusting the client's "I paid" claim
       alone, but adds the extra human approval gate on top since a
       verified transfer and an approved commission-balance credit are
       not the same thing here. */
    rides_require_onboarded($pdo, $u['id']);
    $amount = etel_validate_amount(isset($body['amount']) ? $body['amount'] : null);
    if ($amount === false) fail('amount must be a valid amount greater than 0');
    $tx_ref = isset($body['tx_ref']) ? trim($body['tx_ref']) : '';
    if (!$tx_ref) fail('tx_ref required');
    // Optional: a screenshot of the Etel Pay receipt, uploaded through the
    // same upload flow driver.html already uses for selfie_url/
    // license_photo_url. Not required here (the tx_ref is independently
    // verified against Etel Pay below), but stored for the admin approval
    // screen so an approver can eyeball the receipt alongside the
    // machine-verified reference.
    $receipt_image_url = isset($body['receipt_image_url']) ? trim($body['receipt_image_url']) : '';
    if (mb_strlen($receipt_image_url) > 500) fail('receipt_image_url is too long');

    $usr = $pdo->prepare("SELECT phone FROM users WHERE id=?");
    $usr->execute(array($u['id']));
    $urow = $usr->fetch();
    if (!$urow || !$urow['phone']) fail('No phone number on file for your account');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS driver_etel_money_topups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            tx_ref VARCHAR(40) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'etelpay',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            receipt_image_url VARCHAR(500) NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tx_ref (tx_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Older installs of this table (created before the approval-gate
    // change, or before receipt photos were supported) won't have these
    // columns yet - add them if missing rather than requiring a manual
    // migration step.
    rides_add_missing_cols($pdo, 'driver_etel_money_topups', array(
        'status'             => "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'approved_by'        => "approved_by INT UNSIGNED NULL",
        'approved_at'        => "approved_at DATETIME NULL",
        'receipt_image_url'  => "receipt_image_url VARCHAR(500) NULL"
    ));

    $dupe = $pdo->prepare("SELECT id FROM driver_etel_money_topups WHERE tx_ref=? LIMIT 1");
    $dupe->execute(array($tx_ref));
    if ($dupe->fetch()) fail('This payment has already been used for a top-up', 409);

    require_once __DIR__ . '/../etel_pay_bridge.php';
    $tx = etel_pay_verify_tx($tx_ref, $urow['phone'], $amount);
    if (!$tx) fail('Could not verify Etel Pay payment. If money left your account, contact support with reference ' . $tx_ref, 402);
    if (!etel_pay_claim_tx($pdo, $tx_ref, 'topup')) fail('This payment has already been used', 409);

    try {
        $pdo->prepare("INSERT INTO driver_etel_money_topups (user_id, amount, tx_ref, source, status, receipt_image_url) VALUES (?, ?, ?, 'etelpay', 'pending', ?)")
            ->execute(array($u['id'], $amount, $tx_ref, $receipt_image_url ?: null));
    } catch (Exception $e) {
        fail_ex($e, 'Top-up request failed', 500);
    }

    ok(array(
        'tx_ref' => $tx_ref,
        'amount' => $amount,
        'status' => 'pending',
        'receipt_image_url' => $receipt_image_url,
        'message' => 'Payment verified. Your top-up of ETB ' . number_format($amount, 2) . ' is pending admin approval before it applies to your balance.',
    ));
    break;
}

case 'driver_submit_manual_topup': {
    /* For drivers who pay by direct bank transfer / cash deposit rather
       than through Etel Pay - there's no transaction to verify
       automatically here (unlike driver_topup_etel_money above), so a
       receipt photo is REQUIRED as the driver's proof of payment. Always
       lands as 'pending' with source='manual_receipt'; an admin reviews
       the photo and approves/rejects it with the existing
       admin_approve_etel_topup / admin_reject_etel_topup actions (those
       aren't source-specific, so no change was needed there). */
    $amount = etel_validate_amount(isset($body['amount']) ? $body['amount'] : null);
    if ($amount === false) fail('amount must be a valid amount greater than 0');
    $receipt_image_url = isset($body['receipt_image_url']) ? trim($body['receipt_image_url']) : '';
    if (!$receipt_image_url) fail('A receipt photo (receipt_image_url) is required for a manual top-up request');
    if (mb_strlen($receipt_image_url) > 500) fail('receipt_image_url is too long');
    $note = isset($body['note']) ? mb_substr(trim($body['note']), 0, 255) : '';

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS driver_etel_money_topups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            tx_ref VARCHAR(40) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'etelpay',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            receipt_image_url VARCHAR(500) NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tx_ref (tx_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    rides_add_missing_cols($pdo, 'driver_etel_money_topups', array(
        'status'             => "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'approved_by'        => "approved_by INT UNSIGNED NULL",
        'approved_at'        => "approved_at DATETIME NULL",
        'receipt_image_url'  => "receipt_image_url VARCHAR(500) NULL"
    ));

    // tx_ref has a UNIQUE key but manual submissions have no real
    // transaction reference - synthesize one so the column stays NOT NULL
    // and unique without loosening the schema.
    $manualRef = 'manual-' . $u['id'] . '-' . time() . '-' . bin2hex(random_bytes(4));
    try {
        $pdo->prepare("INSERT INTO driver_etel_money_topups (user_id, amount, tx_ref, source, status, note, receipt_image_url) VALUES (?, ?, ?, 'manual_receipt', 'pending', ?, ?)")
            ->execute(array($u['id'], $amount, $manualRef, $note, $receipt_image_url));
    } catch (Exception $e) {
        fail_ex($e, 'Top-up request failed', 500);
    }

    ok(array(
        'id' => intval($pdo->lastInsertId()),
        'amount' => $amount,
        'status' => 'pending',
        'receipt_image_url' => $receipt_image_url,
        'message' => 'Your top-up request of ETB ' . number_format($amount, 2) . ' and receipt photo were submitted. An admin will review it shortly.',
    ));
    break;
}

case 'driver_get_topup_history': {
    /* Wallet screen history list - both Etel Pay and manual/receipt
       top-ups the driver has submitted, newest first, so they can see
       the status of each and re-view the receipt photo they uploaded. */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS driver_etel_money_topups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            tx_ref VARCHAR(40) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'etelpay',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            receipt_image_url VARCHAR(500) NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tx_ref (tx_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    rides_add_missing_cols($pdo, 'driver_etel_money_topups', array(
        'status'             => "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'approved_by'        => "approved_by INT UNSIGNED NULL",
        'approved_at'        => "approved_at DATETIME NULL",
        'receipt_image_url'  => "receipt_image_url VARCHAR(500) NULL"
    ));
    $s = $pdo->prepare("SELECT id, amount, tx_ref, source, status, note, receipt_image_url, created_at, approved_at
                         FROM driver_etel_money_topups WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $s->execute(array($u['id']));
    ok(array('topups' => $s->fetchAll()));
    break;
}

case 'driver_update_profile': {
    /* Vehicle details (type/make/color/year/plate) for an ALREADY
       onboarded driver no longer write directly to ride_drivers - they
       go into driver_vehicle_change_requests as a pending request an
       admin must approve first (see migration 031). Nothing map-facing
       or ride-facing changes until that happens; a driver can no longer
       silently swap what vehicle they claim to be driving mid-shift.
       A driver's FIRST-ever vehicle declaration (before onboarded=1 is
       ever set) still writes immediately below - there's no "change" to
       gate when nothing has been declared yet, and requiring approval
       before a driver can even complete registration would strand them. */
    $vehicleFields = array('vehicle_type','vehicle_make','vehicle_color','vehicle_year','vehicle_plate');
    $onboardCheck = $pdo->prepare("SELECT onboarded, vehicle_type, vehicle_make, vehicle_color, vehicle_year, vehicle_plate FROM ride_drivers WHERE user_id=?");
    $onboardCheck->execute(array($u['id']));
    $onboardRow = $onboardCheck->fetch();
    $alreadyOnboardedForVehicleGate = $onboardRow && intval($onboardRow['onboarded']) === 1;
    $vehicleFieldsPresent = array();
    foreach ($vehicleFields as $vf) { if (isset($body[$vf])) $vehicleFieldsPresent[$vf] = trim($body[$vf]); }

    if ($alreadyOnboardedForVehicleGate && !empty($vehicleFieldsPresent)) {
        $pending = $pdo->prepare("SELECT id FROM driver_vehicle_change_requests WHERE driver_user_id=? AND status='pending' LIMIT 1");
        $pending->execute(array($u['id']));
        if ($pending->fetch()) fail('You already have a vehicle change request awaiting admin review.');

        $reqType  = isset($vehicleFieldsPresent['vehicle_type'])  ? strtolower($vehicleFieldsPresent['vehicle_type'])  : ($onboardRow['vehicle_type']  ?? '');
        $reqMake  = isset($vehicleFieldsPresent['vehicle_make'])  ? $vehicleFieldsPresent['vehicle_make']  : ($onboardRow['vehicle_make']  ?? '');
        $reqColor = isset($vehicleFieldsPresent['vehicle_color']) ? $vehicleFieldsPresent['vehicle_color'] : ($onboardRow['vehicle_color'] ?? '');
        $reqYear  = isset($vehicleFieldsPresent['vehicle_year'])  ? $vehicleFieldsPresent['vehicle_year']  : ($onboardRow['vehicle_year']  ?? '');
        $reqPlate = isset($vehicleFieldsPresent['vehicle_plate']) ? $vehicleFieldsPresent['vehicle_plate'] : ($onboardRow['vehicle_plate'] ?? '');
        if ($reqPlate === '') fail('Vehicle plate number is required.');

        $ins = $pdo->prepare("INSERT INTO driver_vehicle_change_requests
            (driver_user_id, requested_vehicle_type, requested_vehicle_make, requested_vehicle_color, requested_vehicle_year, requested_vehicle_plate)
            VALUES (?,?,?,?,?,?)");
        $ins->execute(array($u['id'], substr($reqType,0,50), substr($reqMake,0,100), substr($reqColor,0,50), substr($reqYear,0,10), substr($reqPlate,0,50)));

        // Remaining non-vehicle fields (license/selfie) still process normally below.
        foreach ($vehicleFields as $vf) unset($body[$vf]);
    }

    // Photo URLs must be files this driver really uploaded (see rides_owned_upload_url).
    // A URL identical to the one already stored is accepted as-is so an existing driver
    // re-saving their profile is never blocked by an older/moved file.
    $storedPhotos = array('selfie_url' => '', 'license_photo_url' => '');
    try {
        $sp = $pdo->prepare("SELECT selfie_url, license_photo_url FROM ride_drivers WHERE user_id=?");
        $sp->execute(array($u['id']));
        $spr = $sp->fetch();
        if ($spr) $storedPhotos = array('selfie_url' => (string)$spr['selfie_url'], 'license_photo_url' => (string)$spr['license_photo_url']);
    } catch (Exception $e) { /* columns missing: nothing stored to compare */ }
    if (isset($body['selfie_url']) && trim($body['selfie_url']) !== '' && trim($body['selfie_url']) !== $storedPhotos['selfie_url'] && !rides_owned_upload_url($body['selfie_url'], $u['id'], 'driver_selfies', 'selfie_')) {
        fail('Please upload your selfie photo again.', 400);
    }
    if (isset($body['license_photo_url']) && trim($body['license_photo_url']) !== '' && trim($body['license_photo_url']) !== $storedPhotos['license_photo_url'] && !rides_owned_upload_url($body['license_photo_url'], $u['id'], 'driver_licenses', 'license_')) {
        fail('Please upload your license photo again.', 400);
    }

    $allowed = array('vehicle_type','vehicle_make','vehicle_color','vehicle_year',
                     'vehicle_plate','license_number','license_name','license_dob',
                     'selfie_url','license_photo_url');
    $sets = array(); $vals = array();
    foreach ($allowed as $f) {
        if (isset($body[$f])) {
            // Photo URLs can legitimately be longer than the 100-char cap
            // used for text fields below - give them their own, more
            // generous limit instead of silently truncating a valid URL.
            $maxLen = ($f === 'selfie_url' || $f === 'license_photo_url') ? 500 : 100;
            $fieldVal = trim($body[$f]);
            /* CASE-DRIFT FIX: vehicle_type must be stored lowercase, matching
               ride_fare_rules/rides.vehicle_type canonical values ('bajaj',
               'moto', 'car', 'minibus', 'qute'). Previously this was written
               verbatim - fine when the frontend's normal path sent lowercase
               values, but driver.js's own offline/network-failure fallback
               list (loadVehicleTypeOptions's catch handler) uses capitalized
               display strings ('Bajaj', 'Motorcycle', ...) and stored those
               directly if a driver registered while that fallback fired.
               Every matching query elsewhere (driver_get_nearby_requests,
               sse_driver_stream, the push-notification lookup, and this
               action's own availability count) compares against the
               lowercase canonical form, so a driver stuck with a capitalized
               value in the DB would silently stop appearing in any of them -
               no ringing, no push, wrong "drivers online" badge on the
               booking screen - with no error anywhere to point at why. */
            if ($f === 'vehicle_type') $fieldVal = strtolower($fieldVal);
            $sets[] = "$f=?"; $vals[] = substr($fieldVal, 0, $maxLen);
        }
    }
    if (empty($sets)) {
        if ($alreadyOnboardedForVehicleGate && !empty($vehicleFieldsPresent)) {
            ok(array('updated' => false, 'vehicle_change_pending' => true,
                'message' => 'Your vehicle change was submitted and is awaiting admin approval. Your current vehicle stays active until then.'));
        }
        fail('No valid fields provided');
    }
    $check = $pdo->prepare("SELECT vehicle_type, vehicle_plate, license_name, license_dob, selfie_url, license_photo_url, onboarded FROM ride_drivers WHERE user_id=?");
    $check->execute(array($u['id']));
    $cur = $check->fetch();
    $newType  = isset($body['vehicle_type'])  ? trim($body['vehicle_type'])  : ($cur ? ($cur['vehicle_type']  ?? '') : '');
    $newPlate = isset($body['vehicle_plate']) ? trim($body['vehicle_plate']) : ($cur ? ($cur['vehicle_plate'] ?? '') : '');
    $newLicName  = isset($body['license_name'])       ? trim($body['license_name'])       : ($cur ? ($cur['license_name']       ?? '') : '');
    $newLicDob   = isset($body['license_dob'])        ? trim($body['license_dob'])        : ($cur ? ($cur['license_dob']        ?? '') : '');
    $newLicPhoto = isset($body['license_photo_url'])  ? trim($body['license_photo_url'])  : ($cur ? ($cur['license_photo_url']  ?? '') : '');
    $newSelfie   = isset($body['selfie_url'])         ? trim($body['selfie_url'])         : ($cur ? ($cur['selfie_url']         ?? '') : '');

    /* License photo is mandatory once a driver is submitting license
       details at all - a name/DOB claim with nothing to back it up
       defeats the point of the identity cross-check. */
    if (($newLicName || $newLicDob) && !$newLicPhoto) {
        fail('A photo of your driving license is required.');
    }
    /* Selfie is mandatory too, but only before a driver's *first*
       successful onboarding - once onboarded, later profile edits
       (e.g. updating a plate number) shouldn't force a re-upload. */
    $alreadyOnboarded = $cur && intval($cur['onboarded']) === 1;
    if (!$alreadyOnboarded && !$newSelfie) {
        fail('A selfie photo is required to verify your identity.');
    }

    /* Cross-check the license holder's name/DOB (as the driver typed it)
       against their actual EtelID record via the existing service bridge
       (see api/etelid_bridge.php - same bridge used for etelid_login and
       street-hail QR resolution). Only runs once both fields are present;
       fails to 'unverified' (not blocking) if EtelID can't be reached, so
       a transient outage doesn't strand a driver mid-onboarding. */
    $identityMatch = 'unverified';
    if ($newLicName && $newLicDob) {
        require_once __DIR__ . '/../etelid_bridge.php';
        $kyc = etelid_bridge_service_call('kyc', array('identifier' => $u['phone'], 'type' => 'phone'));
        if (is_array($kyc) && !empty($kyc['member'])) {
            $m = $kyc['member'];
            $nameMatch = rides_names_roughly_match($newLicName, isset($m['full_name']) ? $m['full_name'] : '');
            $dobMatch  = !empty($m['dob']) && $m['dob'] === $newLicDob;
            $identityMatch = ($nameMatch && $dobMatch) ? 'matched' : 'mismatch';
        } else {
            error_log('[ETEL][ride] KYC cross-check unreachable for driver uid=' . $u['id']);
        }
        $sets[] = 'identity_match=?'; $vals[] = $identityMatch;
    }

    /* Only auto-onboard once vehicle info AND both identity photos are
       set, AND (no license claim submitted yet, so nothing to gate on,
       OR the license claim matched EtelID). A mismatch or
       unreachable-EtelID result leaves onboarded=0 so the driver shows
       up as pending in admin_get_drivers rather than being silently
       blocked with an error. */
    // No license name/DOB claim means nothing was cross-checked against EtelID, so the
    // driver stays pending for admin approval (admin_approve_driver_identity) instead
    // of being auto-onboarded with no verification at all.
    $effectiveMatch = ($newLicName && $newLicDob) ? $identityMatch : 'unverified';
    $justOnboarded = false;
    if ($newType && $newPlate && $newSelfie && $newLicPhoto && $effectiveMatch === 'matched') { $sets[] = 'onboarded=1'; $justOnboarded = true; }

    $vals[] = $u['id'];
    $pdo->prepare("UPDATE ride_drivers SET " . implode(',', $sets) . " WHERE user_id=?")->execute($vals);

    // The driver registration wizard (and the whole rest of the driver
    // app) is reachable by ANY authenticated user - rides_driver_profile()
    // auto-creates a ride_drivers row for whoever hits it, with no role
    // check at all. Nothing anywhere ever promoted users.role to 'driver'
    // once someone actually finished onboarding, which is why a customer
    // account could complete the entire registration flow and still get
    // redirected to the wrong app on next login, plus a permanent (if
    // toast-only, non-blocking) "Driver account required" warning inside
    // the driver app itself. Promote on first successful clean onboarding.
    if ($justOnboarded) {
        $pdo->prepare("UPDATE users SET role='driver' WHERE id=?")->execute(array($u['id']));
    }

    $resp = array('updated' => true);
    if ($newLicName && $newLicDob) {
        $resp['identity_match'] = $identityMatch;
        if ($identityMatch === 'mismatch') {
            $resp['message'] = 'Your license name/date of birth does not match your EtelID record. Your account has been flagged for admin review before you can go online.';
        } elseif ($identityMatch === 'unverified') {
            $resp['message'] = 'Could not verify against EtelID right now - your details were saved and will be checked automatically once reachable.';
        }
    }
    ok($resp);
    break;
}

case 'driver_get_pending_vehicle_change': {
    $p = $pdo->prepare("SELECT id, requested_vehicle_type, requested_vehicle_make, requested_vehicle_color,
        requested_vehicle_year, requested_vehicle_plate, status, reject_reason, created_at, reviewed_at
        FROM driver_vehicle_change_requests WHERE driver_user_id=? ORDER BY id DESC LIMIT 1");
    $p->execute(array($u['id']));
    $row = $p->fetch();
    ok(array('request' => $row ?: null));
    break;
}

case 'admin_get_pending_vehicle_changes': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $s = $pdo->query("SELECT r.id, r.driver_user_id, r.requested_vehicle_type, r.requested_vehicle_make,
        r.requested_vehicle_color, r.requested_vehicle_year, r.requested_vehicle_plate, r.created_at,
        u.name AS driver_name, u.phone AS driver_phone,
        rd.vehicle_type AS current_vehicle_type, rd.vehicle_make AS current_vehicle_make,
        rd.vehicle_color AS current_vehicle_color, rd.vehicle_year AS current_vehicle_year,
        rd.vehicle_plate AS current_vehicle_plate
        FROM driver_vehicle_change_requests r
        JOIN users u ON u.id = r.driver_user_id
        JOIN ride_drivers rd ON rd.user_id = r.driver_user_id
        WHERE r.status='pending' ORDER BY r.created_at ASC");
    ok(array('requests' => $s->fetchAll()));
    break;
}

case 'admin_approve_vehicle_change': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id required');
    $r = $pdo->prepare("SELECT * FROM driver_vehicle_change_requests WHERE id=? AND status='pending'");
    $r->execute(array($rid));
    $row = $r->fetch();
    if (!$row) fail('Request not found or already reviewed', 404);

    // This is the ONLY place a driver's declared vehicle actually
    // changes in ride_drivers going forward - map markers, ride
    // previews, and the driver's own profile all read from here, so
    // approving here is what makes the change visible everywhere at once.
    $upd = $pdo->prepare("UPDATE ride_drivers SET vehicle_type=?, vehicle_make=?, vehicle_color=?, vehicle_year=?, vehicle_plate=? WHERE user_id=?");
    $upd->execute(array($row['requested_vehicle_type'], $row['requested_vehicle_make'], $row['requested_vehicle_color'],
        $row['requested_vehicle_year'], $row['requested_vehicle_plate'], $row['driver_user_id']));

    $pdo->prepare("UPDATE driver_vehicle_change_requests SET status='approved', reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=?")
        ->execute(array($u['id'], $rid));

    etel_push_send($pdo, intval($row['driver_user_id']), 'Vehicle Update Approved',
        'Your vehicle change was approved and is now active.',
        array('action' => 'vehicle_change_approved'));
    ok(array('approved' => true));
    break;
}

case 'admin_reject_vehicle_change': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    $reason = isset($body['reason']) ? substr(trim($body['reason']), 0, 255) : '';
    if (!$rid) fail('request_id required');
    $n = $pdo->prepare("UPDATE driver_vehicle_change_requests SET status='rejected', reject_reason=?, reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=? AND status='pending'");
    $n->execute(array($reason, $u['id'], $rid));
    if (!$n->rowCount()) fail('Request not found or already reviewed', 404);

    $r2 = $pdo->prepare("SELECT driver_user_id FROM driver_vehicle_change_requests WHERE id=?");
    $r2->execute(array($rid));
    $row2 = $r2->fetch();
    if ($row2) {
        etel_push_send($pdo, intval($row2['driver_user_id']), 'Vehicle Update Rejected',
            $reason !== '' ? $reason : 'Your vehicle change request was not approved.',
            array('action' => 'vehicle_change_rejected'));
    }
    ok(array('rejected' => true));
    break;
}

case 'driver_decline_ride': {
    /* The driver tapped Decline on a request card. Until now that only hid the card on
       their own phone, so the same request came back on the next refresh and kept ringing.
       Recording it in ride_driver_exclusions (the table the reassign path already uses)
       makes every list and push for this driver skip that ride from now on, while other
       drivers still see it. Only a ride that is still searching is recorded. */
    rides_require_onboarded($pdo, $u['id']);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id required');
    rides_ensure_exclusions_table($pdo);
    $pdo->prepare("INSERT IGNORE INTO ride_driver_exclusions (ride_id, driver_id)
                   SELECT id, ? FROM rides WHERE id=? AND status='searching' AND passenger_id != ?")
        ->execute(array($u['id'], $rid, $u['id']));
    ok(array('declined' => true));
    break;
}

case 'driver_accept_ride': {
    rides_require_onboarded($pdo, $u['id']);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id required');
    if (rides_get_active_for_driver($pdo, $u['id'])) fail('Finish your current ride first');
    rides_ensure_exclusions_table($pdo);
    rides_offer_price_ensure($pdo);
    $n = $pdo->prepare(
        "UPDATE rides SET driver_id=?, status='accepted', assigned_at=NOW(),
                agreed_fare=IF(rider_offer IS NOT NULL AND rider_offer > 0, fare_final, NULL)
         WHERE id=? AND status='searching'
           AND NOT EXISTS (SELECT 1 FROM ride_driver_exclusions x WHERE x.ride_id = rides.id AND x.driver_id = ?)");
    $n->execute(array($u['id'], $rid, $u['id']));
    if (!$n->rowCount()) fail('Ride was already taken or cancelled', 409);
    rides_driver_profile($pdo, $u['id']);
    $acceptedRide = rides_get_active_for_driver($pdo, $u['id']);
    if ($acceptedRide && isset($acceptedRide['passenger_id'])) {
        $dnStmt = $pdo->prepare('SELECT name FROM users WHERE id=? LIMIT 1');
        $dnStmt->execute(array($u['id']));
        $dnRow = $dnStmt->fetch();
        $dn = $dnRow ? $dnRow['name'] : 'Your driver';
        etel_push_send($pdo, intval($acceptedRide['passenger_id']), 'Ride Accepted',
            $dn . ' is on the way. Track on map.',
            array('action' => 'ride_accepted', 'ride_id' => $rid, 'url' => '/delivery/ride/'));
    }
    ok($acceptedRide);
    break;
}

case 'driver_hail_customer': {
    rides_require_onboarded($pdo, $u['id']);
    // A street hail is created in the customer's name without them tapping anything, so
    // cap how many one driver can start per hour (stops spraying phone numbers).
    rate_limit_check($pdo, 'hail:' . $u['id'], 12, 3600, 3600);
    rate_limit_fail($pdo, 'hail:' . $u['id']);
    $raw_id = isset($body['phone']) ? trim($body['phone']) : '';
    $mkm    = isset($body['km'])  ? floatval($body['km'])  : 0;
    $open   = (isset($body['open']) && intval($body['open']) === 1) || $mkm <= 0;
    $lat    = isset($body['lat']) ? floatval($body['lat']) : null;
    $lng    = isset($body['lng']) ? floatval($body['lng']) : null;
    if ($raw_id === '') fail('Enter the customer phone number or scan their QR');
    if ($mkm > 100) $mkm = 100;

    $etelid_member = null;
    $digits_probe  = preg_replace('/[^0-9]/', '', $raw_id);
    $looks_etelid  = (stripos($raw_id, '/verify/') !== false)
                  || preg_match('/^[A-Za-z]{1,4}-/', trim($raw_id))
                  || strlen($digits_probe) >= 12;
    if ($looks_etelid) {
        $etelid_member = rides_etelid_lookup($raw_id);
        if ($etelid_member) {
            if (isset($etelid_member['status']) && $etelid_member['status'] !== 'active') {
                fail('EtelID card for ' . $etelid_member['full_name'] . ' is not active (' . $etelid_member['status'] . ')');
            }
            $raw_id = $etelid_member['phone'];
            if (!$raw_id) fail('EtelID member ' . $etelid_member['full_name'] . ' has no phone on file');
        }
    }

    $digits = preg_replace('/[^0-9]/', '', $raw_id);
    if (strlen($digits) < 9) fail('Could not read a valid phone number or EtelID');
    $last9 = substr($digits, -9);

    $s = $pdo->prepare(
        "SELECT id, name, phone FROM users
         WHERE REPLACE(REPLACE(phone,'+',''),' ','') = ?
            OR RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''), 9) = ?
         LIMIT 1");
    $s->execute(array($digits, $last9));
    $cust = $s->fetch();
    if (!$cust) {
        if ($etelid_member) {
            fail('EtelID verified: ' . $etelid_member['full_name'] . ' (' . $etelid_member['etel_id'] . '), but no ETEL Delivery account uses phone ' . $raw_id . '. Ask them to register once with that number.', 404);
        }
        fail('No ETEL account found for that number. Ask the customer to register first.', 404);
    }
    if (intval($cust['id']) === intval($u['id'])) fail('You cannot hail yourself');

    $mine = rides_get_active_for_driver($pdo, $u['id']);
    if ($mine) fail('You have an active ride #' . $mine['id'] . ' (' . $mine['status'] . ') with ' . $mine['passenger_name'] . '. Complete or cancel it first.');
    $s2 = $pdo->prepare("SELECT id, status FROM rides WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived') LIMIT 1");
    $s2->execute(array($cust['id']));
    $theirs = $s2->fetch();
    if ($theirs) {
        // A hail must never cancel a booking the customer made themselves (it used to cancel a
        // 'searching' one) - the customer cancels it, then the driver hails again.
        fail($cust['name'] . ' already has a ride #' . $theirs['id'] . ' (' . $theirs['status'] . '). Ask them to cancel it first.');
    }

    $driverProfile = rides_driver_profile($pdo, $u['id']);
    // The driver's own registered vehicle (car, moto, bajaj...) - a
    // Street Hail ride previously never stored ANY vehicle_type on the
    // rides row at all, so every later query that reads rides.vehicle_type
    // directly (driver_update_location's live meter, most notably) fell
    // back to 'bajaj' regardless of what the driver actually drives.
    // ride_status/my_active_ride happened to look correct anyway only
    // because their SQL JOINs ride_drivers and its same-named vehicle_type
    // column overwrites the (empty) one from rides in the fetched row -
    // papering over the gap for the rider's screen while the driver's own
    // live meter kept quoting bajaj rates for a car. Storing it here, and
    // using it for the initial estimate below, fixes both at the source.
    $driverVtype = (!empty($driverProfile['vehicle_type'])) ? $driverProfile['vehicle_type'] : 'bajaj';
    $fare  = $open ? 0 : rides_calc_fare_simple($pdo, $mkm, $driverVtype);
    $daddr = $open ? 'Open trip - customer directs the driver (GPS meter)'
                   : 'By distance: ' . $mkm . ' km (street hail)';
    if ($lat !== null && $lng !== null) {
        list($hexQ, $hexR) = hexgrid_cell_for($lat, $lng);
        $pdo->prepare("UPDATE ride_drivers SET lat=?, lng=?, last_seen=NOW(), hex_q=?, hex_r=? WHERE user_id=?")
            ->execute(array($lat, $lng, $hexQ, $hexR, $u['id']));
    }
    $extraCols = array(); $extraVals = array();
    try {
        $have = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            $have[strtolower($c['Field'])] = true;
        }
        foreach (array('ride_code', 'request_code', 'code', 'tracking_code', 'order_code') as $cc) {
            if (isset($have[$cc])) {
                $extraCols[] = "`$cc`";
                $extraVals[] = 'R' . date('ymdHis') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5));
            }
        }
        if (isset($have['vehicle_type'])) {
            $extraCols[] = '`vehicle_type`';
            $extraVals[] = $driverVtype;
        }
    } catch (Exception $e) { /* non-fatal */ }

    $cols = "passenger_id, driver_id, pickup_lat, pickup_lng,
             pickup_address, dropoff_address, distance_km,
             fare_estimate, fare_final, payment_method,
             status, open_trip, assigned_at, created_at";
    $ph   = "?,?,?,?, 'Street hail', ?, ?, ?, ?, 'cash', 'accepted', ?, NOW(), NOW()";
    $vals = array($cust['id'], $u['id'], $lat, $lng, $daddr,
                  $open ? 0 : $mkm, $fare, $fare, $open ? 1 : 0);
    if ($extraCols) {
        $cols .= ', ' . implode(', ', $extraCols);
        $ph   .= str_repeat(', ?', count($extraCols));
        $vals  = array_merge($vals, $extraVals);
    }
    $pdo->prepare("INSERT INTO rides ($cols) VALUES ($ph)")->execute($vals);
    // Tell the customer so a hail they did not agree to is visible straight away and can be cancelled.
    if (function_exists('etel_push_send')) {
        try {
            etel_push_send($pdo, intval($cust['id']), 'Street hail started',
                'A driver started a trip with you. If this is not you, open ETEL Ride and cancel it now.',
                array('action' => 'ride_street_hail', 'url' => '/delivery/ride/'));
        } catch (Exception $e) { /* best-effort */ }
    }
    ok(rides_get_active_for_driver($pdo, $u['id']));
    break;
}

case 'driver_start_ride': {
    rides_require_onboarded($pdo, $u['id']);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    require_once __DIR__ . '/../lib/ride_pin.php';
    etel_ride_pin_gate($pdo, $rid, $u['id'], isset($body['pin']) ? $body['pin'] : '');
    $pos = null;
    try {
        $q = $pdo->prepare("SELECT lat, lng FROM ride_drivers WHERE user_id=?");
        $q->execute(array($u['id']));
        $pos = $q->fetch();
    } catch (Exception $e) {}
    $n = $pdo->prepare(
        "UPDATE rides SET status='in_progress', started_at=NOW(),
                traveled_km=0, last_lat=?, last_lng=?, last_location_at=?
         WHERE id=? AND driver_id=? AND status='accepted'");
    $n->execute(array($pos ? $pos['lat'] : null, $pos ? $pos['lng'] : null, $pos ? date('Y-m-d H:i:s') : null, $rid, $u['id']));
    if (!$n->rowCount()) fail('Ride not in accepted state');
    ok(array('started' => true));
    break;
}

// ── Waiting fee: driver starts/stops the clock while stopped for the
// customer (waiting outside a shop, at a pickup that's running late,
// etc). Toggled per ride, only while in_progress. Accumulates real
// elapsed seconds server-side (not trusted from the client) each time
// it's stopped, so the total survives even if the driver's connection
// drops mid-wait - same "resume from server truth" pattern as the GPS
// meter itself. ──
case 'driver_toggle_wait': {
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    if (!$rid) fail('ride_id is required');
    rides_ensure_wait_columns($pdo);
    $s = $pdo->prepare("SELECT id, wait_active, wait_started_at, wait_seconds_total, traveled_km, open_trip, vehicle_type
                        FROM rides WHERE id=? AND driver_id=? AND status='in_progress' LIMIT 1");
    $s->execute(array($rid, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Ride not found or not in progress', 404);

    if (intval($r['wait_active']) === 1) {
        // Stopping: fold the elapsed seconds into the running total.
        $elapsed = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
        $newTotal = intval($r['wait_seconds_total']) + $elapsed;
        $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=?")
            ->execute(array($newTotal, $rid));
        $r['wait_active'] = 0; $r['wait_seconds_total'] = $newTotal; $r['wait_started_at'] = null;
    } else {
        // Starting: stamp now as the beginning of this wait window.
        // Bound as a PHP-generated value (not SQL's NOW()) - every read
        // of this column compares it against PHP's time()/strtotime(),
        // and mixing MySQL's own clock with PHP's for the same
        // before/after pair breaks the moment the two aren't in the same
        // timezone (this host's MySQL session time_zone doesn't have to
        // match date_default_timezone_set('Africa/Addis_Ababa') in
        // config.php - and evidently doesn't). That mismatch was making
        // a wait that had just started read back as ~2 hours old on the
        // very next poll, before the driver had waited any real time at
        // all. Using PHP's own clock for both the write and every
        // subsequent read removes the dependency on MySQL's timezone
        // setting entirely.
        $nowPhp = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE rides SET wait_active=1, wait_started_at=? WHERE id=?")
            ->execute(array($nowPhp, $rid));
        $r['wait_active'] = 1; $r['wait_started_at'] = $nowPhp;
    }

    $vtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $u['id']);
    $waitMin = rides_current_wait_minutes($r);
    $fareCalc = rides_calc_fare($pdo, floatval($r['traveled_km']), $vtype, intval($r['open_trip']) === 1, $waitMin);
    ok(array(
        'wait_active'         => (bool) $r['wait_active'],
        'wait_seconds_total'  => intval($r['wait_seconds_total']),
        'wait_minutes'        => round($waitMin, 2),
        'wait_etb'            => $fareCalc['breakdown']['wait_etb'],
        'meter_fare'          => $fareCalc['fare'],
    ));
    break;
}

case 'driver_arrived': {
    rides_require_onboarded($pdo, $u['id']);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $vtypeCol = rides_has_vehicle_type_column($pdo) ? ', vehicle_type' : '';
    rides_ensure_wait_columns($pdo);
    $s = $pdo->prepare("SELECT id, open_trip, traveled_km, distance_km, fare_final, pickup_lat, pickup_lng, wait_active, wait_started_at, wait_seconds_total" . $vtypeCol . "
                        FROM rides WHERE id=? AND driver_id=? AND status='in_progress'");
    $s->execute(array($rid, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Active ride not found');
    // The vehicle_type actually booked (Bajaj/Moto/Car) - falls back to
    // bajaj only for rides placed before migration 025 added this column.
    $vtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $u['id']);
    // Auto-close a wait clock the driver forgot to stop - the customer
    // shouldn't be billed for waiting time that's still silently ticking
    // just because the driver went straight to "Arrived" instead of
    // tapping Stop Waiting first.
    if (intval($r['wait_active']) === 1) {
        $elapsed = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
        $r['wait_seconds_total'] = intval($r['wait_seconds_total']) + $elapsed;
        $r['wait_active'] = 0;
        $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=?")
            ->execute(array($r['wait_seconds_total'], $rid));
    }
    $waitMinutes = rides_current_wait_minutes($r);

    // Real GPS-tracked distance (accumulated in driver_update_location on
    // every ping since the ride started) now decides the final fare for
    // EVERY ride, not just "open trip" (no-destination) ones. Previously
    // a normal address-to-address ride was billed at whatever straight-line
    // estimate was shown at booking time, no matter how the trip actually
    // went - if the passenger asked to be picked up or dropped off a block
    // over, or the driver had to detour, the customer was still charged the
    // pre-typed-address guess. Now the meter that already runs in the
    // background is what actually gets billed; the pre-booking number is
    // clearly an estimate only (see ride/index.html's fare-card wording).
    $isOpenTrip = intval($r['open_trip']) === 1;
    $traveled = round(floatval($r['traveled_km']), 2);
    // Meter-shortfall floor: a meter that read far below the straight-line
    // trip (weak/network-only GPS, app suspended, stalled pings) is billed
    // at the floor distance and flagged for review rather than at ~0 km.
    $shortfall = rides_meter_shortfall_floor($pdo, $r, $u['id'], $traveled);
    if ($shortfall['applied']) $traveled = $shortfall['km'];
    $finalSurge = null; // null = don't touch the existing stored value (fallback branch)
    $waitEtb = 0;
    $breakdownForUi = null;
    if ($traveled > 0.1) {
        $km = $traveled;
        $fareResult = rides_calc_fare($pdo, $km, $vtype, $isOpenTrip, $waitMinutes);
        $fare = $fareResult['fare'];
        $finalSurge = $fareResult['surge'] ? 1 : 0;
        $waitEtb = $fareResult['breakdown']['wait_etb'];
        $breakdownForUi = rides_fare_breakdown_for_ui($fareResult);
        if ($shortfall['applied']) {
            $breakdownForUi['meter_shortfall'] = true;
            $breakdownForUi['meter_km'] = $shortfall['meter_km'];
            $breakdownForUi['meter_floor_basis'] = $shortfall['basis'];
            rides_log_meter_shortfall($pdo, $rid, $u['id'], $shortfall);
        }
    } else {
        // Safety net only: GPS never reported anything usable (app was
        // killed mid-trip, location permission revoked, or a trip so short
        // it never cleared the 5m jitter floor) - fall back to the
        // booking-time estimate rather than billing ETB 0. Waiting time is
        // still real even if distance tracking failed, so it's still added.
        $km = floatval($r['distance_km']);
        $baseFare = floatval($r['fare_final']) > 0 ? floatval($r['fare_final']) : rides_calc_fare_simple($pdo, $km);
        $waitRule = rides_fare_rule($pdo, $vtype);
        $waitCapMin = rides_wait_cap_minutes($pdo);
        $billableWait = ($waitCapMin > 0) ? min($waitMinutes, $waitCapMin) : $waitMinutes;
        $waitEtb = round($waitRule['wait_rate_per_min'] * $billableWait, 2);
        $fare = $baseFare + $waitEtb;
        // No live surge/base/distance split available in this fallback
        // path (booking-time estimate already has surge baked in, if
        // any) - still surface what we do know rather than nothing.
        $breakdownForUi = array(
            'fare_base' => $baseFare, 'fare_distance' => 0, 'fare_wait' => $waitEtb,
            'fare_subtotal' => round($baseFare + $waitEtb, 2), 'fare_surge_mult' => 1.0,
            'fare_surge_etb' => 0, 'fare_floor_topup' => 0, 'fare_total' => $fare,
        );
    }
    // Persist the exact breakdown used to bill this ride, rather than
    // letting the customer's screen recompute it later - surge windows
    // are time-based, so a recompute done even a few minutes after
    // arrival could land in a different peak/off-peak window than what
    // was actually charged, silently showing a different number than the
    // real bill. This is what lets the customer's "Trip Complete" screen
    // show the same full itemized breakdown the driver's screen already
    // shows, not just a single opaque total.
    $breakdownJson = json_encode($breakdownForUi);
    if ($finalSurge !== null && rides_has_surge_column($pdo)) {
        $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), distance_km=?, fare_final=?, surge_applied=?, fare_breakdown_json=? WHERE id=?")
            ->execute(array($km, $fare, $finalSurge, $breakdownJson, $rid));
    } else {
        $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), distance_km=?, fare_final=?, fare_breakdown_json=? WHERE id=?")
            ->execute(array($km, $fare, $breakdownJson, $rid));
    }
    $fare = rides_agreed_apply($pdo, $rid, $fare);   // a trip with an agreed price is billed at exactly that price
    rides_flag_fare_review($pdo, $rid, $u['id'], $fare, $km);
    $fare = rides_promo_apply_to_arrived($pdo, $rid);
    ok(array_merge(array('arrived' => true, 'distance_km' => $km, 'fare' => $fare,
             'surge' => (bool)($finalSurge !== null ? $finalSurge : !empty($r['surge_applied'])),
             'wait_minutes' => round($waitMinutes, 2), 'wait_etb' => $waitEtb,
             'message' => 'Waiting for customer confirmation'), $breakdownForUi, rides_promo_info($pdo, $rid)));
    break;
}

case 'customer_confirm_arrival': {
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $pm  = isset($body['payment_method']) ? substr(preg_replace('/[^a-z_]/', '', strtolower($body['payment_method'])), 0, 20) : 'cash';
    if ($pm === 'wallet' && !rides_wallet_enabled($pdo)) fail(RIDES_WALLET_SOON_MSG, 400);
    $s = $pdo->prepare("SELECT id, driver_id, fare_final, distance_km FROM rides
                        WHERE id=? AND passenger_id=? AND status='arrived'");
    $s->execute(array($rid, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('No arrived ride waiting for your confirmation', 404);
    $fare = floatval($r['fare_final']);
    // A trip with no fare must never complete as "paid ETB 0" - leave it for support.
    if ($fare <= 0) fail('This trip has no fare yet. Please contact support so it can be corrected.', 409);
    $wallet_tx_ref = null;
    // Chapa rides settle through chapa_init/chapa_status (chapa_bridge.php),
    // never on the client's say-so here.
    if ($pm === 'chapa') fail('Pay with Chapa through the Chapa checkout (chapa_init), then the ride completes automatically.', 400);
    if ($pm === 'wallet') {
        /* The rider already transferred the fare to Etel Delivery's Etel
           Pay merchant account client-side (with their own PIN). We only
           trust that after independently verifying it server-to-server --
           never take the client's word for "I paid" alone. */
        $tx_ref = isset($body['wallet_tx_ref']) ? trim($body['wallet_tx_ref']) : '';
        if (!$tx_ref) fail('wallet_tx_ref required for wallet payment');

        require_once __DIR__ . '/../etel_pay_bridge.php';

        // Replay protection -- a tx_ref can only ever settle one ride.
        try { $pdo->exec("ALTER TABLE rides ADD COLUMN wallet_tx_ref VARCHAR(40) NULL"); } catch (Exception $e) {}
        $dupe = $pdo->prepare("SELECT id FROM rides WHERE wallet_tx_ref = ? LIMIT 1");
        $dupe->execute(array($tx_ref));
        if ($dupe->fetch()) fail('This payment has already been used for another ride', 409);

        $tx = etel_pay_verify_tx($tx_ref, $u['phone'], $fare);
        if (!$tx) fail('Could not verify Etel Pay payment. If money left your account, contact support with reference ' . $tx_ref, 402);
        if (!etel_pay_claim_tx($pdo, $tx_ref, 'ride')) fail('This payment has already been used', 409);
        $wallet_tx_ref = $tx_ref;
    }
    /* PAYOUT: only wallet-paid rides credit total_earnings (the driver's
       cashout-eligible balance) - a cash ride was already paid to the
       driver directly in person, so crediting the same fare here again
       let a driver cash out money they'd already physically collected.
       COMMISSION MODEL: drivers prepay into their own ኢE money balance
       (etel_money_balance) - a running balance THEY hold with the
       platform, topped up via EtelPay or an admin manual top-up (see
       driver_topup_etel_money / admin_topup_etel_money). On every
       completed ride, cash or wallet alike, 5% of the fare is deducted
       from that balance - see rides_complete_and_pay() (lib/ride_fare.php)
       for the full mechanism, shared with admin_manual_complete_ride
       below (a support-desk trip closeout goes through the exact same
       payout math, not a separate copy that could drift). */
    rides_complete_and_pay($pdo, $rid, $r['driver_id'], $fare, $pm, $wallet_tx_ref);
    etel_maybe_pay_referral_reward($pdo, $u['id']);
    ok(array('completed' => true, 'fare_paid' => $fare,
             'distance_km' => floatval($r['distance_km']), 'payment_method' => $pm));
    break;
}

case 'driver_complete_ride': {
    // Previously this completed the ride and credited driver earnings
    // immediately, with no passenger confirmation at all - unlike
    // driver_arrived (metered/open-trip rides), delivery orders, and
    // service-giver pickups, all of which require the other side to
    // confirm before anything is marked paid/complete. Fixed-fare
    // (non-open-trip) rides were the one path that skipped this.
    // Now this behaves identically to driver_arrived: it marks the
    // ride 'arrived' (awaiting passenger confirmation) rather than
    // 'completed', and customer_confirm_arrival is what actually
    // completes the ride and credits earnings. Kept as a separate
    // action name (rather than removed) so any already-cached driver
    // client still calling driver_complete_ride during a rollout gets
    // routed into the confirmation flow instead of bypassing it.
    rides_require_onboarded($pdo, $u['id']);
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $vtypeCol2 = rides_has_vehicle_type_column($pdo) ? ', vehicle_type' : '';
    $s = $pdo->prepare("SELECT id, open_trip, traveled_km, distance_km, fare_final, pickup_lat, pickup_lng" . $vtypeCol2 . "
                        FROM rides WHERE id=? AND driver_id=? AND status='in_progress'");
    $s->execute(array($rid, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Active ride not found');
    $vtype2 = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $u['id']);
    $isOpenTrip2 = intval($r['open_trip']) === 1;
    $traveled2 = round(floatval($r['traveled_km']), 2);
    $shortfall2 = rides_meter_shortfall_floor($pdo, $r, $u['id'], $traveled2);
    if ($shortfall2['applied']) $traveled2 = $shortfall2['km'];
    $finalSurge2 = null;
    $breakdownForUi2 = null;
    if ($traveled2 > 0.1) {
        $km   = $traveled2;
        $fareCalc = rides_calc_fare($pdo, $km, $vtype2, $isOpenTrip2);
        $fare = $fareCalc['fare'];
        $finalSurge2 = $fareCalc['surge'] ? 1 : 0;
        $breakdownForUi2 = rides_fare_breakdown_for_ui($fareCalc);
        if ($shortfall2['applied']) {
            $breakdownForUi2['meter_shortfall'] = true;
            $breakdownForUi2['meter_km'] = $shortfall2['meter_km'];
            $breakdownForUi2['meter_floor_basis'] = $shortfall2['basis'];
            rides_log_meter_shortfall($pdo, $rid, $u['id'], $shortfall2);
        }
    } else {
        $km   = floatval($r['distance_km']);
        $fare = floatval($r['fare_final']) > 0 ? floatval($r['fare_final']) : rides_calc_fare_simple($pdo, $km);
        $breakdownForUi2 = array(
            'fare_base' => $fare, 'fare_distance' => 0, 'fare_wait' => 0,
            'fare_subtotal' => $fare, 'fare_surge_mult' => 1.0,
            'fare_surge_etb' => 0, 'fare_floor_topup' => 0, 'fare_total' => $fare,
        );
    }
    $breakdownJson2 = json_encode($breakdownForUi2);
    if ($finalSurge2 !== null && rides_has_surge_column($pdo)) {
        $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), distance_km=?, fare_final=?, surge_applied=?, fare_breakdown_json=? WHERE id=?")
            ->execute(array($km, $fare, $finalSurge2, $breakdownJson2, $rid));
    } else {
        $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), distance_km=?, fare_final=?, fare_breakdown_json=? WHERE id=?")
            ->execute(array($km, $fare, $breakdownJson2, $rid));
    }
    $fare = rides_agreed_apply($pdo, $rid, $fare);
    rides_flag_fare_review($pdo, $rid, $u['id'], $fare, $km);
    $fare = rides_promo_apply_to_arrived($pdo, $rid);
    ok(array_merge(array('arrived' => true, 'distance_km' => $km, 'fare' => $fare,
             'surge' => (bool)($finalSurge2 !== null ? $finalSurge2 : !empty($r['surge_applied'])),
             'message' => 'Waiting for customer confirmation'), rides_promo_info($pdo, $rid)));
    break;
}

case 'driver_cancel_ride': {
    $rid = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $s = $pdo->prepare("SELECT id, status, passenger_id, pickup_address FROM rides
                        WHERE id=? AND driver_id=? AND status IN ('accepted','in_progress')");
    $s->execute(array($rid, $u['id']));
    $r = $s->fetch();
    if (!$r) fail('Active ride not found');
    /* Used to put EVERY cancelled ride back to 'searching' for new drivers.
       Wrong in two cases, both seen live:
       - a street hail (the driver created it for a rider standing next to
         them): re-dispatching it showed the rider "Finding your driver..."
         and rang the very driver who had just cancelled it;
       - a trip already in progress (passenger on board): there is nothing to
         re-dispatch mid-trip.
       Both now end as 'cancelled', which the rider page already handles.
       Only a normal app-booked ride cancelled before pickup goes back to
       'searching', and the cancelling driver is excluded from seeing it. */
    $isStreetHail = ($r['pickup_address'] === 'Street hail');
    if ($isStreetHail || $r['status'] === 'in_progress') {
        $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=? AND driver_id=? AND status IN ('accepted','in_progress')")
            ->execute(array($rid, $u['id']));
        try {
            etel_push_send($pdo, intval($r['passenger_id']), 'Ride cancelled',
                'Your driver cancelled this ride.',
                array('action' => 'ride_cancelled', 'ride_id' => $rid, 'url' => '/delivery/ride/'));
        } catch (Exception $e) { /* push is best-effort */ }
        ok(array('cancelled' => true, 'redispatched' => false));
        break;
    }
    rides_ensure_exclusions_table($pdo);
    $pdo->prepare("INSERT IGNORE INTO ride_driver_exclusions (ride_id, driver_id) VALUES (?, ?)")
        ->execute(array($rid, $u['id']));
    $n = $pdo->prepare(
        "UPDATE rides SET status='searching', driver_id=NULL, assigned_at=NULL,
                expires_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE)
         WHERE id=? AND driver_id=? AND status='accepted'");
    $n->execute(array($rid, $u['id']));
    if (!$n->rowCount()) fail('Active ride not found');
    ok(array('cancelled' => true, 'redispatched' => true));
    break;
}

case 'passenger_get_ride_history': {
    $s = $pdo->prepare(
        "SELECT r.id, r.pickup_address, r.dropoff_address, r.distance_km,
                r.fare_paid, r.fare_final, r.status, r.rating,
                r.driver_rating, r.created_at, r.assigned_at, r.started_at, r.completed_at,
                r.payment_method, r.open_trip, r.traveled_km, r.driver_id, r.passenger_id,
                r.fare_breakdown_json, r.pickup_lat, r.pickup_lng, r.dropoff_lat, r.dropoff_lng,
                p.name AS passenger_name, p.phone AS passenger_phone,
                d.name AS driver_name, d.phone AS driver_phone,
                rd.vehicle_plate, rd.vehicle_type
         FROM rides r
         LEFT JOIN users p ON p.id = r.passenger_id
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
         WHERE (r.driver_id=? OR r.passenger_id=?) AND r.status='completed'
         ORDER BY r.id DESC LIMIT 30");
    $s->execute(array($u['id'], $u['id']));
    $rows = $s->fetchAll();
    foreach ($rows as &$row) {
        $row['as_driver'] = (intval($row['driver_id']) === intval($u['id']));
        // Same persisted-breakdown pattern already used for the arrived
        // screen - the itemized numbers used to compute this ride's
        // actual bill, not a recompute that could disagree with what was
        // really charged if pricing or surge rules changed since then.
        if (!empty($row['fare_breakdown_json'])) {
            $decoded = json_decode($row['fare_breakdown_json'], true);
            if (is_array($decoded)) $row = array_merge($row, $decoded);
        }
    }
    unset($row);
    ok(array('rides' => $rows));
    break;
}

case 'send_ride_message': {
    $ride_id  = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    $msg_body = isset($body['body'])    ? trim($body['body'])      : '';
    if (!$ride_id || !$msg_body) fail('ride_id and body required');
    if (mb_strlen($msg_body) > 500) fail('Message too long (max 500 chars)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ride_messages` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `ride_id`     INT NOT NULL,
        `sender_id`   INT NOT NULL,
        `sender_role` ENUM('driver','passenger') NOT NULL DEFAULT 'passenger',
        `body`        TEXT NOT NULL,
        `sent_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ride` (`ride_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE ride_messages ADD COLUMN `read_at` DATETIME NULL DEFAULT NULL"); } catch (Exception $e) {}
    $rc = $pdo->prepare("SELECT id, driver_id, passenger_id, status FROM rides WHERE id=?");
    $rc->execute(array($ride_id));
    $ride = $rc->fetch();
    if (!$ride) fail('Ride not found', 404);
    $role = null;
    if (intval($ride['driver_id'])    === intval($u['id'])) $role = 'driver';
    if (intval($ride['passenger_id']) === intval($u['id'])) $role = 'passenger';
    if (!$role && $u['role'] !== 'admin') fail('Not a participant of this ride', 403);
    if (!$role) $role = 'driver';
    // Spam guard: at most 20 messages a minute per sender.
    try {
        $rl = $pdo->prepare("SELECT COUNT(*) FROM ride_messages WHERE sender_id=? AND sent_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $rl->execute(array($u['id']));
        if (intval($rl->fetchColumn()) >= 20) fail('You are sending messages too fast. Wait a moment.', 429);
    } catch (Exception $e) { /* never block chat on the guard */ }
    // Was anything from this sender still unread by the other side in the last 30 s? Then they
    // were already alerted, so don't ping again for every line of a burst.
    $alreadyAlerted = false;
    try {
        $aa = $pdo->prepare("SELECT 1 FROM ride_messages WHERE ride_id=? AND sender_id=? AND read_at IS NULL AND sent_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1");
        $aa->execute(array($ride_id, $u['id']));
        $alreadyAlerted = (bool) $aa->fetchColumn();
    } catch (Exception $e) {}
    $ins = $pdo->prepare("INSERT INTO ride_messages (ride_id, sender_id, sender_role, body) VALUES (?,?,?,?)");
    $ins->execute(array($ride_id, $u['id'], $role, $msg_body));
    $newMsgId = $pdo->lastInsertId();
    // Tell the other party (push) so a message is not missed while the chat is closed.
    if (!$alreadyAlerted && function_exists('etel_push_send')) {
        $otherId = ($role === 'driver') ? intval($ride['passenger_id']) : intval($ride['driver_id']);
        if ($otherId > 0 && $otherId !== intval($u['id'])) {
            try {
                $preview = mb_strlen($msg_body) > 80 ? mb_substr($msg_body, 0, 77) . '...' : $msg_body;
                etel_push_send($pdo, $otherId, ($role === 'driver' ? 'Message from your driver' : 'Message from your passenger'), $preview,
                    array('action' => 'ride_chat', 'ride_id' => intval($ride_id), 'url' => ($role === 'driver' ? '/delivery/ride/' : '/delivery/driver/')));
            } catch (Exception $e) { /* best-effort */ }
        }
    }
    ok(array('id' => $newMsgId, 'role' => $role, 'body' => $msg_body, 'sent_at' => date('Y-m-d H:i:s'), 'read_at' => null));
    break;
}

case 'ride_chat_unread': {
    /* Cheap poll for the badge on the Chat buttons: how many messages from the other
       side are still unread on this ride. */
    $ride_id = isset($_GET['ride_id']) ? intval($_GET['ride_id']) : (isset($body['ride_id']) ? intval($body['ride_id']) : 0);
    if (!$ride_id) fail('ride_id required');
    $rc = $pdo->prepare("SELECT driver_id, passenger_id FROM rides WHERE id=?");
    $rc->execute(array($ride_id));
    $ride = $rc->fetch();
    if (!$ride) fail('Ride not found', 404);
    if (intval($ride['driver_id']) !== intval($u['id']) && intval($ride['passenger_id']) !== intval($u['id'])) fail('Not a participant of this ride', 403);
    $unread = 0; $latest = 0; $preview = '';
    try {
        $q = $pdo->prepare("SELECT COUNT(*) AS n, MAX(id) AS last_id FROM ride_messages WHERE ride_id=? AND sender_id<>? AND read_at IS NULL");
        $q->execute(array($ride_id, $u['id']));
        $row = $q->fetch();
        $unread = intval($row['n']); $latest = intval($row['last_id']);
        if ($latest) {
            $pv = $pdo->prepare("SELECT body FROM ride_messages WHERE id=?");
            $pv->execute(array($latest));
            $b = (string) $pv->fetchColumn();
            $preview = mb_strlen($b) > 60 ? mb_substr($b, 0, 57) . '...' : $b;
        }
    } catch (Exception $e) { /* table not created yet = no messages */ }
    ok(array('unread' => $unread, 'latest_id' => $latest, 'preview' => $preview));
    break;
}

case 'ping_ride_typing': {
    /* Lightweight typing indicator. The client calls this (throttled to
       roughly once per 2s while the input has focus + text) and the other
       party's next get_ride_messages poll picks it up as "other_typing".
       A tiny upsert table, not a new persistent connection -- keeps this
       consistent with the rest of the app's polling model. */
    $ride_id = isset($body['ride_id']) ? intval($body['ride_id']) : 0;
    if (!$ride_id) fail('ride_id required');
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ride_typing` (
        `ride_id`    INT NOT NULL,
        `role`       ENUM('driver','passenger') NOT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`ride_id`,`role`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $rc = $pdo->prepare("SELECT driver_id, passenger_id FROM rides WHERE id=?");
    $rc->execute(array($ride_id));
    $ride = $rc->fetch();
    if (!$ride) fail('Ride not found', 404);
    $role = null;
    if (intval($ride['driver_id'])    === intval($u['id'])) $role = 'driver';
    if (intval($ride['passenger_id']) === intval($u['id'])) $role = 'passenger';
    if (!$role) fail('Not a participant of this ride', 403);
    $pdo->prepare("INSERT INTO ride_typing (ride_id, role, updated_at) VALUES (?,?,NOW())
                   ON DUPLICATE KEY UPDATE updated_at=NOW()")
        ->execute(array($ride_id, $role));
    ok(array('ok' => true));
    break;
}

case 'get_ride_messages': {
    $ride_id  = isset($_GET['ride_id'])  ? intval($_GET['ride_id'])  : (isset($body['ride_id'])  ? intval($body['ride_id'])  : 0);
    $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : (isset($body['since_id']) ? intval($body['since_id']) : 0);
    if (!$ride_id) fail('ride_id required');
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ride_messages` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `ride_id`     INT NOT NULL,
        `sender_id`   INT NOT NULL,
        `sender_role` ENUM('driver','passenger') NOT NULL DEFAULT 'passenger',
        `body`        TEXT NOT NULL,
        `sent_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ride` (`ride_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE ride_messages ADD COLUMN `read_at` DATETIME NULL DEFAULT NULL"); } catch (Exception $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ride_typing` (
        `ride_id`    INT NOT NULL,
        `role`       ENUM('driver','passenger') NOT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`ride_id`,`role`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $rc = $pdo->prepare("SELECT driver_id, passenger_id FROM rides WHERE id=?");
    $rc->execute(array($ride_id));
    $ride = $rc->fetch();
    if (!$ride) fail('Ride not found', 404);
    $my_role = null;
    if (intval($ride['driver_id'])    === intval($u['id'])) $my_role = 'driver';
    if (intval($ride['passenger_id']) === intval($u['id'])) $my_role = 'passenger';
    $is_party = ($my_role !== null);
    if (!$is_party && $u['role'] !== 'admin') fail('Not a participant', 403);

    /* Mark the other party's messages as read now that I'm fetching them. */
    if ($is_party) {
        $other_role = ($my_role === 'driver') ? 'passenger' : 'driver';
        $pdo->prepare("UPDATE ride_messages SET read_at=NOW()
                        WHERE ride_id=? AND sender_role=? AND read_at IS NULL")
            ->execute(array($ride_id, $other_role));
    }

    $sql = "SELECT m.id, m.sender_id, m.sender_role, m.body, m.sent_at, m.read_at, u.name AS sender_name
             FROM ride_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.ride_id = ?";
    $params2 = array($ride_id);
    if ($since_id > 0) { $sql .= " AND m.id > ?"; $params2[] = $since_id; }
    $sql .= " ORDER BY m.id ASC LIMIT 100";
    $sm = $pdo->prepare($sql);
    $sm->execute($params2);

    /* Is the other party actively typing right now (pinged in the last 4s)? */
    $other_typing = false;
    if ($is_party) {
        $other_role = ($my_role === 'driver') ? 'passenger' : 'driver';
        $tp = $pdo->prepare("SELECT 1 FROM ride_typing
                              WHERE ride_id=? AND role=? AND updated_at >= (NOW() - INTERVAL 4 SECOND)");
        $tp->execute(array($ride_id, $other_role));
        $other_typing = (bool) $tp->fetchColumn();
    }

    ok(array('messages' => $sm->fetchAll(), 'ride_id' => $ride_id, 'other_typing' => $other_typing));
    break;
}

// ── FAVORITE ROUTES (rider "Favorites") ─────────────────────────────
// Ride's dispatch model matches the nearest available driver rather
// than letting a passenger pick a specific favorite driver in advance,
// so "favorite driver" isn't meaningful here the way it is for a
// Delivery shop. Saved pickup+dropoff pairs (the trips someone takes
// often) is the practical equivalent - one tap to refill both fields
// instead of re-typing addresses every time.

case 'ride_get_favorite_routes': {
    try {
        $s = $pdo->prepare('SELECT id, label, pickup_address, pickup_lat, pickup_lng, dropoff_address, dropoff_lat, dropoff_lng, created_at FROM ride_favorite_routes WHERE user_id=? ORDER BY created_at DESC');
        $s->execute(array($u['id']));
        $rows = $s->fetchAll();
    } catch (Exception $e) { $rows = array(); }
    ok(array('routes' => $rows));
    break;
}

case 'ride_add_favorite_route': {
    // POST: label?, pickup_address, pickup_lat?, pickup_lng?, dropoff_address, dropoff_lat?, dropoff_lng?
    $label = isset($body['label']) ? trim($body['label']) : 'Saved Route';
    $p_addr = isset($body['pickup_address']) ? trim($body['pickup_address']) : '';
    $d_addr = isset($body['dropoff_address']) ? trim($body['dropoff_address']) : '';
    if (!$p_addr || !$d_addr) fail('Pickup and drop-off addresses are required');
    $p_lat = (isset($body['pickup_lat']) && $body['pickup_lat'] !== '') ? floatval($body['pickup_lat']) : null;
    $p_lng = (isset($body['pickup_lng']) && $body['pickup_lng'] !== '') ? floatval($body['pickup_lng']) : null;
    $d_lat = (isset($body['dropoff_lat']) && $body['dropoff_lat'] !== '') ? floatval($body['dropoff_lat']) : null;
    $d_lng = (isset($body['dropoff_lng']) && $body['dropoff_lng'] !== '') ? floatval($body['dropoff_lng']) : null;
    try {
        $ins = $pdo->prepare('INSERT INTO ride_favorite_routes (user_id,label,pickup_address,pickup_lat,pickup_lng,dropoff_address,dropoff_lat,dropoff_lng,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
        $ins->execute(array($u['id'], $label, $p_addr, $p_lat, $p_lng, $d_addr, $d_lat, $d_lng));
        ok(array('id' => intval($pdo->lastInsertId()), 'message' => 'Route saved'));
    } catch (Exception $e) { fail_ex($e, 'Could not save route', 500); }
    break;
}

case 'ride_delete_favorite_route': {
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('Route id is required');
    try {
        $d = $pdo->prepare('DELETE FROM ride_favorite_routes WHERE id=? AND user_id=?');
        $d->execute(array($id, $u['id']));
        if ($d->rowCount() === 0) fail('Route not found', 404);
        ok(array('message' => 'Route removed'));
    } catch (Exception $e) { fail_ex($e, 'Could not delete route', 500); }
    break;
}

// ── MESSAGES (rider support inbox) ──────────────────────────────────
// Shares the same customer_messages table as Delivery's Messages tab
// (one shared identity/inbox across products), but merges ride-status
// history here instead of order-status history. Sending a message
// still goes through the generic customer_send_message action in
// OrderController.php - no ride-specific send needed.

case 'ride_get_messages': {
    $items = array();
    try {
        $s = $pdo->prepare("SELECT id, sender, subject, body, is_read, created_at FROM customer_messages WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
        $s->execute(array($u['id']));
        foreach ($s->fetchAll() as $row) {
            $items[] = array(
                'kind' => 'message', 'id' => $row['id'], 'sender' => $row['sender'],
                'subject' => $row['subject'], 'body' => $row['body'],
                'is_read' => (bool)$row['is_read'], 'created_at' => $row['created_at'],
            );
        }
    } catch (Exception $e) { /* table may not exist yet */ }
    try {
        $r = $pdo->prepare("SELECT id, status, created_at, completed_at, dropoff_address FROM rides WHERE passenger_id=? ORDER BY created_at DESC LIMIT 15");
        $r->execute(array($u['id']));
        $statusText = array(
            'pending' => 'Ride requested', 'searching' => 'Looking for a driver',
            'accepted' => 'Driver accepted your ride', 'in_progress' => 'Ride in progress',
            'arrived' => 'You have arrived', 'completed' => 'Ride completed', 'cancelled' => 'Ride cancelled',
        );
        foreach ($r->fetchAll() as $ride) {
            $items[] = array(
                'kind' => 'ride_update', 'id' => 'ride-' . $ride['id'],
                'sender' => 'system', 'subject' => 'Ride to ' . ($ride['dropoff_address'] ?: 'destination'),
                'body' => isset($statusText[$ride['status']]) ? $statusText[$ride['status']] : ('Status: ' . $ride['status']),
                'is_read' => true,
                'created_at' => $ride['completed_at'] ? $ride['completed_at'] : $ride['created_at'],
            );
        }
    } catch (Exception $e) { /* stay safe if rides table shape differs */ }
    usort($items, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
    ok(array('messages' => array_slice($items, 0, 60)));
    break;
}

        default:
            fail('Unknown ride action: ' . $action, 404);
}
} catch (Throwable $e) {
    error_log('[ETEL][rides] ' . $action . ' failed: ' . $e->getMessage());
    fail_ex($e, 'Ride action failed', 500);
}
