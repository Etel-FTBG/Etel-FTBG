<?php
// ============================================================
// ETEL - Admin: ride fares, vehicle types, goal tiers, peak windows, zones and delivery tiers (split out of AdminController.php to keep that file small).
// Loaded just before the main switch, in the same scope, so $pdo, $u, $body and $action are the ones it set up.
// An action that is not listed here falls through to the main switch, which answers "Unknown action" as before.
// ============================================================
if (!defined('APP_VER')) { http_response_code(403); exit; } // direct access guard

switch ($action) {


/* ==========================================================
   RIDE PRICING (per vehicle type) - migration 007
   Lets an admin change base fare / per-km rate / wait rate /
   minimum fares per vehicle type, and the peak-hour surge
   multiplier, without a code deploy. See RideController.php's
   rides_fare_rule()/rides_surge_multiplier() for how these are
   read at fare-calculation time (with hardcoded fallbacks if
   this migration hasn't run yet).
   ========================================================== */
case 'admin_get_ride_fare_rules': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $rules = array();
    try {
        // Double-quoted string literals here previously broke under a MySQL
        // session with ANSI_QUOTES in sql_mode (seen on this cPanel host),
        // which treats "bajaj" etc. as column identifiers instead of
        // strings and throws "Unknown column 'bajaj'". Single quotes are
        // unambiguous string literals regardless of sql_mode.
        $s = $pdo->query("SELECT vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active, updated_at FROM ride_fare_rules ORDER BY FIELD(vehicle_type,'bajaj','moto','car','minibus'), vehicle_type");
        $rules = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load ride fare rules - has migration 006_token_revocation.sql / 007_ride_fare_rules.sql been applied? (api/migrate.php --apply)', 500);
    }
    $surge = 1.3;
    try {
        $sv = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_surge_multiplier' LIMIT 1")->fetchColumn();
        if ($sv !== false && $sv !== null && $sv !== '') $surge = floatval($sv);
    } catch (Exception $e) { /* table may not exist yet - default stands */ }
    ok(array('rules' => $rules, 'ride_surge_multiplier' => $surge));
    break;
}


case 'admin_update_ride_fare_rule': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : '';
    // Was a fixed whitelist of 4 hardcoded names (bajaj/moto/car/minibus),
    // which blocked adding any new vehicle type - relaxed to a format
    // check (lowercase snake_case, 2-20 chars) matching how vehicle_type
    // is already read elsewhere (RideController.php's rides_fare_rule()
    // works for any string, only bajaj/moto/car/minibus were ever special
    // as fallback defaults, not as an enforced enum).
    if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $vt)) {
        fail('vehicle_type must be lowercase letters/numbers/underscore, 2-20 characters (e.g. tricycle, mini_truck)', 400);
    }
    $fields = array('base_fare','per_km_rate','per_km_rate_open','wait_rate_per_min','min_fare','min_fare_short_trip');
    $vals = array();
    foreach ($fields as $f) {
        $amt = isset($body[$f]) ? etel_validate_amount($body[$f], true) : false;
        if ($amt === false) fail("$f must be a valid non-negative amount", 400);
        $vals[$f] = $amt;
    }
    $active = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    try {
        $s = $pdo->prepare(
            'INSERT INTO ride_fare_rules (vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                base_fare=VALUES(base_fare), per_km_rate=VALUES(per_km_rate),
                per_km_rate_open=VALUES(per_km_rate_open), wait_rate_per_min=VALUES(wait_rate_per_min),
                min_fare=VALUES(min_fare), min_fare_short_trip=VALUES(min_fare_short_trip),
                active=VALUES(active)'
        );
        $s->execute(array($vt, $vals['base_fare'], $vals['per_km_rate'], $vals['per_km_rate_open'],
                          $vals['wait_rate_per_min'], $vals['min_fare'], $vals['min_fare_short_trip'], $active));
        ok(array('message' => 'Fare rule updated', 'vehicle_type' => $vt));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}


case 'admin_add_vehicle_type': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : '';
    if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $vt)) {
        fail('Vehicle type name must be lowercase letters/numbers/underscore, 2-20 characters (e.g. tricycle, mini_truck)', 400);
    }
    $base = isset($body['base_fare']) ? etel_validate_amount($body['base_fare'], true) : false;
    if ($base === false) fail('base_fare must be a valid non-negative amount', 400);
    $perKm = isset($body['per_km_rate']) ? etel_validate_amount($body['per_km_rate'], true) : false;
    if ($perKm === false) fail('per_km_rate must be a valid non-negative amount', 400);
    // Open-trip rate, wait rate, and minimum fares aren't asked for on the
    // "add" mini-form (keeps it to two required numbers) - seeded from the
    // base/per-km the admin just entered so the row is immediately usable,
    // and fully editable afterward via the normal per-row Save button.
    $perKmOpen = isset($body['per_km_rate_open']) ? etel_validate_amount($body['per_km_rate_open'], true) : $perKm;
    if ($perKmOpen === false) $perKmOpen = $perKm;
    $waitRate = isset($body['wait_rate_per_min']) ? etel_validate_amount($body['wait_rate_per_min'], true) : 3.00;
    if ($waitRate === false) $waitRate = 3.00;
    $minFare = isset($body['min_fare']) ? etel_validate_amount($body['min_fare'], true) : $base;
    if ($minFare === false) $minFare = $base;
    $minFareShort = isset($body['min_fare_short_trip']) ? etel_validate_amount($body['min_fare_short_trip'], true) : $base;
    if ($minFareShort === false) $minFareShort = $base;
    try {
        $exists = $pdo->prepare('SELECT id FROM ride_fare_rules WHERE vehicle_type=? LIMIT 1');
        $exists->execute(array($vt));
        if ($exists->fetch()) fail("Vehicle type '$vt' already exists - edit it in the table above instead", 400);
        $s = $pdo->prepare(
            'INSERT INTO ride_fare_rules (vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $s->execute(array($vt, $base, $perKm, $perKmOpen, $waitRate, $minFare, $minFareShort));
        ok(array('message' => 'Vehicle type added', 'vehicle_type' => $vt));
    } catch (Exception $e) {
        fail_ex($e, 'Could not add vehicle type - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}


case 'admin_delete_vehicle_type': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : '';
    if ($vt === '') fail('vehicle_type is required', 400);
    // Soft-delete only (active=0), same convention as admin_delete_zone -
    // drivers already registered under this vehicle type, and past rides
    // priced with it, keep their history; it just stops being offered.
    try {
        $pdo->prepare('UPDATE ride_fare_rules SET active=0 WHERE vehicle_type=?')->execute(array($vt));
        ok(array('message' => 'Vehicle type deactivated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not deactivate vehicle type - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}


case 'admin_update_ride_surge_multiplier': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $v = isset($body['value']) ? etel_validate_amount($body['value'], false) : false;
    if ($v === false || $v <= 0 || $v > 5) fail('value must be a number between 0 and 5 (e.g. 1.3 for +30%)', 400);
    try {
        $pdo->prepare(
            "INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_surge_multiplier', ?, 'Ride fare multiplier during peak hours (weekday rush + weekend evenings)')
             ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)"
        )->execute(array($v));
        ok(array('message' => 'Surge multiplier updated', 'ride_surge_multiplier' => $v));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}


// -- COMMISSION PLAN ------------------------------------------------
// The standard commission rate and the per-driver launch schedule (commission-free days, then a reduced rate, then the
// standard rate). See lib/ride_commission.php. Applies to trips completed after it is saved; completed trips keep the
// rate they were charged.
case 'admin_get_commission_plan': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    require_once __DIR__ . '/../lib/ride_commission.php';
    $plan = rides_commission_plan($pdo);
    $phases = array('holiday' => 0, 'step' => 0, 'standard' => 0);
    $sample = array();
    try {
        foreach ($pdo->query("SELECT u.id, u.created_at FROM users u JOIN ride_drivers d ON d.user_id=u.id")->fetchAll(PDO::FETCH_ASSOC) as $dr) {
            $cm = rides_commission_rate_on($plan, substr((string)$dr['created_at'], 0, 10), date('Y-m-d'));
            $phases[$cm['phase']]++;
        }
    } catch (Exception $e) { /* counts are optional */ }
    ok(array('plan' => $plan, 'drivers_by_phase' => $phases, 'today' => date('Y-m-d')));
    break;
}

case 'admin_save_commission_plan': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    require_once __DIR__ . '/../lib/ride_commission.php';
    $in = array(
        'standard_rate' => isset($body['standard_rate']) ? floatval($body['standard_rate']) : 0.05,
        'holiday_on'    => !empty($body['holiday_on']) ? 1 : 0,
        'starts_on'     => isset($body['starts_on']) ? trim($body['starts_on']) : '',
        'enroll_until'  => isset($body['enroll_until']) ? trim($body['enroll_until']) : '',
        'phase1_days'   => isset($body['phase1_days']) ? intval($body['phase1_days']) : 60,
        'phase1_rate'   => isset($body['phase1_rate']) ? floatval($body['phase1_rate']) : 0,
        'phase2_days'   => isset($body['phase2_days']) ? intval($body['phase2_days']) : 30,
        'phase2_rate'   => isset($body['phase2_rate']) ? floatval($body['phase2_rate']) : 0.02
    );
    if ($in['standard_rate'] < 0 || $in['standard_rate'] > 0.30) fail('The standard rate must be between 0 and 0.30 (0.05 = 5 percent).', 400);
    if ($in['phase1_rate'] < 0 || $in['phase2_rate'] < 0) fail('Rates cannot be negative.', 400);
    if ($in['phase1_rate'] > $in['standard_rate'] || $in['phase2_rate'] > $in['standard_rate']) fail('A launch rate cannot be higher than the standard rate.', 400);
    if ($in['phase1_days'] < 0 || $in['phase1_days'] > 365 || $in['phase2_days'] < 0 || $in['phase2_days'] > 365) fail('Days must be between 0 and 365.', 400);
    foreach (array('starts_on', 'enroll_until') as $k) {
        if ($in[$k] !== '' && rides_commission_valid_day($in[$k]) === '') fail('Dates must look like 2026-12-31.', 400);
    }
    if ($in['holiday_on'] && $in['starts_on'] === '') $in['starts_on'] = date('Y-m-d'); // switched on without a start day: it starts today
    try {
        $saved = rides_commission_save_plan($pdo, $in);
        ok(array('message' => 'Commission plan saved', 'plan' => $saved));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save the commission plan', 500);
    }
    break;
}


// ── DRIVER DAILY-GOAL TIERS ──────────────────────────────────────────
// Previously six values ("2 trips -> +ETB 250" etc.) hardcoded directly
// into driver/index.html - an admin had no way to change the trip
// counts or bonus amounts without editing code. Same self-healing/seed
// pattern as ride_fare_rules: rides_ensure_goal_tiers_table() creates
// the table on first use, seeded with the exact amounts that were
// previously hardcoded, so nothing changes until an admin edits a tier.
case 'admin_get_goal_tiers': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    rides_ensure_goal_tiers_table($pdo);
    try {
        $rows = $pdo->query("SELECT id, trips_required, bonus_etb, active, sort_order FROM driver_goal_tiers ORDER BY sort_order, trips_required")->fetchAll(PDO::FETCH_ASSOC);
        ok(array('tiers' => $rows));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load goal tiers', 500);
    }
    break;
}


case 'admin_save_goal_tier': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    rides_ensure_goal_tiers_table($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $trips = isset($body['trips_required']) ? intval($body['trips_required']) : 0;
    if ($trips < 1 || $trips > 200) fail('trips_required must be between 1 and 200', 400);
    // bonus_etb is legacy/unused now - the amount shown to a driver is
    // always computed live from THEIR OWN real average fare (see
    // driver_get_goal_tiers in RideController.php), not this column.
    // Kept in the schema in case a fixed-bonus mode is reintroduced
    // later, but the admin UI no longer exposes it, so default to 0.
    $bonus = isset($body['bonus_etb']) ? etel_validate_amount($body['bonus_etb'], true) : 0;
    if ($bonus === false) $bonus = 0;
    $active = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    $sortOrder = isset($body['sort_order']) ? intval($body['sort_order']) : $trips;
    try {
        if ($id > 0) {
            $pdo->prepare('UPDATE driver_goal_tiers SET trips_required=?, bonus_etb=?, active=?, sort_order=? WHERE id=?')
                ->execute(array($trips, $bonus, $active, $sortOrder, $id));
        } else {
            $existing = $pdo->prepare('SELECT id FROM driver_goal_tiers WHERE trips_required=?');
            $existing->execute(array($trips));
            if ($existing->fetch()) fail("A tier for $trips trips already exists - edit it instead", 400);
            $pdo->prepare('INSERT INTO driver_goal_tiers (trips_required, bonus_etb, active, sort_order) VALUES (?, ?, ?, ?)')
                ->execute(array($trips, $bonus, $active, $sortOrder));
        }
        ok(array('message' => 'Goal tier saved'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save goal tier', 500);
    }
    break;
}


case 'admin_delete_goal_tier': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    try {
        $pdo->prepare('DELETE FROM driver_goal_tiers WHERE id=?')->execute(array($id));
        ok(array('message' => 'Goal tier deleted'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not delete goal tier', 500);
    }
    break;
}


// ── PEAK-HOUR WINDOWS (when surge applies) ──────────────────────────
// Previously the actual peak-hour schedule (6-9am, 4-8pm daily, Fri/Sat
// 7pm+) was fixed in RideController.php with no way to change it short of
// a code deploy - only the multiplier itself was admin-editable. This
// pair of actions makes the schedule itself editable too, stored as JSON
// under pricing_rules('ride_peak_windows') and read by
// rides_peak_windows()/rides_active_peak_multiplier() in api/lib/ride_fare.php. An
// install that has never saved this falls back to the exact old
// hardcoded schedule, so behavior is unchanged until an admin actually
// changes it.
case 'admin_get_ride_peak_windows': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $windows = rides_default_peak_windows();
    $isDefault = true;
    try {
        $v = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_peak_windows' LIMIT 1")->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') {
            $decoded = json_decode($v, true);
            if (is_array($decoded) && count($decoded) > 0) { $windows = $decoded; $isDefault = false; }
        }
    } catch (Exception $e) { /* table may not exist yet - default stands */ }
    ok(array('windows' => $windows, 'is_default' => $isDefault));
    break;
}

case 'admin_update_ride_peak_windows': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $windows = isset($body['windows']) ? $body['windows'] : null;
    if (!is_array($windows)) fail('windows must be an array', 400);
    if (count($windows) > 20) fail('Too many windows (max 20)', 400);
    $timeRe = '/^([01]\d|2[0-4]):([0-5]\d)$/';
    $defaultMult = rides_surge_multiplier($pdo);
    $clean = array();
    foreach ($windows as $i => $w) {
        if (!is_array($w)) fail("Window #" . ($i + 1) . " is malformed", 400);
        $label = isset($w['label']) ? trim(substr((string)$w['label'], 0, 60)) : ('Window ' . ($i + 1));
        $days = isset($w['days']) && is_array($w['days']) ? array_values(array_unique(array_map('intval', $w['days']))) : array();
        $days = array_values(array_filter($days, function ($d) { return $d >= 1 && $d <= 7; }));
        if (!$days) fail("Window \"$label\" needs at least one day selected", 400);
        $start = isset($w['start']) ? trim($w['start']) : '';
        $end   = isset($w['end'])   ? trim($w['end'])   : '';
        if (!preg_match($timeRe, $start)) fail("Window \"$label\" has an invalid start time (use HH:MM)", 400);
        if (!preg_match($timeRe, $end))   fail("Window \"$label\" has an invalid end time (use HH:MM, 24:00 allowed for midnight)", 400);
        // end <= start is NOT an error - it's a legitimate overnight window
        // that wraps past midnight (e.g. 22:00 to 02:00 for a late-night
        // surge). rides_active_peak_multiplier() in ride_fare.php knows how
        // to match those correctly. Only reject the genuinely ambiguous
        // case where start and end are identical (zero-length or "always on"
        // - either way, not a real window).
        if ($end === $start) fail("Window \"$label\": start and end time can't be the same", 400);
        // Per-window multiplier - each window can now surge by a
        // different amount (e.g. weekend nights at 1.5x, weekday rush at
        // 1.2x) instead of one multiplier applying everywhere. Falls back
        // to the existing global "Peak-hour surge multiplier" field if a
        // window doesn't specify its own.
        $mult = isset($w['multiplier']) && $w['multiplier'] !== '' ? floatval($w['multiplier']) : $defaultMult;
        if ($mult <= 0 || $mult > 5) fail("Window \"$label\": multiplier must be between 0 and 5 (e.g. 1.3 for +30%)", 400);
        $clean[] = array('label' => $label, 'days' => $days, 'start' => $start, 'end' => $end, 'multiplier' => round($mult, 2));
    }
    try {
        $json = json_encode($clean);
        $pdo->prepare(
            "INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_peak_windows', ?, 'JSON schedule of when ride peak-hour surge pricing applies, and how much, per window')
             ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)"
        )->execute(array($json));
        ok(array('message' => 'Peak-hour schedule updated', 'windows' => $clean));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed - has migration 027_pricing_rules_widen_value.sql been applied?', 500);
    }
    break;
}


/* ==========================================================
   DELIVERY ZONES - migration 007
   Lets an admin add/edit/deactivate the zones customers pick
   for delivery orders (and the flat fee each one charges)
   without a code deploy. See OrderController.php's
   customer_create_order for how the fee is read (falls back
   to the old hardcoded config-etb.php table if a zone isn't
   found here), and boot.php's get_zones for the public list.
   ========================================================== */
case 'admin_get_zones': {
    $pdo = db();
    require_admin($pdo, 'settings.view');
    try {
        $s = $pdo->query('SELECT id, name, base_fare, est_minutes, surge_active, surge_mult, sort_order, active FROM delivery_zones ORDER BY sort_order ASC, name ASC');
        ok(array('zones' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load zones - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}


case 'admin_update_zone': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    $name = isset($body['name']) ? trim($body['name']) : '';
    if (!$name) fail('name is required', 400);
    $fee = isset($body['base_fare']) ? etel_validate_amount($body['base_fare'], true) : false;
    if ($fee === false) fail('base_fare must be a valid non-negative amount', 400);
    $est_minutes = isset($body['est_minutes']) ? max(0, intval($body['est_minutes'])) : 20;
    $sort_order  = isset($body['sort_order']) ? intval($body['sort_order']) : 0;
    $active      = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    try {
        $s = $pdo->prepare('UPDATE delivery_zones SET name=?, base_fare=?, est_minutes=?, sort_order=?, active=? WHERE id=?');
        $s->execute(array($name, $fee, $est_minutes, $sort_order, $active, $id));
        if ($s->rowCount() === 0) fail('Zone not found', 404);
        ok(array('message' => 'Zone updated', 'id' => $id));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}


case 'admin_add_zone': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $name = isset($body['name']) ? trim($body['name']) : '';
    if (!$name) fail('name is required', 400);
    $fee = isset($body['base_fare']) ? etel_validate_amount($body['base_fare'], true) : false;
    if ($fee === false) fail('base_fare must be a valid non-negative amount', 400);
    $est_minutes = isset($body['est_minutes']) ? max(0, intval($body['est_minutes'])) : 20;
    $sort_order  = isset($body['sort_order']) ? intval($body['sort_order']) : 99;
    try {
        $s = $pdo->prepare('INSERT INTO delivery_zones (name, base_fare, est_minutes, sort_order, active) VALUES (?, ?, ?, ?, 1)');
        $s->execute(array($name, $fee, $est_minutes, $sort_order));
        ok(array('message' => 'Zone added', 'id' => intval($pdo->lastInsertId())));
    } catch (Exception $e) {
        fail_ex($e, 'Add failed (zone name may already exist)', 500);
    }
    break;
}


/* ==========================================================
   Delivery tiers (moto / car / express cargo) - mirrors the
   delivery_zones admin pattern above. OrderController.php
   reads this table first (falls back to the old hardcoded
   $DELIVERY_TIERS array in config-etb.php if a tier isn't
   found here), and customer.js's get_delivery_tiers for the
   public tier picker.
   ========================================================== */
case 'admin_get_delivery_tiers': {
    $pdo = db();
    require_admin($pdo, 'settings.view');
    try {
        $s = $pdo->query('SELECT id, tier_key, label, icon, multiplier, eta_delta_min, sort_order, active FROM delivery_tiers ORDER BY sort_order ASC, label ASC');
        ok(array('tiers' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load delivery tiers - has migration 012_delivery_tiers.sql been applied?', 500);
    }
    break;
}


case 'admin_update_delivery_tier': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    $label = isset($body['label']) ? trim($body['label']) : '';
    if (!$label) fail('label is required', 400);
    $icon = isset($body['icon']) ? trim($body['icon']) : '';
    $multiplier = isset($body['multiplier']) ? etel_validate_amount($body['multiplier'], true) : false;
    if ($multiplier === false || $multiplier <= 0) fail('multiplier must be a valid positive number', 400);
    $eta_delta_min = isset($body['eta_delta_min']) ? intval($body['eta_delta_min']) : 0;
    $sort_order    = isset($body['sort_order']) ? intval($body['sort_order']) : 0;
    $active        = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    try {
        $s = $pdo->prepare('UPDATE delivery_tiers SET label=?, icon=?, multiplier=?, eta_delta_min=?, sort_order=?, active=? WHERE id=?');
        $s->execute(array($label, $icon, $multiplier, $eta_delta_min, $sort_order, $active, $id));
        if ($s->rowCount() === 0) fail('Tier not found', 404);
        ok(array('message' => 'Tier updated', 'id' => $id));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}


case 'admin_delete_zone': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    try {
        // Soft delete (deactivate) rather than a hard DELETE, consistent
        // with how service givers are suspended rather than removed -
        // existing orders/history referencing this zone name stay intact.
        $s = $pdo->prepare('UPDATE delivery_zones SET active=0 WHERE id=?');
        $s->execute(array($id));
        if ($s->rowCount() === 0) fail('Zone not found', 404);
        ok(array('message' => 'Zone deactivated', 'id' => $id));
    } catch (Exception $e) {
        fail_ex($e, 'Delete failed', 500);
    }
    break;
}

}
