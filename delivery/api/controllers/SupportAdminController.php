<?php
// ============================================================
// ETEL Support desk - staff actions. Capabilities (role_permissions): support.view (read), support.manage (reply, assign, settings).
// An unrestricted admin ('*') has both. Replies and status changes are recorded as messages in the ticket itself (system lines), so the history is complete.
//   admin_sup_list {status?, app?, priority?, assigned? 'me'|'none', unread?, overdue?, q?, limit?, offset?}   admin_sup_get {id}
//   admin_sup_reply {id, body, note?, status?}      admin_sup_update {id, status?, priority?, assign? 'me'|'none'}
//   admin_sup_canned_list / admin_sup_canned_save {id?, title, body_en, body_am, sort_order?} / admin_sup_canned_delete {id}
//   admin_sup_stats {days?}      admin_sup_settings_get / admin_sup_settings_save {settings}
// ============================================================
require_once __DIR__ . '/../lib/sup_core.php';
require_once __DIR__ . '/../lib/sup_notify.php';

if (!function_exists('sup_admin_row')) {
function sup_admin_row($t) {
    $t['overdue'] = ($t['status'] === 'open' && $t['first_response_at'] === null && $t['due_at'] !== null && strtotime($t['due_at']) < time());
    $t['unread'] = !empty($t['staff_unread']);
    return $t;
}
}

switch ($action) {

case 'admin_sup_list': {
    $pdo = db(); $adm = require_admin($pdo, 'support.view');
    sup_housekeeping($pdo);
    $w = array('1=1'); $p = array();
    $st = isset($body['status']) ? (string) $body['status'] : 'active';
    if ($st === 'active') $w[] = "t.status IN ('open','pending')"; elseif (in_array($st, array('open', 'pending', 'solved', 'closed'), true)) { $w[] = 't.status = ?'; $p[] = $st; }
    if (!empty($body['app']) && isset(sup_apps()[$body['app']])) { $w[] = 't.app = ?'; $p[] = $body['app']; }
    if (!empty($body['priority']) && in_array($body['priority'], array('low', 'normal', 'high', 'urgent'), true)) { $w[] = 't.priority = ?'; $p[] = $body['priority']; }
    if (isset($body['assigned']) && $body['assigned'] === 'me') { $w[] = 't.assigned_to = ?'; $p[] = $adm['id']; } elseif (isset($body['assigned']) && $body['assigned'] === 'none') $w[] = 't.assigned_to IS NULL';
    if (!empty($body['unread'])) $w[] = 't.staff_unread = 1';
    if (!empty($body['overdue'])) $w[] = "t.status = 'open' AND t.first_response_at IS NULL AND t.due_at < NOW()";
    if (!empty($body['q'])) { $q = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), sup_clean($body['q'], 60)) . '%'; $w[] = '(t.ref LIKE ? OR t.subject LIKE ? OR u.name LIKE ? OR u.phone LIKE ?)'; array_push($p, $q, $q, $q, $q); }
    $lim = isset($body['limit']) ? max(1, min(100, intval($body['limit']))) : 40; $off = isset($body['offset']) ? max(0, intval($body['offset'])) : 0;
    $sql = "SELECT t.*, u.name AS user_name, u.phone AS user_phone FROM sup_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE " . implode(' AND ', $w) .
           " ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.updated_at DESC LIMIT " . $lim . " OFFSET " . $off;
    $s = $pdo->prepare($sql); $s->execute($p);
    $rows = array(); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $t) $rows[] = sup_admin_row($t);
    $c = array();
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM sup_tickets GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) $c[$r['status']] = intval($r['n']);
    $c['overdue'] = intval($pdo->query("SELECT COUNT(*) FROM sup_tickets WHERE status = 'open' AND first_response_at IS NULL AND due_at < NOW()")->fetchColumn());
    $c['unread'] = intval($pdo->query("SELECT COUNT(*) FROM sup_tickets WHERE staff_unread = 1 AND status IN ('open','pending')")->fetchColumn());
    ok(array('tickets' => $rows, 'counts' => $c, 'apps' => sup_apps()));
    break;
}

case 'admin_sup_get': {
    $pdo = db(); $adm = require_admin($pdo, 'support.view');
    $t = sup_ticket($pdo, isset($body['id']) ? intval($body['id']) : 0);
    if (!$t) fail('Request not found', 404);
    $q = $pdo->prepare('SELECT m.id, m.sender, m.body, m.created_at, m.staff_id, a.name AS staff_name FROM sup_messages m LEFT JOIN users a ON a.id = m.staff_id WHERE m.ticket_id = ? ORDER BY m.id ASC'); $q->execute(array($t['id']));
    $u = $pdo->prepare('SELECT id, name, phone, etel_id FROM users WHERE id = ?'); $u->execute(array($t['user_id']));
    $o = $pdo->prepare("SELECT id, ref, subject, status FROM sup_tickets WHERE user_id = ? AND id <> ? ORDER BY id DESC LIMIT 5"); $o->execute(array($t['user_id'], $t['id']));
    if (!empty($t['staff_unread'])) $pdo->prepare('UPDATE sup_tickets SET staff_unread = 0 WHERE id = ?')->execute(array($t['id']));
    ok(array('ticket' => sup_admin_row($t), 'messages' => $q->fetchAll(PDO::FETCH_ASSOC), 'user' => $u->fetch(PDO::FETCH_ASSOC), 'other' => $o->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'admin_sup_reply': {
    $pdo = db(); $adm = require_admin($pdo, 'support.manage');
    try { $t = sup_staff_reply($pdo, $adm['id'], isset($body['id']) ? intval($body['id']) : 0, isset($body['body']) ? $body['body'] : '', !empty($body['note']), isset($body['status']) ? (string) $body['status'] : 'pending'); }
    catch (Exception $e) { fail($e->getMessage(), 400); }
    ok(array('ticket' => sup_admin_row($t)));
    break;
}

case 'admin_sup_update': {
    $pdo = db(); $adm = require_admin($pdo, 'support.manage');
    $t = sup_ticket($pdo, isset($body['id']) ? intval($body['id']) : 0);
    if (!$t) fail('Request not found', 404);
    $log = array();
    if (isset($body['status']) && $body['status'] !== $t['status']) {
        if (!in_array($body['status'], array('open', 'pending', 'solved', 'closed'), true)) fail('Unknown status');
        $pdo->prepare("UPDATE sup_tickets SET status = ?, solved_at = IF(? = 'solved', NOW(), solved_at), updated_at = NOW() WHERE id = ?")->execute(array($body['status'], $body['status'], $t['id']));
        $log[] = 'Status: ' . $t['status'] . ' to ' . $body['status'];
    }
    if (isset($body['priority']) && $body['priority'] !== $t['priority']) {
        if (!in_array($body['priority'], array('low', 'normal', 'high', 'urgent'), true)) fail('Unknown priority');
        $S = sup_settings($pdo);
        $pdo->prepare('UPDATE sup_tickets SET priority = ?, due_at = ?, updated_at = NOW() WHERE id = ?')->execute(array($body['priority'], $t['first_response_at'] === null ? sup_due($S, $body['priority'], strtotime($t['created_at'])) : $t['due_at'], $t['id']));
        $log[] = 'Priority: ' . $t['priority'] . ' to ' . $body['priority'];
    }
    if (isset($body['assign'])) {
        $to = $body['assign'] === 'me' ? intval($adm['id']) : null;
        $pdo->prepare('UPDATE sup_tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?')->execute(array($to, $t['id']));
        $log[] = $to ? 'Taken by ' . (isset($adm['name']) ? $adm['name'] : 'staff') : 'Unassigned';
    }
    foreach ($log as $l) sup_add_message($pdo, $t['id'], 'system', $adm['id'], $l);
    ok(array('ticket' => sup_admin_row(sup_ticket($pdo, $t['id']))));
    break;
}

case 'admin_sup_canned_list': {
    $pdo = db(); require_admin($pdo, 'support.view');
    ok(array('canned' => $pdo->query('SELECT id, title, body_en, body_am, sort_order FROM sup_canned ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC)));
    break;
}
case 'admin_sup_canned_save': {
    $pdo = db(); require_admin($pdo, 'support.manage');
    $title = sup_clean(isset($body['title']) ? $body['title'] : '', 80); $en = sup_clean(isset($body['body_en']) ? $body['body_en'] : '', 2000); $am = sup_clean(isset($body['body_am']) ? $body['body_am'] : '', 2000);
    if ($title === '' || ($en === '' && $am === '')) fail('A title and a text are needed');
    $sort = isset($body['sort_order']) ? intval($body['sort_order']) : 0; $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id) $pdo->prepare('UPDATE sup_canned SET title = ?, body_en = ?, body_am = ?, sort_order = ? WHERE id = ?')->execute(array($title, $en, $am, $sort, $id));
    else { $pdo->prepare('INSERT INTO sup_canned (title, body_en, body_am, sort_order, created_at) VALUES (?,?,?,?,NOW())')->execute(array($title, $en, $am, $sort)); $id = intval($pdo->lastInsertId()); }
    ok(array('id' => $id));
    break;
}
case 'admin_sup_canned_delete': {
    $pdo = db(); require_admin($pdo, 'support.manage');
    $pdo->prepare('DELETE FROM sup_canned WHERE id = ?')->execute(array(isset($body['id']) ? intval($body['id']) : 0));
    ok(array('deleted' => true));
    break;
}

case 'admin_sup_stats': {
    $pdo = db(); require_admin($pdo, 'support.view');
    $d = isset($body['days']) ? max(7, min(180, intval($body['days']))) : 30; $since = date('Y-m-d H:i:s', time() - 86400 * $d);
    $one = function ($sql, $p) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchColumn(); };
    $by = function ($col) use ($pdo, $since) { $q = $pdo->prepare("SELECT $col AS k, COUNT(*) AS n FROM sup_tickets WHERE created_at >= ? GROUP BY $col ORDER BY n DESC"); $q->execute(array($since)); return $q->fetchAll(PDO::FETCH_ASSOC); };
    $p = array($since);
    $avgResp = $one('SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) FROM sup_tickets WHERE first_response_at IS NOT NULL AND created_at >= ?', $p);
    $csat = $one('SELECT AVG(rating) FROM sup_tickets WHERE rating IS NOT NULL AND created_at >= ?', $p);
    ok(array('days' => $d, 'created' => intval($one('SELECT COUNT(*) FROM sup_tickets WHERE created_at >= ?', $p)), 'solved' => intval($one("SELECT COUNT(*) FROM sup_tickets WHERE solved_at >= ?", $p)),
        'avg_first_response_hours' => $avgResp === null ? null : round($avgResp / 60, 1), 'csat' => $csat === null ? null : round($csat, 2), 'rated' => intval($one('SELECT COUNT(*) FROM sup_tickets WHERE rating IS NOT NULL AND created_at >= ?', $p)),
        'by_app' => $by('app'), 'by_category' => $by('category'), 'apps' => sup_apps()));
    break;
}

case 'admin_sup_settings_get': {
    $pdo = db(); require_admin($pdo, 'support.view');
    $meta = array(); foreach (sup_defaults() as $k => $d) $meta[$k] = array('default' => $d[0], 'min' => $d[1], 'max' => $d[2]);
    $tok = function_exists('etel_env') ? (etel_env('TG_BOT_TOKEN', 'CFG_TG_BOT_TOKEN', '') !== '') : false;
    ok(array('settings' => sup_settings($pdo, true), 'meta' => $meta, 'tg_token_configured' => $tok));
    break;
}
case 'admin_sup_settings_save': {
    $pdo = db(); $adm = require_admin($pdo, 'support.manage');
    try { ok(array('settings' => sup_settings_save($pdo, isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : array(), $adm['id']))); }
    catch (Exception $e) { fail($e->getMessage(), 400); }
    break;
}

}
