<?php
// Launch incentives: driver trip bonuses and driver referral rewards, paid from Etel's launch budget.
//
// Money rules (all server-side, nothing is paid unless the admin switches the plan ON and sets a monthly budget):
//  - The plan is one JSON setting (pricing_rules key 'incentive_plan'), edited in Admin > Pricing > Incentives.
//  - A monthly budget (monthly_cap, ETB) is the hard ceiling for ALL incentives in a calendar month. 0 means nothing is paid.
//    driver_month_max (ETB, 0 = no limit) is the most one driver can receive in a month.
//  - DAILY TRIP BONUS: the plan lists daily tiers (trips, bonus ETB), e.g. 5 trips = ETB 30, 8 trips = ETB 60. When a driver completes a
//    tier's number of QUALIFYING trips in one day, that tier's bonus is paid once for that day. (The older driver_goal_tiers.bonus_etb
//    column is NOT used: it holds legacy numbers.)
//  - REFERRAL REWARD: a driver whose code (couriers.referred_by) brought in another driver is paid a reward once, when the
//    referred driver has completed referral_trips qualifying trips. At most referral_max_per_month rewards per referrer per month.
//  - A QUALIFYING trip is completed, paid at least min_fare (fare plus promo), at least min_km long, with a rider who is not
//    the driver, and (for the daily bonus) counts at most max_same_rider times per rider per day, so trips between the
//    same two people cannot farm bonuses. For a referral the referrer's own rides do not count, at most 3 trips per rider
//    count, and the trips must come from at least 3 different riders (or all of them if K is smaller).
//  - The money is credited to the driver's Etel money balance (ride_drivers.etel_money_balance), the same place promo
//    discounts funded by Etel go. Every award is one row in ride_incentive_awards (unique per kind, driver and reference, so
//    a trip can never be paid twice) and the amount is also stored on the ride that triggered it (rides.incentive_bonus,
//    rides.incentive_referral), so the investor report can count the spending without extra database access.
//  - An admin can void an award: the credit is taken back and the amount leaves the spending totals.
// ASCII only.

if (!function_exists('rides_incentive_default_plan')) {

function rides_incentive_default_plan() {
    return array(
        'on'                 => 0,
        'monthly_cap'        => 0,
        'driver_month_max'   => 0,
        'daily_on'           => 1,
        'daily_tiers'        => array(),
        'min_fare'           => 30,
        'min_km'             => 0.5,
        'max_same_rider'     => 2,
        'referral_on'        => 0,
        'referral_trips'     => 10,
        'referral_reward'    => 100,
        'referral_max_per_month' => 5
    );
}

function rides_incentive_clean_plan($p) {
    $d = rides_incentive_default_plan();
    if (!is_array($p)) $p = array();
    $num = function ($k, $lo, $hi, $int) use ($p, $d) {
        $v = isset($p[$k]) ? floatval($p[$k]) : $d[$k];
        if ($v < $lo) $v = $lo;
        if ($v > $hi) $v = $hi;
        return $int ? intval(round($v)) : round($v, 2);
    };
    $tiers = array();
    if (isset($p['daily_tiers']) && is_array($p['daily_tiers'])) {
        foreach ($p['daily_tiers'] as $t) {
            if (!is_array($t)) continue;
            $n = isset($t['trips']) ? intval($t['trips']) : 0;
            $b = isset($t['bonus']) ? round(floatval($t['bonus']), 2) : 0;
            if ($n < 1 || $n > 50 || $b <= 0 || $b > 5000) continue;
            $tiers[$n] = $b;   // one bonus per trip count
        }
    }
    ksort($tiers);
    $tierList = array();
    foreach (array_slice($tiers, 0, 8, true) as $n => $b) $tierList[] = array('trips' => $n, 'bonus' => $b);
    return array(
        'daily_tiers'        => $tierList,
        'on'                 => !empty($p['on']) ? 1 : 0,
        'monthly_cap'        => $num('monthly_cap', 0, 10000000, false),
        'driver_month_max'   => $num('driver_month_max', 0, 10000000, false),
        'daily_on'           => (isset($p['daily_on']) ? (!empty($p['daily_on']) ? 1 : 0) : $d['daily_on']),
        'min_fare'           => $num('min_fare', 0, 100000, false),
        'min_km'             => $num('min_km', 0, 100, false),
        'max_same_rider'     => $num('max_same_rider', 1, 50, true),
        'referral_on'        => !empty($p['referral_on']) ? 1 : 0,
        'referral_trips'     => $num('referral_trips', 1, 500, true),
        'referral_reward'    => $num('referral_reward', 0, 100000, false),
        'referral_max_per_month' => $num('referral_max_per_month', 1, 100, true)
    );
}

function rides_incentive_plan($pdo) {
    $plan = rides_incentive_default_plan();
    try {
        $v = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='incentive_plan' LIMIT 1")->fetchColumn();
        if ($v) {
            $j = json_decode($v, true);
            if (is_array($j)) $plan = array_merge($plan, $j);
        }
    } catch (Exception $e) { /* no plan saved: nothing is paid */ }
    return rides_incentive_clean_plan($plan);
}

function rides_incentive_save_plan($pdo, $plan) {
    $plan = rides_incentive_clean_plan($plan);
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('incentive_plan', ?, 'Launch incentives: driver trip bonuses and referral rewards, with a monthly budget (JSON)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array(json_encode($plan)));
    return $plan;
}

function rides_incentive_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ride_incentive_awards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(12) NOT NULL,
            driver_id INT NOT NULL,
            source_user INT NULL,
            ref_key VARCHAR(40) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            ride_id INT NULL,
            note VARCHAR(160) NULL,
            voided_at DATETIME NULL,
            voided_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_incentive (kind, driver_id, ref_key),
            KEY idx_incentive_created (created_at),
            KEY idx_incentive_driver (driver_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[ETEL] rides_incentive_ensure table: ' . $e->getMessage());
    }
    try {
        require_once dirname(__FILE__) . '/schema_helpers.php';
        rides_add_missing_cols($pdo, 'rides', array(
            'incentive_bonus'    => "incentive_bonus DECIMAL(10,2) NULL DEFAULT NULL",
            'incentive_referral' => "incentive_referral DECIMAL(10,2) NULL DEFAULT NULL"
        ));
    } catch (Exception $e) {
        error_log('[ETEL] rides_incentive_ensure columns: ' . $e->getMessage());
    }
}

// SQL for "what the rider paid plus the promo Etel funded" on a ride row (promo_discount may not exist on old installs).
function rides_incentive_fare_sql($pdo) {
    static $has = null;
    if ($has === null) {
        $has = false;
        try { if ($pdo->query("SHOW COLUMNS FROM `rides` LIKE 'promo_discount'")->fetch()) $has = true; } catch (Exception $e) { $has = false; }
    }
    return '(COALESCE(fare_paid,0)' . ($has ? ' + COALESCE(promo_discount,0)' : '') . ')';
}

// Qualifying trips per rider for one driver in a window (SQL date bounds on completed_at), under the plan's rules.
// Returns array(passenger_id => count). $excludePassenger drops that rider (the referrer riding with a friend).
function rides_incentive_qualifying($pdo, $driverId, $fromSql, $toSql, $minFare, $minKm, $excludePassenger) {
    $out = array();
    $sql = "SELECT passenger_id, COUNT(*) AS c FROM rides
            WHERE driver_id=? AND status='completed' AND completed_at >= $fromSql AND completed_at < $toSql
              AND " . rides_incentive_fare_sql($pdo) . " >= ?
              AND COALESCE(NULLIF(traveled_km,0), distance_km, 0) >= ?
              AND passenger_id IS NOT NULL AND passenger_id <> ? AND passenger_id <> ?
            GROUP BY passenger_id";
    try {
        $q = $pdo->prepare($sql);
        $q->execute(array(intval($driverId), floatval($minFare), floatval($minKm), intval($driverId), intval($excludePassenger)));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[intval($r['passenger_id'])] = intval($r['c']);
    } catch (Exception $e) { error_log('[ETEL][incentives] qualifying: ' . $e->getMessage()); }
    return $out;
}

function rides_incentive_month_spent($pdo, $driverId = 0) {
    rides_incentive_ensure($pdo);
    $sql = "SELECT COALESCE(SUM(amount),0) FROM ride_incentive_awards WHERE voided_at IS NULL AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
    $args = array();
    if ($driverId) { $sql .= " AND driver_id=?"; $args[] = intval($driverId); }
    try { $q = $pdo->prepare($sql); $q->execute($args); return round(floatval($q->fetchColumn()), 2); }
    catch (Exception $e) { return 0.0; }
}

// Pays one award if the budget allows. Returns the amount paid (0 when skipped). Never throws.
function rides_incentive_pay($pdo, $plan, $kind, $driverId, $sourceUser, $refKey, $amount, $rideId, $note, $col) {
    $amount = round(floatval($amount), 2);
    if ($amount <= 0) return 0.0;
    try {
        rides_incentive_ensure($pdo);
        if (floatval($plan['monthly_cap']) <= 0) return 0.0;
        if (rides_incentive_month_spent($pdo) + $amount > floatval($plan['monthly_cap']) + 0.0001) return 0.0;   // the budget is the ceiling
        if (floatval($plan['driver_month_max']) > 0 && rides_incentive_month_spent($pdo, $driverId) + $amount > floatval($plan['driver_month_max']) + 0.0001) return 0.0;   // one driver's ceiling
        $ins = $pdo->prepare("INSERT IGNORE INTO ride_incentive_awards (kind, driver_id, source_user, ref_key, amount, ride_id, note) VALUES (?,?,?,?,?,?,?)");
        $ins->execute(array($kind, intval($driverId), $sourceUser ? intval($sourceUser) : null, $refKey, $amount, $rideId ? intval($rideId) : null, $note));
        if ($ins->rowCount() < 1) return 0.0;   // already paid for this reference
        $awardId = intval($pdo->lastInsertId());
        $cr = $pdo->prepare("UPDATE ride_drivers SET etel_money_balance = etel_money_balance + ? WHERE user_id=?");
        $cr->execute(array($amount, intval($driverId)));
        if ($cr->rowCount() < 1) {   // no driver account to credit: undo
            $pdo->prepare("DELETE FROM ride_incentive_awards WHERE id=?")->execute(array($awardId));
            return 0.0;
        }
        if ($rideId && ($col === 'incentive_bonus' || $col === 'incentive_referral')) {
            $pdo->prepare("UPDATE rides SET $col = COALESCE($col,0) + ? WHERE id=?")->execute(array($amount, intval($rideId)));
        }
        return $amount;
    } catch (Exception $e) {
        error_log('[ETEL][incentives] pay: ' . $e->getMessage());
        return 0.0;
    }
}

// Called once when a ride completes (rides_complete_and_pay). Never changes the trip's own payout; never throws.
function rides_incentives_after_complete($pdo, $rideId, $driverId) {
    $paid = array('daily' => 0.0, 'referral' => 0.0);
    try {
        $plan = rides_incentive_plan($pdo);
        if (empty($plan['on']) || floatval($plan['monthly_cap']) <= 0) return $paid;
        rides_incentive_ensure($pdo);
        $driverId = intval($driverId);

        if (!empty($plan['daily_on'])) {
            $per = rides_incentive_qualifying($pdo, $driverId, 'CURDATE()', 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)', $plan['min_fare'], $plan['min_km'], 0);
            $qual = 0;
            foreach ($per as $c) $qual += min($c, intval($plan['max_same_rider']));
            $day = date('Y-m-d');
            foreach ($plan['daily_tiers'] as $t) {
                if ($qual < intval($t['trips'])) continue;
                $paid['daily'] += rides_incentive_pay($pdo, $plan, 'daily', $driverId, 0, 'd:' . $day . ':' . intval($t['trips']), $t['bonus'], $rideId,
                    intval($t['trips']) . ' trips on ' . $day, 'incentive_bonus');
            }
        }

        if (!empty($plan['referral_on']) && floatval($plan['referral_reward']) > 0) {
            $paid['referral'] += rides_incentive_referral($pdo, $plan, $rideId, $driverId);
        }
    } catch (Exception $e) {
        error_log('[ETEL][incentives] after_complete: ' . $e->getMessage());
    }
    return $paid;
}

// The referral reward for the driver who referred $driverId, if the referred driver has now earned it.
function rides_incentive_referral($pdo, $plan, $rideId, $driverId) {
    try {
        $q = $pdo->prepare("SELECT referred_by FROM couriers WHERE user_id=? LIMIT 1");
        $q->execute(array($driverId));
        $code = trim((string)$q->fetchColumn());
        if ($code === '') return 0.0;
        $q = $pdo->prepare("SELECT user_id FROM couriers WHERE referral_code=? LIMIT 1");
        $q->execute(array($code));
        $ref = intval($q->fetchColumn());
        if (!$ref || $ref === intval($driverId)) return 0.0;
        // the referrer must be a driver account that is not suspended
        // (ride_drivers.suspended is added the first time an admin suspends a driver, so an install can lack it)
        $hasSusp = false;
        try { if ($pdo->query("SHOW COLUMNS FROM `ride_drivers` LIKE 'suspended'")->fetch()) $hasSusp = true; } catch (Exception $e) { $hasSusp = false; }
        $q = $pdo->prepare("SELECT " . ($hasSusp ? "COALESCE(suspended,0)" : "0") . " FROM ride_drivers WHERE user_id=?");
        $q->execute(array($ref));
        $susp = $q->fetchColumn();
        if ($susp === false || intval($susp) === 1) return 0.0;
        // already rewarded for this referred driver?
        $q = $pdo->prepare("SELECT COUNT(*) FROM ride_incentive_awards WHERE kind='referral' AND driver_id=? AND ref_key=?");
        $q->execute(array($ref, 'r:' . intval($driverId)));
        if (intval($q->fetchColumn()) > 0) return 0.0;
        // the referrer's monthly limit
        $q = $pdo->prepare("SELECT COUNT(*) FROM ride_incentive_awards WHERE kind='referral' AND driver_id=? AND voided_at IS NULL AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $q->execute(array($ref));
        if (intval($q->fetchColumn()) >= intval($plan['referral_max_per_month'])) return 0.0;
        // the referred driver's real trips: not with the referrer as the rider, at most 3 per rider, from at least 3 different riders
        $per = rides_incentive_qualifying($pdo, $driverId, "'2000-01-01'", "'2100-01-01'", $plan['min_fare'], $plan['min_km'], $ref);
        $qual = 0;
        foreach ($per as $c) $qual += min($c, 3);
        $k = intval($plan['referral_trips']);
        if ($qual < $k || count($per) < min($k, 3)) return 0.0;
        return rides_incentive_pay($pdo, $plan, 'referral', $ref, $driverId, 'r:' . intval($driverId), $plan['referral_reward'], $rideId,
            'driver #' . intval($driverId) . ' reached ' . $k . ' trips', 'incentive_referral');
    } catch (Exception $e) {
        error_log('[ETEL][incentives] referral: ' . $e->getMessage());
        return 0.0;
    }
}

// Takes an award back (fraud, mistake): the credit leaves the driver's Etel money balance and the spending totals.
function rides_incentive_void($pdo, $awardId, $adminId) {
    rides_incentive_ensure($pdo);
    $q = $pdo->prepare("SELECT * FROM ride_incentive_awards WHERE id=?");
    $q->execute(array(intval($awardId)));
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) return array(false, 'Award not found.');
    if ($a['voided_at'] !== null) return array(false, 'This award is already voided.');
    $claim = $pdo->prepare("UPDATE ride_incentive_awards SET voided_at=NOW(), voided_by=? WHERE id=? AND voided_at IS NULL");
    $claim->execute(array(intval($adminId), intval($awardId)));
    if ($claim->rowCount() < 1) return array(false, 'This award is already voided.');
    $pdo->prepare("UPDATE ride_drivers SET etel_money_balance = etel_money_balance - ? WHERE user_id=?")->execute(array(floatval($a['amount']), intval($a['driver_id'])));
    if ($a['ride_id']) {
        $col = $a['kind'] === 'referral' ? 'incentive_referral' : 'incentive_bonus';
        try { $pdo->prepare("UPDATE rides SET $col = GREATEST(COALESCE($col,0) - ?, 0) WHERE id=?")->execute(array(floatval($a['amount']), intval($a['ride_id']))); }
        catch (Exception $e) { error_log('[ETEL][incentives] void ride column: ' . $e->getMessage()); }
    }
    return array(true, 'Award voided.');
}

}
