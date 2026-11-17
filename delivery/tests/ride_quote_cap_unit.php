<?php
/**
 * Ride fare versus quote and the peak-window check (no database needed).
 *
 *   php delivery/tests/ride_quote_cap_unit.php
 *
 * Covers api/lib/ride_quote_cap.php (cap at estimate + X percent, rules that skip the cap) and
 * api/lib/ride_peak_check.php (surge covering most of the week, overlaps, very high multipliers).
 */
define('APP_VER', 'test');
require_once __DIR__ . '/../api/lib/ride_fare.php';
require_once __DIR__ . '/../api/lib/ride_quote_cap.php';
require_once __DIR__ . '/../api/lib/ride_peak_check.php';

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

/* ---- quote cap ---- */
$i = rides_quote_cap_info(25, 205.68, 527.41, false, null);   // ride 372
check('ride 372: meter 527.41 against quote 205.68 is capped', $i['applies'] && $i['capped']);
check('ride 372: final fare is quote + 25 percent = 257.10', abs($i['fare'] - 257.10) < 0.005);
check('ride 372: the meter value is kept for the office', abs($i['uncapped'] - 527.41) < 0.005);

$i = rides_quote_cap_info(25, 200, 230, false, null);
check('a fare inside the limit is charged as metered', $i['applies'] && !$i['capped'] && abs($i['fare'] - 230) < 0.005);
check('a fare above the estimate but inside the limit is flagged "over" for the warning', $i['over'] === true);

$i = rides_quote_cap_info(25, 200, 180, false, null);
check('a fare below the estimate is never raised and not "over"', abs($i['fare'] - 180) < 0.005 && $i['over'] === false);

$i = rides_quote_cap_info(25, 200, 250, false, null);
check('a fare exactly at the limit is not capped', !$i['capped'] && abs($i['fare'] - 250) < 0.005);

$i = rides_quote_cap_info(0, 200, 900, false, null);
check('cap percent 0 switches the cap off', $i['applies'] && !$i['capped'] && abs($i['fare'] - 900) < 0.005 && $i['cap'] === null);

$i = rides_quote_cap_info(25, 200, 900, true, null);
check('an open trip (no destination) is not capped', empty($i['applies']) && abs($i['fare'] - 900) < 0.005);

$i = rides_quote_cap_info(25, 0, 900, false, null);
check('no estimate on the ride: no cap', empty($i['applies']));

$i = rides_quote_cap_info(25, 200, 900, false, 250);
check('a trip with an agreed price is left to the agreed price rule', empty($i['applies']));

$i = rides_quote_cap_info(10, 100, 120, false, null);
check('another percent works (10 percent: 100 -> cap 110)', $i['capped'] && abs($i['fare'] - 110) < 0.005);

/* ---- peak windows ---- */
$live = json_decode('[{"label":"Morning rush","days":[4,5,6,7,1,2,3],"start":"18:00","end":"20:00","multiplier":1.2},{"label":"Evening","days":[3,4,5,1,2,6,7],"start":"12:00","end":"02:00","multiplier":1.2},{"label":"nights","days":[2,1,3,4,5,7,6],"start":"06:00","end":"08:30","multiplier":1.2},{"label":"Over night","days":[2,1,3,4,5,6,7],"start":"09:00","end":"11:30","multiplier":1.3},{"label":"Midnight","days":[3,1,2,4,5,6,7],"start":"11:30","end":"24:00","multiplier":1.5}]', true);
$w = rides_peak_windows_warnings($live);
$txt = implode(' | ', $w);
check('the 17 Nov live schedule is flagged for covering most of the week', strpos($txt, 'percent of the week') !== false);
check('the 17 Nov live schedule is flagged for overlaps', strpos($txt, 'overlap') !== false);

$good = array(
    array('label' => 'Morning rush', 'days' => array(1, 2, 3, 4, 5, 6, 7), 'start' => '06:30', 'end' => '09:00', 'multiplier' => 1.2),
    array('label' => 'Evening rush', 'days' => array(1, 2, 3, 4, 5, 6, 7), 'start' => '16:30', 'end' => '19:30', 'multiplier' => 1.3),
    array('label' => 'Weekend night', 'days' => array(5, 6), 'start' => '19:30', 'end' => '23:00', 'multiplier' => 1.2),
);
check('a normal rush-hour schedule gives no warning', count(rides_peak_windows_warnings($good)) === 0);

$wrapA = array(array('label' => 'A', 'days' => array(5), 'start' => '22:00', 'end' => '02:00', 'multiplier' => 1.2),
               array('label' => 'B', 'days' => array(6), 'start' => '01:00', 'end' => '03:00', 'multiplier' => 1.2));
check('an overnight window that runs into the next day overlaps correctly', count(rides_peak_windows_warnings($wrapA)) === 1);

$sundayWrap = array(array('label' => 'A', 'days' => array(7), 'start' => '23:00', 'end' => '01:00', 'multiplier' => 1.2),
                    array('label' => 'B', 'days' => array(1), 'start' => '00:00', 'end' => '02:00', 'multiplier' => 1.2));
check('Sunday night runs into Monday', count(rides_peak_windows_warnings($sundayWrap)) === 1);

$noOverlap = array(array('label' => 'A', 'days' => array(5), 'start' => '22:00', 'end' => '02:00', 'multiplier' => 1.2),
                   array('label' => 'B', 'days' => array(5), 'start' => '02:00', 'end' => '06:00', 'multiplier' => 1.2));
check('windows that only touch do not overlap', count(rides_peak_windows_warnings($noOverlap)) === 0);

$high = array(array('label' => 'Crazy', 'days' => array(1), 'start' => '10:00', 'end' => '11:00', 'multiplier' => 2.5));
check('a multiplier above 1.5 is flagged', count(rides_peak_windows_warnings($high)) === 1);
check('no windows: no warnings', count(rides_peak_windows_warnings(array())) === 0);

/* ---- database part: rides_quote_cap_apply on a throwaway in-memory database (skipped without pdo_sqlite) ---- */
if (extension_loaded('pdo_sqlite')) {
    $issues = array();
    function rides_sweeper_issue($pdo, $rideId, $reporterId, $category, $details, $status) {
        global $issues; $issues[] = array($rideId, $category, $details, $status);
    }
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE pricing_rules (rule_key TEXT, rule_value TEXT)");
    $db->exec("CREATE TABLE ride_issues (ride_id INT, category TEXT, details TEXT)");
    $db->exec("CREATE TABLE rides (id INT, fare_estimate REAL, fare_final REAL, agreed_fare REAL, open_trip INT, driver_id INT, fare_breakdown_json TEXT)");
    $b = json_encode(array('fare_base' => 100, 'fare_distance' => 305.7, 'fare_total' => 527.41));
    $db->exec("INSERT INTO rides VALUES (372, 205.68, 527.41, NULL, 0, 5, '" . $b . "')");
    $db->exec("INSERT INTO rides VALUES (373, 200, 230, NULL, 0, 5, '" . $b . "')");
    $db->exec("INSERT INTO rides VALUES (374, 0, 800, NULL, 1, 5, '" . $b . "')");
    $db->exec("INSERT INTO rides VALUES (375, 200, 900, 250, 0, 5, '" . $b . "')");

    $fare = rides_quote_cap_apply($db, 372, 527.41);
    $row = $db->query("SELECT fare_final, fare_breakdown_json FROM rides WHERE id=372")->fetch(PDO::FETCH_ASSOC);
    $bj = json_decode($row['fare_breakdown_json'], true);
    check('apply: ride 372 is billed 257.10', abs($fare - 257.10) < 0.005 && abs(floatval($row['fare_final']) - 257.10) < 0.005);
    check('apply: the saved breakdown keeps estimate, cap, the meter value and the new total',
        $bj['quote_estimate'] == 205.68 && $bj['quote_capped'] == 1 && $bj['quote_uncapped'] == 527.41 && abs($bj['fare_total'] - 257.10) < 0.005);
    check('apply: the office gets one review item', count($issues) === 1 && $issues[0][1] === 'fare_review' && strpos($issues[0][2], 'Fare capped') === 0);

    $fare = rides_quote_cap_apply($db, 373, 230);
    $row = $db->query("SELECT fare_final, fare_breakdown_json FROM rides WHERE id=373")->fetch(PDO::FETCH_ASSOC);
    $bj = json_decode($row['fare_breakdown_json'], true);
    check('apply: a fare inside the limit is unchanged but the estimate is saved', abs($fare - 230) < 0.005 && floatval($row['fare_final']) == 230 && $bj['quote_estimate'] == 200 && $bj['quote_capped'] == 0 && $bj['quote_over'] == 1);

    check('apply: an open trip is left alone', abs(rides_quote_cap_apply($db, 374, 800) - 800) < 0.005);
    check('apply: an agreed price is left alone', abs(rides_quote_cap_apply($db, 375, 250) - 250) < 0.005);
    check('apply: an unknown ride returns the fare it was given', abs(rides_quote_cap_apply($db, 999, 123) - 123) < 0.005);
} else {
    echo "  SKIP  database part (no pdo_sqlite)\n";
}

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
