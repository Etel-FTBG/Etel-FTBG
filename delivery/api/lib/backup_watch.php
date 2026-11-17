<?php
/* ETEL Delivery - backup alert (launch-readiness phase 3).
 * Called by cron_ride_monitor.php every few minutes. When the newest COMPLETE backup is older than ETEL_BACKUP_ALERT_HOURS (26)
 * or there is none, every admin gets one push ("Backup problem"), then at most one more every 12 hours until it is fixed.
 * The same rule turns the Backups row on Admin > Rides > System health red (lib/system_health.php).
 * Throttle state = etel_heartbeat row 'backup_alert' (detail 'sent: ...' or 'clear'). Never throws. PHP 5 style, ASCII only.
 */
if (!function_exists('etel_backup_watch')) {

/* Pure: is the backup stale? $createdUnix = newest backup time or null. */
function etel_backup_is_stale($createdUnix, $now, $limitHours) {
    if (!$createdUnix) return true;
    return ($now - $createdUnix) / 3600.0 > $limitHours;
}

/* Returns array('stale' => bool, 'hours' => float|null, 'sent' => int admins pushed). */
function etel_backup_watch($pdo, $push = true) {
    $res = array('stale' => false, 'hours' => null, 'sent' => 0);
    try {
        require_once __DIR__ . '/system_health.php';
        $b = etel_backup_latest();
        $created = $b ? $b['created'] : null;
        $res['hours'] = $created ? round((time() - $created) / 3600, 1) : null;
        $res['stale'] = etel_backup_is_stale($created, time(), ETEL_BACKUP_ALERT_HOURS);
        if (!$res['stale']) {
            $s = $pdo->prepare("SELECT detail FROM etel_heartbeat WHERE name='backup_alert'");
            $s->execute();
            $d = $s->fetchColumn();
            if ($d !== false && $d !== 'clear') etel_health_heartbeat($pdo, 'backup_alert', 'clear');
            return $res;
        }
        if (!$push || !function_exists('etel_push_send')) return $res;
        $q = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, last_run, NOW()) AS mins, detail FROM etel_heartbeat WHERE name='backup_alert'");
        $q->execute();
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && strpos((string) $row['detail'], 'sent') === 0 && intval($row['mins']) < 720) return $res;   // already told them in the last 12 hours
        $body = $created
            ? 'The newest database backup is ' . $res['hours'] . ' hours old (limit ' . ETEL_BACKUP_ALERT_HOURS . '). Check the daily backup cron job in cPanel.'
            : 'No database backup was found. Add the daily backup cron job in cPanel.';
        $ids = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $uid) {
            try { etel_push_send($pdo, intval($uid), 'Backup problem', $body, array('action' => 'system_health', 'url' => '/delivery/admin.html')); $res['sent']++; }
            catch (Exception $e) { /* best effort */ }
        }
        etel_health_heartbeat($pdo, 'backup_alert', 'sent: ' . $res['sent'] . ' admins, backup ' . ($res['hours'] === null ? 'missing' : $res['hours'] . ' h old'));
    } catch (Exception $e) {
        error_log('[ETEL][backup_watch] ' . $e->getMessage());
    }
    return $res;
}

} // end function_exists guard
