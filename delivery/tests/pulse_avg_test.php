<?php
// Admin "Avg delivery time": only believable delivery times count (placed -> delivered, 1 to 180 minutes).
// Orders marked delivered hours later, or with the times the wrong way round, are left out and counted as skipped.
// Throwaway test database only (api/.env, same as the other smoke tests). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
require_once __DIR__ . '/../api/lib/platform_pulse.php';

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "platform pulse: average delivery time\n";
$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'PULSE-%'");
// A day far in the past so no other test data lands in the same window.
$cases = array(
    array('PULSE-1', 'delivered', 20),     // counts
    array('PULSE-2', 'delivered', 40),     // counts
    array('PULSE-3', 'delivered', 400),    // marked delivered hours later: left out
    array('PULSE-4', 'delivered', -5),     // delivered before it was placed: left out
    array('PULSE-5', 'delivered', 0),      // same minute: left out (not a real delivery time)
    array('PULSE-6', 'cancelled', null),   // not delivered: not counted at all
);
$ins = $pdo->prepare("INSERT INTO orders (order_code, customer_id, from_zone, to_zone, status, placed_at, delivered_at)
    VALUES (?, 1, 'Stadium', 'Tabor', ?, '2020-03-01 12:00:00', IF(? IS NULL, NULL, DATE_ADD('2020-03-01 12:00:00', INTERVAL ? MINUTE)))");
foreach ($cases as $c) $ins->execute(array($c[0], $c[1], $c[2], $c[2]));

$r = etel_pulse_period($pdo, "'2020-03-01'", "'2020-03-02'");
check('average counts only the believable deliveries (20 and 40 -> 30)', $r['avg_minutes'] === 30);
check('two orders counted', $r['avg_n'] === 2);
check('three delivered orders with unbelievable times are reported as left out', $r['avg_skipped'] === 3);
check('all five delivered orders are still counted as delivered', $r['delivered'] === 5);

$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'PULSE-%'");
$ins->execute(array('PULSE-7', 'delivered', 500, 500));
$r = etel_pulse_period($pdo, "'2020-03-01'", "'2020-03-02'");
check('when nothing believable exists the average is empty, not a huge number', $r['avg_minutes'] === null && $r['avg_n'] === 0 && $r['avg_skipped'] === 1);

$pdo->exec("DELETE FROM orders WHERE order_code LIKE 'PULSE-%'");
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
