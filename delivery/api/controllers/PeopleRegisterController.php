<?php
// ============================================================
// ETEL Delivery - admin registers a person: customer, courier or driver (the guided "Register a person" panel)
//   admin_register_person { role, name, phone, password,
//       courier: vehicle_type?, zone?
//       driver:  vehicle_type, vehicle_make?, vehicle_color?, vehicle_year?, vehicle_plate }      (users.manage)
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

}
