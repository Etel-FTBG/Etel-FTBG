<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - SHARED QUEUE REGISTRATION LOGIC
// ═══════════════════════════════════════════════════════════════════
//
// Factored out of api/index.php's 'register' action so the same
// rationing checks (vehicle type allowed, parity, already-in-queue,
// served-today-anywhere) and insert logic can be reused by the new
// NFC auto-register endpoint (service_nfc_register.php) without
// duplicating them - both a manual and a tap-triggered registration
// must be held to the exact same rules.

// No rows in controller_vehicle_types for this controller = unrestricted.
function madeyaVehicleTypeAllowed($controllerId, $vehicleTypeId) {
    $rows = db()->fetchAll(
        "SELECT vehicle_type_id FROM controller_vehicle_types WHERE controller_id = ?",
        [$controllerId]
    );
    if (empty($rows)) return true;
    foreach ($rows as $r) {
        if ((int)$r['vehicle_type_id'] === (int)$vehicleTypeId) return true;
    }
    return false;
}

// Returns the required parity ('odd'/'even') if the plate violates the
// controller's odd_even_rule, or null if it's fine (rule is 'all', or
// the plate has no trailing digit to determine parity from).
function madeyaParityViolation($controllerId, $plate) {
    $controller = db()->fetchOne("SELECT odd_even_rule FROM controllers WHERE id = ?", [$controllerId]);
    $rule = $controller['odd_even_rule'] ?? 'all';
    if (empty($rule) || $rule === 'all') return null;
    $isOdd = isOddPlate($plate);
    if ($isOdd === null) return null;
    $plateParity = $isOdd ? 'odd' : 'even';
    return $plateParity !== $rule ? $rule : null;
}

// Cross-station: no controller_id filter, by design.
function madeyaServedTodayConflict($plate, $vehicleTypeId) {
    return db()->fetchOne(
        "SELECT r.served_at, c.station_name FROM report r
         JOIN controllers c ON c.id = r.controller_id
         WHERE r.plate_number = ? AND r.vehicle_type_id = ? AND r.report_date = ?
         ORDER BY r.served_at DESC LIMIT 1",
        [$plate, $vehicleTypeId, date('Y-m-d')]
    );
}

/**
 * Runs the full registration flow (all three rationing guards, then
 * the queue insert) and returns a result array - never exits, so both
 * the manual HTTP action and the NFC service endpoint can call this
 * and shape their own response around it. 'http_code' mirrors exactly
 * what the original inline 'register' action used to send for each
 * case, so behavior for the manual endpoint is unchanged.
 */
function madeyaRegisterVehicle($controllerId, $vehicleTypeId, $plate, $driverName, $driverPhone) {
    if (empty($plate) || !$vehicleTypeId) {
        return ['success' => false, 'message' => 'Missing required fields', 'http_code' => 400];
    }

    if (!madeyaVehicleTypeAllowed($controllerId, $vehicleTypeId)) {
        return ['success' => false, 'message' => 'This vehicle type is not served at this station today.', 'code' => 'TYPE_NOT_ALLOWED', 'http_code' => 400];
    }

    $requiredParity = madeyaParityViolation($controllerId, $plate);
    if ($requiredParity) {
        $need = $requiredParity === 'odd' ? 'odd-numbered' : 'even-numbered';
        return ['success' => false, 'message' => "This station only accepts {$need} plates today.", 'code' => 'WRONG_PARITY', 'http_code' => 400];
    }

    // The duplicate-plate check below and the insert that follows it are
    // not atomic on their own - two concurrent registrations of the same
    // plate (e.g. two NFC taps of two different driver cards that happen
    // to resolve to the same plate, or a manual register racing an NFC
    // auto-register) could both read "not in queue" before either one
    // inserts. Wrapping the check-then-insert in a transaction, with the
    // check done as SELECT ... FOR UPDATE against the indexed
    // plate_number column, makes InnoDB hold a lock on that plate's index
    // range for the duration of the transaction - a concurrent
    // transaction's own SELECT ... FOR UPDATE for the same plate blocks
    // until this one commits or rolls back, serializing the two calls
    // instead of letting both pass the check.
    db()->beginTransaction();
    try {
        $existing = db()->fetchOne(
            "SELECT id FROM queue WHERE plate_number = ? AND status = 'queued' FOR UPDATE",
            [$plate]
        );
        if ($existing) {
            db()->commit();
            return ['success' => false, 'message' => 'Vehicle already in queue', 'http_code' => 200];
        }

        $servedToday = madeyaServedTodayConflict($plate, $vehicleTypeId);
        if ($servedToday) {
            db()->commit();
            $t = date('H:i', strtotime($servedToday['served_at']));
            return [
                'success'      => false,
                'message'      => "This vehicle already received fuel today at {$t} ({$servedToday['station_name']}).",
                'code'         => 'SERVED_TODAY',
                'served_at'    => $t,
                'station_name' => $servedToday['station_name'],
                'http_code'    => 400,
            ];
        }

        $maxPos = db()->fetchOne(
            "SELECT COALESCE(MAX(position), 0) as max_pos FROM queue
             WHERE controller_id = ? AND vehicle_type_id = ? AND status = 'queued'",
            [$controllerId, $vehicleTypeId]
        );
        $nextPosition = $maxPos['max_pos'] + 1;

        $token = strtoupper(substr(md5(uniqid()), 0, 8));

        $parity = isOddPlate($plate);
        $parityStr = $parity === true ? 'odd' : ($parity === false ? 'even' : '');

        $curBatch = db()->fetchOne(
            "SELECT COALESCE(MAX(batch_id), 0) as max_batch FROM queue
             WHERE controller_id = ? AND status = 'queued'",
            [$controllerId]
        );
        $batchId = max(1, (int)$curBatch['max_batch']);

        $id = db()->insert('queue', [
            'controller_id'   => $controllerId,
            'vehicle_type_id' => $vehicleTypeId,
            'plate_number'    => $plate,
            'plate_parity'    => $parityStr,
            'driver_name'     => $driverName,
            'driver_phone'    => $driverPhone,
            'token'           => $token,
            'position'        => $nextPosition,
            'queue_type'      => 'normal',
            'status'          => 'queued',
            'batch_id'        => $batchId,
        ]);

        db()->commit();

        return [
            'success'   => true,
            'message'   => 'Successfully registered in queue',
            'data'      => [
                'id'           => $id,
                'token'        => $token,
                'position'     => $nextPosition,
                'plate_number' => $plate,
            ],
            'http_code' => 200,
        ];
    } catch (Exception $e) {
        db()->rollback();
        throw $e;
    }
}

/**
 * Last-9-digits phone matching, same convention EtelID's own find_by_phone()
 * and Etel Madeya's etelid_bridge_normalize_phone() already use - a
 * phone stored/typed as '0912345678', '+251912345678' or '912345678'
 * is the same real subscriber.
 */
function madeyaNormalizePhone($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return substr($digits, -9);
}

/**
 * Remembers (or updates) which plate/vehicle type belongs to a given
 * phone number, in the driver_vehicles registry - this is what powers
 * "tap the driver's NFC card to auto-register" later: the first time a
 * controller manually registers a vehicle WITH a driver phone, that
 * phone->plate association is saved here for every future tap.
 */
function madeyaRememberDriverVehicle($phone, $plate, $vehicleTypeId, $driverName, $etelId = null) {
    $last9 = madeyaNormalizePhone($phone);
    if (!$last9 || empty($plate)) return;

    $existing = db()->fetchOne("SELECT id FROM driver_vehicles WHERE phone_last9 = ?", [$last9]);
    $data = [
        'phone'           => $phone,
        'phone_last9'     => $last9,
        'plate_number'    => $plate,
        'vehicle_type_id' => $vehicleTypeId,
        'driver_name'     => $driverName,
    ];
    if ($etelId) $data['etel_id'] = $etelId;

    if ($existing) {
        db()->update('driver_vehicles', $data, 'id = ?', [$existing['id']]);
    } else {
        db()->insert('driver_vehicles', $data);
    }
}

/**
 * Finds an active controller row by phone (last-9 match), or null.
 */
function madeyaFindControllerByPhone($phone) {
    $last9 = madeyaNormalizePhone($phone);
    if (!$last9) return null;
    foreach (db()->fetchAll("SELECT * FROM controllers WHERE is_active = 1") as $c) {
        if (madeyaNormalizePhone($c['phone']) === $last9) return $c;
    }
    return null;
}

/**
 * Finds an active admin/manager row by phone (last-9 match), or null.
 * admin_users.phone was added by migration_add_etelid_sso.sql for
 * exactly this kind of EtelID phone matching. 'staff' role is
 * deliberately excluded - only admin/manager can act on behalf of a
 * station they don't personally have a controllers row for.
 */
function madeyaFindAdminByPhone($phone) {
    $last9 = madeyaNormalizePhone($phone);
    if (!$last9) return null;
    foreach (db()->fetchAll("SELECT * FROM admin_users WHERE is_active = 1 AND role IN ('admin','manager')") as $a) {
        if (!empty($a['phone']) && madeyaNormalizePhone($a['phone']) === $last9) return $a;
    }
    return null;
}

/**
 * Active stations for an admin to pick from when registering a
 * vehicle via NFC (they have no single station of their own).
 */
function madeyaActiveStations() {
    return db()->fetchAll("SELECT id, station_name FROM controllers WHERE is_active = 1 ORDER BY station_name");
}
