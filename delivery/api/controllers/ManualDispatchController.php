<?php
// ============================================================
// ETEL Delivery - dispatch by hand for staff (admin only)
//   admin_dispatch_board     {}                             -> live orders needing attention + couriers to choose from
//   admin_assign_courier     { order_id, courier_id }       -> give an order that is not picked up yet to a courier (or change courier)
//   admin_take_back_order    { order_id }                   -> take it from its courier and offer it to the next free one
//   admin_cancel_order       { order_id, reason, note? }    -> cancel any live order with a reason (clears and frees the courier, logs it)
// Viewing needs orders.view, acting needs orders.manage. Every action is logged in order_admin_actions. See api/lib/manual_dispatch.php.
// ============================================================
require_once __DIR__ . '/../lib/manual_dispatch.php';
require_once __DIR__ . '/../lib/order_cancel.php';

switch ($action) {

case 'admin_dispatch_board': {
    $pdo = db();
    require_admin($pdo, 'orders.view');
    ok(etel_dispatch_board($pdo));
    break;
}

case 'admin_assign_courier': {
    $pdo = db();
    $admin = require_admin($pdo, 'orders.manage');
    $r = etel_admin_assign_courier($pdo, isset($body['order_id']) ? $body['order_id'] : 0, isset($body['courier_id']) ? $body['courier_id'] : 0, $admin['id']);
    if (!$r['ok']) fail($r['message'], 400);
    ok($r);
    break;
}

case 'admin_cancel_order': {
    $pdo = db();
    $admin = require_admin($pdo, 'orders.manage');
    $r = etel_cancel_order($pdo, isset($body['order_id']) ? $body['order_id'] : 0, 'admin', $admin['id'], isset($body['reason']) ? $body['reason'] : '', isset($body['note']) ? $body['note'] : '');
    if (!$r['ok']) fail($r['message'], 400);
    ok($r);
    break;
}

case 'admin_take_back_order': {
    $pdo = db();
    $admin = require_admin($pdo, 'orders.manage');
    $r = etel_admin_take_back($pdo, isset($body['order_id']) ? $body['order_id'] : 0, $admin['id']);
    if (!$r['ok']) fail($r['message'], 400);
    ok($r);
    break;
}

}
