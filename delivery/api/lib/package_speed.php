<?php
// ============================================================
// ETEL - Send a package: delivery speed (Standard or Express) and the booking switch.
// Settings (Admin > Packages > Rules, table package_settings): express_on (1/0), express_pct (extra % on the delivery fee),
// express_min (the smallest extra, ETB), booking_on (1 = customers can book, 0 = paused).
// All pure helpers; the setting reads go through etel_package_setting() in package_orders.php.
// ============================================================

// Pure. 'express' or 'standard' from whatever the app sent.
function etel_package_speed_clean($v) {
    return (is_string($v) && strtolower(trim($v)) === 'express') ? 'express' : 'standard';
}

// Pure. The Express extra in ETB for a standard fee: pct % of it, but never less than the minimum extra.
function etel_package_express_extra($std_price, $pct, $min_extra) {
    $extra = (int) round(floatval($std_price) * floatval($pct) / 100);
    $min = (int) round(floatval($min_extra));
    if ($extra < $min) $extra = $min;
    return $extra < 0 ? 0 : $extra;
}

// Pure. Express is quicker to the door: the road part of the time is about a quarter shorter, and never under 10 minutes.
function etel_package_express_eta_min($eta_min) {
    $e = (int) round(floatval($eta_min) * 0.75);
    return $e < 10 ? 10 : $e;
}

// Is Express offered, and on what terms. Returns array(on, pct, min).
function etel_package_speed_rules($pdo) {
    return array(
        'on'  => (etel_package_setting($pdo, 'express_on') >= 1),
        'pct' => floatval(etel_package_setting($pdo, 'express_pct')),
        'min' => floatval(etel_package_setting($pdo, 'express_min')),
    );
}

// Customers can book while this is true.
function etel_package_booking_open($pdo) {
    return etel_package_setting($pdo, 'booking_on') >= 1;
}

// Adds the Express fields to a quote's options: each option gets express_price (standard price + extra). Returns the quote's 'express' block.
function etel_package_quote_speed($pdo, &$options, $eta_min) {
    $r = etel_package_speed_rules($pdo);
    if (!$r['on']) return array('on' => false);
    foreach ($options as $i => $o) {
        $extra = etel_package_express_extra($o['price'], $r['pct'], $r['min']);
        $options[$i]['express_extra'] = $extra;
        $options[$i]['express_price'] = intval($o['price']) + $extra;
    }
    return array('on' => true, 'eta_min' => etel_package_express_eta_min($eta_min));
}
