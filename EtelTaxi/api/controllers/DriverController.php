<?php
require_once __DIR__ . '/../lib/taxi_payments.php';

switch ($action) {

case 'driver_list_associations': {
    // Public on purpose - a driver registering for the first time needs
    // to see and choose their association before they have any account
    // or token at all.
    $pdo = db();
    $s = $pdo->query('SELECT id, name_am, name_en, route_am, route_en FROM ' . tbl('taxi_associations') . ' WHERE active = 1 ORDER BY name_am');
    ok(array('associations' => $s->fetchAll()));
    break;
}

case 'driver_etelid_login': {
    $pdo = db();
    $identifier = isset($body['identifier']) ? trim($body['identifier']) : '';
    $password   = isset($body['password']) ? (string) $body['password'] : '';
    if (!$identifier || !$password) fail('identifier and password are required.');
    if (!defined('ETELID_SERVICE_KEY') || ETELID_SERVICE_KEY === '') fail('EtelID sign-in is not configured on this server yet.', 503);

    $url = 'https://' . ETELID_HOST . '/EtelId/wp-json/etelid/v1/service/authenticate';
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . ETELID_SERVICE_KEY . "\r\n",
        'content' => json_encode(array('identifier' => $identifier, 'password' => $password, 'create_session' => true)),
        'timeout' => 15,
        'ignore_errors' => true,
    )));
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) fail('Could not reach EtelID right now.', 503);
    $decoded = json_decode($resp, true);
    if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['session_token'])) {
        fail(isset($decoded['message']) ? $decoded['message'] : 'Incorrect EtelID or password.', 401);
    }

    $member = $decoded['member'];
    $token  = $decoded['session_token'];

    $ds = $pdo->prepare('SELECT * FROM ' . tbl('taxi_drivers') . ' WHERE etel_id = ?');
    $ds->execute(array($member['etel_id']));
    $driver = $ds->fetch();

    if ($driver) {
        if ($driver['status'] !== 'active') fail('This driver account is suspended. Contact your association.', 403);
        ok(array('token' => $token, 'needs_registration' => false,
                  'driver' => array('full_name' => $driver['full_name'], 'vehicle_plate' => $driver['vehicle_plate'])));
    } else {
        // Recognized by EtelID, but has never registered a vehicle with
        // any association yet - EtelID knows who they are, not their
        // plate or which association they belong to.
        ok(array('token' => $token, 'needs_registration' => true,
                  'member' => array('full_name' => $member['full_name'], 'phone' => $member['phone'], 'etel_id' => $member['etel_id'])));
    }
    break;
}

case 'driver_complete_etelid_registration': {
    $pdo = db();
    // This driver has a real EtelID session token but no taxi_drivers
    // row yet - require_driver_auth's EtelID fallback would normally
    // fail with "not yet registered" for exactly this case, so this
    // action authenticates the token directly against EtelID's session
    // table instead, then creates the missing driver row.
    $token = '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $token = trim(str_ireplace('Bearer', '', $v));
    if (!$token) fail('Sign in required.', 401);

    try {
        // Real table names are "{WP prefix}etelid_sessions"/"etelid_members"
        // (the plugin's own "etelid_" segment nested inside WordPress's
        // own Etelid_ table prefix) - confirmed via debug-db.php against
        // the live schema, not just "{prefix}sessions"/"members".
        // EtelID_Auth::create_session() stores sha256($token) in the
        // token column, never the raw token - hash before comparing,
        // same as EtelID's own member_from_token() does.
        $es = $pdo->prepare('SELECT m.etel_id, m.full_name, m.phone, m.dob
                             FROM ' . ETELID_DB_NAME . '.' . ETELID_DB_PREFIX . 'etelid_sessions es
                             JOIN ' . ETELID_DB_NAME . '.' . ETELID_DB_PREFIX . 'etelid_members m ON m.id = es.member_id
                             WHERE es.token = ? AND es.expires_at > NOW() LIMIT 1');
        $es->execute(array(hash('sha256', $token)));
        $member = $es->fetch();
    } catch (Exception $e) {
        // Cross-database query failed - almost always means the
        // EtelTaxi DB user hasn't been granted SELECT on the etelid
        // database yet (a separate MySQL grant, not something a normal
        // cPanel "add user to database" covers automatically for a
        // DIFFERENT database). Logged for the real detail, shown to
        // the person as a clear, specific message instead of a generic
        // "could not reach the server".
        error_log('[EtelTaxi] cross-DB EtelID session lookup failed: ' . $e->getMessage());
        fail('Could not verify your EtelID session - cross-app database access may not be configured on this server yet. Contact Etel Taxi support.', 500);
    }
    if (!$member) fail('Session expired. Please sign in with EtelID again.', 401);

    $existing = $pdo->prepare('SELECT id FROM ' . tbl('taxi_drivers') . ' WHERE etel_id = ?');
    $existing->execute(array($member['etel_id']));
    if ($existing->fetch()) fail('This EtelID is already registered as a driver.', 409);

    $assocId = isset($body['association_id']) ? intval($body['association_id']) : 0;
    $plate   = isset($body['vehicle_plate']) ? trim($body['vehicle_plate']) : '';
    $licenseNum  = isset($body['license_number']) ? trim($body['license_number']) : '';
    $licenseName = isset($body['license_full_name']) ? trim($body['license_full_name']) : '';
    $licenseDob  = isset($body['license_dob']) ? trim($body['license_dob']) : '';
    if (!$assocId || !$plate) fail('association_id and vehicle_plate are required.');
    if (!$licenseNum || !$licenseName || !$licenseDob) fail('license_number, license_full_name, and license_dob are all required.');

    $chk = $pdo->prepare('SELECT id FROM ' . tbl('taxi_associations') . ' WHERE id = ? AND active = 1');
    $chk->execute(array($assocId));
    if (!$chk->fetch()) fail('That association was not found or is not active.', 404);

    /* Cross-check the license name/DOB the driver typed against the
       already-verified EtelID member record above - no extra network
       call needed here since we already have full_name/dob in $member. */
    $nameMatch = taxi_names_roughly_match($licenseName, $member['full_name']);
    $dobMatch  = !empty($member['dob']) && $member['dob'] === $licenseDob;
    $identityMatch = ($nameMatch && $dobMatch) ? 'matched' : 'mismatch';

    $pdo->prepare('INSERT INTO ' . tbl('taxi_drivers') . '
        (association_id, full_name, phone, etel_id, vehicle_plate, license_number, license_full_name, license_dob, identity_match, status, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active", NOW())')->execute(array(
        $assocId, $member['full_name'], $member['phone'], $member['etel_id'], $plate,
        $licenseNum, $licenseName, $licenseDob, $identityMatch,
    ));

    $resp = array('driver_id' => intval($pdo->lastInsertId()), 'message' => 'Registered successfully.');
    if ($identityMatch === 'mismatch') {
        $resp['identity_match'] = 'mismatch';
        $resp['message'] = 'Registered, but your license name/date of birth does not match your EtelID record. Your account has been flagged for association review.';
    }
    ok($resp);
    break;
}

case 'driver_register': {
    $pdo = db();
    $fullName = isset($body['full_name']) ? trim($body['full_name']) : '';
    $phone    = isset($body['phone']) ? trim($body['phone']) : '';
    $pin      = isset($body['pin']) ? (string) $body['pin'] : '';
    $plate    = isset($body['vehicle_plate']) ? trim($body['vehicle_plate']) : '';
    $assocId  = isset($body['association_id']) ? intval($body['association_id']) : 0;
    $licenseNum  = isset($body['license_number']) ? trim($body['license_number']) : '';
    $licenseName = isset($body['license_full_name']) ? trim($body['license_full_name']) : '';
    $licenseDob  = isset($body['license_dob']) ? trim($body['license_dob']) : '';

    if (!$fullName || !$phone || !$pin || !$plate || !$assocId) fail('full_name, phone, pin, vehicle_plate, and association_id are all required.');
    if (!$licenseNum || !$licenseName || !$licenseDob) fail('license_number, license_full_name, and license_dob are all required.');
    if (strlen($pin) < 4) fail('PIN must be at least 4 digits.');

    $chk = $pdo->prepare('SELECT id FROM ' . tbl('taxi_associations') . ' WHERE id = ? AND active = 1');
    $chk->execute(array($assocId));
    if (!$chk->fetch()) fail('That association was not found or is not active.', 404);

    $dup = $pdo->prepare('SELECT id FROM ' . tbl('taxi_drivers') . ' WHERE phone = ?');
    $dup->execute(array($phone));
    if ($dup->fetch()) fail('A driver is already registered with that phone number.', 409);

    /* This path has no EtelID session to reuse (unlike
       driver_complete_etelid_registration), so cross-check via a fresh
       service/kyc lookup by phone. */
    $identityMatch = taxi_check_license_identity($phone, $licenseName, $licenseDob);

    $ins = $pdo->prepare('INSERT INTO ' . tbl('taxi_drivers') . '
        (association_id, full_name, phone, pin_hash, vehicle_plate, vehicle_type, license_number, license_full_name, license_dob, identity_match, status, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", NOW())');
    $ins->execute(array($assocId, $fullName, $phone, password_hash($pin, PASSWORD_DEFAULT), $plate,
        isset($body['vehicle_type']) ? trim($body['vehicle_type']) : null,
        $licenseNum, $licenseName, $licenseDob, $identityMatch));
    $driverId = intval($pdo->lastInsertId());

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . tbl('taxi_sessions') . ' (driver_id, token, expires_at, created_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY), NOW())')->execute(array($driverId, $token));

    $resp = array('driver_id' => $driverId, 'token' => $token, 'message' => 'Registered successfully.');
    if ($identityMatch !== 'matched') {
        $resp['identity_match'] = $identityMatch;
        $resp['message'] = $identityMatch === 'mismatch'
            ? 'Registered, but your license name/date of birth does not match your EtelID record. Your account has been flagged for association review.'
            : 'Registered - could not verify against EtelID right now, this will be checked automatically once reachable.';
    }
    ok($resp);
    break;
}

case 'driver_login': {
    $pdo = db();
    $phone = isset($body['phone']) ? trim($body['phone']) : '';
    $pin   = isset($body['pin']) ? (string) $body['pin'] : '';
    if (!$phone || !$pin) fail('phone and pin are required.');

    $s = $pdo->prepare('SELECT * FROM ' . tbl('taxi_drivers') . ' WHERE phone = ?');
    $s->execute(array($phone));
    $driver = $s->fetch();
    if (!$driver || !$driver['pin_hash'] || !password_verify($pin, $driver['pin_hash'])) {
        fail('Incorrect phone number or PIN.', 401);
    }
    if ($driver['status'] !== 'active') fail('This driver account is suspended. Contact your association.', 403);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . tbl('taxi_sessions') . ' (driver_id, token, expires_at, created_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY), NOW())')->execute(array($driver['id'], $token));

    ok(array('token' => $token, 'driver' => array(
        'id' => $driver['id'], 'full_name' => $driver['full_name'], 'vehicle_plate' => $driver['vehicle_plate'],
    )));
    break;
}

case 'driver_get_status': {
    $pdo = db();
    $driver = require_driver_auth($pdo);
    $status = taxi_compute_driver_status($pdo, $driver['id'], $driver['association_id']);

    $as = $pdo->prepare('SELECT * FROM ' . tbl('taxi_associations') . ' WHERE id = ?');
    $as->execute(array($driver['association_id']));
    $association = $as->fetch();

    // Daily verify-code for the QR a traffic officer scans - valid only
    // for today, so a screenshot from a previous day can't be reused.
    $verifyCode = null;
    if (defined('TAXI_QR_SIGNING_KEY') && TAXI_QR_SIGNING_KEY !== '') {
        $today = date('Ymd');
        $sig = substr(hash_hmac('sha256', $driver['id'] . '|' . $today, TAXI_QR_SIGNING_KEY), 0, 20);
        $verifyCode = $driver['id'] . '.' . $today . '.' . $sig;
    }

    ok(array(
        'driver'      => array('full_name' => $driver['full_name'], 'vehicle_plate' => $driver['vehicle_plate'], 'phone' => $driver['phone']),
        'association' => $association ? array('name_am' => $association['name_am'], 'name_en' => $association['name_en'], 'route_am' => $association['route_am'], 'route_en' => $association['route_en']) : null,
        'status'      => $status,
        'verify_code' => $verifyCode,
    ));
    break;
}

case 'driver_submit_payment': {
    $pdo = db();
    $driver = require_driver_auth($pdo);
    $method = isset($body['payment_method']) ? trim($body['payment_method']) : '';
    $ref    = isset($body['reference_number']) ? trim($body['reference_number']) : '';
    if (!in_array($method, array('etelpay_wallet', 'telebirr', 'cbe', 'abysinia', 'cash'), true)) {
        fail('payment_method must be one of: etelpay_wallet, telebirr, cbe, abysinia, cash.');
    }
    if ($method === 'etelpay_wallet') {
        // Honest placeholder: no EtelPay codebase to integrate against
        // yet. This is intentionally NOT faked as a success - see the
        // handoff notes for what "real" looks like here.
        fail('EtelPay wallet payment is not available yet on this server. Please use Telebirr, CBE, Abysinia, or cash for now.', 503);
    }
    if (in_array($method, array('telebirr', 'cbe', 'abysinia'), true) && !$ref) {
        fail('reference_number is required for bank/Telebirr payments.');
    }

    $status = taxi_compute_driver_status($pdo, $driver['id'], $driver['association_id']);
    $period = $status['current_period'];
    $amount = $status['monthly_due'];
    $penalty = ($status['status'] === 'overdue') ? $status['penalty_owed'] : 0;

    $exists = $pdo->prepare('SELECT id, status FROM ' . tbl('taxi_dues_payments') . ' WHERE driver_id = ? AND period_month = ?');
    $exists->execute(array($driver['id'], $period));
    $existingRow = $exists->fetch();
    if ($existingRow && $existingRow['status'] !== 'rejected') {
        fail('A payment for this period has already been submitted or verified.', 409);
    }

    if ($existingRow) {
        // Re-submitting after a rejection - update the same row rather
        // than creating a duplicate.
        $pdo->prepare('UPDATE ' . tbl('taxi_dues_payments') . '
            SET amount=?, penalty_amount=?, payment_method=?, reference_number=?, status="pending_verification",
                submitted_at=NOW(), verified_by=NULL, verified_at=NULL, notes=NULL
            WHERE id=?')->execute(array($amount, $penalty, $method, $ref ?: null, $existingRow['id']));
        $paymentId = $existingRow['id'];
    } else {
        $pdo->prepare('INSERT INTO ' . tbl('taxi_dues_payments') . '
            (driver_id, period_month, amount, penalty_amount, payment_method, reference_number, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, "pending_verification", NOW())')
            ->execute(array($driver['id'], $period, $amount, $penalty, $method, $ref ?: null));
        $paymentId = intval($pdo->lastInsertId());
    }

    ok(array(
        'payment_id' => $paymentId, 'period_month' => $period, 'amount' => $amount, 'penalty_amount' => $penalty,
        'status' => 'pending_verification',
        'message' => 'Payment submitted - your association will verify it shortly.',
    ));
    break;
}

case 'driver_get_payment_history': {
    $pdo = db();
    $driver = require_driver_auth($pdo);
    $s = $pdo->prepare('SELECT period_month, amount, penalty_amount, payment_method, reference_number, status, submitted_at, verified_at
                        FROM ' . tbl('taxi_dues_payments') . ' WHERE driver_id = ? ORDER BY period_month DESC LIMIT 24');
    $s->execute(array($driver['id']));
    ok(array('payments' => $s->fetchAll()));
    break;
}

// Public on purpose - the VAPID public key is meant to be handed to any
// browser that wants to subscribe (unlike the private key it's paired
// with, this isn't a secret). Same pattern as Delivery's own
// get_vapid_public_key action.
case 'get_vapid_public_key': {
    if (!defined('VAPID_PUBLIC_KEY') || VAPID_PUBLIC_KEY === '') {
        fail('Push notifications are not configured yet on this server.', 503);
    }
    ok(array('publicKey' => VAPID_PUBLIC_KEY));
    break;
}

case 'driver_register_push_token': {
    $pdo = db();
    $driver = require_driver_auth($pdo);
    $sub = isset($body['subscription']) ? $body['subscription'] : null;
    if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        fail('Invalid push subscription object.');
    }
    $pdo->prepare('UPDATE ' . tbl('taxi_drivers') . ' SET push_subscription = ? WHERE id = ?')
        ->execute(array(json_encode($sub), $driver['id']));
    ok(array('message' => 'Push notifications enabled.'));
    break;
}

case 'driver_get_bank_accounts': {
    $pdo = db();
    $s = $pdo->query('SELECT bank_key, display_name, account_name, account_number FROM ' . tbl('taxi_bank_accounts') . ' WHERE active = 1');
    ok(array('accounts' => $s->fetchAll()));
    break;
}

case 'public_verify_permit': {
    // No login required on purpose - this is what a traffic officer
    // checks by scanning the driver's own displayed QR code. Takes the
    // signed, date-stamped verify_code rather than a raw plate number,
    // so there's no globally-searchable "look up anyone's plate"
    // endpoint - you can only verify a permit you're actually looking
    // at right now.
    $pdo = db();
    $code = isset($body['verify_code']) ? trim($body['verify_code']) : (isset($_GET['code']) ? trim($_GET['code']) : '');
    if (!$code) fail('verify_code is required.');
    if (!defined('TAXI_QR_SIGNING_KEY') || TAXI_QR_SIGNING_KEY === '') fail('Verification is not configured on this server yet.', 503);

    $parts = explode('.', $code);
    if (count($parts) !== 3) fail('Invalid verification code.', 400);
    list($driverId, $day, $sig) = $parts;
    $expectedSig = substr(hash_hmac('sha256', $driverId . '|' . $day, TAXI_QR_SIGNING_KEY), 0, 20);
    if (!hash_equals($expectedSig, $sig)) fail('Invalid or tampered verification code.', 400);
    if ($day !== date('Ymd')) fail('This code has expired - ask the driver to refresh their app and show a new code.', 400);

    $d = $pdo->prepare('SELECT * FROM ' . tbl('taxi_drivers') . ' WHERE id = ?');
    $d->execute(array(intval($driverId)));
    $driver = $d->fetch();
    if (!$driver) fail('Driver not found.', 404);

    $status = taxi_compute_driver_status($pdo, $driver['id'], $driver['association_id']);
    $as = $pdo->prepare('SELECT name_am, name_en, route_am, route_en FROM ' . tbl('taxi_associations') . ' WHERE id = ?');
    $as->execute(array($driver['association_id']));
    $association = $as->fetch();

    ok(array(
        'full_name'     => $driver['full_name'],
        'vehicle_plate' => $driver['vehicle_plate'],
        'association'   => $association,
        'permit_status' => $status['status'], // active | grace_period | overdue
        'valid_through' => $status['window_end'],
    ));
    break;
}

}
