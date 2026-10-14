<?php
/**
 * Courier pay engine. Everything here is controlled by the admin (Admin > Pricing > Courier pay).
 *
 *   OFF (default): the courier is paid the order's delivery fee, exactly as before.
 *   ON:            pay = (base + per-km x km) x night multiplier x tier multiplier, never below the minimum,
 *                  where km = courier -> shop (capped) + shop -> customer, each x a road factor over the straight line.
 *                  The amount is saved on the order (orders.courier_pay) the first time the courier's app sees the
 *                  order, so a later change of the settings never rewrites history. An admin can override any order.
 *   Orders without coordinates for the shop or the customer fall back to the delivery fee.
 *   The customer's tip is always added on top, 100% the courier's (orders.courier_tip).
 *
 * Settings live in pricing_rules (same table as the rest of the admin pricing).
 */

if (!defined('ETEL_MAX_TIP')) define('ETEL_MAX_TIP', 500);

function etel_cpay_defaults() {
    return array(
        'enabled'        => 0,
        'base'           => 25,
        'per_km'         => 7,
        'pickup_cap_km'  => 3,
        'min'            => 40,
        'road_factor'    => 1.3,
        'night_mult'     => 1.3,
        'night_start'    => 21,
        'night_end'      => 6,
        'tip_max'        => 500,
        'tip_presets'    => '10,20,30,50',
    );
}

function etel_cpay_keys() {
    return array(
        'enabled' => 'courier_pay_enabled', 'base' => 'courier_pay_base', 'per_km' => 'courier_pay_per_km',
        'pickup_cap_km' => 'courier_pay_pickup_cap_km', 'min' => 'courier_pay_min', 'road_factor' => 'courier_pay_road_factor',
        'night_mult' => 'courier_pay_night_mult', 'night_start' => 'courier_pay_night_start', 'night_end' => 'courier_pay_night_end',
        'tip_max' => 'tip_max', 'tip_presets' => 'tip_presets',
    );
}

function etel_cpay_num($v, $min, $max, $default) {
    if (!is_numeric($v)) return $default;
    $v = floatval($v);
    if (!is_finite($v)) return $default;
    return max($min, min($max, $v));
}

/** Pure: turn whatever the admin typed into safe settings. Anything missing or invalid keeps its default. */
function etel_cpay_clean($in) {
    $d = etel_cpay_defaults();
    $in = is_array($in) ? $in : array();
    $out = array();
    $out['enabled']       = (!empty($in['enabled']) && $in['enabled'] !== '0' && $in['enabled'] !== 'false') ? 1 : 0;
    $out['base']          = round(etel_cpay_num(isset($in['base']) ? $in['base'] : null, 0, 500, $d['base']), 2);
    $out['per_km']        = round(etel_cpay_num(isset($in['per_km']) ? $in['per_km'] : null, 0, 100, $d['per_km']), 2);
    $out['pickup_cap_km'] = round(etel_cpay_num(isset($in['pickup_cap_km']) ? $in['pickup_cap_km'] : null, 0, 20, $d['pickup_cap_km']), 2);
    $out['min']           = round(etel_cpay_num(isset($in['min']) ? $in['min'] : null, 0, 1000, $d['min']), 2);
    $out['road_factor']   = round(etel_cpay_num(isset($in['road_factor']) ? $in['road_factor'] : null, 1, 2, $d['road_factor']), 2);
    $out['night_mult']    = round(etel_cpay_num(isset($in['night_mult']) ? $in['night_mult'] : null, 1, 3, $d['night_mult']), 2);
    $out['night_start']   = (int) etel_cpay_num(isset($in['night_start']) ? $in['night_start'] : null, 0, 23, $d['night_start']);
    $out['night_end']     = (int) etel_cpay_num(isset($in['night_end']) ? $in['night_end'] : null, 0, 23, $d['night_end']);
    $out['tip_max']       = (int) round(etel_cpay_num(isset($in['tip_max']) ? $in['tip_max'] : null, 0, 5000, $d['tip_max']));
    // Tip presets: up to 6 whole amounts, each 1..tip_max, unique, ascending.
    $raw = isset($in['tip_presets']) ? $in['tip_presets'] : $d['tip_presets'];
    if (is_array($raw)) $raw = implode(',', $raw);
    $list = array();
    foreach (explode(',', (string) $raw) as $p) {
        $p = trim($p);
        if ($p === '' || !is_numeric($p)) continue;
        $p = (int) round(floatval($p));
        if ($p >= 1 && $p <= $out['tip_max']) $list[$p] = $p;
    }
    ksort($list);
    $list = array_slice(array_values($list), 0, 6);
    $out['tip_presets'] = $list ? implode(',', $list) : $d['tip_presets'];
    return $out;
}

function etel_cpay_settings($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = array();
    try {
        $rows = $pdo->query("SELECT rule_key, rule_value FROM pricing_rules WHERE rule_key LIKE 'courier_pay_%' OR rule_key IN ('tip_max','tip_presets')")->fetchAll(PDO::FETCH_ASSOC);
        $byKey = array();
        foreach ($rows as $r) $byKey[$r['rule_key']] = $r['rule_value'];
        foreach (etel_cpay_keys() as $name => $key) { if (isset($byKey[$key])) $raw[$name] = $byKey[$key]; }
    } catch (Exception $e) { /* table missing: defaults */ }
    $cache = etel_cpay_clean($raw);
    return $cache;
}

function etel_cpay_save($pdo, $in) {
    $s = etel_cpay_clean($in);
    $desc = array(
        'enabled' => 'Courier pay by distance: 1 = on, 0 = courier gets the delivery fee',
        'base' => 'Courier pay: base amount per order (ETB)', 'per_km' => 'Courier pay: ETB per km',
        'pickup_cap_km' => 'Courier pay: most km counted from the courier to the shop', 'min' => 'Courier pay: minimum per order (ETB)',
        'road_factor' => 'Courier pay: road distance as a multiple of the straight line', 'night_mult' => 'Courier pay: night multiplier',
        'night_start' => 'Courier pay: night starts at this hour (0-23)', 'night_end' => 'Courier pay: night ends at this hour (0-23)',
        'tip_max' => 'Largest tip a customer can add (ETB)', 'tip_presets' => 'Tip buttons shown at checkout (ETB, comma separated)',
    );
    $st = $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)");
    foreach (etel_cpay_keys() as $name => $key) $st->execute(array($key, (string) $s[$name], $desc[$name]));
    return $s;
}

/** Straight-line km between two points. */
function etel_cpay_km($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Pure: is this hour inside the night window (which may wrap past midnight, e.g. 21 to 6)? */
function etel_cpay_is_night($hour, $start, $end) {
    $hour = (int) $hour; $start = (int) $start; $end = (int) $end;
    if ($start === $end) return false;
    return $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
}

/**
 * Pure: the pay for one order from the straight-line distances. Returns the amount and every part of it.
 * $pickupKm / $dropKm are straight-line; the road factor and the pickup cap are applied here.
 */
function etel_cpay_compute($s, $pickupKm, $dropKm, $isNight, $tierMult) {
    $pickupRoad = min(max(0, $pickupKm) * $s['road_factor'], $s['pickup_cap_km']);
    $dropRoad   = max(0, $dropKm) * $s['road_factor'];
    $km = $pickupRoad + $dropRoad;
    $raw = $s['base'] + $s['per_km'] * $km;
    $night = $isNight ? $s['night_mult'] : 1.0;
    $tier  = $tierMult > 0 ? $tierMult : 1.0;
    $withMults = $raw * $night * $tier;
    $pay = max($s['min'], $withMults);
    return array(
        'pay'         => round($pay),
        'km_pickup'   => round($pickupRoad, 2),
        'km_drop'     => round($dropRoad, 2),
        'km_total'    => round($km, 2),
        'base_part'   => round($s['base'], 2),
        'distance_part' => round($s['per_km'] * $km, 2),
        'night'       => $isNight ? $s['night_mult'] : 1.0,
        'tier'        => $tier,
        'minimum_applied' => $withMults < $s['min'],
    );
}

/** Make sure the order money columns exist (migrations 073 and 074 add them; this covers a server where they have not run). */
function etel_ensure_order_pay_columns($pdo) {
    static $done = false;
    if ($done) return;
    $marker = __DIR__ . '/../.order_pay_columns_ok';   // keeps this off the courier app's polling path once the columns exist
    if (@file_exists($marker)) { $done = true; return; }
    try {
        $have = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `orders`")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[strtolower($c['Field'])] = true;
        $want = array(
            'courier_tip'      => "`courier_tip` DECIMAL(10,2) NOT NULL DEFAULT 0",
            'courier_pay'      => "`courier_pay` DECIMAL(10,2) NULL DEFAULT NULL",
            'courier_pay_note' => "`courier_pay_note` VARCHAR(255) NULL DEFAULT NULL",
        );
        foreach ($want as $name => $ddl) { if (!isset($have[$name])) $pdo->exec("ALTER TABLE `orders` ADD COLUMN $ddl"); }
        $done = true;
        @file_put_contents($marker, '1');
    } catch (Exception $e) { error_log('[ETEL] order pay columns: ' . $e->getMessage()); }
}

function etel_cpay_ensure_log($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_pay_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        admin_id INT UNSIGNED NOT NULL,
        old_pay DECIMAL(10,2) NULL,
        new_pay DECIMAL(10,2) NULL,
        reason VARCHAR(200) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_opl_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** orders.delivery_tier -> multiplier (delivery_tiers table, 1.0 when unknown). */
function etel_cpay_tier_mults($pdo) {
    $m = array();
    try {
        foreach ($pdo->query("SELECT tier_key, multiplier FROM delivery_tiers WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) $m[$r['tier_key']] = floatval($r['multiplier']);
    } catch (Exception $e) { /* table missing */ }
    return $m;
}

/**
 * Compute the pay for one order row (from etel_cpay_fetch_rows) for a courier at ($cLat, $cLng).
 * Returns null when pay by distance is off or the shop / customer position is unknown (the delivery fee applies).
 */
function etel_cpay_for_row($s, $row, $cLat, $cLng, $tierMults) {
    if (empty($s['enabled'])) return null;
    $sLat = isset($row['sg_lat']) ? floatval($row['sg_lat']) : 0; $sLng = isset($row['sg_lng']) ? floatval($row['sg_lng']) : 0;
    $dLat = isset($row['delivery_lat']) ? floatval($row['delivery_lat']) : 0; $dLng = isset($row['delivery_lng']) ? floatval($row['delivery_lng']) : 0;
    if (!$sLat || !$sLng || !$dLat || !$dLng) return null;
    $dropKm = etel_cpay_km($sLat, $sLng, $dLat, $dLng);
    $pickKm = ($cLat && $cLng) ? etel_cpay_km($cLat, $cLng, $sLat, $sLng) : 0;
    $hour = !empty($row['placed_at']) ? (int) date('G', strtotime($row['placed_at'])) : (int) date('G');
    $tier = isset($row['delivery_tier']) && isset($tierMults[$row['delivery_tier']]) ? $tierMults[$row['delivery_tier']] : 1.0;
    return etel_cpay_compute($s, $pickKm, $dropKm, etel_cpay_is_night($hour, $s['night_start'], $s['night_end']), $tier);
}

/** One query for everything the pay needs about a set of orders. */
function etel_cpay_fetch_rows($pdo, $ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return array();
    $in = implode(',', $ids);
    $out = array();
    try {
        $q = $pdo->query("SELECT o.id, o.status, o.courier_id, o.placed_at, o.delivery_tier, o.delivery_lat, o.delivery_lng,
                                 o.courier_pay, o.courier_tip, sg.lat AS sg_lat, sg.lng AS sg_lng
                          FROM orders o
                          LEFT JOIN order_assignments oa ON oa.order_id = o.id
                          LEFT JOIN service_givers sg ON sg.id = oa.service_giver_id
                          WHERE o.id IN ($in)");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[intval($r['id'])] = $r;
    } catch (Exception $e) {
        // Shop or order coordinates not stored on this server yet: still return tip and stored pay.
        try {
            $q = $pdo->query("SELECT id, status, courier_id, placed_at, delivery_tier, courier_pay, courier_tip FROM orders WHERE id IN ($in)");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[intval($r['id'])] = $r;
        } catch (Exception $e2) { /* leave empty */ }
    }
    return $out;
}
