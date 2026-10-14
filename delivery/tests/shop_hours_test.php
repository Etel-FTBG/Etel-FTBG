<?php
// Shop opening hours, "sold out today", the customer feed and the order-time block (api/lib/shop_hours.php).
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'shop-hours-test');
require_once __DIR__ . '/../api/lib/schema_helpers.php';
require_once __DIR__ . '/../api/lib/shop_hours.php';

$fails = 0;
register_shutdown_function(function () { global $done; if (empty($done)) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "times and windows\n";
check('08:00 and 8:00:00 both clean to 08:00', etel_hours_clean('08:00') === '08:00' && etel_hours_clean('8:00:00') === '08:00');
check('junk, 24:00 and 12:60 are not times', etel_hours_clean('abc') === null && etel_hours_clean('24:00') === null && etel_hours_clean('12:60') === null && etel_hours_clean('') === null);
check('08:00-21:00: 12:00 inside, 07:59 and 21:00 outside', etel_hours_within('08:00', '21:00', '12:00') && !etel_hours_within('08:00', '21:00', '07:59') && !etel_hours_within('08:00', '21:00', '21:00'));
check('opening minute counts as open', etel_hours_within('08:00', '21:00', '08:00'));
check('overnight 22:00-02:00: 23:30 and 01:00 inside, 12:00 outside', etel_hours_within('22:00', '02:00', '23:30') && etel_hours_within('22:00', '02:00', '01:00') && !etel_hours_within('22:00', '02:00', '12:00'));
check('no hours or same times: always inside', etel_hours_within(null, null, '03:00') && etel_hours_within('08:00', '08:00', '03:00'));

echo "shop state\n";
$s = etel_shop_state(1, '08:00', '21:00', '12:00');
check('switch on, inside hours: open', $s['open'] === true && $s['reason'] === '' && $s['opens_at'] === '08:00');
$s = etel_shop_state(1, '08:00', '21:00', '23:00');
check('switch on, outside hours: closed because of hours', $s['open'] === false && $s['reason'] === 'hours');
$s = etel_shop_state(0, '08:00', '21:00', '12:00');
check('switch off, inside hours: still closed (the switch wins)', $s['open'] === false && $s['reason'] === 'switch');
$s = etel_shop_state(1, null, null, '03:00');
check('no hours set, switch on: open any time', $s['open'] === true && $s['opens_at'] === null);
$s = etel_shop_state(0, null, null, '12:00');
check('no hours, switch off: closed', $s['open'] === false && $s['reason'] === 'switch');

echo "sold out today\n";
check('sold out on today\'s date', etel_item_sold_out('2026-10-14', '2026-10-14') === true);
check('yesterday\'s sold-out date has expired by itself', etel_item_sold_out('2026-10-13', '2026-10-14') === false);
check('never sold out (null / empty)', etel_item_sold_out(null, '2026-10-14') === false && etel_item_sold_out('', '2026-10-14') === false);

echo "customer feed\n";
$rows = array(
    array('id' => 1, 'is_online' => 1, 'opens_at' => '08:00:00', 'closes_at' => '21:00:00', 'sold_out_date' => null, 'is_available' => 1),
    array('id' => 2, 'is_online' => 1, 'opens_at' => '08:00:00', 'closes_at' => '21:00:00', 'sold_out_date' => '2026-10-14', 'is_available' => 1),
    array('id' => 3, 'is_online' => 0, 'opens_at' => null, 'closes_at' => null, 'sold_out_date' => null, 'is_available' => 1));
$f = etel_shop_feed_apply($rows, '22:30', '2026-10-14');
check('after closing time the feed says closed (is_online 0, reason hours)', $f[0]['is_online'] === 0 && $f[0]['closed_reason'] === 'hours' && $f[0]['opens_at'] === '08:00');
check('sold-out item is marked and not available', $f[1]['sold_out'] === 1 && $f[1]['is_available'] === 0 && !array_key_exists('sold_out_date', $f[1]));
check('switch-off shop reports reason switch', $f[2]['is_online'] === 0 && $f[2]['closed_reason'] === 'switch');
$f = etel_shop_feed_apply($rows, '12:00', '2026-10-14');
check('at noon the first shop is open and its item orderable', $f[0]['is_online'] === 1 && $f[0]['sold_out'] === 0 && $f[0]['is_available'] === 1);

echo "order-time block (real database)\n";
etel_hours_ensure_columns($pdo);
check('columns exist', (bool) $pdo->query("SHOW COLUMNS FROM service_givers LIKE 'closes_at'")->fetch() && (bool) $pdo->query("SHOW COLUMNS FROM items LIKE 'sold_out_date'")->fetch());
$pdo->exec("DELETE FROM items WHERE name LIKE 'SHT-%'"); $pdo->exec("DELETE FROM service_givers WHERE service_giver_code = 'SHT1'"); $pdo->exec("DELETE FROM users WHERE phone = '+251998100001'");
$pdo->exec("INSERT INTO users (name, phone, password_hash, role) VALUES ('Hours Owner', '+251998100001', 'x', 'service_giver')"); $uid = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name, status, is_online, opens_at, closes_at) VALUES (?, 'SHT1', 'Hours Cafe', 'verified', 1, '08:00:00', '21:00:00')")->execute(array($uid)); $sg = (int) $pdo->lastInsertId();
$mk = $pdo->prepare("INSERT INTO items (service_giver_id, item_code, name, category, price, is_available, sold_out_date) VALUES (?, ?, ?, 'food', 50, ?, ?)");
$mk->execute(array($sg, 'SHT-A', 'SHT-Fine', 1, null)); $fine = (int) $pdo->lastInsertId();
$mk->execute(array($sg, 'SHT-B', 'SHT-Hidden', 0, null)); $hidden = (int) $pdo->lastInsertId();
$mk->execute(array($sg, 'SHT-C', 'SHT-Sold', 1, '2026-10-14')); $sold = (int) $pdo->lastInsertId();
check('normal item inside hours: no blocker', etel_order_blockers($pdo, array($fine), '12:00', '2026-10-14') === '');
check('hidden item is refused with its name', strpos(etel_order_blockers($pdo, array($fine, $hidden), '12:00', '2026-10-14'), 'SHT-Hidden') !== false);
check('sold-out item is refused today', strpos(etel_order_blockers($pdo, array($sold), '12:00', '2026-10-14'), 'sold out') !== false);
check('the same item is fine tomorrow (sold-out expired)', etel_order_blockers($pdo, array($sold), '12:00', '2026-10-15') === '');
$msg = etel_order_blockers($pdo, array($fine), '22:00', '2026-10-14');
check('outside the shop hours: refused, message names the hours', strpos($msg, 'closed') !== false && strpos($msg, '08:00 to 21:00') !== false);
$pdo->exec("UPDATE service_givers SET is_online = 0 WHERE id = $sg");
check('switch off but no hour problem: still orderable (customer was warned)', etel_order_blockers($pdo, array($fine), '12:00', '2026-10-14') === '');
$pdo->exec("UPDATE service_givers SET opens_at = NULL, closes_at = NULL WHERE id = $sg");
check('hours cleared: any time is fine', etel_order_blockers($pdo, array($fine), '03:00', '2026-10-14') === '');
check('empty cart: nothing to block', etel_order_blockers($pdo, array(), '03:00', '2026-10-14') === '');
$pdo->exec("DELETE FROM items WHERE service_giver_id = $sg"); $pdo->exec("DELETE FROM service_givers WHERE id = $sg"); $pdo->exec("DELETE FROM users WHERE id = $uid");

$done = true;
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
