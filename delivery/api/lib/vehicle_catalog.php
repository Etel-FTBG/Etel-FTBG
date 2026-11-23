<?php
// The real vehicle types, in one place, for Admin (courier drawer, courier filter, Package prices) and the customer cart.
//   - the types a courier can be registered with (motorcycle, bicycle, car, EV car, bajaj, qute, minibus, on foot)
//   - the package vehicle classes staff price in Admin -> Package prices (bike, moto, car, van and any type they added)
//   - how many couriers each class has (registered, approved, online now), and the courier vehicles that match no class.
// Read-only. ASCII only. Never throws: no table = an empty answer.
require_once __DIR__ . '/package_orders.php';
require_once __DIR__ . '/courier_approval.php';

// Vehicle names a courier can be registered with. key => array(label, icon, class the package prices treat it as, or '' when none).
function etel_vc_courier_types() {
    return array(
        'motorcycle' => array('Motorcycle',       "\xF0\x9F\x8F\x8D\xEF\xB8\x8F", 'moto'),
        'bicycle'    => array('Bicycle',          "\xF0\x9F\x9A\xB2",             'bike'),
        'on_foot'    => array('On foot',          "\xF0\x9F\x9A\xB6",             'bike'),
        'car'        => array('Car',              "\xF0\x9F\x9A\x97",             'car'),
        'ev_car'     => array('Electric car (EV)', "\xF0\x9F\x9A\x97",            'car'),
        'minibus'    => array('Minibus',          "\xF0\x9F\x9A\x90",             'van'),
        'bajaj'      => array('Bajaj',            "\xF0\x9F\x9B\xBA",             ''),
        'qute'       => array('Qute',             "\xF0\x9F\x9B\xBA",             ''),
    );
}

// Count couriers by the vehicle name they have. Returns array(name => array('total' => n, 'online' => n)); '' = no vehicle set.
function etel_vc_courier_counts($pdo) {
    $out = array();
    try {
        $cols = array();
        foreach ($pdo->query('SHOW COLUMNS FROM couriers')->fetchAll(PDO::FETCH_ASSOC) as $c) { $cols[$c['Field']] = true; }
        $where = array('1 = 1');
        if (isset($cols['active'])) $where[] = 'c.active <> 0';
        if (etel_courier_approval_ready($pdo)) $where[] = "c.approval_status = 'approved'";
        $online = isset($cols['status']) ? "SUM(c.status = 'online')" : '0';
        $sql = 'SELECT LOWER(IFNULL(c.vehicle_type, \'\')) AS v, COUNT(*) AS total, ' . $online . ' AS online FROM couriers c JOIN users u ON u.id = c.user_id WHERE '
             . implode(' AND ', $where) . ' AND u.active <> 0 GROUP BY v';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[$r['v']] = array('total' => intval($r['total']), 'online' => intval($r['online'])); }
    } catch (Exception $e) { error_log('[ETEL] vehicle counts: ' . $e->getMessage()); }
    return $out;
}

// The catalog. Each class: class, label, icon, rank, active, max_weight_kg, names[], couriers, online, price_5km (what a 5 km, 3 kg package costs).
// unmapped = courier vehicle names that no class serves (they get no package jobs), with their courier count.
function etel_vehicle_catalog($pdo) {
    $classes = array(); $unmapped = array();
    $counts = etel_vc_courier_counts($pdo);
    $builtin = etel_package_builtin_names();
    $custom = etel_package_custom_defs($pdo);
    $rates = etel_package_rates($pdo, false);
    $perClass = array();
    foreach ($counts as $name => $n) {
        $cls = etel_vehicle_class_of($name, $pdo);
        if ($cls === '') { $unmapped[] = array('name' => $name, 'couriers' => $n['total'], 'online' => $n['online']); continue; }
        if (!isset($perClass[$cls])) $perClass[$cls] = array('total' => 0, 'online' => 0);
        $perClass[$cls]['total'] += $n['total']; $perClass[$cls]['online'] += $n['online'];
    }
    try { $tiers = etel_package_settings($pdo); } catch (Exception $e) { $tiers = null; }
    $ranks = etel_package_classes($pdo);
    foreach ($rates as $r) {
        $k = $r['vehicle_class'];
        $names = isset($builtin[$k]) ? $builtin[$k] : (isset($custom[$k]) ? $custom[$k]['names'] : array());
        $classes[] = array('class' => $k, 'label' => $r['label'], 'icon' => $r['icon'], 'rank' => intval($r['size_rank']), 'active' => intval($r['active']) === 1,
            'max_weight_kg' => floatval($r['max_weight_kg']), 'names' => $names, 'builtin' => isset($builtin[$k]),
            'price_5km' => etel_package_price($r, 5, 3, $tiers, isset($ranks[$k]) ? $ranks[$k] : 0),
            'couriers' => isset($perClass[$k]) ? $perClass[$k]['total'] : 0, 'online' => isset($perClass[$k]) ? $perClass[$k]['online'] : 0);
    }
    return array('classes' => $classes, 'unmapped' => $unmapped);
}

// The choices for a courier's "vehicle" field: every registerable type with the class it counts as, then each name staff added to a custom class.
function etel_vc_choices($pdo) {
    $out = array(); $seen = array();
    $labels = array();
    foreach (etel_package_rates($pdo, false) as $r) { $labels[$r['vehicle_class']] = $r['label']; }
    foreach (etel_vc_courier_types() as $k => $t) {
        $cls = etel_vehicle_class_of($k, $pdo);
        $out[] = array('value' => $k, 'label' => $t[0], 'icon' => $t[1], 'class' => $cls, 'class_label' => ($cls !== '' && isset($labels[$cls])) ? $labels[$cls] : '');
        $seen[$k] = true;
    }
    foreach (etel_package_custom_defs($pdo) as $cls => $d) {
        foreach ($d['names'] as $n) {
            if (isset($seen[$n]) || !preg_match('/^[a-z][a-z0-9_]{1,29}$/', $n)) continue;
            $out[] = array('value' => $n, 'label' => ucfirst(str_replace('_', ' ', $n)), 'icon' => '', 'class' => $cls, 'class_label' => isset($labels[$cls]) ? $labels[$cls] : '');
            $seen[$n] = true;
        }
    }
    return $out;
}

// Shop delivery: which delivery tier (Admin -> Pricing -> delivery tiers: moto / car / express_cargo) prices a vehicle class.
// A class key that already is a tier key (moto, car, express_cargo) stays; bicycle rides like a moto, a van like express cargo,
// and a vehicle staff added follows the size it was set closest to. Unknown keys come back as ''.
function etel_vc_shop_tier($pdo, $key) {
    $key = strtolower(trim((string) $key));
    if ($key === 'moto' || $key === 'car' || $key === 'express_cargo') return $key;
    if ($key === 'bike') return 'moto';
    if ($key === 'van') return 'express_cargo';
    $classes = etel_package_classes($pdo);
    if (!isset($classes[$key])) return '';
    $rank = intval($classes[$key]);
    return $rank <= 2 ? 'moto' : ($rank === 3 ? 'car' : 'express_cargo');
}

// The vehicles a customer can pick in the cart: every package vehicle that is switched on, with the shop-delivery multiplier and time of its tier,
// and how many couriers are online / registered for it. available = at least one courier online now. Smallest vehicle first.
function etel_vc_cart_vehicles($pdo) {
    $cat = etel_vehicle_catalog($pdo);
    $tiers = array();
    try {
        foreach ($pdo->query('SELECT tier_key, label, icon, multiplier, eta_delta_min FROM delivery_tiers WHERE active = 1')->fetchAll(PDO::FETCH_ASSOC) as $t) { $tiers[$t['tier_key']] = $t; }
    } catch (Exception $e) { /* table missing: the fixed fallback below */ }
    if (!$tiers) { $tiers = array('moto' => array('multiplier' => 1.00, 'eta_delta_min' => 0), 'car' => array('multiplier' => 1.12, 'eta_delta_min' => -2), 'express_cargo' => array('multiplier' => 1.60, 'eta_delta_min' => 5)); }
    $out = array();
    foreach ($cat['classes'] as $c) {
        if (!$c['active']) continue;
        $tk = etel_vc_shop_tier($pdo, $c['class']);
        if ($tk === '' || !isset($tiers[$tk])) continue;
        $out[] = array('tier_key' => $c['class'], 'vehicle_class' => $c['class'], 'label' => $c['label'], 'icon' => $c['icon'], 'rank' => $c['rank'],
            'multiplier' => floatval($tiers[$tk]['multiplier']), 'eta_delta_min' => intval($tiers[$tk]['eta_delta_min']), 'max_weight_kg' => $c['max_weight_kg'],
            'couriers' => $c['couriers'], 'online' => $c['online'], 'available' => $c['online'] > 0);
    }
    return $out;
}
