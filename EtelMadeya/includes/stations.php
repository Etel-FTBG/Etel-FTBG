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


/** Self-heal: how many liters a vehicle type's tank holds (used to estimate liters served per day / station). */
function ensureTankColumn() {
    static $done = false; if ($done) return; $done = true;
    try {
        $has = db()->fetchOne("SHOW COLUMNS FROM vehicle_types LIKE 'tank_liters'");
        if (!$has) db()->query("ALTER TABLE vehicle_types ADD COLUMN tank_liters DECIMAL(7,1) NULL DEFAULT NULL");
    } catch (Exception $e) { error_log('ensureTankColumn: ' . $e->getMessage()); }
}

/**
 * Estimated liters for a day. A served row counts its recorded fuel_amount_liters when the controller entered one,
 * otherwise the vehicle type's tank size. $controllerId = null -> all stations.
 * Returns served, liters, avg (liters per vehicle that has a known size), unknown (served vehicles with no size),
 * queued, queue_liters (what the vehicles now waiting would take) and by_type rows.
 */
function madeyaLiterStats($controllerId = null, $date = null) {
    ensureTankColumn();
    $date = $date ?: date('Y-m-d');
    $w = ' WHERE r.report_date = ?'; $p = [$date];
    if ($controllerId) { $w .= ' AND r.controller_id = ?'; $p[] = (int)$controllerId; }
    $rows = db()->fetchAll(
        "SELECT vt.id, vt.name, vt.tank_liters, COUNT(*) n,
                SUM(COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters, 0)) l,
                SUM(CASE WHEN COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters) IS NULL THEN 1 ELSE 0 END) unk
         FROM report r JOIN vehicle_types vt ON vt.id = r.vehicle_type_id" . $w . " GROUP BY vt.id, vt.name, vt.tank_liters ORDER BY n DESC", $p);
    $served = 0; $liters = 0.0; $unknown = 0; $by = [];
    foreach ($rows as $r) {
        $served += (int)$r['n']; $liters += (float)$r['l']; $unknown += (int)$r['unk'];
        $by[$r['id']] = ['id' => (int)$r['id'], 'name' => $r['name'], 'tank_liters' => $r['tank_liters'] === null ? null : (float)$r['tank_liters'], 'served' => (int)$r['n'], 'liters' => round((float)$r['l'], 1), 'queued' => 0, 'queue_liters' => 0.0];
    }
    $qw = " WHERE q.status = 'queued'"; $qp = [];
    if ($controllerId) { $qw .= ' AND q.controller_id = ?'; $qp[] = (int)$controllerId; }
    $queued = 0; $qLit = 0.0;
    foreach (db()->fetchAll("SELECT vt.id, vt.name, vt.tank_liters, COUNT(*) n FROM queue q JOIN vehicle_types vt ON vt.id = q.vehicle_type_id" . $qw . " GROUP BY vt.id, vt.name, vt.tank_liters", $qp) as $r) {
        $queued += (int)$r['n']; $lit = (float)$r['tank_liters'] * (int)$r['n']; $qLit += $lit;
        if (!isset($by[$r['id']])) $by[$r['id']] = ['id' => (int)$r['id'], 'name' => $r['name'], 'tank_liters' => $r['tank_liters'] === null ? null : (float)$r['tank_liters'], 'served' => 0, 'liters' => 0.0, 'queued' => 0, 'queue_liters' => 0.0];
        $by[$r['id']]['queued'] = (int)$r['n']; $by[$r['id']]['queue_liters'] = round($lit, 1);
    }
    $known = $served - $unknown;
    return ['date' => $date, 'served' => $served, 'liters' => round($liters, 1), 'avg' => $known > 0 ? round($liters / $known, 1) : 0, 'unknown' => $unknown,
            'queued' => $queued, 'queue_liters' => round($qLit, 1), 'by_type' => array_values($by)];
}

/** Per-station numbers for the admin dashboard. */
function madeyaStationLiters($date = null) {
    ensureTankColumn();
    $date = $date ?: date('Y-m-d');
    $out = [];
    foreach (db()->fetchAll(
        "SELECT r.controller_id cid, COUNT(*) n, SUM(COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters, 0)) l,
                SUM(CASE WHEN COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters) IS NULL THEN 0 ELSE 1 END) k
         FROM report r JOIN vehicle_types vt ON vt.id = r.vehicle_type_id WHERE r.report_date = ? GROUP BY r.controller_id", [$date]) as $r) {
        $out[(int)$r['cid']] = ['served' => (int)$r['n'], 'liters' => round((float)$r['l'], 1), 'avg' => (int)$r['k'] > 0 ? round((float)$r['l'] / (int)$r['k'], 1) : 0, 'queue_liters' => 0.0];
    }
    foreach (db()->fetchAll("SELECT q.controller_id cid, SUM(vt.tank_liters) l FROM queue q JOIN vehicle_types vt ON vt.id = q.vehicle_type_id WHERE q.status = 'queued' GROUP BY q.controller_id") as $r) {
        $c = (int)$r['cid']; if (!isset($out[$c])) $out[$c] = ['served' => 0, 'liters' => 0.0, 'avg' => 0, 'queue_liters' => 0.0];
        $out[$c]['queue_liters'] = round((float)$r['l'], 1);
    }
    return $out;
}


/** Self-heal: the plate registry = which vehicle type a plate (category + region + number) was FIRST registered under. */
function ensurePlateRegistry() {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->query("CREATE TABLE IF NOT EXISTS plate_registry (
            plate_number VARCHAR(40) NOT NULL PRIMARY KEY,
            vehicle_type_id INT UNSIGNED NOT NULL,
            controller_id INT UNSIGNED NULL,
            station_name VARCHAR(120) NULL,
            first_registered_at DATETIME NOT NULL,
            INDEX idx_pr_type (vehicle_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('ensurePlateRegistry: ' . $e->getMessage()); }
}

/** Every spelling of the same plate: with any category prefix (1-5) or none. "2-SD-10181" -> region+number "SD-10181". */
function madeyaPlateVariants($plate) {
    $core = preg_replace('/^[1-5]-/', '', strtoupper(trim((string)$plate)));
    $v = [$core];
    for ($i = 1; $i <= 5; $i++) $v[] = $i . '-' . $core;
    return array_values(array_unique($v));
}

/**
 * The first registration of a plate, or null when the plate is new.
 * The identity is region + number: using the same number with another plate category does not make it a new plate.
 * Older plates (registered before this registry existed) are found from the report / queue history and saved.
 */
function madeyaPlateOwner($plate) {
    ensurePlateRegistry();
    $plate = strtoupper(trim((string)$plate));
    if ($plate === '') return null;
    $vars = madeyaPlateVariants($plate);
    $ph = implode(',', array_fill(0, count($vars), '?'));
    $row = db()->fetchOne(
        "SELECT pr.plate_number, pr.vehicle_type_id, pr.station_name, pr.first_registered_at, vt.name AS vehicle_type_name
         FROM plate_registry pr LEFT JOIN vehicle_types vt ON vt.id = pr.vehicle_type_id
         WHERE pr.plate_number IN ($ph) ORDER BY pr.first_registered_at ASC LIMIT 1", $vars);
    if ($row) return $row;
    $cands = [];
    $r = db()->fetchOne("SELECT r.plate_number, r.vehicle_type_id, r.controller_id, COALESCE(r.registered_at, r.served_at) AS t, c.station_name FROM report r LEFT JOIN controllers c ON c.id = r.controller_id WHERE UPPER(r.plate_number) IN ($ph) ORDER BY t ASC LIMIT 1", $vars);
    if ($r) $cands[] = $r;
    $q = db()->fetchOne("SELECT q.plate_number, q.vehicle_type_id, q.controller_id, q.registered_at AS t, c.station_name FROM queue q LEFT JOIN controllers c ON c.id = q.controller_id WHERE UPPER(q.plate_number) IN ($ph) ORDER BY q.registered_at ASC LIMIT 1", $vars);
    if ($q) $cands[] = $q;
    if (!$cands) return null;
    usort($cands, function ($a, $b) { return strcmp((string)$a['t'], (string)$b['t']); });
    $f = $cands[0];
    $when = $f['t'] ?: date('Y-m-d H:i:s');
    $key = strtoupper(trim($f['plate_number']));
    db()->query("INSERT IGNORE INTO plate_registry (plate_number, vehicle_type_id, controller_id, station_name, first_registered_at) VALUES (?,?,?,?,?)",
        [$key, (int)$f['vehicle_type_id'], $f['controller_id'] ? (int)$f['controller_id'] : null, $f['station_name'], $when]);
    return madeyaPlateOwner($plate);
}

/** Save the first registration of a plate (no-op if it already has one). */
function madeyaPlateRemember($plate, $vehicleTypeId, $controllerId) {
    ensurePlateRegistry();
    $plate = strtoupper(trim((string)$plate));
    if ($plate === '' || !$vehicleTypeId) return;
    $st = db()->fetchOne("SELECT station_name FROM controllers WHERE id = ?", [(int)$controllerId]);
    db()->query("INSERT IGNORE INTO plate_registry (plate_number, vehicle_type_id, controller_id, station_name, first_registered_at) VALUES (?,?,?,?,?)",
        [$plate, (int)$vehicleTypeId, (int)$controllerId, $st ? $st['station_name'] : null, date('Y-m-d H:i:s')]);
}

/** The refusal payload shown to the controller when a plate is used under another vehicle type. */
function madeyaWrongTypeResult($plate, $owner, $requestedTypeId) {
    $req = db()->fetchOne("SELECT name FROM vehicle_types WHERE id = ?", [(int)$requestedTypeId]);
    $reqName = $req ? $req['name'] : 'this vehicle type';
    $ownName = $owner['vehicle_type_name'] ?: 'another vehicle type';
    $ts  = strtotime($owner['first_registered_at']);
    $day = date('D d M Y', $ts); $time = date('H:i', $ts);
    $stn = $owner['station_name'] ?: 'unknown station';
    return [
        'success'        => false,
        'code'           => 'WRONG_VEHICLE_TYPE',
        'message'        => "WARNING: plate {$plate} does not belong to {$reqName}. It was first registered as {$ownName} (plate {$owner['plate_number']}) on {$day} at {$time} at {$stn}.",
        'plate'          => $plate,
        'first_plate'    => $owner['plate_number'],
        'requested_type' => $reqName,
        'owner_type'     => $ownName,
        'first_day'      => $day,
        'first_time'     => $time,
        'first_station'  => $stn,
        'http_code'      => 400,
    ];
}


/** Self-heal: audit log of every plate registry correction (who changed what). */
function ensurePlateLog() {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->query("CREATE TABLE IF NOT EXISTS plate_registry_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NULL,
            admin_name VARCHAR(120) NULL,
            action VARCHAR(20) NOT NULL,
            old_plate VARCHAR(40) NULL,
            new_plate VARCHAR(40) NULL,
            old_type VARCHAR(100) NULL,
            new_type VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_prl_time (created_at),
            INDEX idx_prl_plate (old_plate, new_plate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('ensurePlateLog: ' . $e->getMessage()); }
}

function madeyaVehicleTypeName($id) {
    if (!$id) return null;
    $r = db()->fetchOne("SELECT name FROM vehicle_types WHERE id = ?", [(int)$id]);
    return $r ? $r['name'] : null;
}

/** Write one audit row. action: edit | bulk | release */
function madeyaPlateLog($action, $oldPlate, $newPlate, $oldType, $newType) {
    ensurePlateLog();
    try {
        db()->query("INSERT INTO plate_registry_log (admin_id, admin_name, action, old_plate, new_plate, old_type, new_type, created_at) VALUES (?,?,?,?,?,?,?,?)",
            [isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null, isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : null,
             $action, $oldPlate, $newPlate, $oldType, $newType, date('Y-m-d H:i:s')]);
    } catch (Exception $e) { error_log('madeyaPlateLog: ' . $e->getMessage()); }
}

/** Split a stored plate into [category, region, digits]. */
function madeyaPlateParts($plate) {
    $plate = strtoupper(trim((string)$plate));
    preg_match('/^(?:([1-5])-)?(?:([A-Z]{2})-)?(.+)$/', $plate, $m);
    return [isset($m[1]) ? $m[1] : '', isset($m[2]) ? $m[2] : '', isset($m[3]) ? $m[3] : $plate];
}
