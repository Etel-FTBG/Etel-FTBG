<?php
// ============================================================
// ETEL Delivery - delivery fees by distance (see api/lib/delivery_fees.php). The admin controls everything.
//   delivery_fee_quote  { item_id | service_giver_id, lat, lng }   -> { mode, vehicles: {class: {fee, km}}, service_pct, too_far }   (public: the cart)
//   admin_dfee_get      {}                                          -> { settings, vehicles, stats, defaults }                          (settings.view)
//   admin_dfee_save     { settings: {...}, vehicles: {class: {base, per_km, min}} } -> the same, read back from the database           (settings.manage)
// The customer never sees the company / courier split: only the fee.
// ============================================================
require_once __DIR__ . '/../lib/delivery_fees.php';

switch ($action) {

case 'delivery_fee_quote': {
    $pdo = db();
    $sg = isset($body['service_giver_id']) ? intval($body['service_giver_id']) : 0;
    if (!$sg && !empty($body['item_id'])) {
        try { $q = $pdo->prepare('SELECT service_giver_id FROM items WHERE id = ?'); $q->execute(array(intval($body['item_id']))); $sg = intval($q->fetchColumn()); }
        catch (Exception $e) { $sg = 0; }
    }
    $lat = isset($body['lat']) && is_numeric($body['lat']) ? floatval($body['lat']) : 0;
    $lng = isset($body['lng']) && is_numeric($body['lng']) ? floatval($body['lng']) : 0;
    $cust = ($lat != 0 || $lng != 0) && abs($lat) <= 90 && abs($lng) <= 180 ? array($lat, $lng) : null;
    $shop = $sg ? etel_dfee_shop_point($pdo, $sg) : null;
    ok(etel_dfee_quote($pdo, $shop, $cust, (int) date('G')));
    break;
}

case 'admin_dfee_get': {
    $pdo = db(); require_admin($pdo, 'settings.view');
    ok(etel_dfee_admin_view($pdo));
    break;
}

case 'admin_dfee_save': {
    $pdo = db(); $u = require_admin($pdo, 'settings.manage');
    try {
        $saved = etel_dfee_save($pdo, array('settings' => isset($body['settings']) ? $body['settings'] : array(), 'vehicles' => isset($body['vehicles']) ? $body['vehicles'] : array()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save the delivery fees.', 500);
    }
    error_log('[ETEL][delivery-fees] changed by admin ' . intval($u['id']));
    $view = etel_dfee_admin_view($pdo);   // read back from the database, so the screen shows what is really saved
    $view['message'] = 'Saved. New orders use these fees at once.';
    ok($view);
    break;
}

}

/** What the admin page shows: the saved settings, each vehicle's price list with its supply, and what the last 30 days earned. */
function etel_dfee_admin_view($pdo) {
    etel_dfee_load($pdo, true);
    $g = etel_dfee_settings($pdo);
    $supply = array();
    try {
        require_once __DIR__ . '/../lib/vehicle_catalog.php';
        $cat = etel_vehicle_catalog($pdo);
        foreach ($cat['classes'] as $c) $supply[$c['class']] = $c;
    } catch (Exception $e) { error_log('[ETEL] dfee catalog: ' . $e->getMessage()); }
    $vehicles = array();
    foreach (etel_dfee_vehicles($pdo, false) as $v) {
        $r = etel_dfee_rate($pdo, $v['class'], $v['rank']);
        $d = etel_dfee_default_rate($v['class'], $v['rank']);
        $s = isset($supply[$v['class']]) ? $supply[$v['class']] : array('couriers' => 0, 'online' => 0);
        $vehicles[] = array('class' => $v['class'], 'label' => $v['label'], 'icon' => $v['icon'], 'rank' => $v['rank'], 'active' => $v['active'],
            'base' => $r['base'], 'per_km' => $r['per_km'], 'min' => $r['min'], 'default' => $d, 'couriers' => (int) $s['couriers'], 'online' => (int) $s['online']);
    }
    $stats = array('orders' => 0, 'fees' => 0, 'courier' => 0, 'company' => 0, 'service' => 0);
    try {
        etel_ensure_order_pay_columns($pdo);
        $q = $pdo->query("SELECT COUNT(*) AS n, IFNULL(SUM(delivery_fee),0) AS fees, IFNULL(SUM(courier_pay),0) AS pay, IFNULL(SUM(service_charge),0) AS svc
                          FROM orders WHERE placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status <> 'cancelled' AND courier_pay_note LIKE 'Fee %'");
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) $stats = array('orders' => (int) $r['n'], 'fees' => round($r['fees']), 'courier' => round($r['pay']), 'company' => round($r['fees'] - $r['pay']), 'service' => round($r['svc']));
    } catch (Exception $e) { /* orders table without the money columns: no numbers yet */ }
    return array('settings' => $g, 'defaults' => etel_dfee_defaults(), 'vehicles' => $vehicles, 'stats' => $stats);
}
