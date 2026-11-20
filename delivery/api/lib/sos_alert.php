<?php
// ============================================================
// SOS (panic button) alerts to staff.
// Before this, sos_trigger only saved a row: staff saw it only when an admin page happened to be open (js/admin-sos.js polls). Now:
//   1. the moment the button is pressed, every staff member who may view SOS gets a push on their phone or browser (sos_notify);
//   2. while the alert is still open, staff are told again (sos_escalate, run by cron_ride_monitor.php, every 5 minutes, at most 5 times);
//   3. Live operations shows a critical alert for every open SOS.
// Staff = admin accounts with the sos.view capability (a legacy admin has every capability). Table sos_alerts is the one sos_trigger creates;
// the two counters below are added to it when first needed (no manual database step).
// ============================================================
if (!function_exists('sos_notify')) {

function sos_max_notices() { return 5; }

function sos_ensure_columns($pdo) {
    static $done = false;
    if ($done) return;
    if (!function_exists('rides_add_missing_cols')) require_once __DIR__ . '/schema_helpers.php';
    rides_add_missing_cols($pdo, 'sos_alerts', array(
        'notified_at'  => 'notified_at DATETIME DEFAULT NULL',
        'notify_count' => 'notify_count TINYINT NOT NULL DEFAULT 0',
        'staff_count'  => 'staff_count SMALLINT NOT NULL DEFAULT 0',
    ));
    $done = true;
}

/* Ids of the staff who should hear about an SOS. */
function sos_staff_ids($pdo) {
    $rows = array();
    try { $rows = $pdo->query("SELECT id, role, staff_role, is_etelgroup_managed FROM users WHERE role = 'admin' AND (active IS NULL OR active = 1)")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) {
        try { $rows = $pdo->query("SELECT id, role FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e2) { return array(); }
    }
    $ids = array();
    foreach ($rows as $r) {
        $may = true;
        if (function_exists('user_has_capability')) { try { $may = user_has_capability($pdo, $r, 'sos.view'); } catch (Exception $e) { $may = true; } }
        if ($may) $ids[] = intval($r['id']);
    }
    return $ids;
}

function sos_clip($v, $n) {
    $v = trim(preg_replace('/\s+/', ' ', strip_tags((string) $v)));
    return function_exists('mb_substr') ? mb_substr($v, 0, $n, 'UTF-8') : substr($v, 0, $n);
}

/* Title and text of the push for one alert row (joined with the reporter). */
function sos_text($a, $again) {
    $who = sos_clip(isset($a['name']) && $a['name'] !== '' ? $a['name'] : 'Someone', 40);
    $role = ((string) $a['role'] === 'driver') ? 'driver' : 'rider';
    $title = ($again ? 'SOS STILL OPEN: ' : 'SOS: ') . $who . ' (' . $role . ')';
    $bits = array();
    if (!empty($a['phone'])) $bits[] = 'phone ' . sos_clip($a['phone'], 25);
    if (!empty($a['ride_id'])) $bits[] = 'trip #' . intval($a['ride_id']);
    if (isset($a['lat']) && $a['lat'] !== null && $a['lng'] !== null) $bits[] = 'location ' . round(floatval($a['lat']), 5) . ',' . round(floatval($a['lng']), 5);
    if (!empty($a['message'])) $bits[] = sos_clip($a['message'], 80);
    if ($again && isset($a['mins'])) $bits[] = 'open for ' . intval($a['mins']) . ' min';
    return array($title, implode(' - ', $bits));
}

function sos_alert_row($pdo, $id) {
    $s = $pdo->prepare('SELECT sa.id, sa.ride_id, sa.role, sa.lat, sa.lng, sa.message, sa.status, TIMESTAMPDIFF(MINUTE, sa.created_at, NOW()) AS mins, u.name, u.phone
                        FROM sos_alerts sa LEFT JOIN users u ON u.id = sa.reporter_id WHERE sa.id = ?');
    $s->execute(array(intval($id)));
    return $s->fetch(PDO::FETCH_ASSOC);
}

/* Pushes one alert to the staff and writes down when and to how many. Returns the number of staff asked. Never throws: a failed push must not break the panic button. */
function sos_notify($pdo, $id, $again = false) {
    try {
        sos_ensure_columns($pdo);
        $a = sos_alert_row($pdo, $id);
        if (!$a) return 0;
        $ids = sos_staff_ids($pdo);
        list($title, $text) = sos_text($a, $again);
        $data = array('type' => 'sos', 'sos_id' => intval($id), 'url' => '/delivery/admin.html');
        foreach ($ids as $uid) {
            try {
                if (function_exists('etel_push_send_async')) etel_push_send_async($pdo, $uid, $title, $text, $data);
                elseif (function_exists('etel_push_send')) etel_push_send($pdo, $uid, $title, $text, $data);
            } catch (Exception $e) { error_log('[ETEL][sos] push failed: ' . $e->getMessage()); }
            catch (Throwable $e) { error_log('[ETEL][sos] push failed: ' . $e->getMessage()); }
        }
        $pdo->prepare('UPDATE sos_alerts SET notified_at = NOW(), notify_count = notify_count + 1, staff_count = ? WHERE id = ?')->execute(array(count($ids), intval($id)));
        return count($ids);
    } catch (Exception $e) { error_log('[ETEL][sos] notify failed: ' . $e->getMessage()); return 0; }
}

/* Cron step: an alert open for 2 minutes or more, last announced 4 minutes ago or more, and announced fewer than 5 times, is announced again. Returns how many alerts. */
function sos_escalate($pdo) {
    $n = 0;
    try {
        sos_ensure_columns($pdo);
        $s = $pdo->prepare("SELECT id FROM sos_alerts WHERE status = 'open' AND created_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND notify_count < ?
                            AND (notified_at IS NULL OR notified_at <= DATE_SUB(NOW(), INTERVAL 4 MINUTE)) ORDER BY id ASC LIMIT 20");
        $s->execute(array(sos_max_notices()));
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) { sos_notify($pdo, $id, true); $n++; }
    } catch (Exception $e) { error_log('[ETEL][sos] escalate failed: ' . $e->getMessage()); }
    return $n;
}

}
