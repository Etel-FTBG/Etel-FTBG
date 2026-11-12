<?php
// Commission plan (api/lib/ride_commission.php): the rate a driver pays per day, and that a completed ride stores the rate it was charged.
// Part 1 is pure (no database). Part 2 needs the throwaway test database (api/.env) and is skipped without one.
require_once __DIR__ . '/../api/lib/ride_commission.php';
$fails = 0;
function check($name, $ok) { global $fails; echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n"; if (!$ok) $fails++; }

echo "plan cleaning\n";
$d = rides_commission_default_plan();
check('the default plan is OFF with a 5 percent standard rate', $d['holiday_on'] === 0 && $d['standard_rate'] == 0.05);
$c = rides_commission_clean_plan(array());
check('an empty plan is the default (every driver pays 5 percent)', $c['holiday_on'] === 0 && $c['standard_rate'] == 0.05 && $c['phase1_rate'] == 0 && $c['phase2_rate'] == 0.02);
$c = rides_commission_clean_plan(array('standard_rate' => 0.9, 'phase1_rate' => 0.5, 'phase2_rate' => -1, 'phase1_days' => 9999, 'phase2_days' => -4, 'starts_on' => 'tomorrow', 'enroll_until' => '2026-13-40'));
check('a standard rate above 30 percent is cut to 30', $c['standard_rate'] == 0.3);
check('a launch rate is never above the standard rate or below zero', $c['phase1_rate'] == 0.3 && $c['phase2_rate'] == 0);
check('days are kept between 0 and 365', $c['phase1_days'] == 365 && $c['phase2_days'] == 0);
check('a bad date becomes empty', $c['starts_on'] === '' && $c['enroll_until'] === '');

echo "rate by day\n";
$plan = rides_commission_clean_plan(array('standard_rate' => 0.05, 'holiday_on' => 1, 'starts_on' => '2026-12-01', 'phase1_days' => 60, 'phase1_rate' => 0, 'phase2_days' => 30, 'phase2_rate' => 0.02));
$r = rides_commission_rate_on($plan, '2026-12-01', '2026-12-01');
check('day 0: phase 1, no commission', $r['rate'] == 0 && $r['phase'] === 'holiday');
check('phase 1 ends on the 60th day (start + 59)', $r['ends_on'] === date('Y-m-d', strtotime('2026-12-01 +59 days')));
$r = rides_commission_rate_on($plan, '2026-12-01', date('Y-m-d', strtotime('2026-12-01 +59 days')));
check('day 59 is still phase 1', $r['rate'] == 0 && $r['phase'] === 'holiday');
$r = rides_commission_rate_on($plan, '2026-12-01', date('Y-m-d', strtotime('2026-12-01 +60 days')));
check('day 60 is phase 2 at 2 percent', $r['rate'] == 0.02 && $r['phase'] === 'step' && $r['ends_on'] === date('Y-m-d', strtotime('2026-12-01 +89 days')));
$r = rides_commission_rate_on($plan, '2026-12-01', date('Y-m-d', strtotime('2026-12-01 +90 days')));
check('day 90 is the standard 5 percent', $r['rate'] == 0.05 && $r['phase'] === 'standard' && $r['ends_on'] === '');
$r = rides_commission_rate_on($plan, '2025-03-10', '2026-12-05');
check('a driver who joined long ago starts from the plan start day, not the account day', $r['rate'] == 0 && $r['phase'] === 'holiday');
$r = rides_commission_rate_on($plan, '2026-11-20', '2026-11-25');
check('before the plan starts a driver is still in their first day (rate follows the plan start)', $r['phase'] === 'holiday');
$plan2 = rides_commission_clean_plan(array('holiday_on' => 1, 'starts_on' => '2026-12-01', 'enroll_until' => '2026-12-31'));
check('a driver who joins after the enrol day pays the standard rate', rides_commission_rate_on($plan2, '2027-01-02', '2027-01-03')['phase'] === 'standard');
check('a driver who joins on the enrol day gets the plan', rides_commission_rate_on($plan2, '2026-12-31', '2027-01-01')['phase'] === 'holiday');
$off = rides_commission_clean_plan(array('holiday_on' => 0, 'phase1_days' => 60));
check('with the plan off everyone pays the standard rate', rides_commission_rate_on($off, '2026-12-01', '2026-12-02')['rate'] == 0.05);
check('an unknown start day gives the standard rate', rides_commission_rate_on($plan, '', '2026-12-02')['phase'] === 'standard');
$p0 = rides_commission_clean_plan(array('holiday_on' => 1, 'starts_on' => '2026-12-01', 'phase1_days' => 0, 'phase2_days' => 10, 'phase2_rate' => 0.01));
check('with no phase 1 the first days are phase 2', rides_commission_rate_on($p0, '2026-12-01', '2026-12-01')['phase'] === 'step');
$pr = rides_commission_clean_plan(array('standard_rate' => 0.04, 'holiday_on' => 1, 'starts_on' => '2026-12-01', 'phase2_rate' => 0.02, 'phase2_days' => 5, 'phase1_days' => 0));
check('the standard rate can be changed (4 percent) and a step rate stays below it', rides_commission_rate_on($pr, '2026-12-01', '2026-12-10')['rate'] == 0.04);

// ---------- Part 2: a completed ride stores the rate it was charged ----------
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $dflt = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $dflt; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP database part: no test database (" . $ex->getMessage() . ")\n"; echo $fails ? "FAILED\n" : "ALL PASS\n"; exit($fails ? 1 : 0); }
if (!defined('APP_VER')) define('APP_VER', 'commission-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/ride_fare.php';
require_once __DIR__ . '/../api/lib/ride_statement.php';

echo "completed rides (database)\n";
$pdo->exec("DELETE FROM pricing_rules WHERE rule_key='commission_plan'");
$pdo->exec("DELETE FROM users WHERE phone IN ('+251998400001','+251998400002','+251998400003')");
$pdo->exec("INSERT INTO users (name, phone, password_hash, role, created_at) VALUES ('Comm Rider', '+251998400001', 'x', 'customer', NOW())"); $rider = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (name, phone, password_hash, role, created_at) VALUES ('Comm New Driver', '+251998400002', 'x', 'courier', NOW())"); $newDrv = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (name, phone, password_hash, role, created_at) VALUES ('Comm Old Driver', '+251998400003', 'x', 'courier', DATE_SUB(NOW(), INTERVAL 400 DAY))"); $oldDrv = (int)$pdo->lastInsertId();
foreach (array($newDrv, $oldDrv) as $dv) $pdo->prepare("INSERT INTO ride_drivers (user_id, total_earnings) VALUES (?, 0)")->execute(array($dv));
function make_ride($pdo, $rider, $drv, $fare) {
    $pdo->prepare("INSERT INTO rides (passenger_id, driver_id, status, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, created_at) VALUES (?,?, 'in_progress', 7.05, 38.47, 7.06, 38.48, NOW())")->execute(array($rider, $drv));
    return (int)$pdo->lastInsertId();
}
function ride_row($pdo, $id) { $q = $pdo->prepare("SELECT * FROM rides WHERE id=?"); $q->execute(array($id)); return $q->fetch(); }
function drv_row($pdo, $id) { $q = $pdo->prepare("SELECT * FROM ride_drivers WHERE user_id=?"); $q->execute(array($id)); return $q->fetch(); }

// plan OFF: 5 percent, exactly as before
$r1 = make_ride($pdo, $rider, $newDrv, 100);
check('the ride completes', rides_complete_and_pay($pdo, $r1, $newDrv, 100.0, 'cash') === true);
$row = ride_row($pdo, $r1); $dr = drv_row($pdo, $newDrv);
check('plan off: 5 percent is stored on the ride (ETB 5 of 100)', floatval($row['commission_rate']) == 0.05 && floatval($row['commission_amt']) == 5.0);
check('plan off: the cash trip adds ETB 5 to what the driver owes Etel', floatval($dr['commission_owed']) == 5.0);

// plan ON: a driver who started today pays 0, an old driver also starts today (plan start)
rides_commission_save_plan($pdo, array('holiday_on' => 1, 'starts_on' => date('Y-m-d'), 'phase1_days' => 60, 'phase1_rate' => 0, 'phase2_days' => 30, 'phase2_rate' => 0.02));
$r2 = make_ride($pdo, $rider, $newDrv, 200);
rides_complete_and_pay($pdo, $r2, $newDrv, 200.0, 'cash');
$row = ride_row($pdo, $r2); $dr = drv_row($pdo, $newDrv);
check('plan on: a new driver pays 0 percent (stored on the ride)', floatval($row['commission_rate']) == 0 && floatval($row['commission_amt']) == 0);
check('plan on: nothing more is owed for that trip', floatval($dr['commission_owed']) == 5.0);
$r3 = make_ride($pdo, $rider, $oldDrv, 300);
rides_complete_and_pay($pdo, $r3, $oldDrv, 300.0, 'wallet');
$row = ride_row($pdo, $r3);
check('plan on: an old driver gets the plan from the plan start day, 0 percent', floatval($row['commission_amt']) == 0);
$dr = drv_row($pdo, $oldDrv);
check('plan on: the wallet trip pays the driver the whole fare', floatval($dr['total_earnings']) == 300.0 && floatval($dr['etel_money_balance']) == 0.0);

// plan changed later: a completed ride keeps its rate
rides_commission_save_plan($pdo, array('holiday_on' => 0));
$r4 = make_ride($pdo, $rider, $oldDrv, 100);
rides_complete_and_pay($pdo, $r4, $oldDrv, 100.0, 'cash');
check('plan switched off: the next trip pays 5 percent', floatval(ride_row($pdo, $r4)['commission_amt']) == 5.0);
check('plan switched off: the earlier free trip keeps its 0', floatval(ride_row($pdo, $r3)['commission_amt']) == 0);
check('a completed ride is not paid twice', rides_complete_and_pay($pdo, $r4, $oldDrv, 100.0, 'cash') === false && floatval(drv_row($pdo, $oldDrv)['commission_owed']) == 5.0);

// statement and the SQL helper use what was charged
$m = rides_trip_money(array('fare_paid' => 200, 'promo_discount' => 0, 'payment_method' => 'cash', 'commission_rate' => 0));
check('a statement line shows 0 commission for a free trip', $m['commission'] == 0 && $m['you_keep'] == 200);
$m = rides_trip_money(array('fare_paid' => 200, 'promo_discount' => 0, 'payment_method' => 'cash'));
check('an older trip without a stored rate still shows 5 percent', $m['commission'] == 10);
$sum = floatval($pdo->query("SELECT COALESCE(SUM(" . rides_commission_sql($pdo) . "),0) FROM rides WHERE id IN ($r1,$r2,$r3,$r4)")->fetchColumn());
check('the SQL helper sums the commission really charged (5 + 0 + 0 + 5)', $sum == 10.0);

$pdo->exec("DELETE FROM pricing_rules WHERE rule_key='commission_plan'");
$pdo->exec("DELETE FROM rides WHERE id IN ($r1,$r2,$r3,$r4)");
$pdo->exec("DELETE FROM ride_drivers WHERE user_id IN ($newDrv,$oldDrv)");
$pdo->exec("DELETE FROM users WHERE id IN ($rider,$newDrv,$oldDrv)");
echo $fails ? "FAILED: $fails\n" : "ALL PASS\n";
exit($fails ? 1 : 0);
