<?php
// Launch incentives (api/lib/ride_incentives.php): daily trip bonuses and driver referral rewards, with the monthly budget as a hard ceiling.
// Part 1 is pure (plan cleaning). Part 2 needs the throwaway test database (api/.env) and is skipped without one.
require_once __DIR__ . '/../api/lib/ride_incentives.php';
$fails = 0;
function check($name, $ok) { global $fails; echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n"; if (!$ok) $fails++; }

echo "plan cleaning\n";
$d = rides_incentive_default_plan();
check('the default plan is OFF, no budget, no tiers: nothing can be paid', $d['on'] === 0 && $d['monthly_cap'] == 0 && $d['daily_tiers'] === array() && $d['referral_on'] === 0);
$c = rides_incentive_clean_plan(array('on' => 1, 'monthly_cap' => 5000, 'daily_tiers' => array(array('trips' => 8, 'bonus' => 60), array('trips' => 5, 'bonus' => 30), array('trips' => 5, 'bonus' => 99), array('trips' => 0, 'bonus' => 10), array('trips' => 9, 'bonus' => 0), array('trips' => 7, 'bonus' => 99999), 'junk')));
check('tiers are sorted, one per trip count (the last one wins), and junk, zero and huge bonuses are dropped',
    $c['daily_tiers'] === array(array('trips' => 5, 'bonus' => 99.0), array('trips' => 8, 'bonus' => 60.0)));
$c = rides_incentive_clean_plan(array('monthly_cap' => -5, 'min_fare' => -1, 'max_same_rider' => 0, 'referral_trips' => 0, 'referral_reward' => 9999999, 'referral_max_per_month' => 0));
check('negative and out-of-range numbers are clamped', $c['monthly_cap'] == 0 && $c['min_fare'] == 0 && $c['max_same_rider'] == 1 && $c['referral_trips'] == 1 && $c['referral_reward'] == 100000 && $c['referral_max_per_month'] == 1);
$many = array(); for ($i = 1; $i <= 12; $i++) $many[] = array('trips' => $i, 'bonus' => 10);
check('at most 8 daily tiers are kept', count(rides_incentive_clean_plan(array('daily_tiers' => $many))['daily_tiers']) == 8);

// ---------- Part 2: on a real database ----------
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $dflt = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $dflt; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP database part: no test database (" . $ex->getMessage() . ")\n"; echo $fails ? "FAILED\n" : "ALL PASS\n"; exit($fails ? 1 : 0); }
if (!defined('APP_VER')) define('APP_VER', 'incentives-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/ride_fare.php';

echo "paying (database)\n";
rides_incentive_ensure($pdo);
$phones = array();
for ($i = 1; $i <= 13; $i++) $phones[] = '+2519985' . str_pad((string)$i, 5, '0', STR_PAD_LEFT);
$old = $pdo->query("SELECT id FROM users WHERE phone IN ('" . implode("','", $phones) . "')")->fetchAll(PDO::FETCH_COLUMN);
if ($old) { $oi = implode(',', array_map('intval', $old)); $pdo->exec("DELETE FROM ride_incentive_awards WHERE driver_id IN ($oi)"); $pdo->exec("DELETE FROM rides WHERE driver_id IN ($oi) OR passenger_id IN ($oi)"); $pdo->exec("DELETE FROM ride_drivers WHERE user_id IN ($oi)"); }
$pdo->exec("DELETE FROM couriers WHERE referral_code='INCREF1' OR courier_code LIKE 'C-INC%'");
$pdo->exec("DELETE FROM users WHERE phone IN ('" . implode("','", $phones) . "')");
$pdo->exec("DELETE FROM pricing_rules WHERE rule_key IN ('incentive_plan','commission_plan')");
function mkuser($pdo, $name, $phone, $role) { $pdo->prepare("INSERT INTO users (name, phone, password_hash, role, created_at) VALUES (?,?, 'x', ?, NOW())")->execute(array($name, $phone, $role)); return (int)$pdo->lastInsertId(); }
$riders = array(); for ($i = 1; $i <= 4; $i++) $riders[$i] = mkuser($pdo, 'Inc Rider ' . $i, $phones[$i - 1], 'customer');
$D = mkuser($pdo, 'Inc Driver D', $phones[4], 'courier');
$D2 = mkuser($pdo, 'Inc Driver D2', $phones[5], 'courier');
$D3 = mkuser($pdo, 'Inc Driver D3', $phones[6], 'courier');
$R = mkuser($pdo, 'Inc Referrer R', $phones[7], 'courier');
$E = mkuser($pdo, 'Inc Referred E', $phones[8], 'courier');
$F = mkuser($pdo, 'Inc Referred F', $phones[9], 'courier');
$G = mkuser($pdo, 'Inc Referred G', $phones[12], 'courier');
$all = array($D, $D2, $D3, $R, $E, $F, $G);
foreach ($all as $dv) $pdo->prepare("INSERT INTO ride_drivers (user_id, total_earnings) VALUES (?, 0)")->execute(array($dv));
$n = 0;
function mkcourier($pdo, $uid, $code, $by) { global $n; $n++; $pdo->prepare("INSERT INTO couriers (user_id, courier_code, referral_code, referred_by) VALUES (?,?,?,?)")->execute(array($uid, 'C-INC' . $n . substr((string)$uid, -4), $code, $by)); }
mkcourier($pdo, $R, 'INCREF1', null); mkcourier($pdo, $E, null, 'INCREF1'); mkcourier($pdo, $F, null, 'INCREF1'); mkcourier($pdo, $G, null, 'INCREF1');
function ride($pdo, $rider, $drv, $fare, $km = 3.0) {
    $pdo->prepare("INSERT INTO rides (passenger_id, driver_id, status, distance_km, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, created_at) VALUES (?,?, 'in_progress', ?, 7.05, 38.47, 7.06, 38.48, NOW())")->execute(array($rider, $drv, $km));
    $id = (int)$pdo->lastInsertId();
    rides_complete_and_pay($pdo, $id, $drv, floatval($fare), 'cash');
    return $id;
}
function bal($pdo, $uid) { $q = $pdo->prepare("SELECT etel_money_balance FROM ride_drivers WHERE user_id=?"); $q->execute(array($uid)); return floatval($q->fetchColumn()); }
function awards($pdo, $uid, $kind = null) { $q = $pdo->prepare("SELECT COUNT(*) FROM ride_incentive_awards WHERE driver_id=?" . ($kind ? " AND kind='$kind'" : '')); $q->execute(array($uid)); return intval($q->fetchColumn()); }
function col($pdo, $rid, $c) { $q = $pdo->prepare("SELECT $c FROM rides WHERE id=?"); $q->execute(array($rid)); return $q->fetchColumn(); }

// plan OFF: no bonus however many trips
$b0 = bal($pdo, $D);
$a = ride($pdo, $riders[1], $D, 100); $b = ride($pdo, $riders[2], $D, 100);
check('plan off: nothing is paid', awards($pdo, $D) === 0 && abs(bal($pdo, $D) - $b0 + 10.0) < 0.001);   // only the 5 percent commission taken twice

// plan ON but no budget: nothing is paid
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 0, 'daily_tiers' => array(array('trips' => 2, 'bonus' => 30), array('trips' => 3, 'bonus' => 50)), 'min_fare' => 30, 'min_km' => 0.5, 'max_same_rider' => 2));
$c1 = ride($pdo, $riders[3], $D, 100);
check('plan on with a budget of 0: nothing is paid', awards($pdo, $D) === 0);

// plan ON with a budget: the next trip pays the tiers already reached (D now has 3 qualifying trips today)
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 100, 'daily_tiers' => array(array('trips' => 2, 'bonus' => 30), array('trips' => 3, 'bonus' => 50)), 'min_fare' => 30, 'min_km' => 0.5, 'max_same_rider' => 2));
$spent0 = rides_incentive_month_spent($pdo);
$before = bal($pdo, $D);
$c2 = ride($pdo, $riders[4], $D, 100);      // 4th trip today: tiers 2 and 3 are both reached now
check('both reached tiers are paid once (30 + 50)', awards($pdo, $D, 'daily') === 2 && abs(rides_incentive_month_spent($pdo) - $spent0 - 80.0) < 0.001);
check('the money is credited to the Etel money balance (80 minus the 5 commission)', abs(bal($pdo, $D) - $before - 75.0) < 0.001);
check('the amount is stored on the trip that triggered it', abs(floatval(col($pdo, $c2, 'incentive_bonus')) - 80.0) < 0.001);
$c3 = ride($pdo, $riders[1], $D, 100);
check('more trips the same day pay nothing more for the same tiers', awards($pdo, $D, 'daily') === 2);

// fraud rules (budget raised so that only the rules decide)
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 100000, 'daily_tiers' => array(array('trips' => 2, 'bonus' => 30), array('trips' => 3, 'bonus' => 50)), 'min_fare' => 30, 'min_km' => 0.5, 'max_same_rider' => 2));
$spent1 = rides_incentive_month_spent($pdo);
for ($i = 0; $i < 4; $i++) ride($pdo, $riders[1], $D2, 100);                       // 4 trips, one rider: counts 2
check('trips with the same rider count at most 2: only the 2-trip tier is paid', awards($pdo, $D2, 'daily') === 1);
for ($i = 0; $i < 3; $i++) ride($pdo, $riders[$i + 1], $D3, 10);                   // fare 10 < minimum 30
check('trips under the minimum fare do not qualify', awards($pdo, $D3) === 0);
for ($i = 0; $i < 3; $i++) ride($pdo, $riders[$i + 1], $D3, 100, 0.1);             // 0.1 km < minimum 0.5
check('trips under the minimum distance do not qualify', awards($pdo, $D3) === 0);
for ($i = 0; $i < 3; $i++) ride($pdo, $D3, $D3, 100);                             // the driver riding with themselves
check('a driver riding with themselves does not qualify', awards($pdo, $D3) === 0);

// the monthly budget is a hard ceiling
$spentNow = rides_incentive_month_spent($pdo);
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => $spentNow + 20, 'daily_tiers' => array(array('trips' => 1, 'bonus' => 25)), 'min_fare' => 30, 'min_km' => 0.5, 'max_same_rider' => 2));
$D4 = mkuser($pdo, 'Inc Driver D4', $phones[10], 'courier'); $pdo->prepare("INSERT INTO ride_drivers (user_id, total_earnings) VALUES (?, 0)")->execute(array($D4));
ride($pdo, $riders[1], $D4, 100);
check('an award that does not fit in what is left of the budget is skipped', awards($pdo, $D4) === 0);
check('the month total never goes above the budget', rides_incentive_month_spent($pdo) <= $spentNow + 20 + 0.001);

// one driver's monthly ceiling
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 100000, 'driver_month_max' => 40, 'daily_tiers' => array(array('trips' => 1, 'bonus' => 25), array('trips' => 2, 'bonus' => 25)), 'min_fare' => 30, 'min_km' => 0.5, 'max_same_rider' => 2));
$D5 = mkuser($pdo, 'Inc Driver D5', $phones[11], 'courier'); $pdo->prepare("INSERT INTO ride_drivers (user_id, total_earnings) VALUES (?, 0)")->execute(array($D5));
ride($pdo, $riders[1], $D5, 100); ride($pdo, $riders[2], $D5, 100);
check('one driver cannot receive more than the per-driver monthly maximum (25 paid, the second 25 would make 50 > 40)', awards($pdo, $D5) === 1);

// void
$q = $pdo->prepare("SELECT id, amount, ride_id FROM ride_incentive_awards WHERE driver_id=? AND kind='daily' ORDER BY id LIMIT 1"); $q->execute(array($D)); $aw = $q->fetch();
$balBefore = bal($pdo, $D); $spBefore = rides_incentive_month_spent($pdo);
$res = rides_incentive_void($pdo, $aw['id'], 0);
check('voiding an award succeeds', $res[0] === true);
check('...the credit is taken back and the spending falls', abs($balBefore - bal($pdo, $D) - floatval($aw['amount'])) < 0.001 && abs($spBefore - rides_incentive_month_spent($pdo) - floatval($aw['amount'])) < 0.001);
check('...and the trip column goes down too', abs(floatval(col($pdo, $aw['ride_id'], 'incentive_bonus')) - (80.0 - floatval($aw['amount']))) < 0.001);
check('an award cannot be voided twice', rides_incentive_void($pdo, $aw['id'], 0)[0] === false);

// referral
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 100000, 'daily_on' => 0, 'referral_on' => 1, 'referral_trips' => 3, 'referral_reward' => 100, 'referral_max_per_month' => 1, 'min_fare' => 30, 'min_km' => 0.5));
$rb = bal($pdo, $R);
ride($pdo, $riders[1], $E, 100); ride($pdo, $R, $E, 100); ride($pdo, $R, $E, 100); ride($pdo, $riders[2], $E, 100);
check('the referrer riding with their referred driver does not count (2 real trips only)', awards($pdo, $R, 'referral') === 0);
$last = ride($pdo, $riders[3], $E, 100);
check('3 real trips from 3 different riders pay the referrer once', awards($pdo, $R, 'referral') === 1 && abs(bal($pdo, $R) - $rb - 100.0) < 0.001);
check('the reward is stored on the trip that earned it', abs(floatval(col($pdo, $last, 'incentive_referral')) - 100.0) < 0.001);
ride($pdo, $riders[4], $E, 100);
check('the same referred driver never pays twice', awards($pdo, $R, 'referral') === 1);
// a second referred driver (F), with the referrer's monthly limit at 5 for now
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 100000, 'daily_on' => 0, 'referral_on' => 1, 'referral_trips' => 3, 'referral_reward' => 100, 'referral_max_per_month' => 5, 'min_fare' => 30, 'min_km' => 0.5));
for ($i = 0; $i < 4; $i++) ride($pdo, $riders[1], $F, 100);
check('one rider alone cannot unlock a referral (at least 3 different riders are needed)', awards($pdo, $R, 'referral') === 1);
ride($pdo, $riders[2], $F, 100);
check('two different riders are still not enough', awards($pdo, $R, 'referral') === 1);
ride($pdo, $riders[3], $F, 100);
check('three different riders unlock the second referral', awards($pdo, $R, 'referral') === 2);

// the referrer monthly limit (2) and a suspended referrer, with a third referred driver (G)
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 100000, 'daily_on' => 0, 'referral_on' => 1, 'referral_trips' => 3, 'referral_reward' => 100, 'referral_max_per_month' => 2, 'min_fare' => 30, 'min_km' => 0.5));
ride($pdo, $riders[1], $G, 100); ride($pdo, $riders[2], $G, 100); ride($pdo, $riders[3], $G, 100);
check('the referrer monthly limit holds (2 a month in this test)', awards($pdo, $R, 'referral') === 2);
rides_incentive_save_plan($pdo, array('on' => 1, 'monthly_cap' => 100000, 'daily_on' => 0, 'referral_on' => 1, 'referral_trips' => 3, 'referral_reward' => 100, 'referral_max_per_month' => 5, 'min_fare' => 30, 'min_km' => 0.5));
rides_add_missing_cols($pdo, 'ride_drivers', array('suspended' => "suspended TINYINT(1) NOT NULL DEFAULT 0"));   // added by the platform the first time a driver is suspended
$pdo->prepare("UPDATE ride_drivers SET suspended=1 WHERE user_id=?")->execute(array($R));
ride($pdo, $riders[4], $G, 100);
check('a suspended referrer is not paid', awards($pdo, $R, 'referral') === 2);
$pdo->prepare("UPDATE ride_drivers SET suspended=0 WHERE user_id=?")->execute(array($R));
ride($pdo, $riders[4], $G, 100);
check('an active referrer is paid for the third referred driver', awards($pdo, $R, 'referral') === 3);

// the trip's own payout is never changed by incentives
check('plan switched off again: a new trip pays nothing extra', (function () use ($pdo, $riders, $D5) { rides_incentive_save_plan($pdo, array('on' => 0)); $b = awards($pdo, $D5); ride($pdo, $riders[3], $D5, 100); return awards($pdo, $D5) === $b; })());

// clean up
$ids = implode(',', array_merge(array($D, $D2, $D3, $D4, $D5, $R, $E, $F, $G), array_values($riders)));
$pdo->exec("DELETE FROM ride_incentive_awards WHERE driver_id IN ($ids)");
$pdo->exec("DELETE FROM rides WHERE driver_id IN ($ids) OR passenger_id IN ($ids)");
$pdo->exec("DELETE FROM couriers WHERE user_id IN ($ids)");
$pdo->exec("DELETE FROM ride_drivers WHERE user_id IN ($ids)");
$pdo->exec("DELETE FROM users WHERE id IN ($ids)");
$pdo->exec("DELETE FROM pricing_rules WHERE rule_key IN ('incentive_plan','commission_plan')");
echo $fails ? "FAILED: $fails\n" : "ALL PASS\n";
exit($fails ? 1 : 0);
