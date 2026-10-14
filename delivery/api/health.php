<?php
/**
 * ETEL - public uptime check for external monitors (UptimeRobot, Better Stack, cron-job.org ...).
 *
 *   GET https://ethelmarket.com/delivery/api/health.php
 *
 * Answers HTTP 200 + {"status":"ok"} only when the database answers, the disk has room and the daily backup is under 36 hours old;
 * HTTP 503 + {"status":"down"|"degraded"} otherwise, so a monitor needs no keyword set-up:
 * "any answer other than 200" = alert. It reveals nothing private (no versions, hosts, counts,
 * names) and writes nothing. The background-job age is reported as a number of minutes only.
 */
if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function hc_reply($arr, $code) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/.env');
function hc_env($key, $default = '') {
    global $env;
    if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

$out = array('status' => 'ok', 'time' => date('c'));
$t0 = microtime(true);

// 1. Database: connect and answer a trivial query.
$pdo = null;
try {
    $pdo = new PDO(
        'mysql:host=' . hc_env('DB_HOST', 'localhost') . ';dbname=' . hc_env('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        hc_env('DB_USER', ''), hc_env('DB_PASS', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
    );
    // Same clock as the rest of the app: the cron heartbeat is written with MySQL's time zone set to
    // Africa/Addis_Ababa, so reading it back in the host's own zone would show a wrong (even negative) age.
    try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Exception $e) { /* keep the server zone */ }
    $pdo->query('SELECT 1')->fetchColumn();
    $out['db'] = 'ok';
} catch (Exception $e) {
    error_log('[ETEL][health.php] DB check failed: ' . $e->getMessage());
    $out['db'] = 'down';
    $out['status'] = 'down';
}

// 2. Disk: a full disk breaks uploads, sessions and backups long before the site goes down.
$free = @disk_free_space(__DIR__);
$total = @disk_total_space(__DIR__);
if ($free !== false && $total) {
    $pct = round($free / $total * 100, 1);
    $out['disk_free_pct'] = $pct;
    if ($free < 100 * 1024 * 1024 || $pct < 3) {
        $out['disk'] = 'low';
        if ($out['status'] === 'ok') $out['status'] = 'degraded';
    } else {
        $out['disk'] = 'ok';
    }
}

// 3. Background jobs: the ride watcher cron leaves a heartbeat every few minutes.
if ($pdo) {
    try {
        $r = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, last_run, NOW()) AS mins FROM etel_heartbeat WHERE name='ride_monitor'")->fetch(PDO::FETCH_ASSOC);
        if ($r && $r['mins'] !== null) {
            $out['cron_age_min'] = max(0, intval($r['mins']));
            if (intval($r['mins']) > 30) {
                $out['cron'] = 'stale';
                if ($out['status'] === 'ok') $out['status'] = 'degraded';
            } else {
                $out['cron'] = 'ok';
            }
        } else {
            $out['cron'] = 'unknown'; // never ran: informational only, does not fail the check
        }
    } catch (Exception $e) {
        $out['cron'] = 'unknown';
    }
}

// 4. Backups: the daily backup cron writes a small record next to each dump. If the newest one is older than
// 36 hours the backup job has stopped, which nobody would otherwise notice until the day they need a restore.
// "No backup found" stays informational (like the job heartbeat above) so a fresh install is not red.
// Only an age in hours is shown, never a file name or folder.
$bkDirs = array();
if (hc_env('BACKUP_DIR', '') !== '') $bkDirs[] = hc_env('BACKUP_DIR', '');
$bkDirs[] = dirname(dirname(dirname(__DIR__))) . '/etel_backups';
$bkDirs[] = __DIR__ . '/_backups';
$bkNewest = 0;
foreach ($bkDirs as $d) {
    if (!is_dir($d)) continue;
    foreach ((array) glob($d . '/etel-*.json') as $f) {
        $m = @json_decode((string) @file_get_contents($f), true);
        if (!is_array($m) || empty($m['created']) || empty($m['file'])) continue;
        if (!is_file($d . '/' . basename($m['file']))) continue; // a record without its dump is not a backup
        $t = strtotime($m['created']);
        if ($t && $t > $bkNewest) $bkNewest = $t;
    }
}
if ($bkNewest > 0) {
    $ageH = max(0, (time() - $bkNewest) / 3600);
    $out['backup_age_h'] = round($ageH, 1);
    if ($ageH > 36) {
        $out['backup'] = 'stale';
        if ($out['status'] === 'ok') $out['status'] = 'degraded';
    } else {
        $out['backup'] = 'ok';
    }
} else {
    $out['backup'] = 'unknown';
}

$out['ms'] = intval((microtime(true) - $t0) * 1000);
hc_reply($out, $out['status'] === 'ok' ? 200 : 503);
