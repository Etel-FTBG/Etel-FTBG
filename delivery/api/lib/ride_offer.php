<?php
/**
 * Ride offer waves: nearest drivers get a new request first.
 *
 * Until now every online driver within range saw a new ride at the same instant, so the first to tap won
 * (often a driver far from the pickup, or one with the fastest phone). Now, per request, drivers are ranked by
 * distance to the pickup (drivers on another trip and drivers who declined this ride are not counted):
 *   wave 1  the nearest ride_offer_wave1_size drivers (default 3)   see it at once
 *   wave 2  the nearest ride_offer_wave2_size drivers (default 8)   see it after ride_offer_wave_secs (default 20)
 *   wave 3  everyone in range                                       sees it after 2 x ride_offer_wave_secs
 * Settings are rows in pricing_rules; ride_offer_wave_secs = 0 switches waves off (everyone at once, as before).
 *
 * Stateless: the wave a driver is in is worked out from the ride's age each time the driver's list is built, so
 * nothing has to be scheduled and nothing is stored per offer. The one stored fact is "the remaining drivers were
 * pushed" (ride_offer_log), so the wake-up push for wave 3 is sent exactly once.
 *
 * Fails open: no driver location, no ranking data or any error means the driver sees the ride.
 */
if (!defined('APP_VER')) { http_response_code(403); exit; }

if (!function_exists('rides_offer_config')) {

function rides_offer_config($pdo) {
    require_once __DIR__ . '/ride_fare.php';
    return array(
        'w1'   => max(0, intval(rides_rule_value($pdo, 'ride_offer_wave1_size', 3))),
        'w2'   => max(0, intval(rides_rule_value($pdo, 'ride_offer_wave2_size', 8))),
        'secs' => max(0, intval(rides_rule_value($pdo, 'ride_offer_wave_secs', 20)))
    );
}

/* Drivers who could take a ride of this vehicle type right now, other than $exceptDriver. */
function rides_offer_competitors($pdo, $vtype, $exceptDriver) {
    $q = $pdo->prepare(
        "SELECT d.user_id, d.lat, d.lng FROM ride_drivers d
         WHERE d.online = 1 AND d.lat IS NOT NULL AND d.lng IS NOT NULL
           AND d.last_seen >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
           AND d.user_id <> ?
           AND (LOWER(d.vehicle_type) = LOWER(?) OR d.vehicle_type IS NULL)
           AND NOT EXISTS (SELECT 1 FROM rides a WHERE a.driver_id = d.user_id AND a.status IN ('accepted','in_progress','arrived'))
         LIMIT 300");
    $q->execute(array(intval($exceptDriver), $vtype));
    return $q->fetchAll();
}

/* 1 = nearest. Ties go to the lower user id so two drivers never both think they are first. */
function rides_offer_rank($myDist, $driverId, $pickLat, $pickLng, $competitors, $excluded) {
    $rank = 1;
    foreach ($competitors as $c) {
        if (isset($excluded[intval($c['user_id'])])) continue;
        $d = rides_haversine_km($pickLat, $pickLng, floatval($c['lat']), floatval($c['lng']));
        if ($d < $myDist || ($d == $myDist && intval($c['user_id']) < intval($driverId))) $rank++;
    }
    return $rank;
}

function rides_offer_allows($cfg, $rank, $age) {
    if ($age >= 2 * $cfg['secs']) return true;
    if ($rank <= $cfg['w1']) return true;
    return $age >= $cfg['secs'] && $rank <= $cfg['w2'];
}

/**
 * Keeps only the requests this driver may see right now.
 * $rides: rows with id, pickup_lat, pickup_lng and age_s (seconds since the ride was requested, from the DB clock).
 */
function rides_offer_filter($pdo, $driverId, $myLat, $myLng, $myVtype, $rides) {
    try {
        $cfg = rides_offer_config($pdo);
        if ($cfg['secs'] <= 0 || $cfg['w1'] <= 0 || !$rides || $myLat === null || $myLng === null || !floatval($myLat)) return $rides;
        $young = array();
        foreach ($rides as $r) {
            if (isset($r['age_s']) && intval($r['age_s']) < 2 * $cfg['secs'] && !empty($r['pickup_lat'])) $young[] = intval($r['id']);
        }
        if (!$young) return $rides;

        $comp = rides_offer_competitors($pdo, $myVtype, $driverId);
        $excl = array();
        $in = implode(',', array_fill(0, count($young), '?'));
        $x = $pdo->prepare("SELECT ride_id, driver_id FROM ride_driver_exclusions WHERE ride_id IN ($in)");
        $x->execute($young);
        foreach ($x->fetchAll() as $e) $excl[intval($e['ride_id'])][intval($e['driver_id'])] = 1;

        $out = array();
        foreach ($rides as $r) {
            if (!in_array(intval($r['id']), $young, true)) { $out[] = $r; continue; }
            $pl = floatval($r['pickup_lat']); $pg = floatval($r['pickup_lng']);
            $mine = rides_haversine_km($pl, $pg, floatval($myLat), floatval($myLng));
            $ex = isset($excl[intval($r['id'])]) ? $excl[intval($r['id'])] : array();
            $rank = rides_offer_rank($mine, $driverId, $pl, $pg, $comp, $ex);
            if (rides_offer_allows($cfg, $rank, intval($r['age_s']))) $out[] = $r;
        }
        return $out;
    } catch (Exception $e) {
        error_log('[ETEL][offer] filter failed, showing all requests: ' . $e->getMessage());
        return $rides;
    }
}

function rides_offer_ensure_log($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ride_offer_log (
        ride_id INT NOT NULL PRIMARY KEY,
        escalated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* Who gets the push when a ride is booked: the nearest wave-2 drivers (everyone when waves are off). */
function rides_offer_first_push_targets($pdo, $candidates, $pickLat, $pickLng) {
    try {
        $cfg = rides_offer_config($pdo);
        if ($cfg['secs'] <= 0 || $cfg['w1'] <= 0) return $candidates;
        usort($candidates, function ($a, $b) use ($pickLat, $pickLng) {
            $da = rides_haversine_km($pickLat, $pickLng, floatval($a['lat']), floatval($a['lng']));
            $db = rides_haversine_km($pickLat, $pickLng, floatval($b['lat']), floatval($b['lng']));
            if ($da == $db) return intval($a['user_id']) - intval($b['user_id']);
            return $da < $db ? -1 : 1;
        });
        return array_slice($candidates, 0, max($cfg['w1'], $cfg['w2']));
    } catch (Exception $e) {
        return $candidates;
    }
}

/**
 * Once a ride has been open for one wave length, wake the drivers who were not in the first push (the nearest
 * wave-2 drivers already got theirs when the ride was booked). Sent at most once per ride: the claim is an
 * INSERT IGNORE into ride_offer_log. Cheap enough to call from the passenger's status poll.
 */
function rides_offer_escalate($pdo, $rideId) {
    static $handled = array();   // the passenger's status stream calls this every few seconds
    if (isset($handled[intval($rideId)])) return 0;
    try {
        $cfg = rides_offer_config($pdo);
        if ($cfg['secs'] <= 0 || $cfg['w1'] <= 0) return 0;
        $s = $pdo->prepare("SELECT id, pickup_lat, pickup_lng, vehicle_type, fare_final, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_s
                            FROM rides WHERE id = ? AND status = 'searching'");
        $s->execute(array(intval($rideId)));
        $r = $s->fetch();
        if (!$r || intval($r['age_s']) < $cfg['secs'] || intval($r['age_s']) > 600 || empty($r['pickup_lat'])) return 0;
        rides_offer_ensure_log($pdo);
        $claim = $pdo->prepare("INSERT IGNORE INTO ride_offer_log (ride_id) VALUES (?)");
        $claim->execute(array(intval($rideId)));
        $handled[intval($rideId)] = 1;
        if ($claim->rowCount() < 1) return 0;   // already escalated

        $vt = $r['vehicle_type'] ? $r['vehicle_type'] : 'bajaj';
        $q = $pdo->prepare("SELECT d.user_id, d.lat, d.lng FROM ride_drivers d
            WHERE d.online = 1 AND d.lat IS NOT NULL AND d.lng IS NOT NULL
              AND d.last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
              AND (LOWER(d.vehicle_type) = LOWER(?) OR d.vehicle_type IS NULL)
              AND NOT EXISTS (SELECT 1 FROM rides a WHERE a.driver_id = d.user_id AND a.status IN ('accepted','in_progress','arrived'))
              AND NOT EXISTS (SELECT 1 FROM ride_driver_exclusions x WHERE x.ride_id = ? AND x.driver_id = d.user_id)
            LIMIT 300");
        $q->execute(array($vt, intval($rideId)));
        $pl = floatval($r['pickup_lat']); $pg = floatval($r['pickup_lng']);
        $all = array();
        foreach ($q->fetchAll() as $d) {
            $km = rides_haversine_km($pl, $pg, floatval($d['lat']), floatval($d['lng']));
            if ($km <= 8) { $d['km'] = $km; $all[] = $d; }
        }
        $first = array();
        foreach (rides_offer_first_push_targets($pdo, $all, $pl, $pg) as $f) $first[intval($f['user_id'])] = 1;
        $sent = 0;
        foreach ($all as $d) {
            if (isset($first[intval($d['user_id'])])) continue;
            if (function_exists('etel_push_send')) {
                etel_push_send($pdo, intval($d['user_id']), 'New ride request nearby!',
                    'ETB ' . number_format(floatval($r['fare_final']), 0) . ' - ' . round($d['km'], 1) . ' km away',
                    array('type' => 'new_ride_request', 'ride_id' => intval($rideId), 'url' => '/delivery/driver/'));
                $sent++;
            }
        }
        return $sent;
    } catch (Exception $e) {
        error_log('[ETEL][offer] escalate failed: ' . $e->getMessage());
        return 0;
    }
}

}
