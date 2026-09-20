<?php
// ============================================================
// ETEL TAXI - Service-to-service API (called by OTHER Etel systems,
// not by drivers/admins/officers in a browser). Gated by
// ETELTAXI_SERVICE_KEY - a shared secret configured in api/.env on
// BOTH this server and the caller's, never by a user session.
// ============================================================
require_once __DIR__ . '/../lib/taxi_payments.php';

function taxi_require_service_key() {
    $hdrs = function_exists('getallheaders') ? getallheaders() : array();
    $key = '';
    foreach ($hdrs as $k => $v) if (strcasecmp($k, 'X-Etel-Service-Key') === 0) $key = trim($v);
    $expected = defined('ETELTAXI_SERVICE_KEY') ? ETELTAXI_SERVICE_KEY : '';
    if ($expected === '') fail('EtelTaxi service bridge is not configured on this server yet.', 503);
    if ($key === '' || !hash_equals($expected, $key)) fail('Invalid or missing service key.', 401);
}

switch ($action) {

// Called by Delivery's NFC "driver details for traffic officer" check
// to show live association-dues status on the card, instead of a
// manually-toggled field. Resolves the driver by etel_id first (a
// driver who signed into EtelTaxi with their real EtelID), falling
// back to phone (last 9 digits, to tolerate +251 vs 0 formatting
// differences between the two systems).
case 'service_check_association_payment': {
    taxi_require_service_key();
    $pdo = db();

    $etelId = isset($body['etel_id']) ? trim($body['etel_id']) : '';
    $phone  = isset($body['phone'])   ? trim($body['phone'])   : '';

    $driver = null;
    if ($etelId !== '') {
        $s = $pdo->prepare('SELECT * FROM ' . tbl('taxi_drivers') . ' WHERE etel_id = ? LIMIT 1');
        $s->execute(array($etelId));
        $driver = $s->fetch();
    }
    if (!$driver && $phone !== '') {
        $last9 = substr(preg_replace('/\D/', '', $phone), -9);
        if ($last9 !== '') {
            // RIGHT() rather than a regex function, since older MySQL/
            // MariaDB builds on shared hosting may not have
            // REGEXP_REPLACE - phone numbers are stored without
            // separators, so the last 9 digits line up whether the
            // prefix is "0", "+251", or "251".
            $s = $pdo->prepare('SELECT * FROM ' . tbl('taxi_drivers') . ' WHERE RIGHT(phone, 9) = ? LIMIT 1');
            $s->execute(array($last9));
            $driver = $s->fetch();
        }
    }

    if (!$driver) {
        ok(array('is_registered' => false));
    }

    $status = taxi_compute_driver_status($pdo, $driver['id'], $driver['association_id']);

    $as = $pdo->prepare('SELECT name_am, name_en FROM ' . tbl('taxi_associations') . ' WHERE id = ? LIMIT 1');
    $as->execute(array($driver['association_id']));
    $assoc = $as->fetch();

    ok(array(
        'is_registered' => true,
        'status'         => $status['status'],
        'current_period' => $status['current_period'],
        'window_end'     => $status['window_end'],
        'monthly_due'    => $status['monthly_due'],
        'penalty_owed'   => $status['penalty_owed'],
        'association'    => $assoc ? array('name_am' => $assoc['name_am'], 'name_en' => $assoc['name_en']) : null,
    ));
    break;
}

default:
    fail('Unknown service action: ' . $action, 404);
}
