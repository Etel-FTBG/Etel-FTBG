<?php
// ============================================================
// ETEL Delivery/Ride - shared fare calculation  v1.0
// Deploy as: api/lib/ride_fare.php
//
// Extracted verbatim from RideController.php so both RideController.php
// (the real app) and AgentController.php (EtelAI's booking connector)
// use the exact same pricing - one source of truth, not two copies that
// could drift apart over time. Behavior is unchanged from before the
// extraction; this is a pure move, not a rewrite.
// ============================================================

/* Waiting-fee tracking columns - added independently of
   rides_ensure_schema() above, which stops checking anything once its
   own .schema_done sentinel file exists. That sentinel would silently
   prevent these columns from ever being created on an install where
   it's already present, so this is its own small, always-checked,
   per-request-cached self-heal instead. */
if (!function_exists('rides_ensure_wait_columns')) {
function rides_ensure_wait_columns($pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM `rides` LIKE 'wait_active'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE `rides`
                ADD COLUMN wait_active TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN wait_started_at DATETIME NULL,
                ADD COLUMN wait_seconds_total INT NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) { /* best-effort - waiting fee simply won't apply until this succeeds */ }
}
}

/* Driver suspension columns on ride_drivers - added on demand via
   rides_add_missing_cols() (lib/schema_helpers.php) at each call site
   instead of a dedicated self-heal function here, matching the existing
   pattern used for commission_owed/etel_money_balance etc. */

/* Current total wait minutes for a ride row (already-accumulated seconds
   plus, if a wait is actively running right now, the seconds since it
   started) - used everywhere the live fare needs to reflect waiting time
   in real time, not just after the driver stops the clock. */
if (!function_exists('rides_current_wait_minutes')) {
function rides_current_wait_minutes($row) {
    $seconds = isset($row['wait_seconds_total']) ? intval($row['wait_seconds_total']) : 0;
    if (!empty($row['wait_active']) && !empty($row['wait_started_at'])) {
        $seconds += max(0, time() - strtotime($row['wait_started_at']));
    }
    return $seconds / 60.0;
}
}

if (!function_exists('rides_fare_rule')) {

function rides_haversine_km($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)*sin($dLat/2) +
         cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)*sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

/* Dynamic fare engine v3.0 - admin-configurable per vehicle type.
   Rates used to be hardcoded here (one flat base/per-km/wait rate plus a
   per-vehicle-type multiplier: bajaj=1.0, moto=1.15, car=1.5,
   minibus=1.3). They now live in the ride_fare_rules table (see migration
   007_ride_fare_rules.sql) so an admin can change them per vehicle type
   from the panel without a code deploy - e.g. raise moto's per-km rate
   for a fuel price change without touching bajaj or car. */

/* Cached per-request (static array) so a single request that calls this
   several times (e.g. re-pricing on every driver_update_location tick)
   doesn't re-query the rates table each time. */
function rides_fare_rule($pdo, $vehicleType) {
    static $cache = array();
    if (isset($cache[$vehicleType])) return $cache[$vehicleType];

    // Same absolute numbers the old hardcoded multiplier table produced
    // for each type - used if ride_fare_rules is missing the row (e.g.
    // migration 007 hasn't run yet) so pricing never silently breaks.
    $fallback = array(
        'bajaj'   => array('base_fare'=>120.00,'per_km_rate'=>32.00,'per_km_rate_open'=>30.00,'wait_rate_per_min'=>3.00,'min_fare'=>120.00,'min_fare_short_trip'=>150.00),
        'moto'    => array('base_fare'=>138.00,'per_km_rate'=>36.80,'per_km_rate_open'=>34.50,'wait_rate_per_min'=>3.45,'min_fare'=>120.00,'min_fare_short_trip'=>150.00),
        'car'     => array('base_fare'=>180.00,'per_km_rate'=>48.00,'per_km_rate_open'=>45.00,'wait_rate_per_min'=>4.50,'min_fare'=>200.00,'min_fare_short_trip'=>200.00),
        'minibus' => array('base_fare'=>156.00,'per_km_rate'=>41.60,'per_km_rate_open'=>39.00,'wait_rate_per_min'=>3.90,'min_fare'=>120.00,'min_fare_short_trip'=>150.00),
    );

    $row = null;
    try {
        $s = $pdo->prepare('SELECT base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip
                             FROM ride_fare_rules WHERE vehicle_type=? AND active=1 LIMIT 1');
        $s->execute(array($vehicleType));
        $row = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* table may not exist yet - fall back below */ }

    $rule = $row ? array(
        'base_fare'           => floatval($row['base_fare']),
        'per_km_rate'         => floatval($row['per_km_rate']),
        'per_km_rate_open'    => floatval($row['per_km_rate_open']),
        'wait_rate_per_min'   => floatval($row['wait_rate_per_min']),
        'min_fare'            => floatval($row['min_fare']),
        'min_fare_short_trip' => floatval($row['min_fare_short_trip']),
    ) : (isset($fallback[$vehicleType]) ? $fallback[$vehicleType] : $fallback['bajaj']);

    $cache[$vehicleType] = $rule;
    return $rule;
}

/* Generic numeric setting from pricing_rules (key/value table), cached per
   request. Falls back to $default when the table or row does not exist yet,
   so a fresh install behaves exactly like the defaults below. */
function rides_rule_value($pdo, $key, $default) {
    static $cache = array();
    if (array_key_exists($key, $cache)) return $cache[$key];
    $val = $default;
    try {
        $s = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key=? LIMIT 1");
        $s->execute(array($key));
        $v = $s->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') $val = floatval($v);
    } catch (Exception $e) { /* table may not exist yet - keep default */ }
    $cache[$key] = $val;
    return $val;
}

/* Paying a ride from the Etel Pay wallet is "coming soon": off unless a pricing_rules row ride_wallet_enabled = 1 is added. */
if (!defined('RIDES_WALLET_SOON_MSG')) define('RIDES_WALLET_SOON_MSG', 'Wallet payment is coming soon. Please pay with cash or Chapa.');
function rides_wallet_enabled($pdo) {
    return intval(rides_rule_value($pdo, 'ride_wallet_enabled', 0)) === 1;
}

/* Most minutes of waiting that are ever BILLED on one ride (pricing_rules
   key 'ride_wait_cap_minutes'; 0 disables the cap). Without a cap, a wait
   clock left running - or a trip nobody closed - billed the waiting rate for
   hours: ride #283 (7 km moto) was charged ETB 4,808 for 23 h of waiting. */
function rides_wait_cap_minutes($pdo) {
    return max(0, rides_rule_value($pdo, 'ride_wait_cap_minutes', 30));
}

/* Admin-configurable peak-hour surge multiplier (pricing_rules table, key
   'ride_surge_multiplier'). Falls back to the old hardcoded 1.3 if the
   table/row doesn't exist yet. */
function rides_surge_multiplier($pdo) {
    static $mult = null;
    if ($mult !== null) return $mult;
    $mult = 1.3;
    try {
        $s = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_surge_multiplier' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') $mult = floatval($v);
    } catch (Exception $e) { /* table may not exist yet - keep default */ }
    return $mult;
}

/* Admin-configurable peak-hour WINDOWS (pricing_rules table, key
   'ride_peak_windows', JSON array). This used to be a fixed condition in
   code - (6-9am, 4-8pm daily, Fri/Sat 7pm+) - with no way to adjust it
   without a deploy. Now it's data: each window is
   {"label":"...", "days":[1..7], "start":"HH:MM", "end":"HH:MM",
   "multiplier":1.3} where days use PHP's date('N') numbering
   (1=Monday...7=Sunday), "end" may be "24:00" to mean end-of-day, and
   "multiplier" is that window's OWN surge multiplier (different windows
   can now surge by different amounts - e.g. weekend nights at 1.5x while
   weekday rush stays at 1.2x). Falls back to the exact old hardcoded
   windows (all at the old global 1.3x) if the row is missing, so
   behavior is unchanged for any install that hasn't set this yet. */
function rides_default_peak_windows() {
    return array(
        array('label' => 'Morning rush',   'days' => array(1,2,3,4,5,6,7), 'start' => '06:00', 'end' => '09:00', 'multiplier' => 1.3),
        array('label' => 'Evening rush',   'days' => array(1,2,3,4,5,6,7), 'start' => '16:00', 'end' => '20:00', 'multiplier' => 1.3),
        array('label' => 'Weekend nights', 'days' => array(5,6),           'start' => '19:00', 'end' => '24:00', 'multiplier' => 1.3),
    );
}
function rides_peak_windows($pdo) {
    static $windows = null;
    if ($windows !== null) return $windows;
    $windows = rides_default_peak_windows();
    try {
        $s = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_peak_windows' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') {
            $decoded = json_decode($v, true);
            if (is_array($decoded) && count($decoded) > 0) $windows = $decoded;
        }
    } catch (Exception $e) { /* table may not exist yet - keep default */ }
    return $windows;
}
function rides_time_to_minutes($hhmm) {
    $parts = explode(':', trim((string)$hhmm));
    $h = isset($parts[0]) ? intval($parts[0]) : 0;
    $m = isset($parts[1]) ? intval($parts[1]) : 0;
    return max(0, min(24, $h)) * 60 + max(0, min(59, $m));
}
/* Returns the multiplier of whichever configured window is active right
   now, or null if none match (i.e. not peak). Replaces the old boolean
   rides_is_peak_now() now that different windows can carry different
   multipliers - a plain true/false could no longer tell rides_calc_fare()
   which number to actually apply. If two windows somehow overlap, the
   first match in the saved order wins. */
function rides_active_peak_multiplier($pdo) {
    $hour = (int)date('H'); $minute = (int)date('i'); $dow = (int)date('N');
    $nowMin = $hour * 60 + $minute;
    $prevDow = ($dow === 1) ? 7 : $dow - 1;
    foreach (rides_peak_windows($pdo) as $w) {
        $days = empty($w['days']) ? array() : array_map('intval', $w['days']);
        $start = rides_time_to_minutes(isset($w['start']) ? $w['start'] : '00:00');
        $end   = rides_time_to_minutes(isset($w['end'])   ? $w['end']   : '00:00');
        $matched = false;
        if ($end > $start) {
            // Normal same-day window (e.g. 06:00-09:00): active on its own
            // configured days, between start and end.
            if (in_array($dow, $days, true) && $nowMin >= $start && $nowMin < $end) $matched = true;
        } else {
            // Overnight window that wraps past midnight (e.g. 22:00-02:00,
            // or 06:00-02:00 like "Morning" set to run until 2am the next
            // day). Splits into two checks: the late-night portion on the
            // window's own configured day (nowMin >= start), and the
            // early-morning portion that spills into the NEXT calendar day
            // (nowMin < end) - which from *that* day's perspective means
            // checking whether *yesterday* was a configured day.
            if (in_array($dow, $days, true) && $nowMin >= $start) $matched = true;
            if (!$matched && in_array($prevDow, $days, true) && $nowMin < $end) $matched = true;
        }
        if ($matched) {
            return isset($w['multiplier']) && floatval($w['multiplier']) > 0 ? floatval($w['multiplier']) : rides_surge_multiplier($pdo);
        }
    }
    return null;
}

function rides_calc_fare($pdo, $distKm, $vehicleType = 'bajaj', $openTrip = false, $waitMinutes = 0) {
    $rule = rides_fare_rule($pdo, $vehicleType);
    $activeMultiplier = rides_active_peak_multiplier($pdo);
    $is_peak = $activeMultiplier !== null;
    $surge = $is_peak ? $activeMultiplier : 1.0;
    $per_km = $openTrip ? $rule['per_km_rate_open'] : $rule['per_km_rate'];
    // Billable waiting time is capped (see rides_wait_cap_minutes).
    $waitMinutesRaw = $waitMinutes;
    $waitCap = rides_wait_cap_minutes($pdo);
    $waitCapped = ($waitCap > 0 && $waitMinutes > $waitCap);
    if ($waitCapped) $waitMinutes = $waitCap;
    /* Matches the customer-facing promise shown in the app:
       "ETB <base> base + ETB <per_km_rate_open> per km" for open trips -
       keep in sync with the client-side display formula in js/ride.js if
       an admin changes bajaj's rates (the default vehicle type shown
       there). */
    $f = ($rule['base_fare'] + $per_km*$distKm + $rule['wait_rate_per_min']*$waitMinutes) * $surge;
    $floor = $openTrip ? $rule['min_fare'] : (($distKm<=3) ? $rule['min_fare_short_trip'] : $rule['min_fare']);
    $final = max($floor, round($f, 2));
    return array('fare'=>$final,'surge'=>$is_peak,'breakdown'=>array(
        'base'=>$rule['base_fare'], 'dist_etb'=>round($per_km*$distKm,2),
        'wait_etb'=>round($rule['wait_rate_per_min']*$waitMinutes,2), 'wait_minutes'=>$waitMinutes,
        'wait_minutes_raw'=>$waitMinutesRaw, 'wait_capped'=>$waitCapped,
        'vehicle_type'=>$vehicleType, 'surge_mult'=>$surge, 'floor'=>$floor, 'final'=>$final,
    ));
}
function rides_calc_fare_simple($pdo, $distKm, $vehicleType='bajaj') {
    $r = rides_calc_fare($pdo, $distKm, $vehicleType);
    return $r['fare'];
}

/* Fare review threshold (pricing_rules key 'ride_review_fare_cap', ETB; 0 turns
   it off). A fare above it is never settled automatically: the trip is flagged
   in ride_issues ('fare_review') for an admin, the auto-complete sweeper skips
   it, and the passenger can still confirm it themselves. There used to be only
   a floor, so a runaway meter or a forgotten trip (e.g. ride #283, ETB 5,153 for
   7 km) was billed without anyone looking. */
function rides_fare_review_cap($pdo) {
    return max(0, rides_rule_value($pdo, 'ride_review_fare_cap', 2000));
}
function rides_fare_needs_review($pdo, $fare) {
    $cap = rides_fare_review_cap($pdo);
    return $cap > 0 && floatval($fare) > $cap;
}
/* Records one open 'fare_review' issue per ride (no duplicates). */
function rides_flag_fare_review($pdo, $rideId, $driverId, $fare, $km) {
    if (!rides_fare_needs_review($pdo, $fare) || !function_exists('rides_sweeper_issue')) return false;
    try {
        $q = $pdo->prepare("SELECT 1 FROM ride_issues WHERE ride_id=? AND category='fare_review' LIMIT 1");
        $q->execute(array(intval($rideId)));
        if ($q->fetchColumn()) return true;
    } catch (Exception $e) { return false; }
    rides_sweeper_issue($pdo, $rideId, $driverId, 'fare_review',
        'Fare ETB ' . number_format(floatval($fare), 2) . ' for ' . round(floatval($km), 2)
        . ' km is above the review limit (ETB ' . rides_fare_review_cap($pdo) . '). Check the trip before it is settled.', 'open');
    return true;
}

/* Vehicle types a rider may quote and book: the five built-in ones plus every ACTIVE type in
   ride_fare_rules. The booking, quote and nearby-driver actions each had their own fixed list of
   five names, so a type an admin added in the Pricing panel (ev_car, which riders already see in
   the vehicle list) was silently quoted and booked as a bajaj. Unknown names still fall back to
   bajaj, as before. */
function rides_normalize_vehicle_type($pdo, $vtype) {
    static $allowed = null;
    $vtype = strtolower(trim((string)$vtype));
    if ($allowed === null) {
        $allowed = array('bajaj', 'moto', 'car', 'minibus', 'qute');
        try {
            foreach ($pdo->query("SELECT LOWER(vehicle_type) FROM ride_fare_rules WHERE active=1")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if ($t !== '' && !in_array($t, $allowed, true)) $allowed[] = $t;
            }
        } catch (Exception $e) { /* table missing: the built-in five only */ }
    }
    return in_array($vtype, $allowed, true) ? $vtype : 'bajaj';
}

} // end function_exists guard

/* Both extracted from RideController.php (was defined right next to it,
   used only by driver_get_profile there) so boot.php's sse_driver_stream
   can call the exact same active-ride logic instead of maintaining its
   own hand-rolled duplicate query. That duplicate is what caused two
   separate bugs already (missing vehicle-type filter on nearby requests,
   then passenger_name/passenger_phone aliased differently and silently
   overwriting the correct data every SSE tick) - same root cause both
   times: a second implementation of "the driver's active ride" that
   nobody kept in sync with the real one. One shared function now, so
   there's only one place left to drift. Pure move, no behavior change. */
if (!function_exists('rides_driver_own_vtype')) {
/* The driver's own registered vehicle type - used as a fallback wherever
   a ride row itself doesn't carry a vehicle_type (older rows, or Street
   Hail rides before that column was added there) so pricing doesn't fall
   back to 'bajaj' regardless of what the driver actually drives. Cached
   per-request since driver_update_location calls this on every 5s tick. */
function rides_driver_own_vtype($pdo, $uid) {
    static $cache = array();
    if (isset($cache[$uid])) return $cache[$uid];
    $vtype = 'bajaj';
    try {
        $s = $pdo->prepare("SELECT vehicle_type FROM ride_drivers WHERE user_id=? LIMIT 1");
        $s->execute(array($uid));
        $v = $s->fetchColumn();
        if ($v) $vtype = $v;
    } catch (Exception $e) { /* fall back to bajaj */ }
    $cache[$uid] = $vtype;
    return $vtype;
}
}

if (!function_exists('rides_get_active_for_driver')) {
function rides_get_active_for_driver($pdo, $uid) {
    $s = $pdo->prepare(
        "SELECT r.*, u.name AS passenger_name, u.phone AS passenger_phone
         FROM rides r JOIN users u ON u.id = r.passenger_id
         WHERE r.driver_id=? AND r.status IN ('accepted','in_progress','arrived')
         ORDER BY r.id DESC LIMIT 1");
    $s->execute(array($uid));
    $r = $s->fetch();
    if (!$r) return null;

    /* Live meter fare, computed off ACTUAL traveled_km (0 at trip start),
       not the static pre-trip booking estimate (fare_estimate/distance_km).
       Without this, the driver's screen showed the estimate - already
       assuming the full estimated route distance - as "Current fare" for
       the first ~5 seconds of a trip before the first driver_update_
       location tick arrived, making it look like a charge was added
       before any real movement happened. Only for open_trip (metered)
       rides in progress or awaiting payment - fixed-fare rides correctly
       keep showing their agreed fare_estimate throughout. */
    if ($r['status'] === 'in_progress' || $r['status'] === 'arrived') {
        rides_ensure_wait_columns($pdo);
        if ($r['status'] === 'arrived' && !empty($r['fare_breakdown_json'])) {
            // The exact breakdown persisted at driver_arrived time - not a
            // recompute. A live recompute here could silently lose the
            // whole breakdown (any exception in rides_calc_fare/
            // rides_fare_rule is swallowed below with just an error_log
            // call, leaving fare_total etc. absent from the response with
            // no visible error) or show a different total than what was
            // actually billed if a surge window boundary passed since
            // arrival.
            $decoded = json_decode($r['fare_breakdown_json'], true);
            if (is_array($decoded)) {
                $r = array_merge($r, $decoded);
                $r['wait_minutes'] = round(rides_current_wait_minutes($r), 2);
                return $r;
            }
            // Fall through to live recompute only if the JSON is somehow
            // unreadable (pre-migration row, corrupt value, etc.)
        }
        try {
            $vtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $r['driver_id']);
            $waitMinutes = rides_current_wait_minutes($r);
            $isOpenTrip = intval($r['open_trip']) === 1;
            $km = $r['status'] === 'arrived' ? floatval($r['distance_km']) : floatval($r['traveled_km']);
            $liveFareCalc = rides_calc_fare($pdo, $km, $vtype, $isOpenTrip, $waitMinutes);
            if ($isOpenTrip && $r['status'] === 'in_progress') {
                $r['meter_fare'] = $liveFareCalc['fare'];
                $r['meter_surge'] = $liveFareCalc['surge'];
            }
            // Full itemized breakdown (base/distance/wait/surge/total) for
            // BOTH trip types now, not just open-trip ones - a fixed-fare
            // ride can still accumulate a real waiting charge, and the
            // driver/rider should be able to see exactly where that came
            // from rather than a single opaque number.
            $r = array_merge($r, rides_fare_breakdown_for_ui($liveFareCalc));
            $r['wait_active'] = (bool) $r['wait_active'];
            $r['wait_minutes'] = round($waitMinutes, 2);
        } catch (Exception $e) { error_log('[ETEL][meter] active-ride fare calc: ' . $e->getMessage()); }
    }
    return $r;
}
}

/* Driver daily-goal tiers ("2 trips -> +ETB 250" etc. on the driver
   home screen) - previously six values hardcoded directly into
   driver/index.html with zero way to change them without editing code.
   Self-healing like rides_ensure_wait_columns() above: creates the
   table and seeds it with the exact same amounts that were hardcoded
   before, so nothing changes for existing drivers until an admin
   actually edits a tier in Admin -> Pricing. */
if (!function_exists('rides_ensure_goal_tiers_table')) {
function rides_ensure_goal_tiers_table($pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'driver_goal_tiers'")->fetch();
        if (!$exists) {
            $pdo->exec("CREATE TABLE driver_goal_tiers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trips_required INT NOT NULL,
                bonus_etb DECIMAL(10,2) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_trips (trips_required)
            )");
            $seed = $pdo->prepare("INSERT INTO driver_goal_tiers (trips_required, bonus_etb, sort_order) VALUES (?, ?, ?)");
            $defaults = array(array(2,250), array(4,510), array(6,780), array(8,1050), array(10,1350), array(12,1700));
            foreach ($defaults as $i => $row) { $seed->execute(array($row[0], $row[1], $i)); }
        }
    } catch (Exception $e) { /* best-effort - falls back to the old hardcoded tiers client-side if this never succeeds */ }
}
}

/* Consistent itemized fare breakdown for the UI - base/distance/wait
   shown separately, surge shown as its own line, so both the rider and
   the driver can see exactly where a total came from (not just a lump
   sum) - this is what caught the runaway wait-timer bug (ride id 100,
   ~4.5 hours of accumulated waiting silently folded into one number
   that looked like a broken distance calculation instead). */
if (!function_exists('rides_fare_breakdown_for_ui')) {
function rides_fare_breakdown_for_ui($fareCalc) {
    $b = $fareCalc['breakdown'];
    $subtotal = round($b['base'] + $b['dist_etb'] + $b['wait_etb'], 2);
    // Pre-floor amount: subtotal with surge applied, but before the
    // per-vehicle minimum-fare floor. Separates two genuinely different
    // things that were previously both dumped into one "surge" number:
    // a real percentage markup (surge) vs. a flat top-up because the trip
    // priced under this vehicle's minimum fare. Without this split, a
    // short/cheap trip that gets floored up (no surge active) shows a
    // Total that's higher than Subtotal with no visible reason at all -
    // the old code only ever labeled this gap "Surge", gated on
    // surge_mult > 1, so a pure floor top-up rendered as an unexplained
    // silent jump between Subtotal and Total.
    $preFloor = round($subtotal * $b['surge_mult'], 2);
    $surgeEtb = round($preFloor - $subtotal, 2);
    $floorTopup = round(max(0, $fareCalc['fare'] - $preFloor), 2);
    return array(
        'fare_base'        => $b['base'],
        'fare_distance'    => $b['dist_etb'],
        'fare_wait'        => $b['wait_etb'],
        // The ETB amount above always round-tripped fine (it's baked
        // into fare_wait), but the raw minute count never did - it was
        // only ever a parameter to rides_calc_fare(), used to compute
        // wait_etb and then discarded, never stored back into the
        // breakdown array it returns. _fareBreakdownHtml() on both
        // driver.js and ride.js reads wait_minutes directly (for the
        // "Waiting X min" sub-label), so without this, any screen that
        // reads the PERSISTED breakdown (customer's Trip Complete, Ride
        // History) always showed "0 min" even though the ETB amount next
        // to it was correct - only a live in-progress read (which
        // recomputes wait_minutes fresh from wait_seconds_total each
        // time) ever had the real number.
        'wait_minutes'     => isset($b['wait_minutes']) ? $b['wait_minutes'] : 0,
        'wait_minutes_raw' => isset($b['wait_minutes_raw']) ? $b['wait_minutes_raw'] : (isset($b['wait_minutes']) ? $b['wait_minutes'] : 0),
        'wait_capped'      => !empty($b['wait_capped']),
        'fare_subtotal'    => $subtotal,
        'fare_surge_mult'  => $b['surge_mult'],
        'fare_surge_etb'   => $surgeEtb,
        'fare_floor_topup' => $floorTopup,
        'fare_total'       => $fareCalc['fare'],
    );
}
}

if (!function_exists('rides_complete_and_pay')) {
/* Marks a ride completed and runs the exact same driver payout/commission
   bookkeeping every completed ride goes through - extracted from
   customer_confirm_arrival's tail (RideController.php) so a second call
   site (admin_manual_complete_ride, for GPS-lost/phone-died trips closed
   out over a support call) doesn't have to duplicate the commission math
   and risk drifting from it. Behavior is unchanged from the original
   inline version - this is a pure move, same pattern as the rest of this
   file. Caller is responsible for validating the ride/fare/payment_method
   first (wallet verification, ownership, status checks - those differ
   between call sites, so they stay at the call site, not in here). */
function rides_complete_and_pay($pdo, $rideId, $driverId, $fare, $paymentMethod, $walletTxRef = null) {
    /* Idempotent: the status flip is the claim. If the ride is already
       completed (a double tap, or the auto-close sweeper racing the
       customer's confirm), rowCount() is 0 and NOTHING is paid out a second
       time. Returns true when this call completed the ride. */
    if ($walletTxRef) {
        $claim = $pdo->prepare("UPDATE rides SET status='completed', completed_at=NOW(), fare_paid=?, payment_method=?, wallet_tx_ref=? WHERE id=? AND status <> 'completed'");
        $claim->execute(array($fare, $paymentMethod, $walletTxRef, $rideId));
    } else {
        $claim = $pdo->prepare("UPDATE rides SET status='completed', completed_at=NOW(), fare_paid=?, payment_method=? WHERE id=? AND status <> 'completed'");
        $claim->execute(array($fare, $paymentMethod, $rideId));
    }
    if ($claim->rowCount() < 1) return false;
    require_once __DIR__ . '/ride_promo.php';
    $promoDiscount = rides_promo_settle($pdo, $rideId); // 0 when the ride had no promo; records the use once
    if ($driverId) {
        rides_add_missing_cols($pdo, 'ride_drivers', array(
            'commission_owed'    => "commission_owed DECIMAL(12,2) DEFAULT 0",
            'cash_fares_total'   => "cash_fares_total DECIMAL(12,2) DEFAULT 0",
            'etel_money_balance' => "etel_money_balance DECIMAL(12,2) DEFAULT 0"
        ));
        /* PROMO: $fare is what the rider paid. A promo discount is funded by Etel (lib/ride_promo.php), so the
           commission is still 5% of the FULL fare and, for a cash trip, the discount is credited to the driver's
           Etel money balance (the rider handed over less cash). A platform-collected trip earns the full fare. */
        $grossFare = $fare + $promoDiscount;
        $commissionRate = 0.05; // ETEL platform commission - activated 2026-08-18
        $commissionAmt = round($grossFare * $commissionRate, 2);
        $pdo->prepare("UPDATE ride_drivers SET etel_money_balance=etel_money_balance-? WHERE user_id=?")
            ->execute(array($commissionAmt, $driverId));
        // 'chapa' counts as platform-collected ONLY with a verified tx ref
        // (chapa_settle passes one); a bare client-supplied 'chapa' stays cash-like.
        if ($paymentMethod === 'wallet' || ($paymentMethod === 'chapa' && $walletTxRef)) {
            $pdo->prepare("UPDATE ride_drivers SET total_earnings=total_earnings+?, total_rides=total_rides+1 WHERE user_id=?")
                ->execute(array($grossFare, $driverId));
        } else {
            $pdo->prepare("UPDATE ride_drivers SET cash_fares_total=cash_fares_total+?, commission_owed=commission_owed+?, total_rides=total_rides+1 WHERE user_id=?")
                ->execute(array($fare, $commissionAmt, $driverId));
            if ($promoDiscount > 0) {
                $pdo->prepare("UPDATE ride_drivers SET etel_money_balance=etel_money_balance+? WHERE user_id=?")
                    ->execute(array($promoDiscount, $driverId));
            }
        }
    }
    return true;
}
}

/* Safety floor for a GPS meter that under-reads. The meter can legitimately
   read near zero when the phone only ever produced weak/network fixes, the
   app was suspended with the screen off, or pings were stalled; billing that
   as-is charges the passenger (and pays the driver) for a trip that clearly
   happened. Compares the metered km against two conservative references:
     - the booking-time estimate (fixed-destination rides only), and
     - the straight line from pickup to the driver's last known position
       (works for open trips too, which have no estimate).
   Both are lower bounds on the real road distance. If the meter is under
   half of the larger one, that reference is used instead and the ride is
   flagged for review. Returns array(applied, km, floor_km, meter_km, basis). */
if (!function_exists('rides_meter_shortfall_floor')) {
function rides_meter_shortfall_floor($pdo, $ride, $driverId, $traveled) {
    $traveled = floatval($traveled);
    $out = array('applied' => false, 'km' => $traveled, 'floor_km' => 0.0, 'meter_km' => $traveled, 'basis' => '');
    $floor = 0.0; $basis = '';
    $est = isset($ride['distance_km']) ? floatval($ride['distance_km']) : 0.0;
    if (intval($ride['open_trip']) !== 1 && $est > 0.3) { $floor = $est; $basis = 'booking_estimate'; }
    try {
        $plat = isset($ride['pickup_lat']) ? floatval($ride['pickup_lat']) : 0.0;
        $plng = isset($ride['pickup_lng']) ? floatval($ride['pickup_lng']) : 0.0;
        if ($plat && $plng) {
            $q = $pdo->prepare("SELECT lat, lng FROM ride_drivers WHERE user_id=?");
            $q->execute(array($driverId));
            $pos = $q->fetch();
            if ($pos && $pos['lat'] !== null && $pos['lng'] !== null) {
                $chord = rides_haversine_km($plat, $plng, floatval($pos['lat']), floatval($pos['lng']));
                if ($chord > 0.3 && $chord > $floor) { $floor = $chord; $basis = 'pickup_to_driver_position'; }
            }
        }
    } catch (Exception $e) { /* position unavailable - estimate-only floor */ }
    $out['floor_km'] = round($floor, 2);
    if ($floor > 0 && $traveled < 0.5 * $floor) {
        $out['applied'] = true;
        $out['km'] = round($floor, 2);
        $out['basis'] = $basis;
    }
    return $out;
}
}

/* Writes the review flag for a ride billed through the shortfall floor. */
if (!function_exists('rides_log_meter_shortfall')) {
function rides_log_meter_shortfall($pdo, $rideId, $driverId, $floor) {
    try {
        $details = 'GPS meter read ' . round($floor['meter_km'], 2) . ' km, below half of the ' . $floor['basis']
            . ' (' . $floor['floor_km'] . ' km) - billed ' . $floor['km'] . ' km instead. Review the route/path points if disputed.';
        $pdo->prepare('INSERT INTO ride_issues (ride_id, reporter_id, category, details, status) VALUES (?, ?, ?, ?, "open")')
            ->execute(array($rideId, $driverId, 'meter_shortfall', $details));
    } catch (Exception $e) {
        error_log('[ETEL][meter_shortfall] could not log for ride ' . $rideId . ': ' . $e->getMessage());
    }
}
}

/* Drivers who cancelled a ride after accepting it. When the ride goes back to
   'searching' for other drivers, these are filtered out of every request
   list (polling, the SSE stream) and blocked from re-accepting, so the
   driver who just walked away isn't rung again for the same ride. */
if (!function_exists('rides_ensure_exclusions_table')) {
function rides_ensure_exclusions_table($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ride_driver_exclusions (
        ride_id INT NOT NULL,
        driver_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ride_id, driver_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}
}
