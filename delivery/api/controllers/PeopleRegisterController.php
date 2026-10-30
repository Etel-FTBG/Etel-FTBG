<?php
// ============================================================
// ETEL Delivery - admin registers a person: customer, courier or driver (the guided "Register a person" panel)
//   admin_register_person { role, name, phone, password,
//       courier: vehicle_type?, zone?
//       driver:  vehicle_type, vehicle_make?, vehicle_color?, vehicle_year?, vehicle_plate }      (users.manage)
//   admin_register_courier { name, phone, password, vehicle_type, plate_number?, home_zone?, id_type, id_number, id_photo_url, selfie_url,
//       licence_number?, licence_photo_url?, emergency_name, emergency_phone, address?, activate?, confirm_duplicate? }   (users.manage)
//       The Courier desk: ID and selfie are kept in the private documents folder. With "activate" the courier is approved and can take jobs at once
//       (staff checked the ID and face in person); without it they wait in the approval list like a self sign-up.
//   admin_register_driver { name, phone, password, vehicle_type, vehicle_make?, vehicle_color?, vehicle_year?, vehicle_plate,
//       license_name, license_number, license_dob, license_photo_url, selfie_url,
//       docs?: { insurance|technical_inspection|road_fund: { expiry_date, receipt_url } }, activate?, confirm_mismatch? }   (users.manage)
//       The Driver desk: the same details the driver would give in the Driver app's sign-up, taken by ETEL staff. With "activate" the driver is
//       switched on at once (staff checked the licence and face in person); without it they wait in the pending list like a self sign-up.
// Creates the sign-in account plus the role's profile. A courier an admin registers is approved straight away (self sign-ups still wait for
// approval). A driver gets the vehicle on their profile but is NOT marked onboarded: they add their selfie and licence photo in the driver app,
// which is what lets them go online. Ride-only staff may only register customers (riders) and drivers.
// ============================================================

switch ($action) {

case 'admin_register_person': {
    $pdo = db();
    $admin = require_admin($pdo, 'users.manage');
    $role = isset($body['role']) ? trim($body['role']) : '';
    if (!in_array($role, array('customer', 'courier', 'driver'), true)) fail('Choose customer, courier or driver', 400);
    if (etel_is_ride_only($pdo, $admin) && !in_array($role, array('customer', 'driver'), true)) fail('You can only register riders and drivers', 403);

    $name = isset($body['name']) ? trim($body['name']) : '';
    if (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') < 2 : strlen($name) < 2) fail('Enter the full name', 400);
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 100, 'UTF-8') : substr($name, 0, 100);
    $phone_raw = isset($body['phone']) ? trim($body['phone']) : '';
    if ($phone_raw === '') fail('Phone is required', 400);
    $phone = etel_validate_phone($phone_raw);
    if (!$phone) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567', 400);
    $pw = isset($body['password']) ? (string) $body['password'] : '';
    if (strlen($pw) < 8) fail('Password must be at least 8 characters', 400);

    $clean = function ($k, $max) use ($body) {
        $v = isset($body[$k]) ? trim(preg_replace('/\s+/u', ' ', (string) $body[$k])) : '';
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    };
    $vehicle = array();
    if ($role === 'courier') {
        $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : 'motorcycle';
        if (!in_array($vt, array('motorcycle', 'bicycle', 'car', 'on_foot'), true)) fail('Choose how the courier travels', 400);
        $vehicle = array('type' => $vt, 'zone' => $clean('zone', 60));
    } elseif ($role === 'driver') {
        $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : '';
        if (!in_array($vt, array('bajaj', 'moto', 'car', 'minibus', 'qute'), true)) fail('Choose the vehicle type', 400);
        $plate = strtoupper($clean('vehicle_plate', 30));
        if ($plate === '') fail('Enter the plate number', 400);
        $yr = isset($body['vehicle_year']) && $body['vehicle_year'] !== '' ? intval($body['vehicle_year']) : 0;
        if ($yr !== 0 && ($yr < 1980 || $yr > intval(date('Y')) + 1)) fail('Enter the vehicle year as four digits, or leave it empty', 400);
        $vehicle = array('type' => $vt, 'make' => $clean('vehicle_make', 60), 'color' => $clean('vehicle_color', 40), 'year' => $yr, 'plate' => $plate);
    }

    // Schema changes first: DDL inside a transaction commits it.
    require_once __DIR__ . '/../lib/schema_helpers.php';
    if ($role === 'courier') { require_once __DIR__ . '/../lib/courier_approval.php'; etel_courier_approval_ready($pdo); }
    if ($role === 'driver') {
        rides_add_missing_cols($pdo, 'ride_drivers', array(
            'vehicle_plate' => "vehicle_plate VARCHAR(30) DEFAULT ''", 'vehicle_type' => "vehicle_type VARCHAR(40) DEFAULT 'Bajaj'",
            'vehicle_make' => "vehicle_make VARCHAR(60) DEFAULT ''", 'vehicle_color' => "vehicle_color VARCHAR(40) DEFAULT ''",
            'vehicle_year' => 'vehicle_year SMALLINT UNSIGNED DEFAULT 0', 'onboarded' => 'onboarded TINYINT(1) DEFAULT 0'));
    }

    if (!etel_phone_lock($pdo, $phone)) fail('Please try again in a moment', 429);
    if (etel_phone_taken($pdo, $phone)) { etel_phone_unlock($pdo, $phone); fail('That phone number already has an ETEL account', 409); }

    $userCols = etel_table_columns($pdo, 'users');
    $fields = array('name', 'phone', 'password_hash', 'role'); $marks = array('?', '?', '?', '?');
    $values = array($name, $phone, password_hash($pw, PASSWORD_DEFAULT), $role);
    if (in_array('active', $userCols, true)) { $fields[] = 'active'; $marks[] = '1'; }
    $fields[] = 'created_at'; $marks[] = 'NOW()';
    $code = '';
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (' . implode(',', $fields) . ') VALUES (' . implode(',', $marks) . ')')->execute($values);
        $uid = intval($pdo->lastInsertId());
        if (!$uid) throw new Exception('no user id');
        if ($role === 'courier') {
            $code = 'C-' . str_pad($uid, 5, '0', STR_PAD_LEFT);
            $cc = etel_table_columns($pdo, 'couriers');
            $cf = array('user_id', 'courier_code'); $cm = array('?', '?'); $cv = array($uid, $code);
            if (in_array('vehicle_type', $cc, true)) { $cf[] = 'vehicle_type'; $cm[] = '?'; $cv[] = $vehicle['type']; }
            if ($vehicle['zone'] !== '') {
                if (in_array('home_zone', $cc, true)) { $cf[] = 'home_zone'; $cm[] = '?'; $cv[] = $vehicle['zone']; }
                if (in_array('zone', $cc, true)) { $cf[] = 'zone'; $cm[] = '?'; $cv[] = $vehicle['zone']; }
            }
            $cf[] = 'status'; $cm[] = "'offline'";
            if (in_array('rating', $cc, true)) { $cf[] = 'rating'; $cm[] = '0.00'; }
            if (in_array('total_deliveries', $cc, true)) { $cf[] = 'total_deliveries'; $cm[] = '0'; }
            if (in_array('active', $cc, true)) { $cf[] = 'active'; $cm[] = '1'; }
            if (in_array('approval_status', $cc, true)) { $cf[] = 'approval_status'; $cm[] = "'approved'"; }
            if (in_array('created_at', $cc, true)) { $cf[] = 'created_at'; $cm[] = 'NOW()'; }
            $pdo->prepare('INSERT INTO couriers (' . implode(',', $cf) . ') VALUES (' . implode(',', $cm) . ')')->execute($cv);
        } elseif ($role === 'driver') {
            $pdo->prepare('INSERT INTO ride_drivers (user_id, online, last_seen, vehicle_type, vehicle_make, vehicle_color, vehicle_year, vehicle_plate) VALUES (?, 0, NOW(), ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE vehicle_type = VALUES(vehicle_type), vehicle_make = VALUES(vehicle_make), vehicle_color = VALUES(vehicle_color), vehicle_year = VALUES(vehicle_year), vehicle_plate = VALUES(vehicle_plate)')
                ->execute(array($uid, $vehicle['type'], $vehicle['make'], $vehicle['color'], $vehicle['year'], $vehicle['plate']));
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        etel_phone_unlock($pdo, $phone);
        fail_ex($e, 'Could not register this person', 500);
    }
    etel_phone_unlock($pdo, $phone);
    ok(array('user_id' => $uid, 'role' => $role, 'name' => $name, 'phone' => $phone, 'code' => $code,
             'message' => ucfirst($role) . ' registered'));
    break;
}

case 'admin_register_driver': {
    $pdo = db();
    $admin = require_admin($pdo, 'users.manage');
    require_once __DIR__ . '/../lib/ride_fare.php';
    require_once __DIR__ . '/../lib/schema_helpers.php';

    $T = function ($k, $max) use ($body) {
        $v = isset($body[$k]) ? trim(preg_replace('/\s+/u', ' ', (string) $body[$k])) : '';
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    };
    $name = $T('name', 100);
    if ((function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) < 2) fail('Enter the driver\'s full name', 400);
    $phone = etel_validate_phone(isset($body['phone']) ? trim($body['phone']) : '');
    if (!$phone) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567', 400);
    $pw = isset($body['password']) ? (string) $body['password'] : '';
    if (strlen($pw) < 8) fail('Password must be at least 8 characters', 400);

    $vt = strtolower(trim(isset($body['vehicle_type']) ? (string) $body['vehicle_type'] : ''));
    if ($vt === '' || rides_normalize_vehicle_type($pdo, $vt) !== $vt) fail('Choose the service this driver will give', 400);
    $plate = strtoupper($T('vehicle_plate', 30));
    if ($plate === '') fail('Enter the plate number', 400);
    $yr = isset($body['vehicle_year']) && $body['vehicle_year'] !== '' ? intval($body['vehicle_year']) : 0;
    if ($yr !== 0 && ($yr < 1980 || $yr > intval(date('Y')) + 1)) fail('Enter the vehicle year as four digits, or leave it empty', 400);
    $make = $T('vehicle_make', 60); $color = $T('vehicle_color', 40);

    $licName = $T('license_name', 100); $licNum = $T('license_number', 60);
    $licDob = isset($body['license_dob']) ? trim((string) $body['license_dob']) : '';
    if ($licDob !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $licDob);
        if (!$dt || $dt->format('Y-m-d') !== $licDob) fail('Enter the date of birth on the licence', 400);
        if ($dt > new DateTime('-18 years')) fail('A driver must be at least 18 years old', 400);
        if ($dt < new DateTime('-90 years')) fail('Check the date of birth on the licence', 400);
    }

    // Photos: only pictures this system stored, in the folder for that kind of picture, and really on disk.
    $photo = function ($key, $folder) use ($body) {
        $u = isset($body[$key]) ? trim((string) $body[$key]) : '';
        if ($u === '') return '';
        $path = parse_url($u, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#/uploads/' . preg_quote($folder, '#') . '/([A-Za-z0-9_.-]+\.(?:jpg|png|gif|webp))$#', $path, $m) || strlen($u) > 500) fail('That picture is not valid. Upload it again.', 400);
        if (!is_file(dirname(__DIR__, 2) . '/uploads/' . $folder . '/' . $m[1])) fail('A picture was not found on the server. Upload it again.', 400);
        return $u;
    };
    $selfie = $photo('selfie_url', 'driver_selfies');
    $licPhoto = $photo('license_photo_url', 'driver_licenses');
    $docs = array();
    $docTypes = array('insurance', 'technical_inspection', 'road_fund');
    $inDocs = isset($body['docs']) && is_array($body['docs']) ? $body['docs'] : array();
    foreach ($docTypes as $dtp) {
        if (empty($inDocs[$dtp]) || !is_array($inDocs[$dtp])) continue;
        $ex = isset($inDocs[$dtp]['expiry_date']) ? trim((string) $inDocs[$dtp]['expiry_date']) : '';
        $rc = isset($inDocs[$dtp]['receipt_url']) ? trim((string) $inDocs[$dtp]['receipt_url']) : '';
        if ($ex === '' && $rc === '') continue;
        $d2 = DateTime::createFromFormat('Y-m-d', $ex);
        if (!$d2 || $d2->format('Y-m-d') !== $ex) fail('Enter the expiry date of the ' . str_replace('_', ' ', $dtp) . ' as a date', 400);
        $path = parse_url($rc, PHP_URL_PATH);
        if ($rc === '' || !is_string($path) || !preg_match('#/uploads/driver_documents/([A-Za-z0-9_.-]+\.(?:jpg|png|gif|webp))$#', $path, $m) || !is_file(dirname(__DIR__, 2) . '/uploads/driver_documents/' . $m[1])) fail('Upload a photo of the ' . str_replace('_', ' ', $dtp) . ' document', 400);
        $docs[$dtp] = array($ex, $rc);
    }

    $activate = !empty($body['activate']);
    if ($activate) {
        if ($licName === '' || $licNum === '' || $licDob === '') fail('To activate the driver now, fill in the licence name, number and date of birth', 400);
        if ($licPhoto === '') fail('To activate the driver now, add the licence photo', 400);
        if ($selfie === '') fail('To activate the driver now, add the selfie', 400);
    }

    // EtelID cross-check (information only: it does not block staff who checked in person, but a mismatch has to be confirmed).
    $identity = 'unverified'; $idCheck = 'not_checked';
    if ($licName !== '' && $licDob !== '') {
        try {
            require_once __DIR__ . '/../etelid_bridge.php';
            $kyc = etelid_bridge_service_call('kyc', array('identifier' => $phone, 'type' => 'phone'));
            if (is_array($kyc) && !empty($kyc['member'])) {
                $m = $kyc['member'];
                $norm = function ($x) { $x = function_exists('mb_strtolower') ? mb_strtolower(trim($x), 'UTF-8') : strtolower(trim($x)); return preg_replace('/\s+/', ' ', $x); };
                $a = $norm($licName); $b = $norm(isset($m['full_name']) ? $m['full_name'] : '');
                $nameOk = $a !== '' && $b !== '' && ($a === $b || strpos($b, $a) !== false || strpos($a, $b) !== false);
                $dobOk = !empty($m['dob']) && $m['dob'] === $licDob;
                $idCheck = ($nameOk && $dobOk) ? 'matched' : 'mismatch';
            } else $idCheck = 'unreachable';
        } catch (Exception $e) { $idCheck = 'unreachable'; }
        if ($idCheck === 'mismatch' && $activate && empty($body['confirm_mismatch'])) {
            fail('The licence name or date of birth does not match this person\'s EtelID record. Check the licence again, or confirm that you checked it in person to activate anyway.', 409, array('identity_check' => 'mismatch'));
        }
    }
    if ($activate) $identity = 'matched'; elseif ($idCheck === 'mismatch') $identity = 'mismatch'; elseif ($idCheck === 'matched') $identity = 'unverified';

    // Schema first (DDL inside a transaction commits it).
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'vehicle_plate' => "vehicle_plate VARCHAR(30) DEFAULT ''", 'vehicle_type' => "vehicle_type VARCHAR(40) DEFAULT 'Bajaj'",
        'vehicle_make' => "vehicle_make VARCHAR(60) DEFAULT ''", 'vehicle_color' => "vehicle_color VARCHAR(40) DEFAULT ''",
        'vehicle_year' => 'vehicle_year SMALLINT UNSIGNED DEFAULT 0', 'onboarded' => 'onboarded TINYINT(1) DEFAULT 0',
        'license_number' => "license_number VARCHAR(60) DEFAULT ''", 'license_name' => "license_name VARCHAR(100) DEFAULT ''", 'license_dob' => 'license_dob DATE DEFAULT NULL',
        'identity_match' => "identity_match ENUM('unverified','matched','mismatch') DEFAULT 'unverified'",
        'selfie_url' => "selfie_url VARCHAR(500) DEFAULT ''", 'license_photo_url' => "license_photo_url VARCHAR(500) DEFAULT ''",
        'insurance_expiry_date' => 'insurance_expiry_date DATE NULL', 'insurance_receipt_url' => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date' => 'technical_inspection_expiry_date DATE NULL', 'technical_inspection_receipt_url' => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date' => 'road_fund_expiry_date DATE NULL', 'road_fund_receipt_url' => "road_fund_receipt_url VARCHAR(500) DEFAULT ''"));

    if (!etel_phone_lock($pdo, $phone)) fail('Please try again in a moment', 429);
    if (etel_phone_taken($pdo, $phone)) { etel_phone_unlock($pdo, $phone); fail('That phone number already has an ETEL account', 409); }

    $userCols = etel_table_columns($pdo, 'users');
    $fields = array('name', 'phone', 'password_hash', 'role'); $marks = array('?', '?', '?', '?');
    $values = array($name, $phone, password_hash($pw, PASSWORD_DEFAULT), 'driver');
    if (in_array('active', $userCols, true)) { $fields[] = 'active'; $marks[] = '1'; }
    $fields[] = 'created_at'; $marks[] = 'NOW()';
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (' . implode(',', $fields) . ') VALUES (' . implode(',', $marks) . ')')->execute($values);
        $uid = intval($pdo->lastInsertId());
        if (!$uid) throw new Exception('no user id');
        $cols = array('user_id' => $uid, 'online' => 0, 'vehicle_type' => $vt, 'vehicle_make' => $make, 'vehicle_color' => $color, 'vehicle_year' => $yr, 'vehicle_plate' => $plate,
            'license_number' => $licNum, 'license_name' => $licName, 'license_dob' => ($licDob !== '' ? $licDob : null), 'identity_match' => $identity,
            'selfie_url' => $selfie, 'license_photo_url' => $licPhoto, 'onboarded' => $activate ? 1 : 0);
        foreach ($docs as $dtp => $dv) { $cols[$dtp . '_expiry_date'] = $dv[0]; $cols[$dtp . '_receipt_url'] = $dv[1]; }
        $names = array_keys($cols);
        $pdo->prepare('INSERT INTO ride_drivers (' . implode(',', $names) . ', last_seen) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ', NOW())
                       ON DUPLICATE KEY UPDATE ' . implode(',', array_map(function ($n) { return "$n = VALUES($n)"; }, array_slice($names, 1))))
            ->execute(array_values($cols));
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        etel_phone_unlock($pdo, $phone);
        fail_ex($e, 'Could not register this driver', 500);
    }
    etel_phone_unlock($pdo, $phone);
    $missing = array();
    if ($licName === '' || $licNum === '' || $licDob === '') $missing[] = 'licence details';
    if ($licPhoto === '') $missing[] = 'licence photo';
    if ($selfie === '') $missing[] = 'selfie';
    ok(array('user_id' => $uid, 'role' => 'driver', 'name' => $name, 'phone' => $phone, 'activated' => $activate, 'identity_check' => $idCheck,
             'identity_match' => $identity, 'missing' => $missing, 'docs_saved' => array_keys($docs),
             'message' => $activate ? 'Driver registered and switched on' : 'Driver registered and waiting for review'));
    break;
}

case 'admin_register_courier': {
    $pdo = db();
    $admin = require_admin($pdo, 'users.manage');
    if (etel_is_ride_only($pdo, $admin)) fail('You can only register riders and drivers', 403);
    require_once __DIR__ . '/../lib/courier_details.php';
    require_once __DIR__ . '/../lib/courier_approval.php';

    $T = function ($k, $max) use ($body) {
        $v = isset($body[$k]) ? trim(preg_replace('/\s+/u', ' ', (string) $body[$k])) : '';
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    };
    $name = $T('name', 100);
    if ((function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) < 2) fail('Enter the courier\'s full name', 400);
    $phone = etel_validate_phone(isset($body['phone']) ? trim($body['phone']) : '');
    if (!$phone) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567', 400);
    $pw = isset($body['password']) ? (string) $body['password'] : '';
    if (strlen($pw) < 8) fail('Password must be at least 8 characters', 400);

    $vt = strtolower(trim(isset($body['vehicle_type']) ? (string) $body['vehicle_type'] : ''));
    if (!in_array($vt, array('motorcycle', 'bicycle', 'car', 'on_foot'), true)) fail('Choose how the courier travels', 400);
    $plate = strtoupper($T('plate_number', 20));
    if (etel_courier_vehicle_needs_plate($vt) && $plate === '') fail('Enter the plate number of the ' . ($vt === 'car' ? 'car' : 'motorcycle'), 400);
    if (!etel_courier_vehicle_needs_plate($vt)) $plate = '';
    $zone = $T('home_zone', 60);

    $idType = isset($body['id_type']) ? trim((string) $body['id_type']) : '';
    $idTypes = etel_courier_id_types();
    if ($idType !== '' && !isset($idTypes[$idType])) fail('Choose the kind of ID', 400);
    $idNumber = strtoupper($T('id_number', 60));
    $licNum = strtoupper($T('licence_number', 60));
    $emName = $T('emergency_name', 100);
    $emPhoneRaw = isset($body['emergency_phone']) ? trim((string) $body['emergency_phone']) : '';
    $emPhone = '';
    if ($emPhoneRaw !== '') {
        $emPhone = etel_validate_phone($emPhoneRaw);
        if (!$emPhone) fail('Enter a valid phone number for the emergency contact', 400);
        if (etel_phone_last9($emPhone) === etel_phone_last9($phone)) fail('The emergency contact must be someone else, not the courier', 400);
    }
    $address = $T('address', 200);

    $photo = function ($key) use ($body) {
        $u = isset($body[$key]) ? trim((string) $body[$key]) : '';
        if ($u === '') return '';
        $path = parse_url($u, PHP_URL_PATH);
        if (!is_string($path) || strlen($u) > 500 || !preg_match('#/uploads/driver_documents/((?:cid|csel|clic)_[A-Za-z0-9_.-]+\.(?:jpg|png|gif|webp))$#', $path, $m)) fail('That picture is not valid. Upload it again.', 400);
        if (!is_file(dirname(__DIR__, 2) . '/uploads/driver_documents/' . $m[1])) fail('A picture was not found on the server. Upload it again.', 400);
        return $u;
    };
    $idPhoto = $photo('id_photo_url'); $selfie = $photo('selfie_url'); $licPhoto = $photo('licence_photo_url');

    $activate = !empty($body['activate']);
    if ($activate) {
        if ($idType === '' || $idNumber === '' || $idPhoto === '') fail('To approve the courier now, add the ID kind, number and photo', 400);
        if ($selfie === '') fail('To approve the courier now, add the selfie', 400);
        if ($emName === '' || $emPhone === '') fail('To approve the courier now, add an emergency contact (name and phone)', 400);
        if ($vt === 'motorcycle' && ($licNum === '' || $licPhoto === '')) fail('To approve a motorcycle courier now, add the driving licence number and photo', 400);
    }

    etel_courier_details_ensure($pdo);
    etel_courier_approval_ready($pdo);
    if ($idNumber !== '') {
        $dup = $pdo->prepare('SELECT u.name FROM courier_details d JOIN users u ON u.id = d.user_id WHERE d.id_number = ? LIMIT 1');
        $dup->execute(array($idNumber));
        $other = $dup->fetchColumn();
        if ($other && empty($body['confirm_duplicate'])) fail('This ID number is already registered to another courier (' . $other . '). Check the ID, or confirm that it is the same person on purpose.', 409, array('duplicate_id' => true));
    }

    if (!etel_phone_lock($pdo, $phone)) fail('Please try again in a moment', 429);
    if (etel_phone_taken($pdo, $phone)) { etel_phone_unlock($pdo, $phone); fail('That phone number already has an ETEL account', 409); }

    $userCols = etel_table_columns($pdo, 'users');
    $fields = array('name', 'phone', 'password_hash', 'role'); $marks = array('?', '?', '?', '?');
    $values = array($name, $phone, password_hash($pw, PASSWORD_DEFAULT), 'courier');
    if (in_array('active', $userCols, true)) { $fields[] = 'active'; $marks[] = '1'; }
    $fields[] = 'created_at'; $marks[] = 'NOW()';
    $code = '';
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (' . implode(',', $fields) . ') VALUES (' . implode(',', $marks) . ')')->execute($values);
        $uid = intval($pdo->lastInsertId());
        if (!$uid) throw new Exception('no user id');
        $code = 'C-' . str_pad($uid, 5, '0', STR_PAD_LEFT);
        $cc = etel_table_columns($pdo, 'couriers');
        $cf = array('user_id', 'courier_code'); $cm = array('?', '?'); $cv = array($uid, $code);
        if (in_array('vehicle_type', $cc, true)) { $cf[] = 'vehicle_type'; $cm[] = '?'; $cv[] = $vt; }
        if ($plate !== '' && in_array('plate_number', $cc, true)) { $cf[] = 'plate_number'; $cm[] = '?'; $cv[] = $plate; }
        if ($zone !== '') {
            if (in_array('home_zone', $cc, true)) { $cf[] = 'home_zone'; $cm[] = '?'; $cv[] = $zone; }
            if (in_array('zone', $cc, true)) { $cf[] = 'zone'; $cm[] = '?'; $cv[] = $zone; }
        }
        $cf[] = 'status'; $cm[] = "'offline'";
        if (in_array('rating', $cc, true)) { $cf[] = 'rating'; $cm[] = '0.00'; }
        if (in_array('total_deliveries', $cc, true)) { $cf[] = 'total_deliveries'; $cm[] = '0'; }
        if (in_array('active', $cc, true)) { $cf[] = 'active'; $cm[] = '1'; }
        if (in_array('approval_status', $cc, true)) { $cf[] = 'approval_status'; $cm[] = $activate ? "'approved'" : "'pending'"; }
        if (in_array('created_at', $cc, true)) { $cf[] = 'created_at'; $cm[] = 'NOW()'; }
        $pdo->prepare('INSERT INTO couriers (' . implode(',', $cf) . ') VALUES (' . implode(',', $cm) . ')')->execute($cv);
        $pdo->prepare('INSERT INTO courier_details (user_id, id_type, id_number, id_photo_url, selfie_url, licence_number, licence_photo_url, emergency_name, emergency_phone, address, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(array($uid, $idType, $idNumber, $idPhoto, $selfie, $licNum, $licPhoto, $emName, $emPhone, $address, intval($admin['id'])));
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        etel_phone_unlock($pdo, $phone);
        fail_ex($e, 'Could not register this courier', 500);
    }
    etel_phone_unlock($pdo, $phone);
    $missing = array();
    if ($idNumber === '' || $idPhoto === '') $missing[] = 'ID';
    if ($selfie === '') $missing[] = 'selfie';
    if ($emName === '' || $emPhone === '') $missing[] = 'emergency contact';
    if ($vt === 'motorcycle' && ($licNum === '' || $licPhoto === '')) $missing[] = 'driving licence';
    ok(array('user_id' => $uid, 'role' => 'courier', 'name' => $name, 'phone' => $phone, 'code' => $code, 'activated' => $activate, 'missing' => $missing,
             'message' => $activate ? 'Courier registered and approved' : 'Courier registered and waiting for approval'));
    break;
}

}
