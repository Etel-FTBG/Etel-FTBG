<?php
// ============================================================
// ETEL TAXI - Audit log viewer and manual payment reminders  v1.0
//   admin_list_audit            who did what (owner / unrestricted admins only)
//   admin_send_reminder         push a payment reminder to ONE overdue driver
//   admin_send_reminders_unpaid  push a reminder to EVERY driver in the admin's scope who has not paid the current due
// A driver is reminded at most once every 12 hours by hand (the daily job's own reminders are separate).
// 'Unpaid' = status is not 'active' (paid). Note: today the system only reports 'overdue' after the NEXT window opens, so a plain unpaid check is the useful one.
// ============================================================
require_once __DIR__ . '/../lib/taxi_payments.php';
require_once __DIR__ . '/../lib/taxi_push.php';
require_once __DIR__ . '/../lib/taxi_audit.php';

if (!function_exists('taxi_remind_driver')) {
    /* Returns 'sent' | 'no_push' | 'failed' | 'too_soon' | 'not_overdue' (= already paid). Logs an audit row for every outcome except too_soon/not_overdue. */
    function taxi_remind_driver($pdo, $admin, $driver, $force = false) {
        $st = taxi_compute_driver_status($pdo, $driver['id'], $driver['association_id']);
        if ($st['status'] === 'active') return 'not_overdue';
        taxi_audit_ensure($pdo);
        $recent = $pdo->prepare('SELECT id FROM ' . tbl('taxi_admin_audit') . ' WHERE action = "reminder_sent" AND target_type = "driver" AND target_id = ? AND detail LIKE "delivered%" AND created_at > (NOW() - INTERVAL 12 HOUR) LIMIT 1');
        $recent->execute(array($driver['id']));
        if ($recent->fetch()) return 'too_soon';
        if (empty($driver['push_subscription'])) {
            taxi_audit($pdo, $admin, 'reminder_sent', 'driver', $driver['id'], 'no push registered - not delivered');
            return 'no_push';
        }
        $period = $st['current_period'];
        if ($st['status'] === 'overdue') {
            $title = 'Payment overdue';
            $text  = 'Your Etel Taxi due for ' . $period . ' is overdue. A penalty of ETB ' . number_format($st['penalty_owed'], 0) . ' applies. Please pay as soon as you can.';
        } else {
            $title = 'Payment reminder';
            $text  = 'Your Etel Taxi due for ' . $period . ' is not paid yet. Please pay before the penalty window ends.';
        }
        $ok = taxi_send_push($driver['push_subscription'], $title, $text, array('type' => 'manual_reminder', 'period' => $period), $pdo, $driver['id']);
        taxi_audit($pdo, $admin, 'reminder_sent', 'driver', $driver['id'], $ok ? 'delivered (' . $st['status'] . ')' : 'push failed - not delivered');
        return $ok ? 'sent' : 'failed';
    }

    function taxi_reminder_drivers($pdo, $admin, $onlyId = 0) {
        $sql = 'SELECT d.id, d.full_name, d.association_id, d.push_subscription FROM ' . tbl('taxi_drivers') . ' d WHERE d.status = "active"';
        $params = array();
        if ($admin['role'] !== 'super_admin') { $sql .= ' AND d.association_id = ?'; $params[] = intval($admin['association_id']); }
        if ($onlyId) { $sql .= ' AND d.id = ?'; $params[] = $onlyId; }
        $sql .= ' ORDER BY d.id LIMIT 500';
        $s = $pdo->prepare($sql); $s->execute($params);
        return $s->fetchAll();
    }
}

switch ($action) {

case 'admin_list_audit': {
    $pdo = db();
    require_admin_auth($pdo, 'owner.only');
    taxi_audit_ensure($pdo);
    $limit = isset($body['limit']) ? intval($body['limit']) : 200;
    if ($limit < 1) $limit = 1;
    if ($limit > 500) $limit = 500;
    $sql = 'SELECT id, admin_id, admin_label, action, target_type, target_id, detail, ip, created_at FROM ' . tbl('taxi_admin_audit');
    $params = array();
    if (!empty($body['action_filter'])) { $sql .= ' WHERE action = ?'; $params[] = (string)$body['action_filter']; }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $s = $pdo->prepare($sql); $s->execute($params);
    ok(array('entries' => $s->fetchAll()));
    break;
}

case 'admin_send_reminder': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'payments.manage');
    $id = isset($body['driver_id']) ? intval($body['driver_id']) : 0;
    if (!$id) fail('driver_id is required.');
    $list = taxi_reminder_drivers($pdo, $admin, $id);
    if (!$list) fail('Driver not found, or not in your association.', 404);
    $r = taxi_remind_driver($pdo, $admin, $list[0]);
    $msg = array(
        'sent'        => 'Reminder sent.',
        'no_push'     => 'This driver has not turned on payment reminders in the app, so nothing could be sent. Contact them by phone.',
        'failed'      => 'The reminder could not be delivered (their notification may have expired).',
        'too_soon'    => 'This driver was already reminded in the last 12 hours.',
        'not_overdue' => 'This driver has already paid the current due.',
    );
    ok(array('result' => $r, 'message' => $msg[$r]));
    break;
}

case 'admin_send_reminders_unpaid': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'payments.manage');
    $counts = array('sent' => 0, 'no_push' => 0, 'failed' => 0, 'too_soon' => 0);
    foreach (taxi_reminder_drivers($pdo, $admin, 0) as $d) {
        $r = taxi_remind_driver($pdo, $admin, $d);                   // skips drivers who are already paid
        if ($r !== 'not_overdue') $counts[$r]++;
    }
    taxi_audit($pdo, $admin, 'reminders_bulk', null, null, 'sent ' . $counts['sent'] . ', no push ' . $counts['no_push'] . ', failed ' . $counts['failed'] . ', already reminded ' . $counts['too_soon']);
    ok(array('counts' => $counts));
    break;
}

}
