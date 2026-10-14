<?php
// Admin ratings report: averages, star spread, tags, lowest couriers and shops, newest ratings with low ones flagged,
// customer first name only. Throwaway test database only (api/.env): it parks every other rating in the year 2001 and does not restore them. SKIP (exit 0) when there is no database.
if (!defined('APP_VER')) define('APP_VER', 'ratings-report-test');
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/ratings_report.php';

$fails = 0; $done = false;
register_shutdown_function(function () { global $done; if (!$done) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "ratings report\n";
// pure rules
check('2 stars is low, 3 is not', etel_rating_is_low(2, null, null) && !etel_rating_is_low(3, null, null));
check('a 1-star courier or food rating is low even with 5 overall', etel_rating_is_low(5, 1, null) && etel_rating_is_low(5, null, 1) && !etel_rating_is_low(5, 2, 2));
check('tag list is split and trimmed', etel_rating_tag_list('late, cold') === array('late', 'cold') && etel_rating_tag_list('') === array() && etel_rating_tag_list(null) === array());
check('days outside 7/30/90 fall back to 30', etel_ratings_days(5) === 30 && etel_ratings_days(7) === 7 && etel_ratings_days('90') === 90);

function cleanup($pdo) {
    $ids = $pdo->query("SELECT id FROM orders WHERE order_code LIKE 'RATE-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) { $in = implode(',', array_map('intval', $ids)); $pdo->exec("DELETE FROM order_ratings WHERE order_id IN ($in)"); $pdo->exec("DELETE FROM order_assignments WHERE order_id IN ($in)"); }
    $pdo->exec("DELETE FROM orders WHERE order_code LIKE 'RATE-%'");
    $pdo->exec("DELETE FROM couriers WHERE courier_code LIKE 'RTC%'");
    $pdo->exec("DELETE FROM service_givers WHERE service_giver_code LIKE 'RTS%'");
    $pdo->exec("DELETE FROM users WHERE phone LIKE '+2519980000%'");
}
cleanup($pdo);
// the report looks at the whole table for the period, so park other ratings far in the past while this test runs
$pdo->exec("UPDATE order_ratings SET rated_at = '2001-01-01 00:00:00' WHERE rated_at >= DATE_SUB(NOW(), INTERVAL 100 DAY)");

$mkUser = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role) VALUES (?, ?, 'x', ?)");
$uid = array();
foreach (array(array('Abebe Bekele', 'customer'), array('Courier One', 'courier'), array('Courier Two', 'courier'), array('Shop Owner A', 'service_giver'), array('Shop Owner B', 'service_giver')) as $i => $u) {
    $mkUser->execute(array($u[0], '+2519980000' . sprintf('%02d', $i), $u[1])); $uid[$i] = (int) $pdo->lastInsertId();
}
$pdo->prepare("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (?, 'RTC1', 'Stadium', 'online', 1)")->execute(array($uid[1])); $c1 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (?, 'RTC2', 'Stadium', 'online', 1)")->execute(array($uid[2])); $c2 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name) VALUES (?, 'RTS1', 'Shop A')")->execute(array($uid[3])); $s1 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name) VALUES (?, 'RTS2', 'Shop B')")->execute(array($uid[4])); $s2 = (int) $pdo->lastInsertId();

$ord = $pdo->prepare("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, placed_at) VALUES (?, ?, ?, 'Stadium', 'Tabor', 'delivered', NOW())");
$asg = $pdo->prepare("INSERT INTO order_assignments (order_id, service_giver_id) VALUES (?, ?)");
$rate = $pdo->prepare("INSERT INTO order_ratings (order_id, customer_id, stars, comment, food_rating, courier_rating, tags, rated_at) VALUES (?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR))");
// code, courier, shop, stars, food, courier stars, tags, comment, hours ago
$cases = array(
    array('RATE-1', $c1, $s1, 5, 5, 5, 'fast_delivery,great_taste', 'Great!', 1),
    array('RATE-2', $c1, $s1, 4, null, 4, 'friendly_courier', '', 2),
    array('RATE-3', $c2, $s2, 1, 2, 1, 'late,cold', 'Cold and an hour late', 3),
    array('RATE-4', $c2, $s2, 3, 3, 3, null, '', 4),
    array('RATE-5', $c1, $s2, 5, 5, 1, 'late', 'Food great, courier slow', 5),   // overall 5 but courier 1 star: still flagged low
);
foreach ($cases as $c) {
    $ord->execute(array($c[0], $uid[0], $c[1])); $oid = (int) $pdo->lastInsertId();
    $asg->execute(array($oid, $c[2]));
    $rate->execute(array($oid, $uid[0], $c[3], $c[7], $c[4], $c[5], $c[6], $c[8]));
}

$r = etel_ratings_report($pdo, 30);
$s = $r['summary'];
check('5 ratings counted', $s['count'] === 5);
check('average stars = (5+4+1+3+5)/5 = 3.6', $s['avg'] === 3.6);
check('average courier stars = (5+4+1+3+1)/5 = 2.8', $s['avg_courier'] === 2.8);
check('average food stars ignores the missing one: (5+2+3+5)/4 = 3.75', $s['avg_food'] === 3.75);
check('star spread 1:1 2:0 3:1 4:1 5:2', $s['stars'][1] === 1 && $s['stars'][2] === 0 && $s['stars'][3] === 1 && $s['stars'][4] === 1 && $s['stars'][5] === 2);
check('low ratings = the 1-star order and the 5-star order with a 1-star courier = 2', $s['low'] === 2 && etel_low_ratings_count($pdo, 7) === 2);
$tags = array(); foreach ($r['tags'] as $t) $tags[$t['tag']] = $t['n'];
check('tag counts: late 2, cold 1, fast_delivery 1', $tags['late'] === 2 && $tags['cold'] === 1 && $tags['fast_delivery'] === 1);
check('most common tag first', $r['tags'][0]['tag'] === 'late');
check('lowest courier first: Courier Two (1,3 -> 2.0) before Courier One (5,4,1 -> 3.33)', $r['couriers'][0]['name'] === 'Courier Two' && (float) $r['couriers'][0]['avg'] === 2.0 && $r['couriers'][1]['name'] === 'Courier One' && (int) $r['couriers'][1]['n'] === 3);
check('lowest shop first: Shop B (1,3,5 -> 3.0) before Shop A (5,4 -> 4.5)', $r['shops'][0]['name'] === 'Shop B' && (float) $r['shops'][0]['avg'] === 3.0 && $r['shops'][1]['name'] === 'Shop A');
check('newest first (RATE-1 was 1 hour ago)', $r['recent'][0]['order_code'] === 'RATE-1' && count($r['recent']) === 5);
$low = array(); foreach ($r['recent'] as $x) if ($x['low']) $low[] = $x['order_code'];
sort($low);
check('low ones are flagged: RATE-3 and RATE-5', $low === array('RATE-3', 'RATE-5'));
check('only the customer first name is returned', $r['recent'][0]['customer'] === 'Abebe' && strpos(json_encode($r), 'Bekele') === false && strpos(json_encode($r), '+251998') === false);
check('shop and courier names come with each rating', $r['recent'][0]['shop'] === 'Shop A' && $r['recent'][0]['courier'] === 'Courier One');
$r7 = etel_ratings_report($pdo, 7);
check('a 7-day report still sees them', $r7['summary']['count'] === 5 && $r7['days'] === 7);
$pdo->exec("UPDATE order_ratings SET rated_at = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'RATE-%')");
check('ratings older than the period are left out (30 days) but 90 days still includes them', etel_ratings_report($pdo, 30)['summary']['count'] === 0 && etel_ratings_report($pdo, 90)['summary']['count'] === 5);
$e0 = etel_ratings_report($pdo, 7);
check('an empty period gives empty averages, not zeros', $e0['summary']['count'] === 0 && $e0['summary']['avg'] === null && $e0['recent'] === array());

cleanup($pdo);
$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
