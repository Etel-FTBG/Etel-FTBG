<?php
// ============================================================
// Fake GPS checks for drivers (table driver_gps_flags, migration 116).
// Two signals, both only RECORDED unless the owner switches blocking on (Admin > Operations > Driver trust):
//   mock : the phone says the position comes from a mock-location app (sent by the ETEL phone app when it reports in the background)
//   jump : the position moved farther than a vehicle can travel since the last ping (works for every app, including the web driver app)
// With the switch on ("trust_block_fake_gps"), a driver with a mock sighting in the last 6 hours is put offline and cannot go online.
// A jump only ever warns: a bad GPS fix or a phone that was off for a while can look like one.
// Everything here fails quiet: a missing table or setting means "nothing flagged", never an error for the driver.
// ============================================================
if (!function_exists('gps_distance_m')) {

function gps_jump_min_m() { return 1500; }         // a move shorter than this is never a jump
function gps_jump_max_secs() { return 300; }       // after a longer silence a big move is normal (phone was off, tunnel, app closed)
function gps_jump_max_mps() { return 55; }         // about 200 km/h
function gps_block_hours() { return 6; }

function gps_distance_m($lat1, $lng1, $lat2, $lng2) {
    $r = 6371000.0;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = $p2 - $p1; $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/* A description of the jump when this ping is impossible given the last one, else null. $secs = seconds since the last ping. */
function gps_check_jump($prevLat, $prevLng, $secs, $lat, $lng) {
    if ($prevLat === null || $prevLng === null || $lat === null || $lng === null || $secs === null) return null;
    $secs = intval($secs);
    if ($secs < 1 || $secs > gps_jump_max_secs()) return null;
    $d = gps_distance_m(floatval($prevLat), floatval($prevLng), floatval($lat), floatval($lng));
    if ($d < gps_jump_min_m()) return null;
    $mps = $d / $secs;
    if ($mps <= gps_jump_max_mps()) return null;
    return 'moved ' . round($d / 1000, 1) . ' km in ' . $secs . ' s (' . round($mps * 3.6) . ' km/h)';
}

function gps_block_enabled($pdo) {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM app_hub_settings WHERE setting_key = 'trust_block_fake_gps'");
        $s->execute();
        return (string) $s->fetchColumn() === '1';
    } catch (Exception $e) { return false; }
}

/* Writes one sighting, at most one per driver and kind every 10 minutes. Returns true when a row was written. Never throws. */
function gps_record($pdo, $uid, $kind, $detail, $lat, $lng) {
    if (!in_array($kind, array('mock', 'jump'), true)) return false;
    try {
        $c = $pdo->prepare('SELECT 1 FROM driver_gps_flags WHERE driver_user_id = ? AND kind = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1');
        $c->execute(array(intval($uid), $kind));
        if ($c->fetchColumn()) return false;
        $detail = function_exists('mb_substr') ? mb_substr((string) $detail, 0, 160, 'UTF-8') : substr((string) $detail, 0, 160);
        $pdo->prepare('INSERT INTO driver_gps_flags (driver_user_id, kind, detail, lat, lng, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute(array(intval($uid), $kind, $detail, $lat, $lng));
        return true;
    } catch (Exception $e) { return false; }
}

/* True when this driver had a mock-location sighting in the last 6 hours. */
function gps_recent_mock($pdo, $uid) {
    try {
        $s = $pdo->prepare("SELECT 1 FROM driver_gps_flags WHERE driver_user_id = ? AND kind = 'mock' AND created_at > DATE_SUB(NOW(), INTERVAL " . gps_block_hours() . " HOUR) LIMIT 1");
        $s->execute(array(intval($uid)));
        return (bool) $s->fetchColumn();
    } catch (Exception $e) { return false; }
}

/* Drivers with sightings in the last $days days, newest first: counts per kind, the last sighting, online now. */
function gps_overview($pdo, $days = 7) {
    $days = max(1, intval($days));
    try {
        $rows = $pdo->query("SELECT f.driver_user_id, u.name, u.phone, COALESCE(rd.online, 0) AS online,
                                    SUM(f.kind = 'mock') AS mocks, SUM(f.kind = 'jump') AS jumps, MAX(f.created_at) AS last_at
                             FROM driver_gps_flags f JOIN users u ON u.id = f.driver_user_id LEFT JOIN ride_drivers rd ON rd.user_id = f.driver_user_id
                             WHERE f.created_at > DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
                             GROUP BY f.driver_user_id, u.name, u.phone, rd.online ORDER BY last_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return array(); }
    $out = array();
    foreach ($rows as $r) {
        $last = null;
        try { $q = $pdo->prepare('SELECT kind, detail FROM driver_gps_flags WHERE driver_user_id = ? ORDER BY id DESC LIMIT 1'); $q->execute(array($r['driver_user_id'])); $last = $q->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) { $last = null; }
        $out[] = array('user_id' => intval($r['driver_user_id']), 'name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'online' => intval($r['online']) === 1 ? 1 : 0,
                       'mocks' => intval($r['mocks']), 'jumps' => intval($r['jumps']), 'last_at' => (string) $r['last_at'],
                       'last_kind' => $last ? (string) $last['kind'] : '', 'last_detail' => $last ? (string) $last['detail'] : '');
    }
    return $out;
}

/* Removes a driver's sightings (support checked the phone). Returns how many rows went. */
function gps_clear($pdo, $uid) {
    try { $s = $pdo->prepare('DELETE FROM driver_gps_flags WHERE driver_user_id = ?'); $s->execute(array(intval($uid))); return $s->rowCount(); }
    catch (Exception $e) { return 0; }
}

}
