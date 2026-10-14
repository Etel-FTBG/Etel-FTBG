<?php
// api/health.php must turn "the daily backup stopped" into a non-200 answer so the phone monitor fires.
// Runs health.php in a child process against a temp backup folder. Needs the test database (api/.env); SKIPs without it.
date_default_timezone_set('Africa/Addis_Ababa'); // the backup job writes its time in this zone
$root = dirname(__DIR__);
$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }
function health($root, $dir) {
    $cmd = 'BACKUP_DIR=' . escapeshellarg($dir) . ' php ' . escapeshellarg($root . '/api/health.php') . ' 2>/dev/null';
    $o = shell_exec($cmd);
    return json_decode((string) $o, true);
}
function put($dir, $name, $created, $withDump = true) {
    if ($withDump) file_put_contents($dir . '/' . $name . '.sql.gz', 'x');
    file_put_contents($dir . '/' . $name . '.json', json_encode(array('file' => $name . '.sql.gz', 'created' => $created, 'bytes' => 1)));
}
$tmp = sys_get_temp_dir() . '/etel_hb_' . getmypid();
@mkdir($tmp, 0700, true);

$h = health($root, $tmp);
if (!is_array($h) || (isset($h['db']) && $h['db'] !== 'ok')) { echo "SKIP: no test database\n"; exit(0); }

echo "no backups yet\n";
check('reported as unknown', $h['backup'] === 'unknown');
check('does not turn the check red by itself', $h['status'] === 'ok' || isset($h['disk']) && $h['disk'] === 'low' || (isset($h['cron']) && $h['cron'] === 'stale'));

echo "a fresh backup\n";
put($tmp, 'etel-fresh', date('Y-m-d H:i:s', time() - 3600 * 5));
$h = health($root, $tmp);
check('ok, age about 5 hours', $h['backup'] === 'ok' && $h['backup_age_h'] > 4.9 && $h['backup_age_h'] < 5.2);

echo "only an old backup\n";
foreach (glob($tmp . '/*') as $f) unlink($f);
put($tmp, 'etel-old', date('Y-m-d H:i:s', time() - 3600 * 50));
$h = health($root, $tmp);
check('stale', $h['backup'] === 'stale' && $h['backup_age_h'] > 49);
check('answer is not ok (monitor will alert)', $h['status'] !== 'ok');

echo "a record without its dump file\n";
foreach (glob($tmp . '/*') as $f) unlink($f);
put($tmp, 'etel-orphan', date('Y-m-d H:i:s', time() - 3600), false);
$h = health($root, $tmp);
check('does not count as a backup', $h['backup'] === 'unknown');

echo "nothing private in the answer\n";
$raw = json_encode($h);
check('no folder or file names', strpos($raw, 'etel-') === false && strpos($raw, $tmp) === false);

foreach (glob($tmp . '/*') as $f) unlink($f);
@rmdir($tmp);
echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
