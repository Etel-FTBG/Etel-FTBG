<?php
// Behavioural test: a trip that ended without the customer moving is billed the BASE FARE (plus waiting), never the booked price.
// Runs the REAL ride_fare.php against a local MariaDB (ANSI_QUOTES on). Usage: php backend_fare_nomove.php
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test'); define('TOKEN_SECRET_FINAL', 'test-secret');
date_default_timezone_set('Africa/Addis_Ababa');
$API = realpath(__DIR__ . '/../../api');
function db() { global $PDO; return $PDO; }
function ok($d = null) {} function fail($m, $c = 400, $x = array()) { throw new Exception($m); }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
require_once $API . '/lib/ride_fare.php';
$PASS = 0; $FAILS = array();
function t($n, $c, $x = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($x !== '' ? ' -> ' . $x : ''); }

foreach (array('bajaj', 'moto', 'car') as $v) {
    $rule = rides_fare_rule($PDO, $v);
    $full = rides_calc_fare($PDO, 2.8, $v, true, 0);
    $nm = rides_calc_fare_no_move($PDO, $v, true, 0);
    $surge = $nm['breakdown']['surge_mult'];
    t("$v: no movement is the base fare (x surge) only", abs($nm['fare'] - round($rule['base_fare'] * $surge, 2)) < 0.011, $nm['fare'] . ' vs base ' . $rule['base_fare']);
    t("$v: no movement is cheaper than the booked 2.8 km trip", $nm['fare'] < $full['fare'], $nm['fare'] . ' vs ' . $full['fare']);
    t("$v: no minimum-fare floor is applied", $nm['breakdown']['floor'] === 0);
    $w = rides_calc_fare_no_move($PDO, $v, true, 4);
    t("$v: real waiting time is added", $w['fare'] > $nm['fare'], $w['fare'] . ' vs ' . $nm['fare']);
    $ui = rides_fare_breakdown_for_ui($nm);
    t("$v: breakdown shows base only, distance 0", floatval($ui['fare_distance']) === 0.0 && abs(floatval($ui['fare_base']) - floatval($rule['base_fare'])) < 0.011 && floatval($ui['fare_floor_topup']) === 0.0, json_encode($ui));
    $half = rides_calc_fare($PDO, 1.0, $v, true, 0);
    t("$v: a partial trip (1 km) pays by distance, between base and full", $half['fare'] >= $nm['fare'] && $half['fare'] < $full['fare'], $half['fare']);
}
echo 'PASSED: ' . $PASS . "\nFAILED: " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo '  X ' . $f . "\n";
