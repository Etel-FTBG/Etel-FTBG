<?php
/**
 * ETEL Delivery - fees by distance, one price list per vehicle, and the company / courier split.
 * Everything here is controlled by the admin (Admin > Delivery fees). ASCII only, PHP5 style, never throws on a missing table.
 *
 *   fee      = (base + per_km x road km) x night multiplier (only inside the night hours), never below the vehicle's minimum,
 *              rounded to the nearest "round step" (1, 5 or 10 ETB). road km = straight line shop -> customer x road factor.
 *   split    = the courier gets courier% of the fee (default 60), the company keeps the rest (default 40). The tip is always 100% the courier's.
 *   service  = a percent of the food/items subtotal. Default 0 (free); the admin can switch it on.
 *   fallback = when the shop or the customer position is unknown, or the switch is off, the old zone fee applies (lib/delivery_pricing.php).
 *
 * Settings live in pricing_rules (same table as the rest of the admin pricing):
 *   dfee_enabled, dfee_company_pct, dfee_service_pct, dfee_road_factor, dfee_round_step, dfee_max_km,
 *   dfee_night_mult, dfee_night_start, dfee_night_end, and per vehicle class: dfee_<class>_base, dfee_<class>_per_km, dfee_<class>_min.
 * Plain numbers only (the column may still be DECIMAL on an old server).
 */
require_once __DIR__ . '/courier_pay.php';

/** A message the customer may read as it is (for example: too far to deliver). delivery_order_core.php passes it through. */
if (!class_exists('EtelFeeException')) { class EtelFeeException extends Exception {} }

/** Starting prices for the real vehicle types. Bicycle: 45 ETB + 15 ETB per km (the owner's price). Others are sized up from it. */
function etel_dfee_default_rate($class, $rank) {
    $class = strtolower((string) $class);
    $table = array(
        'bike' => array(45, 15, 60),
        'moto' => array(55, 18, 75),
        'car'  => array(80, 28, 120),
        'van'  => array(120, 40, 180),
    );
    if (isset($table[$class])) $r = $table[$class];
    elseif (strpos($class, 'bajaj') !== false || strpos($class, 'qute') !== false) $r = array(65, 22, 90);
    else {
        $rank = (int) $rank;
        $r = $rank <= 1 ? $table['bike'] : ($rank == 2 ? $table['moto'] : ($rank == 3 ? $table['car'] : $table['van']));
    }
    return array('base' => $r[0], 'per_km' => $r[1], 'min' => $r[2]);
}

function etel_dfee_defaults() {
    return array(
        'enabled'     => 1,
        'company_pct' => 40,
        'service_pct' => 0,
        'road_factor' => 1.3,
        'round_step'  => 5,
        'max_km'      => 0,
        'night_mult'  => 1.0,
        'night_start' => 21,
        'night_end'   => 6,
    );
}

function etel_dfee_global_keys() {
    return array('enabled' => 'dfee_enabled', 'company_pct' => 'dfee_company_pct', 'service_pct' => 'dfee_service_pct',
        'road_factor' => 'dfee_road_factor', 'round_step' => 'dfee_round_step', 'max_km' => 'dfee_max_km',
        'night_mult' => 'dfee_night_mult', 'night_start' => 'dfee_night_start', 'night_end' => 'dfee_night_end');
}

function etel_dfee_class_ok($class) {
    return is_string($class) && preg_match('/^[a-z][a-z0-9_]{0,29}$/', $class) === 1;
}

/** Pure: whatever the admin typed -> safe global settings. Anything missing or invalid keeps its default. */
function etel_dfee_clean($in) {
    $d = etel_dfee_defaults();
    $in = is_array($in) ? $in : array();
    $out = array();
    $out['enabled']     = (isset($in['enabled']) ? (!empty($in['enabled']) && $in['enabled'] !== '0' && $in['enabled'] !== 'false') : (bool) $d['enabled']) ? 1 : 0;
    $out['company_pct'] = round(etel_cpay_num(isset($in['company_pct']) ? $in['company_pct'] : null, 0, 100, $d['company_pct']), 2);
    $out['service_pct'] = round(etel_cpay_num(isset($in['service_pct']) ? $in['service_pct'] : null, 0, 30, $d['service_pct']), 2);
    $out['road_factor'] = round(etel_cpay_num(isset($in['road_factor']) ? $in['road_factor'] : null, 1, 2, $d['road_factor']), 2);
    $step = (int) round(etel_cpay_num(isset($in['round_step']) ? $in['round_step'] : null, 1, 10, $d['round_step']));
    $out['round_step']  = in_array($step, array(1, 5, 10)) ? $step : $d['round_step'];
    $out['max_km']      = round(etel_cpay_num(isset($in['max_km']) ? $in['max_km'] : null, 0, 200, $d['max_km']), 1);
    $out['night_mult']  = round(etel_cpay_num(isset($in['night_mult']) ? $in['night_mult'] : null, 1, 3, $d['night_mult']), 2);
    $out['night_start'] = (int) etel_cpay_num(isset($in['night_start']) ? $in['night_start'] : null, 0, 23, $d['night_start']);
    $out['night_end']   = (int) etel_cpay_num(isset($in['night_end']) ? $in['night_end'] : null, 0, 23, $d['night_end']);
    return $out;
}

/** Pure: one vehicle's price list. Missing or invalid values keep the vehicle's starting price. */
function etel_dfee_clean_rate($in, $class, $rank) {
    $d = etel_dfee_default_rate($class, $rank);
    $in = is_array($in) ? $in : array();
    return array(
        'base'   => round(etel_cpay_num(isset($in['base']) ? $in['base'] : null, 0, 2000, $d['base']), 2),
        'per_km' => round(etel_cpay_num(isset($in['per_km']) ? $in['per_km'] : null, 0, 500, $d['per_km']), 2),
        'min'    => round(etel_cpay_num(isset($in['min']) ? $in['min'] : null, 0, 5000, $d['min']), 2),
    );
}

/** The vehicle classes a customer can choose (active ones), with label, icon and rank. Smallest first. */
function etel_dfee_vehicles($pdo, $only_active) {
    $out = array();
    try {
        require_once __DIR__ . '/package_orders.php';
        $rank = etel_package_classes($pdo);
        foreach (etel_package_rates($pdo, $only_active) as $r) {
            $k = $r['vehicle_class'];
            if (!etel_dfee_class_ok($k)) continue;
            $out[] = array('class' => $k, 'label' => $r['label'], 'icon' => $r['icon'], 'rank' => isset($rank[$k]) ? (int) $rank[$k] : (int) $r['size_rank'],
                'active' => ((int) $r['active'] === 1));
        }
    } catch (Exception $e) { error_log('[ETEL] dfee vehicles: ' . $e->getMessage()); }
    return $out;
}

/** All saved numbers in one query. Returns array('g' => global settings, 'raw' => array(rule_key => value)). */
function etel_dfee_load($pdo, $fresh) {
    static $cache = null;
    if ($fresh) $cache = null;
    if ($cache !== null) return $cache;
    $raw = array();
    try {
        $rows = $pdo->query("SELECT rule_key, rule_value FROM pricing_rules WHERE rule_key LIKE 'dfee\\_%'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $raw[$r['rule_key']] = $r['rule_value'];
    } catch (Exception $e) { /* table missing: defaults */ }
    $in = array();
    foreach (etel_dfee_global_keys() as $name => $key) { if (isset($raw[$key])) $in[$name] = $raw[$key]; }
    $cache = array('g' => etel_dfee_clean($in), 'raw' => $raw);
    return $cache;
}

function etel_dfee_settings($pdo, $fresh = false) {
    $c = etel_dfee_load($pdo, $fresh);
    return $c['g'];
}

/** One vehicle's saved price list (or its starting price). */
function etel_dfee_rate($pdo, $class, $rank) {
    $c = etel_dfee_load($pdo, false);
    $in = array();
    foreach (array('base', 'per_km', 'min') as $f) {
        $k = 'dfee_' . $class . '_' . $f;
        if (isset($c['raw'][$k])) $in[$f] = $c['raw'][$k];
    }
    return etel_dfee_clean_rate($in, $class, $rank);
}

/** Service charge percent of the items subtotal (0 = free). */
function etel_dfee_service_pct($pdo) {
    $s = etel_dfee_settings($pdo);
    return $s['service_pct'];
}

/**
 * Save global settings and per-vehicle price lists. $in = array('settings' => array(...), 'vehicles' => array(class => array(base, per_km, min))).
 * Returns the cleaned values that were written (the caller reads them back from the database to be sure).
 */
function etel_dfee_save($pdo, $in) {
    $g = etel_dfee_clean(isset($in['settings']) ? $in['settings'] : array());
    $st = $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)");
    $desc = array(
        'enabled' => 'Delivery fees by distance: 1 = on, 0 = zone fees', 'company_pct' => 'Delivery fee: the company keeps this percent, the courier gets the rest',
        'service_pct' => 'Service charge: percent of the items subtotal (0 = free)', 'road_factor' => 'Delivery fee: road distance as a multiple of the straight line',
        'round_step' => 'Delivery fee: round to the nearest 1, 5 or 10 ETB', 'max_km' => 'Delivery fee: farthest delivery in km (0 = no limit)',
        'night_mult' => 'Delivery fee: night multiplier', 'night_start' => 'Delivery fee: night starts at this hour (0-23)', 'night_end' => 'Delivery fee: night ends at this hour (0-23)',
    );
    foreach (etel_dfee_global_keys() as $name => $key) $st->execute(array($key, (string) $g[$name], $desc[$name]));
    $vehicles = isset($in['vehicles']) && is_array($in['vehicles']) ? $in['vehicles'] : array();
    $known = array();
    foreach (etel_dfee_vehicles($pdo, false) as $v) $known[$v['class']] = $v;
    $saved = array();
    foreach ($vehicles as $class => $rate) {
        $class = strtolower((string) $class);
        if (!isset($known[$class])) continue;   // only real vehicle types can be priced
        $r = etel_dfee_clean_rate($rate, $class, $known[$class]['rank']);
        foreach (array('base' => 'base fee', 'per_km' => 'ETB per km', 'min' => 'minimum fee') as $f => $label) {
            $st->execute(array('dfee_' . $class . '_' . $f, (string) $r[$f], 'Delivery fee, ' . $class . ': ' . $label));
        }
        $saved[$class] = $r;
    }
    etel_dfee_load($pdo, true);
    return array('settings' => $g, 'vehicles' => $saved);
}

/** Pure: split a fee into the courier part and the company part (whole ETB, the parts add up to the fee). */
function etel_dfee_split($fee, $companyPct) {
    $fee = max(0, round($fee));
    $courier = round($fee * (100 - $companyPct) / 100);
    return array('fee' => $fee, 'courier' => $courier, 'company' => $fee - $courier);
}

/** Pure: round to the nearest step (1, 5, 10). */
function etel_dfee_round($v, $step) {
    $step = max(1, (int) $step);
    return (int) (round($v / $step) * $step);
}

/**
 * Pure: the delivery fee for one trip with one vehicle's price list. $straightKm is shop -> customer in a straight line.
 * Returns the fee and every part of it.
 */
function etel_dfee_compute($g, $rate, $straightKm, $isNight) {
    $roadKm = max(0, $straightKm) * $g['road_factor'];
    $raw = $rate['base'] + $rate['per_km'] * $roadKm;
    $mult = $isNight ? $g['night_mult'] : 1.0;
    $withMult = $raw * $mult;
    $fee = max($rate['min'], $withMult);
    $fee = etel_dfee_round($fee, $g['round_step']);
    if ($fee < $rate['min']) $fee = (int) ceil($rate['min']);   // rounding never takes the fee under the minimum
    $split = etel_dfee_split($fee, $g['company_pct']);
    return array(
        'fee' => $split['fee'], 'courier' => $split['courier'], 'company' => $split['company'],
        'km_road' => round($roadKm, 2), 'km_straight' => round(max(0, $straightKm), 2),
        'base_part' => round($rate['base'], 2), 'distance_part' => round($rate['per_km'] * $roadKm, 2),
        'night' => $isNight ? $g['night_mult'] : 1.0, 'minimum_applied' => ($withMult < $rate['min']),
    );
}

/** The class that prices an order from its old delivery tier (old apps send moto / car / express_cargo, or nothing). */
function etel_dfee_class_for_tier($tier) {
    $tier = strtolower((string) $tier);
    if ($tier === 'car') return 'car';
    if ($tier === 'express_cargo') return 'van';
    return 'moto';
}

/** A shop's map position, or null when it has none. */
function etel_dfee_shop_point($pdo, $serviceGiverId) {
    try {
        $q = $pdo->prepare('SELECT lat, lng FROM service_givers WHERE id = ?');
        $q->execute(array((int) $serviceGiverId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r && is_numeric($r['lat']) && is_numeric($r['lng']) && (floatval($r['lat']) != 0 || floatval($r['lng']) != 0)) {
            return array(floatval($r['lat']), floatval($r['lng']));
        }
    } catch (Exception $e) { /* no coordinates on this server yet */ }
    return null;
}

/**
 * The fee for one order. Returns null when the distance fee cannot apply (switch off, a position unknown, unknown vehicle):
 * the caller then uses the zone fee as before. Returns array('too_far' => km) when the trip is longer than the admin's limit.
 * $class may be '' (old apps): the class then follows $tier.
 */
function etel_dfee_for_order($pdo, $class, $tier, $shop, $cust, $hour) {
    $g = etel_dfee_settings($pdo);
    if (empty($g['enabled']) || !$shop || !$cust) return null;
    $vehicles = etel_dfee_vehicles($pdo, true);
    $pick = null;
    $want = etel_dfee_class_ok((string) $class) ? $class : etel_dfee_class_for_tier($tier);
    foreach ($vehicles as $v) { if ($v['class'] === $want) $pick = $v; }
    if (!$pick && $class !== '') {   // an unknown or switched-off vehicle: price by the old tier
        $want = etel_dfee_class_for_tier($tier);
        foreach ($vehicles as $v) { if ($v['class'] === $want) $pick = $v; }
    }
    if (!$pick) return null;
    $km = etel_cpay_km($shop[0], $shop[1], $cust[0], $cust[1]);
    if ($g['max_km'] > 0 && $km * $g['road_factor'] > $g['max_km']) return array('too_far' => round($g['max_km'], 1));
    $isNight = etel_cpay_is_night($hour, $g['night_start'], $g['night_end']);
    $r = etel_dfee_compute($g, etel_dfee_rate($pdo, $pick['class'], $pick['rank']), $km, $isNight);
    $r['vehicle_class'] = $pick['class'];
    $r['note'] = 'Fee ' . $r['fee'] . ' ETB: courier ' . (100 - $g['company_pct']) . '% = ' . $r['courier'] . ', company ' . $g['company_pct'] . '% = ' . $r['company'] . ' (' . $r['km_road'] . ' km by road)';
    return $r;
}

/** The fee for every vehicle a customer can choose, for one shop and one customer position (the cart picker). */
function etel_dfee_quote($pdo, $shop, $cust, $hour) {
    $g = etel_dfee_settings($pdo);
    $out = array('mode' => 'zone', 'service_pct' => $g['service_pct'], 'vehicles' => array(), 'too_far' => 0);
    if (empty($g['enabled']) || !$shop || !$cust) return $out;
    $km = etel_cpay_km($shop[0], $shop[1], $cust[0], $cust[1]);
    if ($g['max_km'] > 0 && $km * $g['road_factor'] > $g['max_km']) { $out['mode'] = 'distance'; $out['too_far'] = round($g['max_km'], 1); return $out; }
    $isNight = etel_cpay_is_night($hour, $g['night_start'], $g['night_end']);
    foreach (etel_dfee_vehicles($pdo, true) as $v) {
        $r = etel_dfee_compute($g, etel_dfee_rate($pdo, $v['class'], $v['rank']), $km, $isNight);
        $out['vehicles'][$v['class']] = array('fee' => $r['fee'], 'km' => $r['km_road']);
    }
    $out['mode'] = $out['vehicles'] ? 'distance' : 'zone';
    $out['km'] = round($km * $g['road_factor'], 1);
    return $out;
}
