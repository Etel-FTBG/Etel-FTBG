<?php
// ============================================================
// ETEL Delivery - shared order pricing  v1.0
// Deploy as: api/lib/delivery_pricing.php
//
// Extracted verbatim from OrderController.php's customer_create_order so
// AgentController.php's order-quote tool can compute the exact same
// delivery fee + service charge a real checkout would, without
// duplicating ~30 lines of zone/tier lookup logic that could drift out
// of sync. Requires api/config-etb.php already loaded (for
// $HAWASSA_DELIVERY_FEES, $DELIVERY_TIERS, SERVICE_CHARGE_PERCENT).
// ============================================================

if (!function_exists('delivery_calc_fee')) {

function delivery_calc_fee($pdo, $to_zone, $delivery_tier) {
    global $HAWASSA_DELIVERY_FEES, $DELIVERY_TIERS;

    // Zone-based delivery fee - DB table first (what Admin -> Pricing
    // actually edits), hardcoded array as fallback only if the table is
    // missing or doesn't have this zone yet.
    $zone_base_fee = null;
    try {
        $zq = $pdo->prepare('SELECT base_fare, surge_active, surge_mult FROM delivery_zones WHERE name = ? AND active = 1');
        $zq->execute(array($to_zone));
        $zrow = $zq->fetch();
        if ($zrow) {
            $zone_base_fee = floatval($zrow['base_fare']);
            if (!empty($zrow['surge_active']) && $zrow['surge_mult'] > 0) {
                $zone_base_fee = $zone_base_fee * floatval($zrow['surge_mult']);
            }
        }
    } catch (Exception $e) { /* table may not exist yet - fall back below */ }
    if ($zone_base_fee === null) {
        $zone_base_fee = isset($HAWASSA_DELIVERY_FEES[$to_zone]) ? $HAWASSA_DELIVERY_FEES[$to_zone]['fee'] : null;
    }
    if ($zone_base_fee === null) return null; // unknown zone - let the caller decide how to handle that

    // Delivery tier (moto / car / express cargo) - same DB-first pattern.
    $tier_multiplier = null;
    try {
        $tq = $pdo->prepare('SELECT tier_key, multiplier FROM delivery_tiers WHERE tier_key = ? AND active = 1');
        $tq->execute(array($delivery_tier));
        $trow = $tq->fetch();
        if ($trow) $tier_multiplier = floatval($trow['multiplier']);
    } catch (Exception $e) { /* table may not exist yet - fall back below */ }
    if ($tier_multiplier === null) {
        if (isset($DELIVERY_TIERS[$delivery_tier])) {
            $tier_multiplier = $DELIVERY_TIERS[$delivery_tier]['multiplier'];
        } else {
            $delivery_tier = 'moto';
            $tier_multiplier = 1.00;
        }
    }

    return array(
        'delivery_fee'  => round($zone_base_fee * $tier_multiplier),
        'tier_used'     => $delivery_tier,
    );
}

function delivery_calc_service_charge($items_subtotal) {
    return round($items_subtotal * SERVICE_CHARGE_PERCENT / 100, 2);
}

// Distance-based delivery fee - computed straight from GPS, no zone name
// needed at all. Uses the same pricing_rules table (base_fare,
// per_km_rate, min_fare) that was seeded early on but never actually
// wired into anything, since named zones were the only path until now.
// Falls back to those same seeded values hardcoded if the table row is
// ever missing, so this never silently breaks.
function delivery_calc_fee_by_distance($pdo, $distanceKm) {
    $get = function ($key, $default) use ($pdo) {
        try {
            $v = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key=? LIMIT 1");
            $v->execute(array($key));
            $row = $v->fetchColumn();
            return $row !== false ? floatval($row) : $default;
        } catch (Exception $e) {
            return $default;
        }
    };
    $baseFare  = $get('base_fare', 15.00);
    $perKmRate = $get('per_km_rate', 8.00);
    $minFare   = $get('min_fare', 30.00);

    $fee = $baseFare + ($perKmRate * $distanceKm);
    return array(
        'delivery_fee' => round(max($fee, $minFare)),
        'distance_km'  => round($distanceKm, 2),
    );
}

// Straight-line distance from Hawassa's own center point - used as the
// reference for distance-based fee calc when no zone name is given.
function delivery_distance_from_center($lat, $lng) {
    $centerLat = 7.0504; $centerLng = 38.4955;
    $R = 6371;
    $dLat = deg2rad($lat - $centerLat);
    $dLng = deg2rad($lng - $centerLng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($centerLat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Matches a customer's GPS position to the nearest zone that has
// coordinates (see seed-zone-coords.php), instead of requiring them to
// already know the exact zone name. Returns the zone name string, or
// null if no zone has coordinates yet (caller should fall back to
// asking for the zone name by hand in that case).
function delivery_nearest_zone($pdo, $lat, $lng) {
    try {
        $rows = $pdo->query("SELECT name, lat, lng FROM delivery_zones WHERE active=1 AND lat IS NOT NULL AND lng IS NOT NULL")->fetchAll();
    } catch (Exception $e) {
        return null;
    }
    if (!$rows) return null;

    $best = null; $bestDist = null;
    foreach ($rows as $z) {
        // Haversine distance - same formula used everywhere else in
        // this codebase for ride fare/distance (see lib/ride_fare.php).
        $R = 6371;
        $dLat = deg2rad($z['lat'] - $lat);
        $dLng = deg2rad($z['lng'] - $lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($z['lat'])) * sin($dLng / 2) ** 2;
        $dist = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
        if ($bestDist === null || $dist < $bestDist) { $bestDist = $dist; $best = $z['name']; }
    }
    return $best;
}

}
