<?php
// One run at a time per cron job (api/lib/cron_lock.php, used by the cron_*.php scripts).
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
$connect = function () use ($e) {
    return new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
};
try { $a = $connect(); $b = $connect(); } catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
require_once __DIR__ . '/../api/lib/cron_lock.php';

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

$name = 'locktest_' . substr((string) time(), -6);
check('the first run gets the lock', etel_cron_lock($a, $name) === true);
check('a second run, on another connection, does not', etel_cron_lock($b, $name) === false);
check('a different job is not blocked', etel_cron_lock($b, $name . '_other') === true);
$a->query("SELECT RELEASE_LOCK('etel_cron_" . $name . "')");
check('once the first run is over the next one gets it', etel_cron_lock($b, $name) === true);

// fails open: a database that cannot answer must not stop the job
class BrokenPdo { function prepare($s) { throw new Exception('no lock for you'); } }
check('a database error lets the job run as before', etel_cron_lock(new BrokenPdo(), 'x') === true);

// the real scripts: while the lock is held they report "skipped" and do nothing else
foreach (array('cron_ride_monitor' => 'ride_monitor', 'cron_close_stuck_rides' => 'close_stuck_rides', 'cron_dispatch_scheduled' => 'dispatch_scheduled') as $script => $lockName) {
    $holder = $connect();
    $holder->query("SELECT GET_LOCK('etel_cron_" . $lockName . "', 0)");
    // some scripts only run over the web with ?key=, so hand them the key the way a web request would
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$_GET["key"] = "' . $e('CRON_DISPATCH_KEY') . '"; include $argv[1];') . ' '
        . escapeshellarg(__DIR__ . '/../api/' . $script . '.php') . ' 2>&1');
    $j = json_decode((string) $out, true);
    check($script . ' skips while its previous run is still going', is_array($j) && !empty($j['skipped']));
    $holder->query("SELECT RELEASE_LOCK('etel_cron_" . $lockName . "')");
}
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
