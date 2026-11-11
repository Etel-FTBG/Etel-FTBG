<?php
/**
 * Etel Madeya - driver account (EtelID): saved vehicles and personal fuel history.
 * A driver who signed in with EtelID can save up to 5 plates. One plate belongs to one driver account (an admin can unlink it),
 * so nobody can read another driver's fuel history just by typing that plate.
 */
function ensureDriverVehicles() {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->query("CREATE TABLE IF NOT EXISTS driver_vehicles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_key VARCHAR(30) NOT NULL,
            plate_number VARCHAR(40) NOT NULL,
            vehicle_type_id INT UNSIGNED NOT NULL,
            nickname VARCHAR(40) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_dv_plate (plate_number),
            KEY idx_dv_member (member_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('ensureDriverVehicles: ' . $e->getMessage()); }
}

/** Stable key of the signed-in driver (digits of the EtelID phone), or '' when not signed in. */
function madeyaMemberKey() {
    $m = madeyaDriverMember();
    if (!$m) return '';
    return substr(preg_replace('/\D+/', '', (string)$m['phone']), -12);
}

/** Last fuel fill of a plate for a vehicle type + when it may fuel again (null = no fill on record). */
function madeyaDriverVehicleStatus($plate, $typeId) {
    $vars = madeyaPlateVariants($plate);
    $ph = implode(',', array_fill(0, count($vars), '?'));
    $last = db()->fetchOne("SELECT r.report_date, r.served_at, COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters) AS liters, c.station_name
                            FROM report r JOIN controllers c ON c.id = r.controller_id JOIN vehicle_types vt ON vt.id = r.vehicle_type_id
                            WHERE UPPER(r.plate_number) IN ($ph) ORDER BY r.report_date DESC, r.id DESC LIMIT 1", $vars);
    $out = ['last' => null, 'next_date' => null, 'can_fuel_now' => true, 'cooldown_days' => madeyaFuelLimitDays($typeId)];
    if ($last) {
        $out['last'] = ['date' => $last['report_date'], 'station' => $last['station_name'], 'liters' => $last['liters'] === null ? null : (float)$last['liters']];
        if ($out['cooldown_days'] > 0) {
            $out['next_date'] = date('Y-m-d', strtotime($last['report_date'] . ' +' . $out['cooldown_days'] . ' days'));
            $out['can_fuel_now'] = date('Y-m-d') >= $out['next_date'];
        }
    }
    return $out;
}

/** Where the plate stands in a queue right now, or null. */
function madeyaDriverQueueNow($plate) {
    $vars = madeyaPlateVariants($plate);
    $ph = implode(',', array_fill(0, count($vars), '?'));
    $q = db()->fetchOne("SELECT q.controller_id, q.queue_type, q.batch_id, q.position, c.station_name FROM queue q JOIN controllers c ON c.id = q.controller_id
                         WHERE UPPER(q.plate_number) IN ($ph) AND q.status = 'queued' ORDER BY q.registered_at DESC LIMIT 1", $vars);
    if (!$q) return null;
    $ahead = db()->fetchOne("SELECT COUNT(*) a FROM queue WHERE controller_id = ? AND status = 'queued' AND queue_type = 'normal' AND (batch_id < ? OR (batch_id = ? AND position < ?))",
        [$q['controller_id'], $q['batch_id'], $q['batch_id'], $q['position']]);
    return ['station' => $q['station_name'], 'queue_type' => $q['queue_type'], 'position' => (int)$ahead['a'] + 1];
}
