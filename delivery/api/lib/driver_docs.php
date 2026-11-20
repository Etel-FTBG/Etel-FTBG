<?php
// ============================================================
// Driver documents: who is expired or about to expire, reminders to the driver, and the owner's switch that stops an expired driver going online.
// The three documents already live on ride_drivers (insurance, technical inspection, road fund: *_expiry_date); drivers renew them in the app (Documents)
// and staff approve the renewal. This adds the missing watching:
//   dd_list            drivers with a document that is expired or expires within N days (Admin > Operations > Driver trust)
//   dd_send_reminders  push to the driver at 14, 7, 3, 1 days and on the day, and once when it has expired (cron_ride_monitor.php), each sent once
//   dd_blocked_reason  the expired documents of ONE driver, but only when the owner switched "block going online with an expired document" on (default off)
// A server whose ride_drivers table has no date columns yet gives "nothing to report", never an error.
// ============================================================
if (!function_exists('dd_types')) {

function dd_types() { return array('insurance' => 'insurance', 'technical_inspection' => 'technical inspection', 'road_fund' => 'road fund'); }
function dd_stages() { return array(14, 7, 3, 1, 0); }

function dd_columns_ready($pdo) {
    try {
        $have = array();
        foreach ($pdo->query('SHOW COLUMNS FROM ride_drivers')->fetchAll(PDO::FETCH_ASSOC) as $c) $have[strtolower($c['Field'])] = true;
        foreach (array_keys(dd_types()) as $t) if (empty($have[$t . '_expiry_date'])) return false;
        return true;
    } catch (Exception $e) { return false; }
}

/* Is the owner's switch on? Stored with the other ETEL app settings (app_hub_settings, key trust_block_expired_docs). */
function dd_block_enabled($pdo) {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM app_hub_settings WHERE setting_key = 'trust_block_expired_docs'");
        $s->execute();
        return (string) $s->fetchColumn() === '1';
    } catch (Exception $e) { return false; }
}

/* Drivers with a document that is expired or expires within $within days. Each: user_id, name, phone, online, docs: type => {label, expiry, days_left, state}. */
function dd_list($pdo, $within = 14) {
    if (!dd_columns_ready($pdo)) return array();
    $within = max(0, intval($within));
    $cols = ''; $cond = array();
    foreach (array_keys(dd_types()) as $t) {
        $cols .= ', rd.' . $t . '_expiry_date AS e_' . $t . ', DATEDIFF(rd.' . $t . '_expiry_date, CURDATE()) AS d_' . $t;
        $cond[] = '(rd.' . $t . '_expiry_date IS NOT NULL AND rd.' . $t . '_expiry_date <= DATE_ADD(CURDATE(), INTERVAL ' . $within . ' DAY))';
    }
    try {
        $rows = $pdo->query("SELECT rd.user_id, u.name, u.phone, rd.online" . $cols . "
                             FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
                             WHERE rd.onboarded = 1 AND (" . implode(' OR ', $cond) . ") ORDER BY u.name ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return array(); }
    $out = array();
    foreach ($rows as $r) {
        $docs = array();
        foreach (dd_types() as $t => $label) {
            if ($r['e_' . $t] === null) continue;
            $d = intval($r['d_' . $t]);
            if ($d > $within) continue;
            $docs[$t] = array('label' => $label, 'expiry' => (string) $r['e_' . $t], 'days_left' => $d, 'state' => $d < 0 ? 'expired' : ($d === 0 ? 'today' : 'soon'));
        }
        if ($docs) $out[] = array('user_id' => intval($r['user_id']), 'name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'online' => intval($r['online']) === 1 ? 1 : 0, 'docs' => $docs);
    }
    return $out;
}

/* The labels of the documents of this driver that have expired, when the switch is on; otherwise an empty list. */
function dd_blocked_reason($pdo, $uid) {
    if (!dd_block_enabled($pdo) || !dd_columns_ready($pdo)) return array();
    $cols = array(); foreach (array_keys(dd_types()) as $t) $cols[] = 'DATEDIFF(' . $t . '_expiry_date, CURDATE()) AS d_' . $t;   // the database's own clock, the same as dd_list
    try {
        $s = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM ride_drivers WHERE user_id = ?');
        $s->execute(array(intval($uid)));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return array();
        $bad = array();
        foreach (dd_types() as $t => $label) { if ($r['d_' . $t] !== null && intval($r['d_' . $t]) < 0) $bad[] = $label; }
        return $bad;
    } catch (Exception $e) { return array(); }
}

/* The reminder stage for a number of days left: the smallest of 14, 7, 3, 1, 0 that is not smaller than the days left; -1 once expired. */
function dd_stage_for($daysLeft) {
    if ($daysLeft < 0) return -1;
    $pick = null;
    foreach (dd_stages() as $s) { if ($daysLeft <= $s && ($pick === null || $s < $pick)) $pick = $s; }
    return $pick;
}

function dd_reminder_text($label, $stage, $blocking) {
    $name = ucfirst($label);
    if ($stage === -1) return array('Your ' . $label . ' has expired', 'Renew it now: open ETEL, Documents, and upload the new receipt.' . ($blocking ? ' You cannot go online until it is renewed and approved.' : ''));
    if ($stage === 0) return array('Your ' . $label . ' expires today', 'Renew it today: open ETEL, Documents, and upload the new receipt.' . ($blocking ? ' After today you cannot go online until it is renewed.' : ''));
    return array($name . ' expires in ' . $stage . ($stage === 1 ? ' day' : ' days'), 'Renew it in time: open ETEL, Documents, and upload the new receipt.');
}

/* Sends the reminders that are due (each driver, document, expiry date and stage once). Daytime only (7:00 to 19:59). Returns how many were sent. */
function dd_send_reminders($pdo, $hour = null) {
    $hour = $hour === null ? intval(date('G')) : intval($hour);
    if ($hour < 7 || $hour >= 20) return 0;
    $sent = 0;
    $blocking = dd_block_enabled($pdo);
    foreach (dd_list($pdo, 14) as $drv) {
        foreach ($drv['docs'] as $type => $d) {
            if ($sent >= 200) return $sent;
            $stage = dd_stage_for($d['days_left']);
            if ($stage === null) continue;
            try {
                $ins = $pdo->prepare('INSERT IGNORE INTO driver_doc_reminders (driver_user_id, doc_type, expiry_date, stage, sent_at) VALUES (?, ?, ?, ?, NOW())');
                $ins->execute(array($drv['user_id'], $type, $d['expiry'], $stage));
                if ($ins->rowCount() < 1) continue;            // already sent
            } catch (Exception $e) { error_log('[ETEL][docs] reminder log failed: ' . $e->getMessage()); return $sent; }
            list($title, $body) = dd_reminder_text($d['label'], $stage, $blocking);
            try {
                if (function_exists('etel_push_send_async')) etel_push_send_async($pdo, $drv['user_id'], $title, $body, array('type' => 'doc_expiry', 'doc' => $type));
                elseif (function_exists('etel_push_send')) etel_push_send($pdo, $drv['user_id'], $title, $body, array('type' => 'doc_expiry', 'doc' => $type));
            } catch (Exception $e) { error_log('[ETEL][docs] push failed: ' . $e->getMessage()); }
            catch (Throwable $e) { error_log('[ETEL][docs] push failed: ' . $e->getMessage()); }
            $sent++;
        }
    }
    return $sent;
}

}
