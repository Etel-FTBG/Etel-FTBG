<?php
// ============================================================
// ETEL - pictures of the vehicle (front, side, back) for couriers and drivers.
//   vehicle_photos_get         {}                                  -> which sides this person has sent (never the links of others)
//   vehicle_photos_save        { front?, side?, back? }            signed-in courier or driver, own account: Driver app, courier sign-up page
//   admin_vehicle_photos_save  { user_id, kind, front?, side?, back? }    (users.manage) for a courier or driver that already exists
// Pictures come from api/upload.php with type vehicle_front / vehicle_side / vehicle_back. Only our own links are accepted.
// ============================================================
require_once __DIR__ . '/../lib/vehicle_photos.php';

switch ($action) {

case 'vehicle_photos_get': {
    $pdo = db(); $u = require_auth($pdo);
    $role = isset($u['role']) ? $u['role'] : '';
    if ($role !== 'courier' && $role !== 'driver') fail('Only couriers and drivers have vehicle pictures.', 403);
    $p = etel_vp_get($pdo, $role, $u['id']);
    $needed = !etel_vp_complete($p);
    if ($needed && $role === 'courier') {   // a courier who walks has no vehicle to photograph
        try { $q = $pdo->prepare('SELECT vehicle_type FROM couriers WHERE user_id = ? LIMIT 1'); $q->execute(array($u['id'])); if ($q->fetchColumn() === 'on_foot') $needed = false; } catch (Exception $e) { }
    }
    ok(array('have' => array('front' => $p['front'] !== '', 'side' => $p['side'] !== '', 'back' => $p['back'] !== ''), 'complete' => etel_vp_complete($p), 'needed' => $needed, 'role' => $role));
    break;
}

case 'vehicle_photos_save': {
    $pdo = db(); $u = require_auth($pdo);
    $role = isset($u['role']) ? $u['role'] : '';
    if ($role !== 'courier' && $role !== 'driver') fail('Only couriers and drivers have vehicle pictures.', 403);
    $urls = etel_vp_clean($body);
    if (!array_filter($urls)) fail('Send at least one picture of the vehicle.', 400);
    etel_vp_save($pdo, $role, $u['id'], $urls);
    $p = etel_vp_get($pdo, $role, $u['id']);
    ok(array('saved' => true, 'have' => array('front' => $p['front'] !== '', 'side' => $p['side'] !== '', 'back' => $p['back'] !== ''), 'complete' => etel_vp_complete($p)));
    break;
}

case 'admin_vehicle_photos_save': {
    $pdo = db(); $adm = require_admin($pdo, 'users.manage');
    $uid = intval(isset($body['user_id']) ? $body['user_id'] : 0);
    $kind = isset($body['kind']) ? (string) $body['kind'] : '';
    if (!in_array($kind, array('courier', 'driver'), true)) fail('Choose courier or driver.', 400);
    $q = $pdo->prepare('SELECT role FROM users WHERE id = ?'); $q->execute(array($uid)); $role = $q->fetchColumn();
    if ($role !== $kind) fail('That account is not a ' . $kind . '.', 404);
    $urls = etel_vp_clean($body);
    if (!array_filter($urls)) fail('Send at least one picture of the vehicle.', 400);
    etel_vp_save($pdo, $kind, $uid, $urls);
    error_log('[ETEL][vehicle_photos] admin #' . intval($adm['id']) . ' saved ' . $kind . ' pictures for user #' . $uid);
    $p = etel_vp_get($pdo, $kind, $uid);
    ok(array('saved' => true, 'photos' => $p, 'complete' => etel_vp_complete($p)));
    break;
}

}
