<?php
// The driver's Pull tab and special offers.
//   Pull: a ride nobody near the rider has taken for a while (ride_pull_after_secs, default 20) is shown to drivers who are FAR from the pickup
//         (at least ride_pull_min_km, default 2, and up to ride_pull_max_km, default 25) in a tab of their own. The normal Requests list is unchanged.
//   Special offers: a ride that pays a lot (fare at or above ride_vip_min_fare, default 350, 0 = off) or that is called from one of the special areas staff
//         draw in Admin (a "sweet area" or a "VIP area") is shown at once, with all trip details, to every matching driver up to ride_pull_max_km.
// A ride can be taken from here with the normal driver_accept_ride. ASCII only. Never throws on a missing table (empty answer).
if (!defined('APP_VER')) { http_response_code(403); exit; }
require_once __DIR__ . '/ride_fare.php';

function rides_pull_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ride_special_areas (
            id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(80) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'sweet',
            lat DECIMAL(10,7) NOT NULL, lng DECIMAL(10,7) NOT NULL, radius_km DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            note VARCHAR(160) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('[ETEL] ride_special_areas: ' . $e->getMessage()); }
}

function rides_pull_defaults() {
    return array('ride_pull_on' => 1, 'ride_pull_after_secs' => 20, 'ride_pull_min_km' => 2, 'ride_pull_max_km' => 25, 'ride_vip_min_fare' => 350);
}
function rides_pull_settings($pdo) {
    $d = rides_pull_defaults(); $out = array();
    foreach ($d as $k => $v) $out[$k] = rides_rule_value($pdo, $k, $v);
    return $out;
}
// Saves what staff typed. Returns array(ok, message, settings). Every value is range-checked.
function rides_pull_save_settings($pdo, $in) {
    $rules = array('ride_pull_on' => array(0, 1), 'ride_pull_after_secs' => array(0, 600), 'ride_pull_min_km' => array(0, 20), 'ride_pull_max_km' => array(2, 60), 'ride_vip_min_fare' => array(0, 20000));
    $desc = array('ride_pull_on' => 'Pull tab for far drivers: 1 on, 0 off', 'ride_pull_after_secs' => 'Seconds a ride waits before far drivers can pull it',
                  'ride_pull_min_km' => 'Pull tab: a driver at least this far from the pickup (km)', 'ride_pull_max_km' => 'Pull tab and special offers: farthest driver (km)', 'ride_vip_min_fare' => 'Fare (ETB) from which a ride is a VIP offer, 0 = off');
    $vals = array();
    foreach ($rules as $k => $r) {
        if (!isset($in[$k]) || $in[$k] === '') continue;
        if (!is_numeric($in[$k])) return array('ok' => false, 'message' => 'Use numbers only.');
        $v = floatval($in[$k]);
        if ($v < $r[0] || $v > $r[1]) return array('ok' => false, 'message' => $k . ' must be between ' . $r[0] . ' and ' . $r[1] . '.');
        $vals[$k] = $v;
    }
    $min = isset($vals['ride_pull_min_km']) ? $vals['ride_pull_min_km'] : rides_rule_value($pdo, 'ride_pull_min_km', 2);
    $max = isset($vals['ride_pull_max_km']) ? $vals['ride_pull_max_km'] : rides_rule_value($pdo, 'ride_pull_max_km', 25);
    if ($max <= $min) return array('ok' => false, 'message' => 'The farthest distance must be more than the nearest.');
    try {
        $q = $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)");
        foreach ($vals as $k => $v) $q->execute(array($k, $v, $desc[$k]));
    } catch (Exception $e) { error_log('[ETEL] pull settings: ' . $e->getMessage()); return array('ok' => false, 'message' => 'Could not save.'); }
    return array('ok' => true, 'message' => 'Saved.', 'settings' => null);
}
function rides_pull_settings_fresh($pdo) {
    $out = array(); $d = rides_pull_defaults();
    foreach ($d as $k => $v) {
        try { $s = $pdo->prepare('SELECT rule_value FROM pricing_rules WHERE rule_key = ? LIMIT 1'); $s->execute(array($k)); $x = $s->fetchColumn(); $out[$k] = ($x === false || $x === null || $x === '') ? $v : floatval($x); }
        catch (Exception $e) { $out[$k] = $v; }
    }
    return $out;
}

function rides_pull_areas($pdo, $onlyActive) {
    rides_pull_ensure_schema($pdo);
    try {
        $rows = $pdo->query('SELECT id, name, kind, lat, lng, radius_km, note, active FROM ride_special_areas' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return array(); }
    foreach ($rows as &$r) { $r['id'] = intval($r['id']); $r['lat'] = floatval($r['lat']); $r['lng'] = floatval($r['lng']); $r['radius_km'] = floatval($r['radius_km']); $r['active'] = intval($r['active']); }
    unset($r);
    return $rows;
}
// Saves one area (id 0 = new). Returns array(ok, message, id).
function rides_pull_save_area($pdo, $in) {
    rides_pull_ensure_schema($pdo);
    $name = isset($in['name']) ? trim(substr((string) $in['name'], 0, 80)) : '';
    $kind = isset($in['kind']) && $in['kind'] === 'vip' ? 'vip' : 'sweet';
    $lat = isset($in['lat']) && is_numeric($in['lat']) ? floatval($in['lat']) : null;
    $lng = isset($in['lng']) && is_numeric($in['lng']) ? floatval($in['lng']) : null;
    $rad = isset($in['radius_km']) && is_numeric($in['radius_km']) ? floatval($in['radius_km']) : 1.0;
    $note = isset($in['note']) ? trim(substr((string) $in['note'], 0, 160)) : '';
    $act = !isset($in['active']) || $in['active'] ? 1 : 0;
    $id = isset($in['id']) ? intval($in['id']) : 0;
    if ($name === '') return array('ok' => false, 'message' => 'Give the area a name.');
    if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return array('ok' => false, 'message' => 'Enter the latitude and longitude of the area centre.');
    if ($rad < 0.1 || $rad > 20) return array('ok' => false, 'message' => 'The radius must be between 0.1 and 20 km.');
    try {
        if ($id > 0) {
            $q = $pdo->prepare('UPDATE ride_special_areas SET name=?, kind=?, lat=?, lng=?, radius_km=?, note=?, active=? WHERE id=?'); $q->execute(array($name, $kind, $lat, $lng, $rad, $note, $act, $id));
        } else {
            $n = intval($pdo->query('SELECT COUNT(*) FROM ride_special_areas')->fetchColumn());
            if ($n >= 50) return array('ok' => false, 'message' => 'You already have 50 areas. Delete one first.');
            $q = $pdo->prepare('INSERT INTO ride_special_areas (name, kind, lat, lng, radius_km, note, active, created_at) VALUES (?,?,?,?,?,?,?,NOW())'); $q->execute(array($name, $kind, $lat, $lng, $rad, $note, $act));
            $id = intval($pdo->lastInsertId());
        }
    } catch (Exception $e) { error_log('[ETEL] special area: ' . $e->getMessage()); return array('ok' => false, 'message' => 'Could not save the area.'); }
    return array('ok' => true, 'message' => 'Saved.', 'id' => $id);
}

// The tags of one ride: array of array(kind => vip|sweet, label, note). $fare = what the ride pays.
function rides_pull_tags($pickLat, $pickLng, $fare, $areas, $vipMin) {
    $tags = array();
    if ($vipMin > 0 && $fare >= $vipMin) $tags[] = array('kind' => 'vip', 'label' => 'High paying trip', 'note' => 'ETB ' . round($fare));
    if ($pickLat !== null && $pickLng !== null) {
        foreach ($areas as $a) {
            if (rides_haversine_km(floatval($pickLat), floatval($pickLng), $a['lat'], $a['lng']) <= $a['radius_km']) {
                $tags[] = array('kind' => $a['kind'], 'label' => ($a['kind'] === 'vip' ? 'VIP area: ' : 'Sweet area: ') . $a['name'], 'note' => $a['note']);
            }
        }
    }
    return $tags;
}

// What this driver may pull and which special offers he sees. $me = rides_driver_profile row (lat, lng, vehicle_type).
// Returns array('pull' => [...], 'offers' => [...], 'settings' => [...], 'no_position' => bool).
function rides_pull_for_driver($pdo, $uid, $me) {
    require_once __DIR__ . '/service_areas.php';
    $set = rides_pull_settings($pdo);
    $out = array('pull' => array(), 'offers' => array(), 'settings' => $set, 'no_position' => false);
    if (!$me || $me['lat'] === null || $me['lng'] === null || !floatval($me['lat'])) { $out['no_position'] = true; return $out; }
    $vt = !empty($me['vehicle_type']) ? $me['vehicle_type'] : 'bajaj';
    $areas = rides_pull_areas($pdo, true);
    try {
        require_once __DIR__ . '/ride_price_offer.php';
        rides_offer_price_ensure($pdo); // adds rides.rider_offer on a fresh database
        $s = $pdo->prepare("SELECT r.id, r.pickup_lat, r.pickup_lng, r.dropoff_lat, r.dropoff_lng, r.pickup_address, r.dropoff_address, r.distance_km, r.fare_final, r.fare_estimate, r.rider_offer,
                   r.created_at, u.name AS passenger_name
            FROM rides r JOIN users u ON u.id = r.passenger_id
            WHERE r.status = 'searching' AND r.passenger_id != ? AND (r.expires_at IS NULL OR r.expires_at > NOW())
              AND (LOWER(r.vehicle_type) = LOWER(?) OR r.vehicle_type IS NULL)
              AND NOT EXISTS (SELECT 1 FROM ride_driver_exclusions x WHERE x.ride_id = r.id AND x.driver_id = ?)
            ORDER BY r.id DESC LIMIT 60");
        $s->execute(array(intval($uid), $vt, intval($uid)));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('[ETEL][pull] list failed: ' . $e->getMessage()); return $out; }
    $myArea = null; $areasOn = etel_areas_enforced($pdo);
    if ($areasOn) { $myArea = etel_area_for_point($pdo, $me['lat'], $me['lng']); if (!$myArea) return $out; }
    foreach ($rows as $r) {
        if ($r['pickup_lat'] === null) continue;
        if ($myArea) { $ra = etel_area_for_point($pdo, $r['pickup_lat'], $r['pickup_lng']); if (!$ra || $ra['code'] !== $myArea['code']) continue; }
        $dist = rides_haversine_km(floatval($me['lat']), floatval($me['lng']), floatval($r['pickup_lat']), floatval($r['pickup_lng']));
        if ($dist > $set['ride_pull_max_km']) continue;
        $age = $r['created_at'] ? max(0, time() - strtotime($r['created_at'])) : 0; // PHP clock both sides: the row is written by PHP, so this holds even if the DB session zone is not synced
        $fare = floatval($r['fare_final']) > 0 ? floatval($r['fare_final']) : floatval($r['fare_estimate']);
        $tags = rides_pull_tags($r['pickup_lat'], $r['pickup_lng'], $fare, $areas, $set['ride_vip_min_fare']);
        $row = array('id' => intval($r['id']), 'pickup_address' => $r['pickup_address'], 'dropoff_address' => $r['dropoff_address'],
                     'pickup_lat' => $r['pickup_lat'], 'pickup_lng' => $r['pickup_lng'], 'dropoff_lat' => $r['dropoff_lat'], 'dropoff_lng' => $r['dropoff_lng'],
                     'trip_km' => round(floatval($r['distance_km']), 1), 'fare' => round($fare), 'rider_offer' => floatval($r['rider_offer']) > 0 ? round(floatval($r['rider_offer'])) : 0,
                     'from_driver_km' => round($dist, 1), 'eta_min' => max(1, (int) round($dist / 25 * 60)), 'waiting_s' => $age,
                     'passenger_name' => $r['passenger_name'] ? preg_replace('/\s.*$/', '', $r['passenger_name']) : '', 'tags' => $tags);
        if ($tags) { $out['offers'][] = $row; continue; }
        if ($set['ride_pull_on'] >= 1 && $age >= $set['ride_pull_after_secs'] && $dist >= $set['ride_pull_min_km']) $out['pull'][] = $row;
    }
    usort($out['offers'], function ($a, $b) { return $b['fare'] - $a['fare']; });
    usort($out['pull'], function ($a, $b) { return $b['waiting_s'] - $a['waiting_s']; });
    return $out;
}
