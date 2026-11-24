<?php
// Delivery fees by distance: the pure maths (no database). php delivery/tests/delivery_fees_test.php
$fails = 0;
function check($name, $ok, $info = '') { global $fails; echo ($ok ? "  PASS  " : "  FAIL  ") . $name . ($ok ? '' : '  ' . $info) . "\n"; if (!$ok) $fails++; }

require_once __DIR__ . '/../api/lib/delivery_fees.php';

echo "starting prices\n";
$bike = etel_dfee_default_rate('bike', 1);
check('bicycle starts at 45 ETB + 15 ETB per km (the owner price)', $bike['base'] == 45 && $bike['per_km'] == 15);
$all = array('bike', 'moto', 'car', 'van');
$prev = 0; $grow = true;
foreach ($all as $c) { $r = etel_dfee_default_rate($c, 0); if ($r['base'] <= $prev) $grow = false; $prev = $r['base']; }
check('a bigger vehicle costs more: bike < moto < car < van', $grow);
$bj = etel_dfee_default_rate('bajaj_qute', 2);
check('Bajaj / Qute sits between a motorbike and a car', $bj['base'] > etel_dfee_default_rate('moto', 2)['base'] && $bj['base'] < etel_dfee_default_rate('car', 3)['base']);
check('a vehicle staff added is priced by its size', etel_dfee_default_rate('tuktuk', 4)['base'] == etel_dfee_default_rate('van', 4)['base']);

echo "settings are cleaned\n";
$d = etel_dfee_defaults();
$g = etel_dfee_clean(array());
check('defaults: on, company 40 percent, service charge free, road factor 1.3, round to 5',
    $g['enabled'] == 1 && $g['company_pct'] == 40 && $g['service_pct'] == 0 && $g['road_factor'] == 1.3 && $g['round_step'] == 5);
$g = etel_dfee_clean(array('company_pct' => 150, 'service_pct' => -3, 'road_factor' => 9, 'round_step' => 7, 'max_km' => 'abc', 'night_mult' => 0.2));
check('absurd numbers are pulled back into range', $g['company_pct'] == 100 && $g['service_pct'] == 0 && $g['road_factor'] == 2 && $g['round_step'] == 5 && $g['max_km'] == 0 && $g['night_mult'] == 1);
$g = etel_dfee_clean(array('enabled' => '0'));
check('the switch can be turned off', $g['enabled'] == 0);
$g = etel_dfee_clean(array('enabled' => 'false', 'service_pct' => '5'));
check('"false" is off and a service charge of 5 is kept', $g['enabled'] == 0 && $g['service_pct'] == 5);
$rt = etel_dfee_clean_rate(array('base' => 'x', 'per_km' => -4, 'min' => 99999), 'bike', 1);
check('a bad price list: text keeps the starting price, a minus becomes 0, too big is capped', $rt['base'] == 45 && $rt['per_km'] == 0 && $rt['min'] == 5000, $rt);

echo "the fee\n";
$g = etel_dfee_clean(array());
$bike['min'] = 60;
$r = etel_dfee_compute($g, $bike, 3, false);
check('bicycle, 3 km in a straight line = 3.9 km by road: 45 + 15 x 3.9 = 103.5, rounded to 105', $r['fee'] == 105, $r);
check('the split is 60 percent courier (63) and 40 percent company (42)', $r['courier'] == 63 && $r['company'] == 42 && $r['courier'] + $r['company'] == $r['fee'], $r);
$r = etel_dfee_compute($g, $bike, 0.1, false);
check('a very short trip is never below the minimum (60)', $r['fee'] == 60 && $r['minimum_applied'] === true, $r);
$g1 = etel_dfee_clean(array('round_step' => 1, 'road_factor' => 1));
$r = etel_dfee_compute($g1, $bike, 2, false);
check('road factor 1 and round step 1: 45 + 15 x 2 = 75 exactly', $r['fee'] == 75, $r);
$g2 = etel_dfee_clean(array('round_step' => 1, 'road_factor' => 1, 'night_mult' => 1.5));
$day = etel_dfee_compute($g2, $bike, 2, false); $night = etel_dfee_compute($g2, $bike, 2, true);
check('the night multiplier raises the fee only at night (75 -> 113)', $day['fee'] == 75 && $night['fee'] == 113, array($day['fee'], $night['fee']));
$g3 = etel_dfee_clean(array('company_pct' => 0, 'round_step' => 1, 'road_factor' => 1));
$r = etel_dfee_compute($g3, $bike, 2, false);
check('company 0 percent: the courier gets all of it', $r['courier'] == 75 && $r['company'] == 0, $r);
$g4 = etel_dfee_clean(array('company_pct' => 100, 'round_step' => 1, 'road_factor' => 1));
$r = etel_dfee_compute($g4, $bike, 2, false);
check('company 100 percent: the courier gets nothing from the fee', $r['courier'] == 0 && $r['company'] == 75, $r);
$sp = etel_dfee_split(101, 40);
check('an odd fee still adds up (101 = 61 + 40)', $sp['courier'] == 61 && $sp['company'] == 40 && $sp['courier'] + $sp['company'] == 101, $sp);
check('rounding: 103.5 to 5 is 105; 102 to 5 is 100; to 10 is 100; to 1 is 102', etel_dfee_round(103.5, 5) == 105 && etel_dfee_round(102, 5) == 100 && etel_dfee_round(102, 10) == 100 && etel_dfee_round(102, 1) == 102);
$car = array('base' => 80, 'per_km' => 28, 'min' => 120);
$rc = etel_dfee_compute($g, $car, 3, false); $rb = etel_dfee_compute($g, $bike, 3, false);
check('a car costs more than a bicycle for the same trip', $rc['fee'] > $rb['fee'], array($rc['fee'], $rb['fee']));

echo "old apps and the night window\n";
check('old apps (no vehicle): moto -> moto, car -> car, express cargo -> van, empty -> moto',
    etel_dfee_class_for_tier('moto') == 'moto' && etel_dfee_class_for_tier('car') == 'car' && etel_dfee_class_for_tier('express_cargo') == 'van' && etel_dfee_class_for_tier('') == 'moto');
check('vehicle keys must be safe names', etel_dfee_class_ok('bike') && etel_dfee_class_ok('bajaj_qute') && !etel_dfee_class_ok('Bike') && !etel_dfee_class_ok('a; DROP') && !etel_dfee_class_ok(''));

echo $fails ? "FAILED: $fails\n" : "ALL PASS\n";
exit($fails ? 1 : 0);
