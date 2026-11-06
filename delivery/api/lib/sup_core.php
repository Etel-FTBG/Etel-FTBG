<?php
// ============================================================
// ETEL Support desk - core helpers (settings, ticket rules, create / reply / status). ONE inbox for every Etel app.
// Users reach it from the Help page (delivery/help/). Staff work it in Admin > Support desk (capabilities support.view / support.manage).
// Nothing here talks to a person outside the app: Telegram is in lib/sup_notify.php and is OFF until the owner turns it on. ASCII only.
// ============================================================
if (!defined('APP_VER') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }

if (!function_exists('sup_defaults')) {

/* key => array(default, min, max). Same defaults are seeded by migration 103; this is the in-code fallback and the validator. */
function sup_defaults() {
    return array(
        'desk_enabled'      => array(1, 0, 1),
        'sla_first_hours'   => array(8, 1, 168),
        'sla_urgent_hours'  => array(2, 1, 48),
        'max_open_per_user' => array(5, 1, 50),
        'max_new_per_hour'  => array(5, 1, 60),
        'auto_close_days'   => array(7, 1, 60),
        'csat_enabled'      => array(1, 0, 1),
        'tg_enabled'        => array(0, 0, 1),
        'tg_notify_user'    => array(0, 0, 1),
    );
}
function sup_apps() {
    return array('ride' => 'Ride', 'delivery' => 'Delivery', 'driver' => 'Driver', 'courier' => 'Courier', 'service' => 'Service Giver', 'agent' => 'Agents', 'pay' => 'EtelPay',
                 'gebeya' => 'EtelGebeya', 'taxi' => 'EtelTaxi', 'madeya' => 'EtelMadeya', 'group' => 'Etel Group', 'id' => 'EtelID', 'ai' => 'EtelAi', 'other' => 'Other');
}
function sup_categories() { return array('payment', 'account', 'trip', 'order', 'safety', 'app', 'other'); }
function sup_priority_for($cat) { return $cat === 'safety' ? 'urgent' : ($cat === 'payment' ? 'high' : 'normal'); }

function sup_settings($pdo, $refresh = false) {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    $out = array();
    foreach (sup_defaults() as $k => $d) $out[$k] = $d[0];
    $out['tg_staff_chat_id'] = '';
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM sup_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['setting_key'];
            if ($k === 'tg_staff_chat_id') $out[$k] = preg_match('/^-?[0-9]{5,20}$/', $r['setting_value']) ? $r['setting_value'] : '';
            elseif (isset($out[$k]) && is_numeric($r['setting_value'])) $out[$k] = $r['setting_value'] + 0;
        }
    } catch (Exception $e) { /* not migrated yet: safe defaults */ }
    $cache = $out;
    return $out;
}

/* Validates and saves a partial map. Unknown keys are ignored; out-of-range values are rejected, never clamped. */
function sup_settings_save($pdo, $input, $adminId) {
    $defs = sup_defaults(); $cur = sup_settings($pdo, true); $changes = array();
    foreach ($input as $k => $v) {
        if ($k === 'tg_staff_chat_id') {
            $v = trim((string) $v);
            if ($v !== '' && !preg_match('/^-?[0-9]{5,20}$/', $v)) throw new Exception('The staff Telegram chat id must be a number (it may start with a minus sign)');
            if ($cur[$k] !== $v) $changes[$k] = $v;
            continue;
        }
        if (!isset($defs[$k])) continue;
        if (!is_numeric($v)) throw new Exception('Setting ' . $k . ' must be a number');
        $v = $v + 0;
        if ($v < $defs[$k][1] || $v > $defs[$k][2]) throw new Exception('Setting ' . $k . ' must be between ' . $defs[$k][1] . ' and ' . $defs[$k][2]);
        if ($cur[$k] != $v) $changes[$k] = $v;
    }
    $m = array_merge($cur, $changes);
    if ($m['sla_urgent_hours'] > $m['sla_first_hours']) throw new Exception('The urgent reply time cannot be longer than the normal reply time');
    if ($m['tg_enabled'] && $m['tg_staff_chat_id'] === '' && empty($m['tg_notify_user'])) throw new Exception('Telegram needs a staff chat id, or the option to message customers');
    $up = $pdo->prepare('INSERT INTO sup_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()');
    foreach ($changes as $k => $v) $up->execute(array($k, (string) $v, intval($adminId)));
    return sup_settings($pdo, true);
}

function sup_ref($id) { return 'ET-' . str_pad((string) intval($id), 6, '0', STR_PAD_LEFT); }
function sup_due($S, $priority, $from = null) {
    $h = ($priority === 'urgent') ? $S['sla_urgent_hours'] : (($priority === 'high') ? max($S['sla_urgent_hours'], intval($S['sla_first_hours'] / 2)) : $S['sla_first_hours']);
    return date('Y-m-d H:i:s', ($from === null ? time() : $from) + 3600 * $h);
}
function sup_clean($s, $max) {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s);
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}
function sup_len($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }

function sup_add_message($pdo, $ticketId, $sender, $staffId, $body) {
    $pdo->prepare('INSERT INTO sup_messages (ticket_id, sender, staff_id, body, created_at) VALUES (?,?,?,?,NOW())')->execute(array(intval($ticketId), $sender, $staffId ? intval($staffId) : null, $body));
    return intval($pdo->lastInsertId());
}
function sup_ticket($pdo, $id) {
    $q = $pdo->prepare('SELECT * FROM sup_tickets WHERE id = ?'); $q->execute(array(intval($id)));
    return $q->fetch(PDO::FETCH_ASSOC);
}

/* Creates a ticket for a signed-in user. Throws Exception with a plain message the person can read. */
function sup_create($pdo, $user, $app, $cat, $subject, $body, $related, $lang) {
    $S = sup_settings($pdo);
    if (empty($S['desk_enabled'])) throw new Exception('Support requests are paused right now. Please try again later.');
    $apps = sup_apps();
    if (!isset($apps[$app])) $app = 'other';
    if (!in_array($cat, sup_categories(), true)) $cat = 'other';
    $subject = sup_clean($subject, 160); $body = sup_clean($body, 4000); $related = sup_clean($related, 60);
    if (sup_len($subject) < 3) throw new Exception('Please write a short title (at least 3 letters).');
    if (sup_len($body) < 5) throw new Exception('Please tell us what happened (at least 5 letters).');
    $uid = intval($user['id']);
    $q = $pdo->prepare("SELECT COUNT(*) FROM sup_tickets WHERE user_id = ? AND status IN ('open','pending')"); $q->execute(array($uid));
    if (intval($q->fetchColumn()) >= $S['max_open_per_user']) throw new Exception('You already have ' . intval($S['max_open_per_user']) . ' open requests. Please wait for a reply or close one first.');
    $q = $pdo->prepare('SELECT COUNT(*) FROM sup_tickets WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'); $q->execute(array($uid));
    if (intval($q->fetchColumn()) >= $S['max_new_per_hour']) throw new Exception('Too many requests in a short time. Please try again in a little while.');
    $q = $pdo->prepare("SELECT id FROM sup_tickets WHERE user_id = ? AND subject = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1"); $q->execute(array($uid, $subject));
    $dup = $q->fetchColumn();
    if ($dup) return sup_ticket($pdo, $dup);
    $prio = sup_priority_for($cat); $lang = ($lang === 'en') ? 'en' : 'am';
    $pdo->prepare("INSERT INTO sup_tickets (ref, user_id, app, category, subject, related, status, priority, last_actor, user_unread, staff_unread, lang, due_at, created_at, updated_at)
                   VALUES ('', ?, ?, ?, ?, ?, 'open', ?, 'user', 0, 1, ?, ?, NOW(), NOW())")->execute(array($uid, $app, $cat, $subject, $related, $prio, $lang, sup_due($S, $prio)));
    $id = intval($pdo->lastInsertId());
    $pdo->prepare('UPDATE sup_tickets SET ref = ? WHERE id = ?')->execute(array(sup_ref($id), $id));
    sup_add_message($pdo, $id, 'user', null, $body);
    $t = sup_ticket($pdo, $id);
    if (function_exists('sup_notify_staff_new')) { try { sup_notify_staff_new($pdo, $t); } catch (Exception $e) { /* a failed notice never blocks the request */ } }
    return $t;
}

function sup_user_reply($pdo, $user, $ticketId, $body) {
    $t = sup_ticket($pdo, $ticketId);
    if (!$t || intval($t['user_id']) !== intval($user['id'])) throw new Exception('Request not found');
    if ($t['status'] === 'closed') throw new Exception('This request is closed. Please open a new one.');
    $body = sup_clean($body, 4000);
    if (sup_len($body) < 2) throw new Exception('Please write your message.');
    $S = sup_settings($pdo);
    $q = $pdo->prepare("SELECT COUNT(*) FROM sup_messages WHERE ticket_id = ? AND sender = 'user' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"); $q->execute(array($t['id']));
    if (intval($q->fetchColumn()) >= 5) throw new Exception('Too many messages in a minute. Please wait a little.');
    sup_add_message($pdo, $t['id'], 'user', null, $body);
    $pdo->prepare("UPDATE sup_tickets SET status = 'open', last_actor = 'user', staff_unread = 1, solved_at = NULL, due_at = ?, updated_at = NOW() WHERE id = ?")->execute(array(sup_due($S, $t['priority']), $t['id']));
    return sup_ticket($pdo, $t['id']);
}

/* Staff reply or internal note. A reply waits on the customer ('pending'), unless $status is given. Notes never reach the customer. */
function sup_staff_reply($pdo, $adminId, $ticketId, $body, $isNote, $status) {
    $t = sup_ticket($pdo, $ticketId);
    if (!$t) throw new Exception('Request not found');
    $body = sup_clean($body, 4000);
    if (sup_len($body) < 1) throw new Exception('Please write a message.');
    sup_add_message($pdo, $t['id'], $isNote ? 'note' : 'staff', $adminId, $body);
    if ($isNote) { $pdo->prepare('UPDATE sup_tickets SET staff_unread = 0 WHERE id = ?')->execute(array($t['id'])); return sup_ticket($pdo, $t['id']); }
    if (!in_array($status, array('open', 'pending', 'solved', 'closed'), true)) $status = 'pending';
    $pdo->prepare("UPDATE sup_tickets SET status = ?, last_actor = 'staff', user_unread = 1, staff_unread = 0, assigned_to = COALESCE(assigned_to, ?), first_response_at = COALESCE(first_response_at, NOW()),
                   solved_at = IF(? = 'solved', NOW(), solved_at), updated_at = NOW() WHERE id = ?")->execute(array($status, intval($adminId), $status, $t['id']));
    $t = sup_ticket($pdo, $t['id']);
    if (function_exists('sup_notify_user_reply')) { try { sup_notify_user_reply($pdo, $t); } catch (Exception $e) { /* never blocks the reply */ } }
    return $t;
}

/* Marks old 'solved' requests closed. Cheap; called when the queue is opened. */
function sup_housekeeping($pdo) {
    $S = sup_settings($pdo);
    try {
        $u = $pdo->prepare("UPDATE sup_tickets SET status = 'closed', updated_at = NOW() WHERE status = 'solved' AND solved_at IS NOT NULL AND solved_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $u->execute(array(intval($S['auto_close_days'])));
        return $u->rowCount();
    } catch (Exception $e) { return 0; }
}

}
