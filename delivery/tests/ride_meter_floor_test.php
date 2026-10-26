<?php
// The GPS-meter safety floor (api/lib/ride_fare.php): a rider is billed the distance actually driven, never the distance
// booked. Pure function test with a stub database; needs no real database. Exits 0 on pass, 1 on failure.
if (!defined('APP_VER')) define('APP_VER', 'meter-floor-test');
require_once __DIR__ . '/../api/lib/ride_fare.php';

class StubStmt { public $row; function __construct($row) { $this->row = $row; } function execute($p = array()) { return true; } function fetch() { return $this->row; } }
class StubPdo { public $pos; function __construct($pos) { $this->pos = $pos; } function prepare($sql) { return new StubStmt($this->pos); } }

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "ride meter floor\n";
// booked 2.75 km, the meter says it really covered 1 km, and no position data
$r = array('open_trip' => 0, 'distance_km' => 2.75);
$o = rides_meter_shortfall_floor(new StubPdo(null), $r, 1, 1.0);
check('booked 2.75 km but driven 1 km is billed 1 km, not the booking', $o['applied'] === false && abs($o['km'] - 1.0) < 0.001);
$o = rides_meter_shortfall_floor(new StubPdo(null), $r, 1, 3.4);
check('a longer trip than booked is billed what was driven', $o['applied'] === false && abs($o['km'] - 3.4) < 0.001);
$o = rides_meter_shortfall_floor(new StubPdo(null), $r, 1, 0.0);
check('a dead meter with no position data gets no booking-estimate floor', $o['applied'] === false);

// pickup 9.0000,38.7000 and the driver ended 0.0216 deg north (about 2.4 km in a straight line)
$pickup = array('open_trip' => 0, 'distance_km' => 2.75, 'pickup_lat' => 9.0, 'pickup_lng' => 38.7);
$pos = array('lat' => 9.0216, 'lng' => 38.7);
$o = rides_meter_shortfall_floor(new StubPdo($pos), $pickup, 1, 0.5);
check('a meter far below the straight line to where the driver ended uses the line', $o['applied'] === true && $o['basis'] === 'pickup_to_driver_position' && $o['km'] > 2.3 && $o['km'] < 2.5);
$o = rides_meter_shortfall_floor(new StubPdo($pos), $pickup, 1, 1.5);
check('a meter at or above half the line stands', $o['applied'] === false && abs($o['km'] - 1.5) < 0.001);
$o = rides_meter_shortfall_floor(new StubPdo($pos), $pickup, 1, 2.0);
check('a normal road trip (longer than the line) stands', $o['applied'] === false && abs($o['km'] - 2.0) < 0.001);

if ($fails) { echo "FAILED: $fails\n"; exit(1); }
echo "All ride meter floor checks passed\n";
