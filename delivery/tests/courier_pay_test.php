<?php
// Courier pay engine: pure functions, no database. Admin settings are cleaned, the formula is exact, the night window wraps midnight.
require_once __DIR__ . '/../api/lib/courier_pay.php';
$fails = 0;
function check($name, $ok) { global $fails; echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n"; if (!$ok) $fails++; }

echo "courier pay settings\n";
$d = etel_cpay_defaults();
check('defaults are OFF so a deploy changes nothing until the admin switches it on', $d['enabled'] === 0);
$c = etel_cpay_clean(array());
check('empty input gives the defaults', $c['base'] == 25 && $c['per_km'] == 7 && $c['min'] == 40 && $c['enabled'] === 0);
$c = etel_cpay_clean(array('enabled' => '1', 'base' => '30', 'per_km' => '9.5', 'min' => '50'));
check('admin values are kept', $c['enabled'] === 1 && $c['base'] == 30 && $c['per_km'] == 9.5 && $c['min'] == 50);
$c = etel_cpay_clean(array('base' => 99999, 'per_km' => -5, 'min' => 'abc', 'road_factor' => 9, 'night_mult' => 0.2, 'night_start' => 77, 'tip_max' => 10**9));
check('out-of-range numbers are clamped, junk falls back', $c['base'] == 500 && $c['per_km'] == 0 && $c['min'] == 40 && $c['road_factor'] == 2 && $c['night_mult'] == 1 && $c['night_start'] == 23 && $c['tip_max'] == 5000);
check('enabled "0" and "false" mean off', etel_cpay_clean(array('enabled' => '0'))['enabled'] === 0 && etel_cpay_clean(array('enabled' => 'false'))['enabled'] === 0);
$c = etel_cpay_clean(array('tip_presets' => '50, 10,abc,20,20,0,-3,900,30,40,60,70,80', 'tip_max' => 100));
check('tip buttons: unique, ascending, 1..max, at most 6', $c['tip_presets'] === '10,20,30,40,50,60');
check('no valid tip button falls back to the defaults', etel_cpay_clean(array('tip_presets' => 'x,y'))['tip_presets'] === '10,20,30,50');

echo "night window\n";
check('21 to 6 wraps midnight: 23h and 2h are night', etel_cpay_is_night(23, 21, 6) && etel_cpay_is_night(2, 21, 6));
check('21 to 6: 6h and noon are day', !etel_cpay_is_night(6, 21, 6) && !etel_cpay_is_night(12, 21, 6));
check('same start and end means never night', !etel_cpay_is_night(3, 5, 5));
check('a daytime window works: 12 to 14', etel_cpay_is_night(13, 12, 14) && !etel_cpay_is_night(14, 12, 14));

echo "pay formula\n";
$s = etel_cpay_clean(array('enabled' => 1, 'base' => 25, 'per_km' => 7, 'pickup_cap_km' => 3, 'min' => 40, 'road_factor' => 1.3, 'night_mult' => 1.3));
$r = etel_cpay_compute($s, 1.0, 2.0, false, 1.0);          // km = 1.3 + 2.6 = 3.9 ; 25 + 27.3 = 52.3
check('1 km to the shop + 2 km to the customer = ETB 52', $r['pay'] == 52 && $r['km_total'] == 3.9);
$r = etel_cpay_compute($s, 10.0, 2.0, false, 1.0);         // pickup capped at 3 km ; km = 3 + 2.6 ; 25 + 39.2 = 64.2
check('a far pickup is capped at 3 km', $r['km_pickup'] == 3 && $r['pay'] == 64);
$r = etel_cpay_compute($s, 0.0, 0.2, false, 1.0);          // 25 + 1.82 = 26.8 -> minimum 40
check('a very short order gets the minimum (40)', $r['pay'] == 40 && $r['minimum_applied'] === true);
$r = etel_cpay_compute($s, 1.0, 2.0, true, 1.0);           // 52.3 x 1.3 = 68
check('night multiplier applies (52.3 x 1.3 = 68)', $r['pay'] == 68);
$r = etel_cpay_compute($s, 1.0, 2.0, false, 1.12);         // 52.3 x 1.12 = 58.6 -> 59
check('car tier multiplier applies (52.3 x 1.12 = 59)', $r['pay'] == 59);
$r = etel_cpay_compute($s, -5, -5, false, 0);
check('negative distances and a zero tier are treated safely', $r['pay'] == 40 && $r['tier'] == 1.0);

echo "rows\n";
$row = array('sg_lat' => 7.0500, 'sg_lng' => 38.4800, 'delivery_lat' => 7.0590, 'delivery_lng' => 38.4800, 'placed_at' => '2026-10-13 12:00:00', 'delivery_tier' => 'moto');
$off = etel_cpay_clean(array());
check('pay by distance OFF returns null (delivery fee applies)', etel_cpay_for_row($off, $row, 7.0500, 38.4800, array()) === null);
$on = etel_cpay_for_row($s, $row, 7.0500, 38.4800, array('moto' => 1.0));   // shop -> customer about 1.0 km straight
check('ON with coordinates gives a pay', is_array($on) && $on['pay'] >= 40);
$noCoords = $row; $noCoords['delivery_lat'] = 0;
check('a missing customer position falls back (null)', etel_cpay_for_row($s, $noCoords, 7.05, 38.48, array()) === null);
$noShop = $row; unset($noShop['sg_lat']);
check('a missing shop position falls back (null)', etel_cpay_for_row($s, $noShop, 7.05, 38.48, array()) === null);
$noCourier = etel_cpay_for_row($s, $row, 0, 0, array());
check('an unknown courier position counts the pickup as 0 km', is_array($noCourier) && $noCourier['km_pickup'] == 0);
check('haversine: 0.01 degree of latitude is about 1.11 km', abs(etel_cpay_km(7.0, 38.0, 7.01, 38.0) - 1.112) < 0.01);

echo $fails ? "FAILED: $fails\n" : "ALL PASS\n";
exit($fails ? 1 : 0);
