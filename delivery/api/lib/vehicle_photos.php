<?php
// ============================================================
// ETEL - pictures of a courier's or driver's vehicle (three sides: front, side, back).
// Saved on the person's own row: courier_details (couriers) and ride_drivers (drivers), columns vehicle_photo_front / _side / _back.
// The columns are added automatically the first time they are needed. Used by the agent registration, the admin desks,
// the courier sign-up page and the Driver app. (Shop place pictures are papers in sg_documents: see sg_docs.php.)
// ============================================================
if (!function_exists('etel_vp_sides')) {

function etel_vp_sides() { return array('front', 'side', 'back'); }

/* Adds the three columns where missing (once per request). Idempotent. */
function etel_vp_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        require_once __DIR__ . '/schema_helpers.php';
        require_once __DIR__ . '/courier_details.php';
        etel_courier_details_ensure($pdo);
        $cols = array();
        foreach (etel_vp_sides() as $s) $cols['vehicle_photo_' . $s] = "vehicle_photo_$s VARCHAR(500) NOT NULL DEFAULT ''";
        rides_add_missing_cols($pdo, 'courier_details', $cols);
        try { rides_add_missing_cols($pdo, 'ride_drivers', $cols); } catch (Exception $e) { /* no drivers table on this install yet */ }
    } catch (Exception $e) { error_log('[ETEL][vehicle_photos] ' . $e->getMessage()); }
}

/* Only pictures this system stored in its own vehicle-pictures folder are accepted. */
function etel_vp_url_ok($url) {
    return is_string($url) && strlen($url) <= 500 && preg_match('#^https?://[^\s"<>]+/uploads/vehicle_photos/[A-Za-z0-9_.-]+$#', $url) === 1;
}

/* array('front' => url|'', 'side' => ..., 'back' => ...) from request data (keys front/side/back or vehicle_photo_front/...), bad links dropped. */
function etel_vp_clean($in) {
    $out = array();
    foreach (etel_vp_sides() as $s) {
        $v = '';
        if (is_array($in)) { if (isset($in[$s])) $v = $in[$s]; elseif (isset($in['vehicle_photo_' . $s])) $v = $in['vehicle_photo_' . $s]; }
        $v = trim((string) $v);
        $out[$s] = etel_vp_url_ok($v) ? $v : '';
    }
    return $out;
}

/* For the staff desks: the three pictures from request data. A picture that is sent must be one this system stored (and still on disk), else the request is refused.
   Uses the global fail(); returns array(front, side, back) with '' for any not sent. */
function etel_vp_body_strict($body) {
    $out = etel_vp_clean($body);
    foreach (etel_vp_sides() as $s) {
        $sent = is_array($body) ? (isset($body[$s]) ? $body[$s] : (isset($body['vehicle_photo_' . $s]) ? $body['vehicle_photo_' . $s] : '')) : '';
        $sent = trim((string) $sent);
        if ($sent === '') continue;
        if ($out[$s] === '') fail('A vehicle picture is not valid. Upload it again.', 400);
        $path = parse_url($out[$s], PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#/uploads/vehicle_photos/([A-Za-z0-9_.-]+)$#', $path, $m) || !is_file(dirname(__DIR__, 2) . '/uploads/vehicle_photos/' . $m[1])) fail('A vehicle picture was not found on the server. Upload it again.', 400);
    }
    return $out;
}

function etel_vp_complete($urls) { foreach (etel_vp_sides() as $s) { if (empty($urls[$s])) return false; } return true; }

/* Saves the given sides on the person's row. $kind = 'courier' | 'driver'. Only the sides that are not empty are changed. */
function etel_vp_save($pdo, $kind, $userId, $urls) {
    etel_vp_ensure($pdo);
    $set = array(); $val = array();
    foreach (etel_vp_sides() as $s) { if (!empty($urls[$s])) { $set[] = "vehicle_photo_$s = ?"; $val[] = $urls[$s]; } }
    if (!$set) return false;
    $userId = intval($userId);
    if ($kind === 'courier') {
        $pdo->prepare('INSERT IGNORE INTO courier_details (user_id) VALUES (?)')->execute(array($userId));
        $val[] = $userId;
        $pdo->prepare('UPDATE courier_details SET ' . implode(', ', $set) . ' WHERE user_id = ?')->execute($val);
    } else {
        $pdo->prepare('INSERT IGNORE INTO ride_drivers (user_id) VALUES (?)')->execute(array($userId));
        $val[] = $userId;
        $pdo->prepare('UPDATE ride_drivers SET ' . implode(', ', $set) . ' WHERE user_id = ?')->execute($val);
    }
    return true;
}

/* The saved pictures of a person: array(front, side, back) with '' for any missing. */
function etel_vp_get($pdo, $kind, $userId) {
    etel_vp_ensure($pdo);
    $out = array('front' => '', 'side' => '', 'back' => '');
    try {
        $t = $kind === 'courier' ? 'courier_details' : 'ride_drivers';
        $q = $pdo->prepare("SELECT vehicle_photo_front, vehicle_photo_side, vehicle_photo_back FROM $t WHERE user_id = ? LIMIT 1");
        $q->execute(array(intval($userId)));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) foreach (etel_vp_sides() as $s) $out[$s] = (string) $r['vehicle_photo_' . $s];
    } catch (Exception $e) { }
    return $out;
}

}
