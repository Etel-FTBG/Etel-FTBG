<?php
/**
 * Etel Madeya - station location / open-today columns (self-healing, no manual migration).
 * Adds latitude, longitude, is_open and open_hours to `controllers` the first time it is needed.
 */
function ensureStationColumns() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = [];
        foreach (db()->fetchAll("SHOW COLUMNS FROM controllers") as $c) $have[$c['Field']] = true;
        if (empty($have['latitude']))   db()->query("ALTER TABLE controllers ADD COLUMN latitude DECIMAL(10,7) NULL");
        if (empty($have['longitude']))  db()->query("ALTER TABLE controllers ADD COLUMN longitude DECIMAL(10,7) NULL");
        if (empty($have['is_open']))    db()->query("ALTER TABLE controllers ADD COLUMN is_open TINYINT(1) NOT NULL DEFAULT 1");
        if (empty($have['open_hours'])) db()->query("ALTER TABLE controllers ADD COLUMN open_hours VARCHAR(60) NULL");
    } catch (Exception $e) {
        error_log('ensureStationColumns: ' . $e->getMessage());
    }
}

/** Parse a posted coordinate: returns float or null when blank/out of range. */
function parseCoord($v, $min, $max) {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    return ($f >= $min && $f <= $max) ? $f : null;
}

/** vehicle_types.icon was VARCHAR(10); widen it so a picture file name (e.g. minibus.png) fits. */
function ensureVehicleIconLen() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c = db()->fetchOne("SHOW COLUMNS FROM vehicle_types LIKE 'icon'");
        if ($c && preg_match('/varchar\((\d+)\)/i', $c['Type'], $m) && (int)$m[1] < 120) {
            db()->query("ALTER TABLE vehicle_types MODIFY icon VARCHAR(120) NULL");
        }
    } catch (Exception $e) {
        error_log('ensureVehicleIconLen: ' . $e->getMessage());
    }
}
