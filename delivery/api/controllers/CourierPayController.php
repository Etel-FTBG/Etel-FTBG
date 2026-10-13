<?php
// ============================================================
// ETEL Delivery - courier pay and tip settings (admin controls everything; see api/lib/courier_pay.php)
//   admin_get_courier_pay      {}                                   -> { settings, defaults, stats }
//   admin_save_courier_pay     { enabled, base, per_km, ... }       -> { settings }
//   admin_preview_courier_pay  { pickup_km, drop_km, night, tier }  -> pay and every part of it, with the saved settings
//   admin_get_order_pay        { order_code | order_id }            -> what the customer paid, what the courier gets, history
//   admin_set_order_pay        { order_id, amount|null, reason }    -> override one order's courier pay (logged)
//   get_tip_options            {}                                   -> { presets, max }   (any signed-in user: the checkout buttons)
// ============================================================
require_once __DIR__ . '/../lib/courier_pay.php';

switch ($action) {

case 'get_tip_options': {
    $pdo = db();
    require_auth($pdo);
    $s = etel_cpay_settings($pdo);
    $presets = array();
    foreach (explode(',', $s['tip_presets']) as $p) $presets[] = (int) $p;
    ok(array('presets' => $presets, 'max' => $s['tip_max']));
    break;
}

case 'admin_get_courier_pay': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    etel_ensure_order_pay_columns($pdo);
    $stats = array('paid_by_distance_7d' => 0, 'avg_pay_7d' => 0, 'avg_fee_7d' => 0, 'tips_7d' => 0);
    try {
        $r = $pdo->query("SELECT SUM(courier_pay IS NOT NULL) AS n, AVG(COALESCE(courier_pay, delivery_fee)) AS avg_pay, AVG(delivery_fee) AS avg_fee,
                                 COALESCE(SUM(courier_tip),0) AS tips
                          FROM orders WHERE status = 'delivered' AND delivered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch(PDO::FETCH_ASSOC);
        if ($r) $stats = array('paid_by_distance_7d' => intval($r['n']), 'avg_pay_7d' => round(floatval($r['avg_pay']), 1),
                               'avg_fee_7d' => round(floatval($r['avg_fee']), 1), 'tips_7d' => round(floatval($r['tips']), 2));
    } catch (Exception $e) { /* stats are optional */ }
    ok(array('settings' => etel_cpay_settings($pdo), 'defaults' => etel_cpay_defaults(), 'stats' => $stats));
    break;
}

case 'admin_save_courier_pay': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    try { ok(array('settings' => etel_cpay_save($pdo, $body))); }
    catch (Exception $e) { fail_ex($e, 'Could not save the courier pay settings', 500); }
    break;
}

case 'admin_preview_courier_pay': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $s = etel_cpay_settings($pdo);
    $pk = isset($body['pickup_km']) ? floatval($body['pickup_km']) : 0;
    $dk = isset($body['drop_km']) ? floatval($body['drop_km']) : 0;
    $night = !empty($body['night']);
    $tier = isset($body['tier']) ? floatval($body['tier']) : 1.0;
    ok(array('settings_on' => !empty($s['enabled']), 'result' => etel_cpay_compute($s, max(0, min($pk, 100)), max(0, min($dk, 100)), $night, $tier > 0 ? min($tier, 5) : 1.0)));
    break;
}

case 'admin_get_order_pay': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    etel_ensure_order_pay_columns($pdo);
    $code = isset($body['order_code']) ? trim($body['order_code']) : '';
    $id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if ($code === '' && !$id) fail('Enter an order code', 400);
    $q = $pdo->prepare("SELECT o.id, o.order_code, o.status, o.delivery_tier, o.fare_total, o.delivery_fee, o.courier_tip, o.courier_pay, o.courier_pay_note,
                               o.courier_id, cu.name AS courier_name, o.placed_at, o.delivered_at
                        FROM orders o LEFT JOIN couriers c ON c.id = o.courier_id LEFT JOIN users cu ON cu.id = c.user_id
                        WHERE " . ($id ? 'o.id = ?' : 'o.order_code = ?') . " LIMIT 1");
    $q->execute(array($id ? $id : $code));
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) fail('Order not found', 404);
    $log = array();
    try {
        etel_cpay_ensure_log($pdo);
        $l = $pdo->prepare("SELECT l.old_pay, l.new_pay, l.reason, l.created_at, u.name AS admin_name FROM order_pay_log l LEFT JOIN users u ON u.id = l.admin_id
                            WHERE l.order_id = ? ORDER BY l.id DESC LIMIT 10");
        $l->execute(array($o['id']));
        $log = $l->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* log is optional */ }
    $pay = ($o['courier_pay'] !== null) ? floatval($o['courier_pay']) : floatval($o['delivery_fee']);
    ok(array('order' => $o, 'courier_gets' => round($pay + floatval($o['courier_tip']), 2), 'pay_source' => ($o['courier_pay'] !== null ? 'saved pay' : 'delivery fee'), 'log' => $log));
    break;
}

case 'admin_set_order_pay': {
    $pdo = db();
    $admin = require_admin($pdo, 'pricing.manage');
    etel_ensure_order_pay_columns($pdo);
    $id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$id) fail('order_id is required', 400);
    $reason = isset($body['reason']) ? trim((string) $body['reason']) : '';
    if ($reason === '') fail('Write the reason for the change (it is saved in the log)', 400);
    if (function_exists('mb_substr')) $reason = mb_substr($reason, 0, 200); else $reason = substr($reason, 0, 200);
    $clear = !isset($body['amount']) || $body['amount'] === null || $body['amount'] === '';
    $amount = null;
    if (!$clear) {
        if (!is_numeric($body['amount']) || floatval($body['amount']) < 0 || floatval($body['amount']) > 2000) fail('The amount must be between 0 and 2000 ETB', 400);
        $amount = round(floatval($body['amount']), 2);
    }
    $q = $pdo->prepare('SELECT id, status, courier_pay FROM orders WHERE id = ?');
    $q->execute(array($id));
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) fail('Order not found', 404);
    if ($o['status'] === 'cancelled') fail('This order was cancelled', 409);
    try {
        etel_cpay_ensure_log($pdo);
        $note = $clear ? 'cleared by admin: ' . $reason : 'set by admin: ' . $reason;
        if (function_exists('mb_substr')) $note = mb_substr($note, 0, 255); else $note = substr($note, 0, 255);
        $pdo->prepare('UPDATE orders SET courier_pay = ?, courier_pay_note = ? WHERE id = ?')->execute(array($amount, $note, $id));
        $pdo->prepare('INSERT INTO order_pay_log (order_id, admin_id, old_pay, new_pay, reason) VALUES (?,?,?,?,?)')
            ->execute(array($id, $admin['id'], $o['courier_pay'], $amount, $reason));
    } catch (Exception $e) { fail_ex($e, 'Could not save the change', 500); }
    ok(array('order_id' => $id, 'courier_pay' => $amount));
    break;
}

}
