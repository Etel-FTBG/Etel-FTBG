<?php
// Package delivery: a customer sends a parcel from one place to another, no shop involved (like a courier service).
//
//   customer picks pickup + drop-off + package details -> sees a price for each vehicle that can carry it -> chooses a vehicle
//   -> the order goes only to couriers who drive that kind of vehicle (bike, moto, car, van).
//
// A package is a normal row in `orders` with order_kind = 'package', so tracking, cancel, rating, payment, the courier list and the
// admin dispatch board all work on it. It has NO row in order_assignments: that table points at a shop (a foreign key on the live
// database), and a package has none. The code that reads it for shop orders treats a missing row as "no shop involved"
// (the courier job list lists packages by order_kind; the cancel rules take the order kind).
// Prices come from `package_rates` (edited in Admin), never from the client. Never throws out of the public functions that say so.

require_once __DIR__ . '/zones.php';

function etel_package_builtin_classes() {
    // order = size of vehicle, smallest first
    return array('bike' => 1, 'moto' => 2, 'car' => 3, 'van' => 4);
}

// Courier vehicle names each built-in class covers.
function etel_package_builtin_names() {
    return array(
        'bike' => array('bicycle', 'bike', 'cycle', 'on_foot', 'foot', 'walking'),
        'moto' => array('motorcycle', 'motorbike', 'moto', 'scooter'),
        'car'  => array('car', 'sedan', 'taxi', 'vehicle'),
        'van'  => array('van', 'pickup', 'pick-up', 'truck', 'minibus', 'lorry', 'cargo'),
    );
}

// The database to read added vehicle types from when the caller did not pass one: the last one the schema check saw, else db().
function etel_package_pdo($set = null) {
    static $last = null;
    if ($set) $last = $set;
    if ($last) return $last;
    return function_exists('db') ? db() : null;
}

// Vehicle types staff added in Admin (Package prices -> Add): class => array('rank' => int, 'names' => array of lowercase courier vehicle names).
// Read once per request. $reset = true forgets it (after an add or edit, and in tests). Never throws: no database = none.
function etel_package_custom_defs($pdo = null, $reset = false) {
    static $cache = null;
    if ($reset) { $cache = null; return array(); }
    if ($cache !== null) return $cache;
    $cache = array();
    try {
        if (!$pdo) $pdo = etel_package_pdo();
        if (!$pdo) { $cache = null; return array(); }
        etel_package_ensure_schema($pdo);
        $builtin = etel_package_builtin_classes();
        foreach ($pdo->query('SELECT vehicle_class, size_rank, courier_names FROM package_rates')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($builtin[$r['vehicle_class']])) continue;
            $names = array();
            foreach (explode(',', (string) $r['courier_names']) as $n) { $n = strtolower(trim($n)); if ($n !== '') $names[] = $n; }
            $cache[$r['vehicle_class']] = array('rank' => max(1, intval($r['size_rank'])), 'names' => $names);
        }
    } catch (Exception $e) { error_log('[ETEL] package custom types: ' . $e->getMessage()); $cache = null; return array(); }
    return $cache;
}

function etel_package_classes($pdo = null) {
    $out = etel_package_builtin_classes();
    foreach (etel_package_custom_defs($pdo) as $k => $d) $out[$k] = $d['rank'];
    return $out;
}

// Smallest vehicle that can carry a package of this size.
function etel_package_sizes() {
    return array(
        'document' => array('label' => 'Document or envelope', 'min_class' => 'bike'),
        'small'    => array('label' => 'Small (shoebox)',      'min_class' => 'bike'),
        'medium'   => array('label' => 'Medium (backpack)',    'min_class' => 'moto'),
        'large'    => array('label' => 'Large (box, suitcase)', 'min_class' => 'car'),
    );
}

// What a courier's free-text vehicle_type means for packages. Unknown or empty = motorbike (the default on sign-up).
function etel_vehicle_class_of($vehicle_type, $pdo = null) {
    $v = strtolower(trim((string) $vehicle_type));
    if ($v === '') return 'moto';
    foreach (etel_package_builtin_names() as $class => $names) { if (in_array($v, $names, true)) return $class; }
    foreach (etel_package_custom_defs($pdo) as $class => $d) { if (in_array($v, $d['names'], true)) return $class; }
    return '';
}

// SQL piece that keeps only couriers of one vehicle class: array(sql, params). $alias = the couriers table alias.
function etel_vehicle_sql($class, $alias = 'c', $pdo = null) {
    $names = etel_package_builtin_names();
    if (!isset($names[$class])) {
        $custom = etel_package_custom_defs($pdo);
        if (isset($custom[$class]) && $custom[$class]['names']) $names[$class] = $custom[$class]['names'];
    }
    if (!isset($names[$class])) return array(' AND 1 = 0', array());
    $marks = implode(',', array_fill(0, count($names[$class]), '?'));
    $sql = $alias . '.vehicle_type IS NOT NULL AND LOWER(' . $alias . '.vehicle_type) IN (' . $marks . ')';
    if ($class === 'moto') $sql = '(' . $alias . '.vehicle_type IS NULL OR ' . $alias . ".vehicle_type = '' OR LOWER(" . $alias . '.vehicle_type) IN (' . $marks . '))';
    return array(' AND ' . $sql, $names[$class]);
}

function etel_package_default_rates() {
    return array(
        array('bike', 'Bicycle',       "\xF0\x9F\x9A\xB2",  40,  8,   3, 5,  50,    5, 1),
        array('moto', 'Motorbike',     "\xF0\x9F\x9B\xB5",  60, 12,   5, 6,  80,   25, 2),
        array('car',  'Car',           "\xF0\x9F\x9A\x97", 150, 25,  20, 4, 200,  150, 3),
        array('van',  'Van / pickup',  "\xF0\x9F\x9A\x90", 350, 45, 100, 3, 450, 1000, 4),
    );
}

// Adds the columns and the rates table when the migration has not run, and fills the starting prices once. Safe to call often.
function etel_package_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    etel_package_pdo($pdo);
    try {
        $have = array();
        foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll() as $c) $have[$c['Field']] = true;
        $want = array(
            'order_kind' => "VARCHAR(12) NOT NULL DEFAULT 'shop'", 'vehicle_class' => 'VARCHAR(12) NULL DEFAULT NULL',
            'pickup_address' => 'VARCHAR(255) NULL DEFAULT NULL', 'pkg_size' => 'VARCHAR(12) NULL DEFAULT NULL',
            'pkg_weight_kg' => 'DECIMAL(7,2) NULL DEFAULT NULL', 'pkg_desc' => 'VARCHAR(255) NULL DEFAULT NULL',
            'pkg_value' => 'DECIMAL(10,2) NULL DEFAULT NULL', 'distance_km' => 'DECIMAL(6,2) NULL DEFAULT NULL',
            'pickup_pin' => 'VARCHAR(6) NULL DEFAULT NULL', 'delivery_pin' => 'VARCHAR(6) NULL DEFAULT NULL',
            'sender_name' => "VARCHAR(120) NULL DEFAULT ''", 'sender_phone' => "VARCHAR(25) NULL DEFAULT ''",
            'pickup_lat' => 'DECIMAL(10,7) NULL DEFAULT NULL', 'pickup_lng' => 'DECIMAL(10,7) NULL DEFAULT NULL',
            'recipient_address' => "VARCHAR(255) NULL DEFAULT ''",
        );
        $add = array();
        foreach ($want as $name => $def) { if (empty($have[$name])) $add[] = 'ADD COLUMN `' . $name . '` ' . $def; }
        if ($add) $pdo->exec('ALTER TABLE orders ' . implode(', ', $add));
        $pdo->exec("CREATE TABLE IF NOT EXISTS package_rates (
            id INT(11) NOT NULL AUTO_INCREMENT, vehicle_class VARCHAR(12) NOT NULL, label VARCHAR(60) NOT NULL, icon VARCHAR(8) NOT NULL DEFAULT '',
            base_fee DECIMAL(10,2) NOT NULL DEFAULT 0, per_km DECIMAL(10,2) NOT NULL DEFAULT 0, free_kg DECIMAL(7,2) NOT NULL DEFAULT 0,
            per_kg_over DECIMAL(10,2) NOT NULL DEFAULT 0, min_fee DECIMAL(10,2) NOT NULL DEFAULT 0, max_weight_kg DECIMAL(8,2) NOT NULL DEFAULT 0,
            sort_order INT(11) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_vehicle_class (vehicle_class)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Which couriers' apps can run a package job (they send pkg=1 while open). Older apps cannot show the pickup address or ask for the PINs,
        // so a package is never offered to them: it would be accepted and then be stuck.
        $courierCols = array();
        foreach ($pdo->query('SHOW COLUMNS FROM couriers')->fetchAll() as $c) $courierCols[$c['Field']] = true;
        if (empty($courierCols['package_capable_until'])) $pdo->exec('ALTER TABLE couriers ADD COLUMN package_capable_until DATETIME NULL DEFAULT NULL');
        // Wrong PIN tries at the door: 5 wrong in a row lock that step for 15 minutes.
        $pdo->exec("CREATE TABLE IF NOT EXISTS package_pin_tries (
            order_id INT NOT NULL, kind VARCHAR(10) NOT NULL, tries INT NOT NULL DEFAULT 0, locked_until DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (order_id, kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Small settings staff change in Admin (Package prices): for now the fee for cancelling after a courier has accepted.
        $pdo->exec("CREATE TABLE IF NOT EXISTS package_settings (
            setting_key VARCHAR(40) NOT NULL, setting_value VARCHAR(100) NOT NULL DEFAULT '', updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (setting_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Added vehicle types (Admin -> Package prices -> Add): where each sits in the size order and which courier vehicle names serve it.
        $rateCols = array();
        foreach ($pdo->query('SHOW COLUMNS FROM package_rates')->fetchAll() as $c) $rateCols[$c['Field']] = true;
        if (empty($rateCols['size_rank'])) $pdo->exec('ALTER TABLE package_rates ADD COLUMN size_rank TINYINT NOT NULL DEFAULT 0');
        if (empty($rateCols['courier_names'])) $pdo->exec("ALTER TABLE package_rates ADD COLUMN courier_names VARCHAR(200) NOT NULL DEFAULT ''");
        if (intval($pdo->query('SELECT COUNT(*) FROM package_rates')->fetchColumn()) === 0) {
            $ins = $pdo->prepare('INSERT IGNORE INTO package_rates (vehicle_class, label, icon, base_fee, per_km, free_kg, per_kg_over, min_fee, max_weight_kg, sort_order, active) VALUES (?,?,?,?,?,?,?,?,?,?,1)');
            foreach (etel_package_default_rates() as $r) $ins->execute($r);
        }
    } catch (Exception $e) { error_log('[ETEL] package schema: ' . $e->getMessage()); }
}

// All rates (or only active ones), smallest vehicle first.
function etel_package_rates($pdo, $only_active = true) {
    etel_package_ensure_schema($pdo);
    try {
        $sql = 'SELECT vehicle_class, label, icon, base_fee, per_km, free_kg, per_kg_over, min_fee, max_weight_kg, sort_order, active, size_rank, courier_names FROM package_rates'
             . ($only_active ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, id';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return array(); }
    $classes = etel_package_classes($pdo);
    $out = array();
    foreach ($rows as $r) { if (isset($classes[$r['vehicle_class']])) $out[] = $r; }
    return $out;
}

// Pure. Price in whole birr for one vehicle: base + per km, plus per kg above the free weight, never below the minimum.
function etel_package_price($rate, $distance_km, $weight_kg) {
    $fee = floatval($rate['base_fee']) + floatval($rate['per_km']) * floatval($distance_km);
    $over = floatval($weight_kg) - floatval($rate['free_kg']);
    if ($over > 0) $fee += floatval($rate['per_kg_over']) * $over;
    if ($fee < floatval($rate['min_fee'])) $fee = floatval($rate['min_fee']);
    return (int) round($fee);
}

// Pure. Road distance estimate: straight line x 1.3, at least 1 km.
function etel_package_distance_km($lat1, $lng1, $lat2, $lng2) {
    $km = etel_geo_km($lat1, $lng1, $lat2, $lng2) * 1.3;
    return round(max(1.0, $km), 1);
}

// Pure. Rough minutes door to door once a courier has the package: 10 minutes handling plus the ride at 20 km/h.
function etel_package_eta_min($distance_km) {
    return (int) (10 + round(floatval($distance_km) / 20 * 60));
}

// One end of the trip -> array(lat, lng, zone, address) or a string with what is wrong. Coordinates win; a known zone name is the fallback.
function etel_package_point($in, $what) {
    $in = is_array($in) ? $in : array();
    $addr = isset($in['address']) ? trim((string) $in['address']) : '';
    if ($addr === '' || strlen($addr) > 255) return 'Enter the ' . $what . ' address.';
    $lat = (isset($in['lat']) && is_numeric($in['lat'])) ? floatval($in['lat']) : null;
    $lng = (isset($in['lng']) && is_numeric($in['lng'])) ? floatval($in['lng']) : null;
    $zone = isset($in['zone']) ? trim((string) $in['zone']) : '';
    $centres = etel_zone_centroids();
    if ($lat !== null && $lng !== null && $lat > 3 && $lat < 15 && $lng > 33 && $lng < 48) {
        return array('lat' => $lat, 'lng' => $lng, 'zone' => etel_zone_nearest($lat, $lng), 'address' => $addr);
    }
    if ($zone !== '' && isset($centres[$zone])) {
        return array('lat' => $centres[$zone][0], 'lng' => $centres[$zone][1], 'zone' => $zone, 'address' => $addr);
    }
    return 'Pick the ' . $what . ' on the map or choose its area.';
}

// Pure. Checks the package details -> array(weight, size) cleaned, or an error string.
function etel_package_details($in) {
    $in = is_array($in) ? $in : array();
    $sizes = etel_package_sizes();
    $size = isset($in['size']) ? strtolower(trim((string) $in['size'])) : '';
    if (!isset($sizes[$size])) return 'Choose the package size.';
    if (!isset($in['weight_kg']) || !is_numeric($in['weight_kg'])) return 'Enter the package weight in kg.';
    $w = round(floatval($in['weight_kg']), 2);
    if ($w <= 0 || $w > 1000) return 'Enter a weight between 0.1 and 1000 kg.';
    return array('weight' => $w, 'size' => $size);
}

// Which vehicles can carry it, with price and time for each. $pickup/$drop are the raw inputs from the app.
// Returns array('ok' => true, 'distance_km', 'eta_min', 'options' => [...], 'pickup' => point, 'drop' => point, 'details' => ...) or array('ok' => false, 'message').
function etel_package_quote($pdo, $pickup_in, $drop_in, $details_in) {
    $p = etel_package_point($pickup_in, 'pickup');
    if (is_string($p)) return array('ok' => false, 'message' => $p);
    $d = etel_package_point($drop_in, 'drop-off');
    if (is_string($d)) return array('ok' => false, 'message' => $d);
    $det = etel_package_details($details_in);
    if (is_string($det)) return array('ok' => false, 'message' => $det);
    $km = etel_package_distance_km($p['lat'], $p['lng'], $d['lat'], $d['lng']);
    if ($km > 60) return array('ok' => false, 'message' => 'Packages go within Hawassa and nearby (up to 60 km). This trip is about ' . $km . ' km.');
    $classes = etel_package_classes($pdo);
    $sizes = etel_package_sizes();
    $minRank = $classes[$sizes[$det['size']]['min_class']];
    $options = array();
    foreach (etel_package_rates($pdo, true) as $r) {
        if ($classes[$r['vehicle_class']] < $minRank) continue;
        if (floatval($r['max_weight_kg']) < $det['weight']) continue;
        $options[] = array('vehicle_class' => $r['vehicle_class'], 'label' => $r['label'], 'icon' => $r['icon'],
            'price' => etel_package_price($r, $km, $det['weight']), 'max_weight_kg' => floatval($r['max_weight_kg']));
    }
    if (!$options) return array('ok' => false, 'message' => 'No vehicle can carry this package. Check the weight and size, or contact support.');
    return array('ok' => true, 'distance_km' => $km, 'eta_min' => etel_package_eta_min($km), 'options' => $options, 'pickup' => $p, 'drop' => $d, 'details' => $det);
}

function etel_package_pin() {
    return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

// Makes the order. $in: pickup{}, drop{}, size, weight_kg, vehicle_class, description, declared_value?, sender_name?, sender_phone?,
// recipient_name, recipient_phone, notes?, payment_method?, ack_prohibited (must be true), _allow_chapa?
// Returns array('ok' => true, order_id, order_code, total, ...) or array('ok' => false, 'message'). Never throws.
function etel_package_create($pdo, $user, $in) {
    require_once __DIR__ . '/../validate.php';
    try {
        etel_package_ensure_schema($pdo);
        if (!is_array($in)) return array('ok' => false, 'message' => 'Missing package details.');
        if (empty($in['ack_prohibited'])) return array('ok' => false, 'message' => 'Please confirm the package has no prohibited items.');
        $q = etel_package_quote($pdo, isset($in['pickup']) ? $in['pickup'] : null, isset($in['drop']) ? $in['drop'] : null, $in);
        if (!$q['ok']) return $q;
        $class = isset($in['vehicle_class']) ? strtolower(trim((string) $in['vehicle_class'])) : '';
        $chosen = null;
        foreach ($q['options'] as $o) { if ($o['vehicle_class'] === $class) $chosen = $o; }
        if (!$chosen) return array('ok' => false, 'message' => 'Choose a vehicle that can carry this package.');

        $desc = isset($in['description']) ? trim((string) $in['description']) : '';
        if ($desc === '' || strlen($desc) > 255) return array('ok' => false, 'message' => 'Say what is inside (a few words).');
        $value = (isset($in['declared_value']) && $in['declared_value'] !== '' && is_numeric($in['declared_value'])) ? round(max(0, floatval($in['declared_value'])), 2) : null;
        $rname = isset($in['recipient_name']) ? trim((string) $in['recipient_name']) : '';
        if ($rname === '' || strlen($rname) > 120) return array('ok' => false, 'message' => 'Enter the recipient name.');
        $rphone = etel_validate_phone(isset($in['recipient_phone']) ? $in['recipient_phone'] : '');
        if (!$rphone) return array('ok' => false, 'message' => 'Enter a valid Ethiopian mobile number for the recipient.');
        $sname = isset($in['sender_name']) ? trim((string) $in['sender_name']) : '';
        if ($sname === '') $sname = isset($user['name']) ? (string) $user['name'] : '';
        $sphone_in = isset($in['sender_phone']) && trim((string) $in['sender_phone']) !== '' ? $in['sender_phone'] : (isset($user['phone']) ? $user['phone'] : '');
        $sphone = etel_validate_phone($sphone_in);
        if (!$sphone) return array('ok' => false, 'message' => 'Enter a valid Ethiopian mobile number for the sender.');
        $notes = isset($in['notes']) ? substr(trim((string) $in['notes']), 0, 500) : '';

        $method = isset($in['payment_method']) ? trim((string) $in['payment_method']) : 'cash';
        $allowed = array('cash', 'telebirr', 'abysinia', 'cbe', 'wallet');
        if (!empty($in['_allow_chapa'])) $allowed[] = 'chapa';
        if (!in_array($method, $allowed, true)) $method = 'cash';
        if ($method === 'chapa') {
            // orders.payment_method is an ENUM on most installs: add 'chapa' once (same step as shop orders).
            $col = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'payment_method'")->fetch();
            if ($col && stripos($col['Type'], 'enum(') === 0 && stripos($col['Type'], "'chapa'") === false) {
                $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('cash','telebirr','abysinia','cbe','wallet','chapa') DEFAULT 'cash'");
            }
        }

        // orders.package_type is an ENUM('document','parcel','food','bulk') on the live database, so only those words fit; the real size is in pkg_size.
        $code = 'ETP-' . strtoupper(bin2hex(random_bytes(3)));
        $price = intval($chosen['price']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO orders (order_code, customer_id, from_zone, to_zone, package_type, delivery_tier, recipient_name, recipient_phone, recipient_address, notes,
                    subtotal, delivery_fee, service_charge, fare_total, payment_method, payment_status, status, delivery_lat, delivery_lng,
                    order_kind, vehicle_class, pickup_address, pkg_size, pkg_weight_kg, pkg_desc, pkg_value, distance_km, pickup_pin, delivery_pin,
                    sender_name, sender_phone, pickup_lat, pickup_lng, placed_at)
                VALUES (?,?,?,?,?,?,?,?,?,?, 0,?,0,?,?, 'unpaid','pending',?,?, 'package',?,?,?,?,?,?,?,?,?, ?,?,?,?, NOW())")
                ->execute(array($code, $user['id'], $q['pickup']['zone'], $q['drop']['zone'], ($q['details']['size'] === 'document' ? 'document' : 'parcel'), $class,
                    $rname, $rphone, $q['drop']['address'], $notes,
                    $price, $price, $method, $q['drop']['lat'], $q['drop']['lng'],
                    $class, $q['pickup']['address'], $q['details']['size'], $q['details']['weight'], $desc, $value, $q['distance_km'], etel_package_pin(), etel_package_pin(),
                    $sname, $sphone, $q['pickup']['lat'], $q['pickup']['lng']));
            $order_id = intval($pdo->lastInsertId());
            $pdo->commit();
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

        if ($method !== 'chapa') {   // Chapa packages wait for a verified payment, like shop orders (chapa_settle dispatches them)
            require_once __DIR__ . '/order_dispatch.php';
            etel_dispatch_order($pdo, $order_id);
        }
        return array('ok' => true, 'order_id' => $order_id, 'order_code' => $code, 'status' => 'pending', 'total' => $price, 'delivery_fee' => $price,
            'vehicle_class' => $class, 'distance_km' => $q['distance_km'], 'eta_min' => $q['eta_min'], 'payment_method' => $method, 'message' => 'Package booked.');
    } catch (Exception $e) {
        error_log('[ETEL] package create: ' . $e->getMessage());
        return array('ok' => false, 'message' => 'Could not book the package. Please try again.');
    }
}

// The pins only the sender (the customer who booked) may see: the courier asks for the pickup pin at the sender, the recipient gives the delivery pin.
function etel_package_public_fields($order) {
    $keys = array('order_kind', 'vehicle_class', 'pickup_address', 'pkg_size', 'pkg_weight_kg', 'pkg_desc', 'distance_km', 'sender_name');
    $out = array();
    foreach ($keys as $k) { if (isset($order[$k])) $out[$k] = $order[$k]; }
    return $out;
}

// Admin: the price fields shared by "save" and "add". Returns array(ok, vals) or array(ok=false, message).
function etel_package_rate_fields($r) {
    $num = function ($k, $max) use ($r) {
        if (!isset($r[$k]) || !is_numeric($r[$k])) return false;
        $v = round(floatval($r[$k]), 2);
        return ($v < 0 || $v > $max) ? false : $v;
    };
    $base = $num('base_fee', 100000); $km = $num('per_km', 10000); $free = $num('free_kg', 1000);
    $over = $num('per_kg_over', 10000); $min = $num('min_fee', 100000); $max = $num('max_weight_kg', 5000);
    foreach (array('Base fee' => $base, 'Price per km' => $km, 'Free weight' => $free, 'Price per extra kg' => $over, 'Minimum fee' => $min, 'Maximum weight' => $max) as $name => $v) {
        if ($v === false) return array('ok' => false, 'message' => $name . ' must be a number from 0 up.');
    }
    if ($max <= 0) return array('ok' => false, 'message' => 'Maximum weight must be more than 0.');
    $label = isset($r['label']) ? trim((string) $r['label']) : '';
    if ($label === '' || strlen($label) > 60) return array('ok' => false, 'message' => 'Give the vehicle a name (up to 60 characters).');
    $icon = isset($r['icon']) ? trim((string) $r['icon']) : '';
    if (strlen($icon) > 8) $icon = '';
    return array('ok' => true, 'base' => $base, 'km' => $km, 'free' => $free, 'over' => $over, 'min' => $min, 'max' => $max,
        'label' => $label, 'icon' => $icon, 'active' => !empty($r['active']) ? 1 : 0);
}

// Courier vehicle names for an added type: "bajaj, tuk-tuk" -> array. $class = the type being edited (its own names may stay).
// Returns array(ok, names) or array(ok=false, message). Every name must be new: a name that already means another vehicle would send jobs to the wrong couriers.
function etel_package_parse_names($pdo, $text, $class = '') {
    $names = array();
    foreach (preg_split('/[,\n]+/', (string) $text) as $n) {
        $n = strtolower(trim($n));
        if ($n === '') continue;
        if (!preg_match('/^[a-z0-9][a-z0-9 _-]{1,29}$/', $n)) return array('ok' => false, 'message' => '"' . $n . '" is not a usable vehicle word (2 to 30 letters or numbers).');
        $names[$n] = true;
    }
    $names = array_keys($names);
    if (!$names) return array('ok' => false, 'message' => 'Type at least one vehicle word, the one couriers choose for their vehicle (for example: bajaj).');
    if (count($names) > 8) return array('ok' => false, 'message' => 'Use up to 8 vehicle words.');
    $taken = array();
    foreach (etel_package_builtin_names() as $k => $list) foreach ($list as $n) $taken[$n] = $k;
    foreach (etel_package_custom_defs($pdo) as $k => $d) { if ($k !== $class) foreach ($d['names'] as $n) $taken[$n] = $k; }
    foreach ($names as $n) {
        if (isset($taken[$n])) return array('ok' => false, 'message' => '"' . $n . '" already belongs to another vehicle type. Use a different word.');
    }
    return array('ok' => true, 'names' => $names);
}

// $like = which built-in vehicle the new type carries about as much as: bike | moto | car | van. Returns its rank, or 0.
function etel_package_rank_of($like) {
    $b = etel_package_builtin_classes();
    $like = strtolower(trim((string) $like));
    return isset($b[$like]) ? $b[$like] : 0;
}

// Admin: save one vehicle's rate. $r keys: label, icon, base_fee, per_km, free_kg, per_kg_over, min_fee, max_weight_kg, active
// (and, for a vehicle type staff added: size_like, courier_names). Returns array(ok, message).
function etel_package_save_rate($pdo, $class, $r) {
    etel_package_ensure_schema($pdo);
    $classes = etel_package_classes($pdo);
    if (!isset($classes[$class])) return array('ok' => false, 'message' => 'Unknown vehicle.');
    $f = etel_package_rate_fields($r);
    if (!$f['ok']) return $f;
    $custom = !isset(etel_package_builtin_classes()[$class]);
    $extraSql = ''; $extra = array();
    if ($custom) {
        if (isset($r['courier_names'])) {
            $n = etel_package_parse_names($pdo, $r['courier_names'], $class);
            if (!$n['ok']) return $n;
            $extraSql .= ', courier_names=?'; $extra[] = implode(', ', $n['names']);
        }
        if (isset($r['size_like'])) {
            $rank = etel_package_rank_of($r['size_like']);
            if (!$rank) return array('ok' => false, 'message' => 'Pick which vehicle it is closest in size to.');
            $extraSql .= ', size_rank=?, sort_order=?'; $extra[] = $rank; $extra[] = $rank;
        }
    }
    try {
        $pdo->prepare('UPDATE package_rates SET label=?, icon=?, base_fee=?, per_km=?, free_kg=?, per_kg_over=?, min_fee=?, max_weight_kg=?, active=?, updated_at=NOW()' . $extraSql . ' WHERE vehicle_class=?')
            ->execute(array_merge(array($f['label'], $f['icon'], $f['base'], $f['km'], $f['free'], $f['over'], $f['min'], $f['max'], $f['active']), $extra, array($class)));
    } catch (Exception $e) { error_log('[ETEL] package rate save: ' . $e->getMessage()); return array('ok' => false, 'message' => 'Could not save the price.'); }
    etel_package_custom_defs(null, true);
    return array('ok' => true, 'message' => 'Saved.');
}

// Admin: add a vehicle type of our own (for example a Bajaj or a mini truck). $r: the price fields plus size_like and courier_names.
// The new type gets the class key x1, x2, ... (short, never reused). Returns array(ok, message, vehicle_class).
function etel_package_add_type($pdo, $r) {
    etel_package_ensure_schema($pdo);
    $f = etel_package_rate_fields($r);
    if (!$f['ok']) return $f;
    $rank = etel_package_rank_of(isset($r['size_like']) ? $r['size_like'] : '');
    if (!$rank) return array('ok' => false, 'message' => 'Pick which vehicle it is closest in size to.');
    $n = etel_package_parse_names($pdo, isset($r['courier_names']) ? $r['courier_names'] : '');
    if (!$n['ok']) return $n;
    try {
        $count = intval($pdo->query("SELECT COUNT(*) FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')")->fetchColumn());
        if ($count >= 12) return array('ok' => false, 'message' => 'You already have 12 added vehicle types. Switch one off instead.');
        $key = '';
        for ($i = $count + 1; $i <= $count + 30; $i++) {
            $try = 'x' . $i;
            $ex = $pdo->prepare('SELECT 1 FROM package_rates WHERE vehicle_class=?'); $ex->execute(array($try));
            if (!$ex->fetchColumn()) { $key = $try; break; }
        }
        if ($key === '') return array('ok' => false, 'message' => 'Could not add the vehicle type.');
        $pdo->prepare('INSERT INTO package_rates (vehicle_class, label, icon, base_fee, per_km, free_kg, per_kg_over, min_fee, max_weight_kg, sort_order, active, size_rank, courier_names, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute(array($key, $f['label'], $f['icon'], $f['base'], $f['km'], $f['free'], $f['over'], $f['min'], $f['max'], $rank, $f['active'], $rank, implode(', ', $n['names'])));
    } catch (Exception $e) { error_log('[ETEL] package add type: ' . $e->getMessage()); return array('ok' => false, 'message' => 'Could not add the vehicle type.'); }
    etel_package_custom_defs(null, true);
    return array('ok' => true, 'message' => 'Added. Couriers whose vehicle is set to ' . implode(' or ', $n['names']) . ' will now get these jobs.', 'vehicle_class' => $key);
}

// Package settings staff can change. key => default. late_cancel_fee = ETB the sender pays for cancelling after a courier accepted
// (only taken when the package was paid online: it is kept out of the refund). 0 = cancelling is always free.
function etel_package_setting_defaults() { return array('late_cancel_fee' => 30); }

function etel_package_setting($pdo, $key) {
    $def = etel_package_setting_defaults();
    if (!isset($def[$key])) return null;
    try {
        etel_package_ensure_schema($pdo);
        $q = $pdo->prepare('SELECT setting_value FROM package_settings WHERE setting_key = ?'); $q->execute(array($key));
        $v = $q->fetchColumn();
        if ($v !== false && is_numeric($v)) return floatval($v);
    } catch (Exception $e) { error_log('[ETEL] package setting: ' . $e->getMessage()); }
    return floatval($def[$key]);
}

function etel_package_settings($pdo) {
    $out = array();
    foreach (etel_package_setting_defaults() as $k => $d) $out[$k] = etel_package_setting($pdo, $k);
    return $out;
}

// Admin: save one setting. Returns array(ok, message).
function etel_package_save_setting($pdo, $key, $value) {
    $def = etel_package_setting_defaults();
    if (!isset($def[$key])) return array('ok' => false, 'message' => 'Unknown setting.');
    if (!is_numeric($value) || floatval($value) < 0 || floatval($value) > 5000) return array('ok' => false, 'message' => 'The fee must be a number from 0 to 5000 (0 = always free).');
    $v = round(floatval($value), 2);
    try {
        etel_package_ensure_schema($pdo);
        $pdo->prepare('INSERT INTO package_settings (setting_key, setting_value, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()')
            ->execute(array($key, (string) $v));
    } catch (Exception $e) { error_log('[ETEL] package setting save: ' . $e->getMessage()); return array('ok' => false, 'message' => 'Could not save.'); }
    return array('ok' => true, 'message' => 'Saved.');
}

// ---------- the courier's side of a package: who can take it, and the PIN steps at pickup and delivery ----------

// The courier's app reported (pkg=1 on its polls) that it can run package jobs. Valid for 90 seconds, like offers.
function etel_package_mark_capable($pdo, $courier_id) {
    try { etel_package_ensure_schema($pdo); $pdo->prepare('UPDATE couriers SET package_capable_until = DATE_ADD(NOW(), INTERVAL 90 SECOND) WHERE id = ?')->execute(array(intval($courier_id))); }
    catch (Exception $e) { error_log('[ETEL] package mark capable: ' . $e->getMessage()); }
}

function etel_package_courier_capable($pdo, $courier_id) {
    try {
        etel_package_ensure_schema($pdo);
        $q = $pdo->prepare('SELECT 1 FROM couriers WHERE id = ? AND package_capable_until IS NOT NULL AND package_capable_until > NOW()');
        $q->execute(array(intval($courier_id)));
        return (bool) $q->fetchColumn();
    } catch (Exception $e) { return false; }
}

// SQL piece for the courier picks: only couriers whose app can run packages.
// SQL piece: leave out the courier whose own account placed the order (an EtelID can be a customer and a courier at once).
function etel_not_own_order_sql($customer_id, $alias = 'c') {
    return ' AND ' . $alias . '.user_id <> ' . intval($customer_id);
}

function etel_package_capable_sql($alias = 'c') {
    return ' AND ' . $alias . '.package_capable_until IS NOT NULL AND ' . $alias . '.package_capable_until > NOW()';
}

// Pure: is $given the PIN? Digits only, constant-time.
function etel_package_pin_matches($stored, $given) {
    $stored = preg_replace('/\D/', '', (string) $stored);
    $given = preg_replace('/\D/', '', (string) $given);
    return $stored !== '' && strlen($given) === strlen($stored) && hash_equals($stored, $given);
}

// One PIN step by the courier. $kind = 'pickup' (the sender's PIN, order goes assigned -> picked_up) or 'delivery' (the recipient's PIN,
// picked_up / in_transit -> delivered). 5 wrong PINs in a row lock the step for 15 minutes. Returns array(ok, message, status?).
function etel_package_courier_step($pdo, $courier_id, $order_id, $kind, $pin) {
    $order_id = intval($order_id); $courier_id = intval($courier_id);
    if (!in_array($kind, array('pickup', 'delivery'), true)) return array('ok' => false, 'message' => 'Unknown step.');
    try {
        etel_package_ensure_schema($pdo);
        $q = $pdo->prepare("SELECT id, status, courier_id, order_kind, pickup_pin, delivery_pin, customer_id FROM orders WHERE id = ? LIMIT 1");
        $q->execute(array($order_id));
        $o = $q->fetch();
        if (!$o || $o['order_kind'] !== 'package' || intval($o['courier_id']) !== $courier_id) return array('ok' => false, 'message' => 'This package is not yours.');
        $from = $kind === 'pickup' ? array('assigned') : array('picked_up', 'in_transit');
        if (!in_array($o['status'], $from, true)) {
            return array('ok' => false, 'message' => $kind === 'pickup' ? 'This package is not waiting for pickup.' : 'Pick the package up first, then deliver it.');
        }
        $t = $pdo->prepare('SELECT tries, locked_until, (locked_until IS NOT NULL AND locked_until > NOW()) AS locked FROM package_pin_tries WHERE order_id = ? AND kind = ?');
        $t->execute(array($order_id, $kind));
        $tr = $t->fetch();
        if ($tr && intval($tr['locked']) === 1) return array('ok' => false, 'message' => 'Too many wrong PINs. Try again in 15 minutes, or call support.');
        $stored = $kind === 'pickup' ? $o['pickup_pin'] : $o['delivery_pin'];
        if (!etel_package_pin_matches($stored, $pin)) {
            $pdo->prepare('INSERT INTO package_pin_tries (order_id, kind, tries) VALUES (?,?,1) ON DUPLICATE KEY UPDATE tries = IF(locked_until IS NOT NULL AND locked_until <= NOW(), 1, tries + 1)')->execute(array($order_id, $kind));
            $pdo->prepare('UPDATE package_pin_tries SET locked_until = IF(tries >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NULL) WHERE order_id = ? AND kind = ?')->execute(array($order_id, $kind));
            return array('ok' => false, 'message' => $kind === 'pickup' ? 'That is not the sender\'s pickup PIN. Ask the sender to open the package in their app.' : 'That is not the recipient\'s delivery PIN. Ask the recipient for it.');
        }
        $to = $kind === 'pickup' ? 'picked_up' : 'delivered';
        $extra = $kind === 'delivery' ? ', delivered_at = NOW()' : '';
        $u = $pdo->prepare("UPDATE orders SET status = ?$extra WHERE id = ? AND courier_id = ? AND status IN ('" . implode("','", $from) . "')");
        $u->execute(array($to, $order_id, $courier_id));
        if ($u->rowCount() !== 1) return array('ok' => false, 'message' => 'The package changed while you were entering the PIN. Refresh and try again.');
        $pdo->prepare('DELETE FROM package_pin_tries WHERE order_id = ? AND kind = ?')->execute(array($order_id, $kind));
        try { require_once __DIR__ . '/courier_offers.php'; etel_offer_mark_accepted($pdo, $order_id); } catch (Exception $e) {} catch (Throwable $e) {}
        if ($to === 'picked_up') { try { require_once __DIR__ . '/order_stamps.php'; etel_order_stamp_pickup($pdo, $order_id); } catch (Exception $e) {} catch (Throwable $e) {} }
        if (function_exists('etel_notify_order_status')) { try { etel_notify_order_status($pdo, $order_id); } catch (Exception $e) {} catch (Throwable $e) {} }
        if ($to === 'delivered' && function_exists('etel_maybe_pay_referral_reward')) { try { etel_maybe_pay_referral_reward($pdo, intval($o['customer_id'])); } catch (Exception $e) {} catch (Throwable $e) {} }
        return array('ok' => true, 'status' => $to, 'message' => $to === 'picked_up' ? 'Package picked up. Now take it to the recipient.' : 'Delivered. Well done!');
    } catch (Exception $e) {
        error_log('[ETEL] package courier step: ' . $e->getMessage());
        return array('ok' => false, 'message' => 'Could not save that. Please try again.');
    }
}
