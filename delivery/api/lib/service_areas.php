<?php
// ============================================================
// ETEL Delivery and Ride - service areas (cities). A person in Shashemene only gets Shashemene: places in the map search, drivers, shops, orders and rides.
//   * An area is a city described by a centre and a radius in km (admin edits them: Admin > Service areas). Seeded with Hawassa and Shashemene.
//   * A point belongs to an area when it is inside its circle; when circles overlap, the area whose centre is nearest wins.
//   * Enforcement is one switch (service_area_settings.enforce, default on). Anything that cannot be decided (no coordinates, no active area, table
//     problem) is allowed: this layer must never lock people out, it only refuses what is clearly in another city or in no served city.
//   * Messages come with an Amharic twin (message_am) because the apps show server messages as they are. ASCII only (Amharic as \u escapes).
// ============================================================

if (!function_exists('etel_areas_ensure')) {

function etel_area_km($lat1, $lng1, $lat2, $lng2) {
    $dlat = deg2rad($lat2 - $lat1); $dlng = deg2rad($lng2 - $lng1);
    $a = sin($dlat / 2) * sin($dlat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) * sin($dlng / 2);
    return 6371 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
}

/* Creates the two tables when missing and seeds Hawassa and Shashemene once. Returns false when the database refuses (then nothing is enforced). */
function etel_areas_ensure($pdo) {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `service_areas` (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `code` VARCHAR(40) NOT NULL, `name` VARCHAR(80) NOT NULL, `name_am` VARCHAR(80) NOT NULL DEFAULT '',
            `center_lat` DECIMAL(10,7) NOT NULL, `center_lng` DECIMAL(10,7) NOT NULL, `radius_km` DECIMAL(6,2) NOT NULL DEFAULT 10,
            `active` TINYINT(1) NOT NULL DEFAULT 1, `sort_order` INT(11) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_service_area_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `service_area_settings` (
            `k` VARCHAR(40) NOT NULL, `v` VARCHAR(255) NOT NULL DEFAULT '', `updated_at` DATETIME NOT NULL, PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (intval($pdo->query('SELECT COUNT(*) FROM service_areas')->fetchColumn()) === 0) {
            $ins = $pdo->prepare('INSERT IGNORE INTO service_areas (code, name, name_am, center_lat, center_lng, radius_km, active, sort_order, created_at, updated_at) VALUES (?,?,?,?,?,?,1,?,NOW(),NOW())');
            $ins->execute(array('hawassa', 'Hawassa', json_decode('"\u1200\u12cb\u1233"'), 7.0621000, 38.4763000, 12, 1));
            $ins->execute(array('shashemene', 'Shashemene', json_decode('"\u123b\u1238\u1218\u1292"'), 7.2000000, 38.5900000, 10, 2));
        }
        $pdo->exec("INSERT IGNORE INTO service_area_settings (k, v, updated_at) VALUES ('enforce', '1', NOW())");
        $done = true;
    } catch (Exception $e) { error_log('[ETEL][areas] ' . $e->getMessage()); $done = false; }
    return $done;
}

/* Areas as plain arrays (numbers are numbers). Active ones only unless asked. */
function etel_areas_list($pdo, $activeOnly = true) {
    static $cache = array();
    $ck = $activeOnly ? 1 : 0;
    if (isset($cache[$ck])) return $cache[$ck];
    $out = array();
    if (!$pdo || !etel_areas_ensure($pdo)) return $out;
    try {
        $rows = $pdo->query('SELECT * FROM service_areas' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $out[] = etel_area_shape($r);
    } catch (Exception $e) { /* none */ }
    return $cache[$ck] = $out;
}
function etel_areas_cache_clear() { /* the lists are cached per request only; saving ends the request */ }

function etel_area_shape($r) {
    $lat = floatval($r['center_lat']); $lng = floatval($r['center_lng']); $rad = floatval($r['radius_km']);
    $dlat = $rad / 111.32; $dlng = $rad / (111.32 * max(0.2, cos(deg2rad($lat))));
    return array('id' => intval($r['id']), 'code' => $r['code'], 'name' => $r['name'], 'name_am' => isset($r['name_am']) ? $r['name_am'] : '',
        'lat' => $lat, 'lng' => $lng, 'radius_km' => $rad, 'active' => intval($r['active']) === 1, 'sort_order' => intval($r['sort_order']),
        'bounds' => array('south' => round($lat - $dlat, 6), 'west' => round($lng - $dlng, 6), 'north' => round($lat + $dlat, 6), 'east' => round($lng + $dlng, 6)));
}

function etel_area_contains($a, $lat, $lng) { return etel_area_km($a['lat'], $a['lng'], floatval($lat), floatval($lng)) <= $a['radius_km']; }

/* The active area a point is in (nearest centre when circles overlap), or null. */
function etel_area_for_point($pdo, $lat, $lng) {
    $lat = floatval($lat); $lng = floatval($lng);
    if (!$lat && !$lng) return null;
    $best = null; $bd = null;
    foreach (etel_areas_list($pdo, true) as $a) {
        $d = etel_area_km($a['lat'], $a['lng'], $lat, $lng);
        if ($d <= $a['radius_km'] && ($bd === null || $d < $bd)) { $best = $a; $bd = $d; }
    }
    return $best;
}
/* The nearest active area whatever the distance: array(area, distance_km) or null. */
function etel_area_nearest($pdo, $lat, $lng) {
    $best = null; $bd = null;
    foreach (etel_areas_list($pdo, true) as $a) {
        $d = etel_area_km($a['lat'], $a['lng'], floatval($lat), floatval($lng));
        if ($bd === null || $d < $bd) { $best = $a; $bd = $d; }
    }
    return $best ? array('area' => $best, 'distance_km' => round($bd, 1)) : null;
}
function etel_area_by_code($pdo, $code) {
    $code = strtolower(trim((string)$code));
    foreach (etel_areas_list($pdo, true) as $a) { if ($a['code'] === $code) return $a; }
    return null;
}
function etel_area_names($pdo) {
    return implode(', ', array_map(function ($a) { return $a['name']; }, etel_areas_list($pdo, true)));
}

/* True when the one switch is on AND at least one area is active. */
function etel_areas_enforced($pdo) {
    if (!$pdo || !etel_areas_ensure($pdo)) return false;
    try {
        $v = $pdo->query("SELECT v FROM service_area_settings WHERE k = 'enforce'")->fetchColumn();
        if ($v === '0') return false;
    } catch (Exception $e) { return false; }
    return count(etel_areas_list($pdo, true)) > 0;
}
function etel_areas_set_enforced($pdo, $on) {
    etel_areas_ensure($pdo);
    $pdo->prepare("INSERT INTO service_area_settings (k, v, updated_at) VALUES ('enforce', ?, NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()")->execute(array($on ? '1' : '0'));
}

function etel_area_am($s) { return json_decode('"' . $s . '"'); }

/* Is this one point acceptable? array(ok, area, reason, message, message_am). Not enforced or no coordinates = ok. */
function etel_area_check_point($pdo, $lat, $lng, $what = 'location') {
    $res = array('ok' => true, 'area' => null, 'reason' => '', 'message' => '', 'message_am' => '');
    if (!etel_areas_enforced($pdo)) return $res;
    $lat = floatval($lat); $lng = floatval($lng);
    if (!$lat && !$lng) return $res;
    $a = etel_area_for_point($pdo, $lat, $lng);
    if ($a) { $res['area'] = $a; return $res; }
    $names = etel_area_names($pdo);
    $res['ok'] = false; $res['reason'] = 'OUTSIDE_AREA';
    $res['message'] = 'Etel is not available at this ' . $what . ' yet. We currently serve: ' . $names . '.';
    $res['message_am'] = etel_area_am('\u12a2\u1275\u12a4\u120d \u12a0\u1201\u1295 \u1260\u12da\u1205 \u1240\u1275\u1275\u1218\u1275 \u12a0\u12ed\u1308\u1298\u121d\u1362 \u12a5\u12eb\u1308\u1208\u1308\u1209 \u12eb\u1209\u1275\u1361 ') . $names . '.';
    return $res;
}

/* A trip: the pickup must be in a served area and the drop-off (when we know it) in the same one. */
function etel_area_check_trip($pdo, $plat, $plng, $dlat, $dlng) {
    $p = etel_area_check_point($pdo, $plat, $plng, 'pickup location');
    if (!$p['ok'] || !$p['area']) return $p;
    if (!floatval($dlat) && !floatval($dlng)) return $p;
    $a = $p['area'];
    $other = etel_area_for_point($pdo, $dlat, $dlng);
    if (!$other || $other['code'] !== $a['code']) {
        $p['ok'] = false; $p['reason'] = $other ? 'CROSS_AREA' : 'DROPOFF_OUTSIDE';
        $p['message'] = $other
            ? 'Your pickup is in ' . $a['name'] . ', so the drop-off must also be in ' . $a['name'] . '. Please choose a place in ' . $a['name'] . '.'
            : 'That drop-off is outside ' . $a['name'] . '. Please choose a place in ' . $a['name'] . '.';
        $nm = $a['name_am'] !== '' ? $a['name_am'] : $a['name'];
        $p['message_am'] = etel_area_am('\u121b\u12a8\u12a8\u120d\u1361 ') . $nm . etel_area_am('\u12cd\u1235\u1325 \u12a0\u12ed\u12f0\u1208\u121d\u1362 \u12a5\u1263\u12ad\u12ce \u1260') . ' ' . $nm . ' ' . etel_area_am('\u12cd\u1235\u1325 \u12eb\u1208 \u1240\u1325 \u12ed\u121d\u1228\u1321\u1362');
    }
    return $p;
}

/* WHERE-part limiting service givers (alias $alias) to one area: coordinates inside the circle, or - when the shop has no coordinates - its city text.
   Returns array(sql starting with " AND (", params). */
function etel_area_shop_where($area, $have_cols, $alias = 'sg', $others = array()) {
    $parts = array(); $params = array();
    if (in_array('lat', $have_cols, true) && in_array('lng', $have_cols, true)) {
        // inside this city's circle AND no other city's centre is nearer (circles may overlap; the nearest centre wins, as for a person's own position)
        $dist = "(6371 * ACOS(LEAST(1, GREATEST(-1, COS(RADIANS(?)) * COS(RADIANS($alias.lat)) * COS(RADIANS($alias.lng) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS($alias.lat))))))";
        $cond = "$alias.lat IS NOT NULL AND $alias.lng IS NOT NULL AND $dist <= ?";
        array_push($params, $area['lat'], $area['lng'], $area['lat'], $area['radius_km']);
        foreach ($others as $o) {
            if ($o['code'] === $area['code']) continue;
            $cond .= " AND $dist <= $dist";
            array_push($params, $area['lat'], $area['lng'], $area['lat'], $o['lat'], $o['lng'], $o['lat']);
        }
        $parts[] = "($cond)";
        $noCoord = "($alias.lat IS NULL OR $alias.lng IS NULL OR ($alias.lat = 0 AND $alias.lng = 0))";
    } else { $noCoord = '1 = 1'; }
    if (in_array('city', $have_cols, true)) {
        $parts[] = "($noCoord AND LOWER(TRIM($alias.city)) IN (?, ?))";
        array_push($params, strtolower($area['name']), $area['code']);
    }
    if (!$parts) return array('', array());
    return array(' AND (' . implode(' OR ', $parts) . ')', $params);
}

/* Which area a shop row belongs to: its coordinates when it has them, else its city text. null when unknown. */
function etel_area_of_shop($pdo, $lat, $lng, $city) {
    if (floatval($lat) || floatval($lng)) return etel_area_for_point($pdo, $lat, $lng);
    $c = strtolower(trim((string)$city));
    foreach (etel_areas_list($pdo, true) as $a) { if ($c === strtolower($a['name']) || $c === $a['code']) return $a; }
    return null;
}

}
