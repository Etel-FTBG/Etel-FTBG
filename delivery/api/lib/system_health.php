<?php
/**
 * One-page system health for the admin: is everything that should be running
 * actually running? Read-only, except the cron heartbeat below.
 *
 * Checks (each: name, status ok|warn|bad|info, detail):
 *   Database       - answers, and how fast
 *   Cron job       - cron_ride_monitor.php left a heartbeat in the last 15 min
 *   Scheduled-ride cron - cron_dispatch_scheduled.php ran recently (only matters when rides are pre-booked)
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

function etel_health_item($name, $status, $detail, $extra = array()) {
    return array_merge(array('name' => $name, 'status' => $status, 'detail' => $detail), $extra);
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

/* The newest complete backup: array(path, name, created (unix), bytes, sha256) or null.
   "Complete" = both the .sql.gz and its .json record exist. */
function etel_backup_latest() {
    $best = null;
    foreach (etel_health_backup_dirs() as $d) {
        if (!is_dir($d)) continue;
        foreach ((array) glob($d . '/etel-*.json') as $f) {
            $m = @json_decode((string) @file_get_contents($f), true);
            if (!is_array($m) || empty($m['created']) || empty($m['file'])) continue;
            $path = $d . '/' . basename($m['file']); // basename: never trust a path from a file
            if (!is_file($path)) continue;
            $t = strtotime($m['created']);
            if ($t && (!$best || $t > $best['created'])) {
                $best = array('path' => $path, 'name' => basename($path), 'created' => $t,
                              'bytes' => filesize($path), 'sha256' => isset($m['sha256']) ? $m['sha256'] : '');
            }
        }
    }
    return $best;
}

/* Sends the backup as a download and stops. The caller has already checked who is asking.
   Refuses a file whose checksum no longer matches its record. */
function etel_backup_send($b) {
    if ($b['sha256'] !== '' && !hash_equals($b['sha256'], (string) hash_file('sha256', $b['path']))) return false;
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $b['name'] . '"');
    header('Content-Length: ' . filesize($b['path']));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($b['path']);
    exit;
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

/* Is there a copy of the backups somewhere other than this server? Either the automatic off-site push worked
   recently, or an owner downloaded the newest backup in the last 7 days. */
function etel_health_backup_copy($pdo) {
    $row = function ($name) use ($pdo) {
        try {
            $s = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, last_run, NOW()) AS mins, detail FROM etel_heartbeat WHERE name=?");
            $s->execute(array($name));
            return $s->fetch();
        } catch (Exception $e) { return false; }
    };
    $off = $row('backup_offsite'); $dl = $row('backup_download');
    if ($off && strpos((string) $off['detail'], 'ok') === 0 && intval($off['mins']) <= 2160) {
        return etel_health_item('Backup copy elsewhere', 'ok', 'Sent off the server ' . etel_health_ago($off['mins']) . '.');
    }
    $why = ($off && strpos((string) $off['detail'], 'failed') === 0) ? ' The automatic off-site copy is failing (' . substr($off['detail'], 8) . ').' : '';
    if ($dl && intval($dl['mins']) <= 10080) {
        return etel_health_item('Backup copy elsewhere', 'ok', 'A copy was downloaded ' . etel_health_ago($dl['mins']) . '.' . $why);
    }
    if ($dl) {
        return etel_health_item('Backup copy elsewhere', 'warn', 'No one has downloaded a backup for ' . floor(intval($dl['mins']) / 1440) . ' days. Download one and keep it off the server.' . $why);
    }
    return etel_health_item('Backup copy elsewhere', 'warn', 'The backups only exist on this server. Download a copy with the button above and keep it somewhere else, or set up the automatic off-site copy.' . $why);
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

    // Delivery dispatch: jobs nobody has taken, and whether job offers are switched on.
    try {
        $open = intval($pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending' AND courier_id IS NULL
            AND placed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND placed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            AND NOT (payment_method='chapa' AND payment_status <> 'paid')")->fetchColumn());
        $online = intval($pdo->query("SELECT COUNT(*) FROM couriers WHERE status='online' AND active=1")->fetchColumn());
        if ($open > 0) {
            $checks[] = etel_health_item('Courier dispatch', $open >= 3 ? 'bad' : 'warn', $open . ' order(s) have waited over 10 minutes with no courier. ' . $online . ' courier(s) online. Call couriers or assign by hand in Admin > Orders.');
        } else {
            $checks[] = etel_health_item('Courier dispatch', 'ok', 'No order is waiting for a courier. ' . $online . ' courier(s) online.');
        }
    } catch (Exception $e) { /* delivery tables not present */ }

    // Orders that need a person: stuck with a courier who never started, money not recorded, paid-then-cancelled.
    try {
        $stuck = intval($pdo->query("SELECT COUNT(*) FROM orders WHERE status='assigned' AND courier_accepted_at IS NULL
            AND placed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND placed_at > DATE_SUB(NOW(), INTERVAL 2 DAY)")->fetchColumn());
        $checks[] = $stuck > 0
            ? etel_health_item('Orders waiting on a courier', 'bad', $stuck . ' order(s) were handed to a courier over 30 minutes ago and nothing has happened. Call the courier or move the order to another one in Admin > Orders.')
            : etel_health_item('Orders waiting on a courier', 'ok', 'No assigned order is stuck.');
        $unpaid = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(fare_total),0) AS amt FROM orders WHERE status='delivered' AND payment_status <> 'paid' AND payment_method <> 'chapa'")->fetch(PDO::FETCH_ASSOC);
        if (intval($unpaid['n']) > 0) $checks[] = etel_health_item('Delivered orders not marked paid', 'warn', intval($unpaid['n']) . ' delivered order(s), ETB ' . number_format(floatval($unpaid['amt']), 2) . ', have no payment record. Open Admin > Payments > Delivered orders not marked paid.');
        $pc = intval($pdo->query("SELECT COUNT(*) FROM orders o JOIN chapa_payments cp ON cp.kind='order' AND cp.target_id=o.id AND cp.status='completed' WHERE o.status='cancelled'")->fetchColumn());
        if ($pc > 0) $checks[] = etel_health_item('Paid but cancelled', 'bad', $pc . ' order(s) were paid online and then cancelled. The customer needs a refund (see Admin > Payments > Online payments).');
    } catch (Exception $e) { /* tables not present yet */ }

    // Online payments (Chapa): is it set up, and is any paid-looking payment stuck unsettled?
    try {
        $_chk = ''; $envf = __DIR__ . '/../.env';
        if (is_readable($envf)) { foreach ((array) @file($envf, FILE_IGNORE_NEW_LINES) as $_ln) { if (strpos(ltrim($_ln), 'CHAPA_SECRET_KEY=') === 0) { $_chk = trim(substr(ltrim($_ln), 17), " \t\"'"); } } }
        if ($_chk === '' || stripos($_chk, 'change_me') === 0 || strpos($_chk, 'CHASECK_TEST-xxxx') === 0) {
            $checks[] = etel_health_item('Online payments (Chapa)', 'info', 'Not set up. Telebirr and bank payments are confirmed by hand until CHAPA_SECRET_KEY is added to api/.env.');
        } else {
            $stuck = 0; $hbm = null;
            try { $stuck = intval($pdo->query("SELECT COUNT(*) FROM chapa_payments cp WHERE cp.status='pending' AND cp.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND cp.created_at > DATE_SUB(NOW(), INTERVAL 3 DAY) AND NOT EXISTS (SELECT 1 FROM chapa_payments d WHERE d.kind = cp.kind AND d.target_id = cp.target_id AND d.status = 'completed')")->fetchColumn()); } catch (Exception $e) { /* table not created yet */ }
            try { $hb2 = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, last_run, NOW()) AS mins FROM etel_heartbeat WHERE name='chapa_reconcile'")->fetch(); $hbm = $hb2 ? intval($hb2['mins']) : null; } catch (Exception $e) {}
            if ($hbm === null) {
                $checks[] = etel_health_item('Online payments (Chapa)', 'warn', 'Set up. Add the 5-minute cron for cron_chapa_reconcile.php so a payment whose webhook is lost still settles.' . ($stuck > 0 ? ' ' . $stuck . ' payment(s) are waiting over 30 minutes.' : ''));
            } elseif ($hbm > 15) {
                $checks[] = etel_health_item('Online payments (Chapa)', 'bad', 'The reconcile cron last ran ' . etel_health_ago($hbm) . '. Check cPanel > Cron Jobs.' . ($stuck > 0 ? ' ' . $stuck . ' payment(s) are waiting over 30 minutes.' : ''));
            } else {
                $checks[] = etel_health_item('Online payments (Chapa)', $stuck > 0 ? 'warn' : 'ok', $stuck > 0 ? $stuck . ' payment(s) still unconfirmed after 30 minutes (customer may not have paid). Reconcile ran ' . etel_health_ago($hbm) . '.' : 'Set up. Reconcile ran ' . etel_health_ago($hbm) . '; nothing stuck.');
            }
        }
    } catch (Exception $e) { /* skip */ }

    // Scheduled-ride cron (cron_dispatch_scheduled.php): it starts the driver search for pre-booked rides
    // and sends the reminder push, so it only matters once someone has pre-booked a ride.
    try {
        $due = intval($pdo->query("SELECT COUNT(*) FROM rides WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn());
        $wait = intval($pdo->query("SELECT COUNT(*) FROM rides WHERE status='scheduled' AND scheduled_at IS NOT NULL")->fetchColumn());
        $hb = null;
        try { $hb = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, last_run, NOW()) AS mins FROM etel_heartbeat WHERE name='dispatch_scheduled'")->fetch(); } catch (Exception $e) {}
        $mins = $hb ? intval($hb['mins']) : null;
        $setup = 'Add the 1-2 minute cron for cron_dispatch_scheduled.php in cPanel.';
        if ($due > 0 && ($mins === null || $mins > 10)) {
            $checks[] = etel_health_item('Scheduled-ride cron', 'bad', $due . ' pre-booked ride(s) are past their start time and nobody has started them. ' . ($mins === null ? 'The cron has never run. ' : 'Last ran ' . etel_health_ago($mins) . '. ') . $setup);
        } elseif ($mins === null) {
            $checks[] = etel_health_item('Scheduled-ride cron', $wait > 0 ? 'warn' : 'info', $wait > 0 ? $wait . ' pre-booked ride(s) are waiting but the cron has never run. ' . $setup : 'Has not run. Not needed until riders pre-book a ride. ' . $setup);
        } elseif ($mins > 10) {
            $checks[] = etel_health_item('Scheduled-ride cron', $wait > 0 ? 'warn' : 'info', 'Last ran ' . etel_health_ago($mins) . ($wait > 0 ? ' with ' . $wait . ' pre-booked ride(s) waiting.' : '. Nothing is waiting right now.'));
        } else {
            $checks[] = etel_health_item('Scheduled-ride cron', 'ok', 'Ran ' . etel_health_ago($mins) . '. ' . ($wait > 0 ? $wait . ' pre-booked ride(s) waiting.' : 'No pre-booked rides waiting.'));
        }
    } catch (Exception $e) { /* rides table not ready: skip */ }

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
    $bk = etel_health_backup();
    if (etel_backup_latest()) $bk['download'] = true; // the admin card shows a Download button
    $checks[] = $bk;
    $checks[] = etel_health_backup_copy($pdo);

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

    // Is two-step sign-in required for every admin?
    try {
        $rq = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='require_admin_2fa' LIMIT 1")->fetchColumn();
        $req = intval($rq) === 1;
        $checks[] = etel_health_item('Admin 2FA rule', $req ? 'ok' : 'info', $req ? 'Required: admins without two-step sign-in cannot open admin pages.' : 'Optional. Turn it on once every admin has saved their backup codes.', array('require_toggle' => $req ? 'on' : 'off'));
    } catch (Exception $e) {}

    // Trip start PIN
    try {
        $pq = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_pin_required' LIMIT 1")->fetchColumn();
        $pon = intval($pq) === 1;
        $checks[] = etel_health_item('Trip start PIN', $pon ? 'ok' : 'info', $pon ? 'Required: drivers type the rider\'s 4-digit PIN to start a trip (street hails exempt).' : 'Off. When on, riders see a 4-digit PIN and the driver must type it to start the trip.', array('pin_toggle' => $pon ? 'on' : 'off'));
    } catch (Exception $e) {}

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
