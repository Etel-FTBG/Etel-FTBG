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

/**
 * Save an uploaded vehicle-type picture to uploads/vehicle-types/.
 * Returns ['path' => 'uploads/vehicle-types/xxx.png'] or ['error' => '...'] or [] when no file was sent.
 */
function saveVehicleTypeImage($key = 'image') {
    if (empty($_FILES[$key]) || ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [];
    $f = $_FILES[$key];
    if ($f['error'] !== UPLOAD_ERR_OK) return ['error' => 'Upload failed - try a smaller picture'];
    if ($f['size'] > 4 * 1024 * 1024) return ['error' => 'Picture too large (max 4 MB)'];
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $map[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($map[$info[2]])) return ['error' => 'Use a PNG, JPG or WEBP picture'];
    $dir = __DIR__ . '/../uploads/vehicle-types/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['error' => 'Cannot create uploads folder'];
    $name = 'vt_' . date('ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $map[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return ['error' => 'Failed to save picture'];
    return ['path' => 'uploads/vehicle-types/' . $name];
}


/** One-time: add the extra vehicle types (van, bus) the stations asked for. Marker file keeps deleted ones deleted. */
function ensureExtraVehicleTypes() {
    $flag = __DIR__ . '/../uploads/.extra_types_v1';
    if (file_exists($flag)) return;
    try {
        $want = [['van', 'ቫን', 7], ['bus', 'አውቶቡስ', 8]];
        foreach ($want as $w) {
            $has = db()->fetchOne("SELECT id FROM vehicle_types WHERE LOWER(name) = ?", [$w[0]]);
            if (!$has) {
                db()->insert('vehicle_types', ['name' => $w[0], 'name_am' => $w[1], 'icon' => '', 'sort_order' => $w[2]]);
            }
        }
        if (!is_dir(dirname($flag))) @mkdir(dirname($flag), 0755, true);
        @file_put_contents($flag, date('c'));
    } catch (Exception $e) { error_log('ensureExtraVehicleTypes: ' . $e->getMessage()); }
}


/** Self-heal: per-vehicle-type fuel limit = how many days must pass between two fills (1 = once a day, 0 = no limit). */
function ensureCooldownColumn() {
    static $done = false; if ($done) return; $done = true;
    try {
        $has = db()->fetchOne("SHOW COLUMNS FROM vehicle_types LIKE 'cooldown_days'");
        if (!$has) db()->query("ALTER TABLE vehicle_types ADD COLUMN cooldown_days SMALLINT UNSIGNED NOT NULL DEFAULT 1");
    } catch (Exception $e) { error_log('ensureCooldownColumn: ' . $e->getMessage()); }
}
