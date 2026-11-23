<?php
// ============================================================
// A ride driver's extra job: deliveries (api/lib/driver_courier.php).
//   driver_courier_status  {}  -> { state: none|pending|approved|rejected|suspended, role, can_apply, note, ... }  (any signed-in user)
//   driver_courier_apply   {}  -> a ride driver asks for the courier job; an admin approves it in Admin -> Couriers
// ============================================================
require_once __DIR__ . '/../lib/driver_courier.php';

switch ($action) {

case 'driver_courier_status': {
    $pdo = db();
    $u = require_auth($pdo);
    ok(etel_dc_status($pdo, $u));
    break;
}

case 'driver_courier_apply': {
    $pdo = db();
    $u = require_auth($pdo);
    $r = etel_dc_apply($pdo, $u);
    if (!$r['ok']) fail($r['message'], 400);
    ok(array('message' => $r['message'], 'status' => $r['status']));
    break;
}

default:
    fail('Unknown action: ' . $action, 404);
}
