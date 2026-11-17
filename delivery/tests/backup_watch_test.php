<?php
// Backup alert + backup report + admin two-step list (api/lib/backup_watch.php, system_health.php, cron_backup_db.php, admin_2fa_policy.php).
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
define('APP_VER', 'test');
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4', $e('DB_USER'), $e('DB_PASS'),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

$dir = sys_get_temp_dir() . '/etel_bk_test_' . getmypid();
@mkdir($dir, 0700, true);
putenv('BACKUP_DIR=' . $dir);

$pushed = array();
function etel_push_send($pdo, $uid, $title, $body, $data = array()) { global $pushed; $pushed[] = array($uid, $title, $body); return true; }
require_once __DIR__ . '/../api/lib/system_health.php';
require_once __DIR__ . '/../api/lib/backup_watch.php';
require_once __DIR__ . '/../api/lib/admin_2fa_policy.php';

function fake_backup($dir, $name, $ageHours) {
    $created = date('Y-m-d H:i:s', time() - (int) ($ageHours * 3600));
    file_put_contents($dir . '/' . $name . '.sql.gz', 'x');
    file_put_contents($dir . '/' . $name . '.json', json_encode(array('file' => $name . '.sql.gz', 'created' => $created, 'bytes' => 1, 'sha256' => 'x')));
}
$admins = intval($pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn());
etel_health_heartbeat($pdo, 'test_init', 'x');   // the table is created on first use
$pdo->exec("DELETE FROM etel_heartbeat WHERE name IN ('backup_alert','backup_db')");

// 1. no backup at all
$r = etel_backup_watch($pdo, true);
check('no backup: stale', $r['stale'] === true && $r['hours'] === null);
check('no backup: every admin got one push', $admins > 0 && count($pushed) === $admins && $r['sent'] === $admins && $pushed[0][1] === 'Backup problem');
$h = etel_health_backup();
check('health Backups row is red when there is no backup', $h['status'] === 'bad');
$r = etel_backup_watch($pdo, true);
check('asked again straight away: no second push (12 hour throttle)', $r['stale'] === true && $r['sent'] === 0 && count($pushed) === $admins);
$r = etel_backup_watch($pdo, false);
check('push switched off (push=0): nothing sent', $r['sent'] === 0);

// 2. a 30 hour old backup is stale, a fresh one clears the alert
fake_backup($dir, 'etel-old', 30);
$pdo->exec("DELETE FROM etel_heartbeat WHERE name='backup_alert'");
$pushed = array();
$r = etel_backup_watch($pdo, true);
check('30 hour old backup: stale, about 30 hours', $r['stale'] === true && $r['hours'] >= 29.9 && $r['hours'] <= 30.2);
check('30 hour old backup: pushed again once the throttle is clear', count($pushed) === $admins && strpos($pushed[0][2], 'hours old') !== false);
$h = etel_health_backup();
check('health Backups row is red at 30 hours and says so', $h['status'] === 'bad' && strpos($h['detail'], '26 hours') !== false);

fake_backup($dir, 'etel-new', 2);
$pushed = array();
$r = etel_backup_watch($pdo, true);
check('a 2 hour old backup: not stale, no push', $r['stale'] === false && count($pushed) === 0 && $r['hours'] <= 2.1);
check('alert state goes back to clear', $pdo->query("SELECT detail FROM etel_heartbeat WHERE name='backup_alert'")->fetchColumn() === 'clear');
check('health Backups row is green', etel_health_backup()['status'] === 'ok');
fake_backup($dir, 'etel-edge', 25);   // an older one next to the fresh one must not matter
check('the NEWEST backup counts, not the oldest', etel_backup_watch($pdo, true)['stale'] === false);

// 3. the backup cron: runs from the command line with no key, and reports
$cmd = 'BACKUP_DIR=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../api/cron_backup_db.php') . ' 2>&1';
$out = shell_exec($cmd);
$j = json_decode((string) $out, true);
check('cron_backup_db.php run from the command line needs no key and succeeds', is_array($j) && !empty($j['success']) && !empty($j['file']), substr((string) $out, 0, 200));
$hb = $pdo->query("SELECT detail FROM etel_heartbeat WHERE name='backup_db'")->fetchColumn();
check('the cron reports a heartbeat "ok: ..."', is_string($hb) && strpos($hb, 'ok:') === 0, (string) $hb);
$bj = etel_health_backup_job($pdo);
check('health Backup job row is green after a run', $bj['status'] === 'ok');
$pdo->exec("UPDATE etel_heartbeat SET last_run = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE name='backup_db'");
check('health Backup job row is red when the last run was 30 hours ago', etel_health_backup_job($pdo)['status'] === 'bad');
$pdo->exec("UPDATE etel_heartbeat SET detail = 'failed: could not create the backup file', last_run = NOW() WHERE name='backup_db'");
check('health Backup job row is red when the last run failed', etel_health_backup_job($pdo)['status'] === 'bad');
$pdo->exec("DELETE FROM etel_heartbeat WHERE name='backup_db'");
check('health Backup job row warns when the cron never reported', etel_health_backup_job($pdo)['status'] === 'warn');

// 4. admin and staff without two-step
// the table is the one lib/totp.php creates
$pdo->exec("CREATE TABLE IF NOT EXISTS `admin_2fa` (`user_id` INT UNSIGNED NOT NULL PRIMARY KEY, `secret_enc` TEXT NOT NULL, `enabled` TINYINT(1) NOT NULL DEFAULT 0, `backup_codes` TEXT NULL, `last_step` BIGINT NOT NULL DEFAULT 0, `created_at` DATETIME NULL, `enabled_at` DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$miss = etel_admin_2fa_missing($pdo);
check('every admin without two-step is listed', count($miss) === $admins && isset($miss[0]['name']));
$uid = intval($pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn());
$pdo->exec("DELETE FROM admin_2fa WHERE user_id = " . $uid);
$pdo->exec("INSERT INTO admin_2fa (user_id, secret_enc, enabled) VALUES (" . $uid . ", 'x', 1)");
check('an admin with two-step ON is not listed', count(etel_admin_2fa_missing($pdo)) === $admins - 1);
$pdo->exec("UPDATE admin_2fa SET enabled = 0 WHERE user_id = " . $uid);
check('two-step saved but switched OFF still counts as missing', count(etel_admin_2fa_missing($pdo)) === $admins);
$pdo->exec("DELETE FROM admin_2fa WHERE user_id = " . $uid);

foreach (glob($dir . '/*') as $f) @unlink($f);
@rmdir($dir);
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
