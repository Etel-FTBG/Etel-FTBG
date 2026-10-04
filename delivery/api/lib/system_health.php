<?php
/**
 * One-page system health for the admin: is everything that should be running
 * actually running? Read-only, except the cron heartbeat below.
 *
 * Checks (each: name, status ok|warn|bad|info, detail):
 *   Database       - answers, and how fast
 *   Cron job       - cron_ride_monitor.php left a heartbeat in the last 15 min
 *   Trip watch     - stuck trips / safety alerts / API problems from ride_monitor.php
 *   API speed      - average answer time and 5xx rate, last 10 min (api_perf.php)
 *   Backups        - newest backup is recent (daily cron), its size and age
 *   Disk space     - free space where the API lives
 *   Admin 2FA      - whether the signed-in admin has two-step sign-in on
 * Overall = the worst of the above. The thresholds are plain numbers here
 * (backup older than 36 h = warn, 72 h = bad; cron silent 15 min = bad).
 *
 * Heartbeat: cron_ride_monitor.php calls etel_health_heartbeat() after each run,
 * so "the cron job is not set up / stopped" is visible instead of silent.
 * Time: written and compared with SQL NOW() (see ride_sweeper.php).
 */

if (!function_exists('etel_system_health')) {

function etel_health_heartbeat($pdo, $name, $detail) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS etel_heartbeat (
            name     VARCHAR(40)  NOT NULL PRIMARY KEY,
            last_run DATETIME     NOT NULL,
            detail   VARCHAR(200) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO etel_heartbeat (name, last_run, detail) VALUES (?, NOW(), ?)
                       ON DUPLICATE KEY UPDATE last_run = NOW(), detail = VALUES(detail)")
            ->execute(array($name, substr((string) $detail, 0, 200)));
    } catch (Exception $e) { error_log('[ETEL][health] heartbeat failed: ' . $e->getMessage()); }
}

function etel_health_item($name, $status, $detail) {
    return array('name' => $name, 'status' => $status, 'detail' => $detail);
}

function etel_health_ago($min) {
    $min = intval($min);
    if ($min < 1) return 'just now';
    if ($min < 60) return $min . ' min ago';
    if ($min < 2880) return floor($min / 60) . ' h ago';
    return floor($min / 1440) . ' days ago';
}

/* Same places cron_backup_db.php writes to. */
function etel_health_backup_dirs() {
    $api = dirname(__DIR__); // .../delivery/api
    $dirs = array();
    $set = function_exists('etel_env') ? etel_env('BACKUP_DIR', 'CFG_BACKUP_DIR', '') : (getenv('BACKUP_DIR') ? getenv('BACKUP_DIR') : '');
    if ($set) $dirs[] = $set;
    $dirs[] = dirname(dirname(dirname($api))) . '/etel_backups';
    $dirs[] = $api . '/_backups';
    return $dirs;
}

function etel_health_backup() {
    $newest = null;
    foreach (etel_health_backup_dirs() as $d) {
        if (!is_dir($d)) continue;
        foreach ((array) glob($d . '/etel-*.json') as $f) {
            $m = @json_decode((string) @file_get_contents($f), true);
            if (!is_array($m) || empty($m['created'])) continue;
            $t = strtotime($m['created']);
            if ($t && (!$newest || $t > $newest['t'])) $newest = array('t' => $t, 'bytes' => isset($m['bytes']) ? intval($m['bytes']) : 0);
        }
    }
    if (!$newest) return etel_health_item('Backups', 'warn', 'No backup found yet. Add the daily backup cron (cron_backup_db.php) - see the setup notes.');
    $hours = (time() - $newest['t']) / 3600;
    $mb = round($newest['bytes'] / 1048576, 1);
    $txt = 'Newest backup ' . etel_health_ago(floor($hours * 60)) . ', ' . $mb . ' MB.';
    if ($hours > 72) return etel_health_item('Backups', 'bad', $txt . ' No backup for over 3 days - check the daily cron.');
    if ($hours > 36) return etel_health_item('Backups', 'warn', $txt . ' Older than a day and a half.');
    return etel_health_item('Backups', 'ok', $txt);
}

/* $adminId: the signed-in admin (for the 2FA nudge), or 0. */
function etel_system_health($pdo, $adminId = 0) {
    $checks = array();

    // Database
    $t0 = microtime(true);
    try {
        $pdo->query('SELECT 1')->fetchColumn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $checks[] = etel_health_item('Database', $ms > 300 ? 'warn' : 'ok', 'Answers in ' . $ms . ' ms.');
    } catch (Exception $e) {
        $checks[] = etel_health_item('Database', 'bad', 'Not answering.');
    }

    // Cron job heartbeat
    try {
        $r = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, last_run, NOW()) AS mins FROM etel_heartbeat WHERE name='ride_monitor'")->fetch();
        if (!$r) {
            $checks[] = etel_health_item('Cron job', 'warn', 'Has not run yet. Add the 5-minute cron for cron_ride_monitor.php in cPanel.');
        } else {
            $m = intval($r['mins']);
            $checks[] = $m > 15
                ? etel_health_item('Cron job', 'bad', 'Last ran ' . etel_health_ago($m) . '. The 5-minute cron has stopped - check cPanel > Cron Jobs.')
                : etel_health_item('Cron job', 'ok', 'Ran ' . etel_health_ago($m) . ' (every 5 min).');
        }
    } catch (Exception $e) {
        $checks[] = etel_health_item('Cron job', 'warn', 'Has not run yet. Add the 5-minute cron for cron_ride_monitor.php in cPanel.');
    }

    // Trip watch + API speed (the same scan the Live trip watch uses)
    $api = null; $critical = 0; $warn = 0; $apiFindings = 0;
    if (function_exists('rides_monitor_scan')) {
        try {
            $scan = rides_monitor_scan($pdo);
            $api = isset($scan['api']) ? $scan['api'] : null;
            foreach ($scan['findings'] as $f) {
                if ($f['rule'] === 'api:slow' || $f['rule'] === 'api:errors') { $apiFindings++; continue; }
                if ($f['severity'] === 'critical') $critical++; else $warn++;
            }
            if ($critical > 0) $checks[] = etel_health_item('Trip watch', 'bad', $critical . ' critical' . ($warn ? ' and ' . $warn . ' other' : '') . ' trip problem(s). See Live trip watch.');
            elseif ($warn > 0) $checks[] = etel_health_item('Trip watch', 'warn', $warn . ' trip(s) need attention. See Live trip watch.');
            else $checks[] = etel_health_item('Trip watch', 'ok', 'No stuck trips. ' . intval($scan['active']) . ' active trip(s).');
        } catch (Exception $e) {
            $checks[] = etel_health_item('Trip watch', 'warn', 'Could not run the check.');
        }
    }
    if ($api) {
        $txt = $api['requests'] . ' requests in ' . $api['window_min'] . ' min, average ' . $api['avg_ms'] . ' ms, ' . $api['error_pct'] . '% server errors.';
        $checks[] = etel_health_item('API speed', $apiFindings > 0 ? 'bad' : 'ok', $txt);
    } else {
        $checks[] = etel_health_item('API speed', 'info', 'No requests recorded in the last 10 minutes.');
    }

    // Backups
    $checks[] = etel_health_backup();

    // Disk
    if (function_exists('disk_free_space')) {
        $free = @disk_free_space(dirname(__DIR__));
        if ($free !== false) {
            $gb = round($free / 1073741824, 1);
            $checks[] = etel_health_item('Disk space', $gb < 0.5 ? 'bad' : ($gb < 2 ? 'warn' : 'ok'), $gb . ' GB free.');
        }
    }

    // Admin 2FA
    if ($adminId && function_exists('etel_2fa_is_enabled')) {
        try {
            $on = etel_2fa_is_enabled($pdo, $adminId);
            $checks[] = etel_health_item('Your two-step sign-in', $on ? 'ok' : 'warn', $on ? 'On.' : 'Off. Turn it on from the Two-step sign-in menu item.');
        } catch (Exception $e) {}
    }

    $rank = array('ok' => 0, 'info' => 0, 'warn' => 1, 'bad' => 2);
    $worst = 0; $bad = 0; $warns = 0;
    foreach ($checks as $c) {
        $r = isset($rank[$c['status']]) ? $rank[$c['status']] : 0;
        if ($r > $worst) $worst = $r;
        if ($c['status'] === 'bad') $bad++; elseif ($c['status'] === 'warn') $warns++;
    }
    return array('overall' => $worst === 2 ? 'bad' : ($worst === 1 ? 'warn' : 'ok'), 'problems' => $bad, 'warnings' => $warns, 'checks' => $checks);
}

} // end function_exists guard
