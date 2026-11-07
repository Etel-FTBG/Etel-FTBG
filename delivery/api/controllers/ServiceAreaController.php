<?php
// ============================================================
// ETEL - service areas (cities). Public: get_service_areas. Admin (settings.view / settings.manage):
//   admin_areas_list / admin_area_save / admin_area_delete / admin_areas_settings_save
// The rules live in api/lib/service_areas.php. ASCII only.
// ============================================================
require_once __DIR__ . '/../lib/service_areas.php';

switch ($action) {

case 'get_service_areas': {
    // Public (the apps ask it before sign-in): the served cities, and - when lat/lng are sent - which one the person is in.
    $pdo = db();
    $lat = isset($_GET['lat']) ? floatval($_GET['lat']) : (isset($body['lat']) ? floatval($body['lat']) : 0);
    $lng = isset($_GET['lng']) ? floatval($_GET['lng']) : (isset($body['lng']) ? floatval($body['lng']) : 0);
    $enforced = etel_areas_enforced($pdo);
    $areas = etel_areas_list($pdo, true);
    $cur = null; $near = null;
    if (($lat || $lng) && abs($lat) <= 90 && abs($lng) <= 180) { $cur = etel_area_for_point($pdo, $lat, $lng); if (!$cur) $near = etel_area_nearest($pdo, $lat, $lng); }
    ok(array('enforced' => $enforced, 'areas' => $areas, 'current' => $cur, 'nearest' => $near, 'outside' => ($enforced && ($lat || $lng) && !$cur) ? true : false));
    break;
}

case 'admin_areas_list': {
    $pdo = db(); require_admin($pdo, 'settings.view');
    $areas = etel_areas_list($pdo, false);
    $now = time();
    foreach ($areas as &$a) {
        $a['shops'] = 0; $a['drivers_online'] = 0; $a['rides_7d'] = 0;
        try {
            $sc = etel_table_columns($pdo, 'service_givers');
            list($sql, $par) = etel_area_shop_where($a, $sc, 'sg', etel_areas_list($pdo, true));
            if ($sql !== '') { $q = $pdo->prepare("SELECT COUNT(*) FROM service_givers sg WHERE sg.status = 'verified'" . $sql); $q->execute($par); $a['shops'] = intval($q->fetchColumn()); }
        } catch (Exception $e) { /* no shops table */ }
        try {
            $q = $pdo->query("SELECT lat, lng FROM ride_drivers WHERE online = 1 AND lat IS NOT NULL AND lng IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) { $pa = etel_area_for_point($pdo, $d['lat'], $d['lng']); if ($pa && $pa['code'] === $a['code']) $a['drivers_online']++; }
        } catch (Exception $e) { /* no drivers table */ }
        try {
            $q = $pdo->query("SELECT pickup_lat, pickup_lng FROM rides WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND pickup_lat IS NOT NULL LIMIT 5000");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $pa = etel_area_for_point($pdo, $r['pickup_lat'], $r['pickup_lng']); if ($pa && $pa['code'] === $a['code']) $a['rides_7d']++; }
        } catch (Exception $e) { /* no rides table */ }
    }
    unset($a);
    ok(array('areas' => $areas, 'enforced' => etel_areas_enforced($pdo), 'switch_on' => (function ($pdo) { try { return $pdo->query("SELECT v FROM service_area_settings WHERE k = 'enforce'")->fetchColumn() !== '0'; } catch (Exception $e) { return true; } })($pdo)));
    break;
}

case 'admin_area_save': {
    $pdo = db(); $adm = require_admin($pdo, 'settings.manage');
    if (!etel_areas_ensure($pdo)) fail('The service areas table could not be created.', 500);
    $id = intval(isset($body['id']) ? $body['id'] : 0);
    $name = trim(isset($body['name']) ? (string) $body['name'] : ''); $nameAm = trim(isset($body['name_am']) ? (string) $body['name_am'] : '');
    $lat = isset($body['lat']) ? floatval($body['lat']) : 0; $lng = isset($body['lng']) ? floatval($body['lng']) : 0; $rad = isset($body['radius_km']) ? floatval($body['radius_km']) : 0;
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 80) fail('Write the city name (2 to 80 letters).');
    if (mb_strlen($nameAm, 'UTF-8') > 80) fail('The Amharic name is too long.');
    if ($lat < 3 || $lat > 15 || $lng < 32 || $lng > 48) fail('The centre must be a place in Ethiopia: check the latitude and longitude.');
    if ($rad < 1 || $rad > 60) fail('The radius must be between 1 and 60 km.');
    $active = !empty($body['active']) ? 1 : 0; $sort = intval(isset($body['sort_order']) ? $body['sort_order'] : 0);
    if ($id > 0) {
        $q = $pdo->prepare('SELECT id FROM service_areas WHERE id = ?'); $q->execute(array($id)); if (!$q->fetchColumn()) fail('Area not found', 404);
        $pdo->prepare('UPDATE service_areas SET name = ?, name_am = ?, center_lat = ?, center_lng = ?, radius_km = ?, active = ?, sort_order = ?, updated_at = NOW() WHERE id = ?')->execute(array($name, $nameAm, $lat, $lng, $rad, $active, $sort, $id));
    } else {
        $code = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', isset($body['code']) && trim($body['code']) !== '' ? (string) $body['code'] : $name), '-'));
        if ($code === '') fail('Write the city name in English letters.');
        $q = $pdo->prepare('SELECT id FROM service_areas WHERE code = ?'); $q->execute(array($code)); if ($q->fetchColumn()) fail('There is already an area with this name.', 409);
        $pdo->prepare('INSERT INTO service_areas (code, name, name_am, center_lat, center_lng, radius_km, active, sort_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())')->execute(array($code, $name, $nameAm, $lat, $lng, $rad, $active, $sort ? $sort : 99));
    }
    error_log('[ETEL][areas] admin #' . intval($adm['id']) . ' saved area "' . $name . '"');
    ok(array('saved' => true));
    break;
}

case 'admin_area_delete': {
    $pdo = db(); $adm = require_admin($pdo, 'settings.manage');
    $id = intval(isset($body['id']) ? $body['id'] : 0);
    if (!etel_areas_ensure($pdo)) fail('The service areas table could not be created.', 500);
    $pdo->prepare('DELETE FROM service_areas WHERE id = ?')->execute(array($id));
    error_log('[ETEL][areas] admin #' . intval($adm['id']) . ' deleted area #' . $id);
    ok(array('deleted' => true));
    break;
}

case 'admin_areas_settings_save': {
    $pdo = db(); $adm = require_admin($pdo, 'settings.manage');
    etel_areas_set_enforced($pdo, !empty($body['enforce']));
    error_log('[ETEL][areas] admin #' . intval($adm['id']) . ' set enforcement ' . (!empty($body['enforce']) ? 'ON' : 'OFF'));
    ok(array('enforce' => !empty($body['enforce'])));
    break;
}

}
