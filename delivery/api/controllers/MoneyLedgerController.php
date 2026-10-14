<?php
// ============================================================
// ETEL Delivery - refunds and courier cash bookkeeping for staff (admin only)
//   admin_refunds_report       { days? }                                   -> cancelled-but-paid orders still to refund, and refunds already recorded
//   admin_refund_mark          { order_id, method, reference?, note?, amount? } -> records a refund (once per order)
//   admin_courier_cash_report  {}                                          -> per courier: cash collected, handed over, still holding
//   admin_cash_remit           { courier_id, amount, note? }               -> records cash received from a courier
// Viewing needs finance.view, changing needs finance.manage. See api/lib/money_ledger.php.
// ============================================================
require_once __DIR__ . '/../lib/money_ledger.php';

switch ($action) {

case 'admin_refunds_report': {
    $pdo = db();
    require_admin($pdo, 'finance.view');
    $days = isset($body['days']) ? $body['days'] : (isset($_GET['days']) ? $_GET['days'] : 30);
    ok(etel_refunds_report($pdo, $days));
    break;
}

case 'admin_refund_mark': {
    $pdo = db();
    $admin = require_admin($pdo, 'finance.manage');
    $r = etel_refund_record($pdo, isset($body['order_id']) ? $body['order_id'] : 0, $admin['id'],
        isset($body['method']) ? $body['method'] : '', isset($body['reference']) ? $body['reference'] : '',
        isset($body['note']) ? $body['note'] : '', isset($body['amount']) ? $body['amount'] : null);
    if (!$r['ok']) fail($r['message'], 400);
    ok($r);
    break;
}

case 'admin_courier_cash_report': {
    $pdo = db();
    require_admin($pdo, 'finance.view');
    ok(etel_courier_cash_report($pdo));
    break;
}

case 'admin_cash_remit': {
    $pdo = db();
    $admin = require_admin($pdo, 'finance.manage');
    $r = etel_cash_remit_record($pdo, isset($body['courier_id']) ? $body['courier_id'] : 0, isset($body['amount']) ? $body['amount'] : 0,
        $admin['id'], isset($body['note']) ? $body['note'] : '');
    if (!$r['ok']) fail($r['message'], 400);
    ok($r);
    break;
}

}
