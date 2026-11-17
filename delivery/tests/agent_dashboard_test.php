<?php
/**
 * Agent dashboard numbers (action fa_agent_dashboard).
 *   php delivery/tests/agent_dashboard_test.php
 * Runs the REAL controller file on an in-memory SQLite database. Covers: the right totals per day and per period, clawbacks reduce
 * earnings, rejected earnings and rejected riders do not count, only this agent's rows, days outside the range are ignored,
 * missing bookings table gives zeros, a pending agent or a non-agent is refused, an unknown period falls back to 30 days.
 */
$src = file_get_contents(__DIR__ . '/../api/controllers/FieldAgentDashboardController.php');
$src = preg_replace('/^<\?php/', '', $src);
$src = str_replace("require_once __DIR__ . '/../lib/fa_core.php';", '', $src);
class OkEx extends Exception { public $data; }
class FailEx extends Exception { public $code2; }
function ok($d) { $e = new OkEx('ok'); $e->data = $d; throw $e; }
function fail($m, $c = 400) { $e = new FailEx($m); $e->code2 = $c; throw $e; }
$GLOBALS['pdo'] = new PDO('sqlite::memory:');
function db() { return $GLOBALS['pdo']; }
function require_auth($pdo) { return $GLOBALS['user']; }
$p = $GLOBALS['pdo'];
$p->exec("CREATE TABLE fa_agents (id INTEGER PRIMARY KEY, user_id INT, status TEXT)");
$p->exec("CREATE TABLE fa_earnings (id INTEGER PRIMARY KEY, agent_id INT, amount REAL, status TEXT, earned_at TEXT)");
$p->exec("CREATE TABLE fa_referrals (id INTEGER PRIMARY KEY, agent_id INT, status TEXT, claimed_at TEXT, first_ride_at TEXT)");
$p->exec("INSERT INTO fa_agents VALUES (1, 10, 'approved'), (2, 20, 'approved'), (3, 30, 'pending')");
function d($n) { return date('Y-m-d', strtotime("-$n day")); }
$ins = $p->prepare("INSERT INTO fa_earnings (agent_id, amount, status, earned_at) VALUES (?,?,?,?)");
foreach (array(array(1, 50, 'paid', d(0) . ' 10:00:00'), array(1, 30, 'pending', d(0) . ' 11:00:00'), array(1, -20, 'payable', d(0) . ' 12:00:00'),
               array(1, 99, 'rejected', d(0) . ' 13:00:00'), array(1, 40, 'payable', d(3) . ' 09:00:00'), array(1, 25, 'paid', d(10) . ' 09:00:00'),
               array(1, 500, 'paid', d(200) . ' 09:00:00'), array(2, 77, 'paid', d(0) . ' 09:00:00')) as $r) $ins->execute($r);
$rf = $p->prepare("INSERT INTO fa_referrals (agent_id, status, claimed_at, first_ride_at) VALUES (?,?,?,?)");
foreach (array(array(1, 'claimed', d(0) . ' 08:00:00', null), array(1, 'qualified', d(1) . ' 08:00:00', d(0) . ' 09:00:00'), array(1, 'rejected', d(0) . ' 07:00:00', null),
               array(1, 'qualified', d(12) . ' 08:00:00', d(11) . ' 09:00:00'), array(2, 'claimed', d(0) . ' 08:00:00', null)) as $r) $rf->execute($r);

$fails = 0;
function check($l, $c) { global $fails; if ($c) echo "ok   $l\n"; else { echo "FAIL $l\n"; $fails++; } }
function run($user, $body) {
    $GLOBALS['user'] = $user; $action = 'fa_agent_dashboard';
    try { eval($GLOBALS['src']); return array(null, 'fell through'); }
    catch (OkEx $e) { return array($e->data, null); } catch (FailEx $e) { return array(null, $e->code2); }
}
list($r, $e) = run(array('id' => 10), array('days' => 7));
check('7 days: ok', $e === null && $r['days'] === 7);
check('today earnings = 50 + 30 - 20 (rejected not counted)', abs($r['today']['earned'] - 60) < 0.001);
check('this 7 days earnings = 60 + 40', abs($r['current']['earned'] - 100) < 0.001);
check('previous 7 days earnings = 25 (day 10)', abs($r['previous']['earned'] - 25) < 0.001);
check('riders this 7 days = 2 (rejected one not counted)', $r['current']['riders'] === 2);
check('qualified this 7 days = 1', $r['current']['qualified'] === 1);
check('conversion = 50 percent', $r['conversion_pct'] === 50);
check('series has 7 rows, last is today', count($r['series']) === 7 && $r['series'][6]['d'] === d(0));
check('best day is today with 60', $r['best_day'] && $r['best_day']['d'] === d(0) && abs($r['best_day']['earned'] - 60) < 0.001);
check('streak counts today', $r['streak_days'] >= 1);
check('another agent money not mixed in', abs($r['current']['earned'] - 100) < 0.001);
list($r, $e) = run(array('id' => 10), array('days' => 30));
check('30 days: earnings 125', abs($r['current']['earned'] - 125) < 0.001 && count($r['series']) === 30);
check('30 days: row from 200 days ago ignored', abs($r['current']['earned'] + $r['previous']['earned'] - 125) < 0.001);
list($r, $e) = run(array('id' => 10), array('days' => 5));
check('unknown period falls back to 30', $r['days'] === 30);
list($r, $e) = run(array('id' => 10), array());
check('missing fa_bookings table gives zero bookings', $r['current']['bookings'] === 0);
list($r, $e) = run(array('id' => 20), array('days' => 7));
check('agent 2 only sees own 77', abs($r['today']['earned'] - 77) < 0.001);
list($r, $e) = run(array('id' => 30), array());
check('pending agent refused 403', $e === 403);
list($r, $e) = run(array('id' => 99), array());
check('non-agent refused 403', $e === 403);
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n"; exit($fails ? 1 : 0);
