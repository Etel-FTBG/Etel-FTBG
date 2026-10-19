<?php
// Tips paid through Chapa (api/lib/ride_tip.php + chapa_settle), on a real database, with Chapa's verify answer faked.
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'tip-chapa-test');
$GLOBALS['fake'] = array('status' => 'success', 'amount' => 25.0, 'currency' => 'ETB');
function chapa_verify($txRef) { return $GLOBALS['fake']; }   // defined first: chapa_bridge.php only defines its own when none exists
require_once __DIR__ . '/../api/chapa_bridge.php';
require_once __DIR__ . '/../api/lib/ride_tip.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

rides_tip_ensure($pdo);
chapa_ensure_table($pdo);
$pdo->exec("DELETE FROM chapa_payments WHERE tx_ref LIKE 'TIPT-%'");
$pdo->exec("DELETE FROM users WHERE phone IN ('+251998300001','+251998300002')");
$pdo->exec("INSERT INTO users (name, phone, password_hash, role) VALUES ('Tip Rider', '+251998300001', 'x', 'customer')"); $rider = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (name, phone, password_hash, role) VALUES ('Tip Driver', '+251998300002', 'x', 'courier')"); $drv = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ride_drivers (user_id, total_earnings) VALUES (?, 100)")->execute(array($drv));
$pdo->prepare("INSERT INTO rides (passenger_id, driver_id, status, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, completed_at, created_at) VALUES (?,?, 'completed', 7.05, 38.49, 7.09, 38.52, NOW(), NOW())")->execute(array($rider, $drv));
$ride = (int)$pdo->lastInsertId();
$bal = function () use ($pdo, $drv) { $s = $pdo->prepare("SELECT total_earnings FROM ride_drivers WHERE user_id=?"); $s->execute(array($drv)); return (float)$s->fetchColumn(); };
$pay = function ($ref, $amt) use ($pdo, $ride, $rider) {
    $pdo->prepare("INSERT INTO chapa_payments (tx_ref, kind, target_id, user_id, amount) VALUES (?, 'tip', ?, ?, ?)")->execute(array($ref, $ride, $rider, $amt));
};

// pending at Chapa: nothing happens
$pay('TIPT-1', 25);
$GLOBALS['fake'] = array('status' => 'pending', 'amount' => 25.0, 'currency' => 'ETB');
check('a payment still pending pays nobody', chapa_settle($pdo, 'TIPT-1') === 'pending' && $bal() == 100.0);

// wrong amount: refused
$GLOBALS['fake'] = array('status' => 'success', 'amount' => 5.0, 'currency' => 'ETB');
check('a wrong amount is refused', chapa_settle($pdo, 'TIPT-1') === 'failed' && $bal() == 100.0);
$pdo->exec("UPDATE chapa_payments SET status='pending' WHERE tx_ref='TIPT-1'");

// confirmed: the driver gets all of it, once
$GLOBALS['fake'] = array('status' => 'success', 'amount' => 25.0, 'currency' => 'ETB');
check('a confirmed tip completes', chapa_settle($pdo, 'TIPT-1') === 'completed');
check('the driver gets ALL of it (+25)', abs($bal() - 125.0) < 0.01);
$t = $pdo->query("SELECT * FROM ride_tips WHERE ride_id=$ride")->fetch();
check('the tip is saved as approved by Chapa', $t && $t['status'] === 'approved' && $t['bank_key'] === 'chapa' && $t['payment_ref'] === 'TIPT-1' && abs($t['amount'] - 25) < 0.01);
check('settling again pays nothing more', chapa_settle($pdo, 'TIPT-1') === 'already' && abs($bal() - 125.0) < 0.01);

// a second payment for a ride that already has a tip: the money was taken, so the driver is still paid
$pay('TIPT-2', 25);
check('a second payment for the same trip still completes', chapa_settle($pdo, 'TIPT-2') === 'completed');
check('and the driver is paid for it', abs($bal() - 150.0) < 0.01);
check('but only one tip row exists', (int)$pdo->query("SELECT COUNT(*) FROM ride_tips WHERE ride_id=$ride")->fetchColumn() === 1);

$pdo->exec("DELETE FROM ride_tips WHERE ride_id=$ride");
$pdo->exec("DELETE FROM chapa_payments WHERE tx_ref LIKE 'TIPT-%'");
$done = true;
if ($fails) { echo "FAILED: $fails check(s)\n"; exit(1); }
echo "All Chapa tip checks passed\n";
