<?php
// ============================================================
// Admin -> Ride -> Special offers: the settings of the driver Pull tab and the special areas (lib/ride_pull.php).
//   admin_ride_offers_get   {}                      -> { settings, areas }                          (rides.view)
//   admin_ride_offers_save  { ride_pull_on, ride_pull_after_secs, ride_pull_min_km, ride_pull_max_km, ride_vip_min_fare }   (rides.manage)
//   admin_ride_area_save    { id?, name, kind: sweet|vip, lat, lng, radius_km, note, active }        (rides.manage)
//   admin_ride_area_delete  { id }                                                                   (rides.manage)
// ============================================================
require_once __DIR__ . '/../lib/ride_pull.php';

switch ($action) {

case 'admin_ride_offers_get': {
    $pdo = db(); require_admin($pdo, 'rides.view');
    ok(array('settings' => rides_pull_settings_fresh($pdo), 'areas' => rides_pull_areas($pdo, false)));
    break;
}

case 'admin_ride_offers_save': {
    $pdo = db(); $u = require_admin($pdo, 'rides.manage');
    $r = rides_pull_save_settings($pdo, $body);
    if (!$r['ok']) fail($r['message'], 400);
    error_log('[ETEL][ride-offers] settings changed by admin ' . intval($u['id']));
    ok(array('message' => $r['message'], 'settings' => rides_pull_settings_fresh($pdo)));
    break;
}

case 'admin_ride_area_save': {
    $pdo = db(); $u = require_admin($pdo, 'rides.manage');
    $r = rides_pull_save_area($pdo, $body);
    if (!$r['ok']) fail($r['message'], 400);
    error_log('[ETEL][ride-offers] area ' . intval($r['id']) . ' saved by admin ' . intval($u['id']));
    ok(array('message' => $r['message'], 'id' => $r['id'], 'areas' => rides_pull_areas($pdo, false)));
    break;
}

case 'admin_ride_area_delete': {
    $pdo = db(); $u = require_admin($pdo, 'rides.manage');
    rides_pull_ensure_schema($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id required', 400);
    try { $pdo->prepare('DELETE FROM ride_special_areas WHERE id = ?')->execute(array($id)); } catch (Exception $e) { fail_ex($e, 'Could not delete the area', 500); }
    error_log('[ETEL][ride-offers] area ' . $id . ' deleted by admin ' . intval($u['id']));
    ok(array('message' => 'Deleted.', 'areas' => rides_pull_areas($pdo, false)));
    break;
}

default:
    fail('Unknown action: ' . $action, 404);
}
