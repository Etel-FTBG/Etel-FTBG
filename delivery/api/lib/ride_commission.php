<?php
// Ride commission plan: the rate Etel takes from a driver's fares, per driver and per day.
//
// Why: for the launch Etel may charge new drivers no commission for a while, then a reduced rate, then the
// standard rate. The plan is one JSON setting (pricing_rules key 'commission_plan'), edited in Admin > Pricing >
// Commission. With the plan off, every driver pays the standard rate (5% unless changed), exactly as before.
//
// How a driver's rate is worked out (rides_commission_for_driver):
//  - The driver's start day is the day their account was created, or the plan's start day if that is later
//    (so drivers who joined before the plan began get their free period from the day the plan starts).
//  - Drivers whose start day is after the plan's "enrol until" day (when one is set) pay the standard rate.
//  - Day 0 .. phase1_days-1 from the start day: phase 1 rate (0% by default).
//  - Then phase2_days more days: phase 2 rate (2% by default).
//  - After that, the standard rate.
//  - A holiday rate is never above the standard rate.
// The rate used is stored on every completed ride (rides.commission_rate and rides.commission_amt), so statements,
// the economics screens and the investor report all show what was really charged, not a guess.
// ASCII only.

if (!function_exists('rides_commission_default_plan')) {

function rides_commission_default_plan() {
    return array(
        'standard_rate' => 0.05,
        'holiday_on'    => 0,
        'starts_on'     => '',
        'enroll_until'  => '',
        'phase1_days'   => 60,
        'phase1_rate'   => 0.0,
        'phase2_days'   => 30,
        'phase2_rate'   => 0.02
    );
}

function rides_commission_valid_day($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : '';
}

// Clamps every field to something safe, so a bad saved value can never overcharge or crash a payout.
function rides_commission_clean_plan($p) {
    $d = rides_commission_default_plan();
    if (!is_array($p)) $p = array();
    $std = isset($p['standard_rate']) ? floatval($p['standard_rate']) : $d['standard_rate'];
    if ($std < 0) $std = 0;
    if ($std > 0.30) $std = 0.30;
    $r1 = isset($p['phase1_rate']) ? floatval($p['phase1_rate']) : $d['phase1_rate'];
    $r2 = isset($p['phase2_rate']) ? floatval($p['phase2_rate']) : $d['phase2_rate'];
    $r1 = max(0, min($r1, $std));
    $r2 = max(0, min($r2, $std));
    $n1 = isset($p['phase1_days']) ? intval($p['phase1_days']) : $d['phase1_days'];
    $n2 = isset($p['phase2_days']) ? intval($p['phase2_days']) : $d['phase2_days'];
    $n1 = max(0, min($n1, 365));
    $n2 = max(0, min($n2, 365));
    return array(
        'standard_rate' => round($std, 4),
        'holiday_on'    => !empty($p['holiday_on']) ? 1 : 0,
        'starts_on'     => rides_commission_valid_day(isset($p['starts_on']) ? $p['starts_on'] : ''),
        'enroll_until'  => rides_commission_valid_day(isset($p['enroll_until']) ? $p['enroll_until'] : ''),
        'phase1_days'   => $n1,
        'phase1_rate'   => round($r1, 4),
        'phase2_days'   => $n2,
        'phase2_rate'   => round($r2, 4)
    );
}

function rides_commission_plan($pdo) {
    $plan = rides_commission_default_plan();
    try {
        $v = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='commission_plan' LIMIT 1")->fetchColumn();
        if ($v) {
            $j = json_decode($v, true);
            if (is_array($j)) $plan = array_merge($plan, $j);
        }
    } catch (Exception $e) { /* no plan saved: the standard rate for everyone */ }
    return rides_commission_clean_plan($plan);
}

function rides_commission_save_plan($pdo, $plan) {
    $plan = rides_commission_clean_plan($plan);
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('commission_plan', ?, 'Commission plan: standard rate and the per-driver launch schedule (JSON)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array(json_encode($plan)));
    return $plan;
}

// The day the driver's account was created (Y-m-d), or '' when unknown.
function rides_commission_driver_start($pdo, $driverId) {
    static $cache = array();
    $k = intval($driverId);
    if (isset($cache[$k])) return $cache[$k];
    $day = '';
    try {
        $q = $pdo->prepare("SELECT created_at FROM users WHERE id=?");
        $q->execute(array($k));
        $v = $q->fetchColumn();
        if ($v) $day = substr((string)$v, 0, 10);
    } catch (Exception $e) { /* unknown start: the standard rate applies */ }
    $cache[$k] = $day;
    return $day;
}

// Pure: the rate for a driver who started on $startDay, on day $today (both Y-m-d), under $plan (already cleaned).
function rides_commission_rate_on($plan, $startDay, $today) {
    $std = floatval($plan['standard_rate']);
    $out = array('rate' => $std, 'phase' => 'standard', 'ends_on' => '', 'standard_rate' => $std, 'day' => null);
    if (empty($plan['holiday_on'])) return $out;
    if ($startDay === '' || rides_commission_valid_day($startDay) === '') return $out;
    if ($plan['starts_on'] !== '' && $plan['starts_on'] > $startDay) $startDay = $plan['starts_on'];
    if ($plan['enroll_until'] !== '' && $startDay > $plan['enroll_until']) return $out;
    $age = intval(floor((strtotime($today) - strtotime($startDay)) / 86400));
    if ($age < 0) $age = 0;
    $out['day'] = $age;
    $n1 = intval($plan['phase1_days']);
    $n2 = intval($plan['phase2_days']);
    if ($age < $n1) {
        $out['rate'] = floatval($plan['phase1_rate']);
        $out['phase'] = 'holiday';
        $out['ends_on'] = date('Y-m-d', strtotime($startDay . ' +' . ($n1 - 1) . ' days'));
    } elseif ($age < $n1 + $n2) {
        $out['rate'] = floatval($plan['phase2_rate']);
        $out['phase'] = 'step';
        $out['ends_on'] = date('Y-m-d', strtotime($startDay . ' +' . ($n1 + $n2 - 1) . ' days'));
    }
    if ($out['rate'] > $std) $out['rate'] = $std;
    return $out;
}

// The rate this driver pays on a trip completed now (or on $todayYmd). Never throws.
function rides_commission_for_driver($pdo, $driverId, $todayYmd = null) {
    $plan = rides_commission_plan($pdo);
    if ($todayYmd === null) $todayYmd = date('Y-m-d');
    if (empty($plan['holiday_on'])) return rides_commission_rate_on($plan, '', $todayYmd);
    return rides_commission_rate_on($plan, rides_commission_driver_start($pdo, $driverId), $todayYmd);
}

// SQL for the commission Etel really took on a completed ride: the stored amount, or the old 5% of (paid + promo)
// for rides completed before the amount was stored. $a is the table alias with a trailing dot ('r.') or ''.
function rides_commission_sql($pdo, $a = '') {
    static $has = null;
    if ($has === null) {
        $has = array('commission_amt' => false, 'promo_discount' => false);
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $f = strtolower($c['Field']);
                if (isset($has[$f])) $has[$f] = true;
            }
        } catch (Exception $e) { /* keep false */ }
    }
    $legacy = '(COALESCE(' . $a . 'fare_paid,0)' . ($has['promo_discount'] ? ' + COALESCE(' . $a . 'promo_discount,0)' : '') . ') * 0.05';
    return $has['commission_amt'] ? 'COALESCE(' . $a . 'commission_amt, ' . $legacy . ')' : '(' . $legacy . ')';
}

}
