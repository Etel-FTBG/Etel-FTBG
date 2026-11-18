<?php
// ============================================================
// Messages the owner sends to phones from Admin (table push_broadcasts, migration 114).
//   audience: all | customer | driver | courier | service_giver | user (one person, found by phone number)
// Only active accounts that have at least one phone or browser able to receive a push are counted and sent to.
// The message goes out AFTER the answer has been returned to the admin page (so the page does not wait), and the row is updated with
// how many people it reached. It sends with etel_push_to_user() (web push and the phone app's Expo push), the same as every other push.
// ============================================================
if (!function_exists('broadcast_audiences')) {

function broadcast_audiences() { return array('all', 'customer', 'driver', 'courier', 'service_giver', 'user'); }
function broadcast_max_recipients() { return 2000; }

function broadcast_text($v, $max) {
    $v = strip_tags((string) $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    $v = trim(preg_replace('/\s+/', ' ', $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/* Finds the people. Returns array('ids' => user ids that can receive a push, 'people' => everybody in the audience, 'name' => for one person, 'error' => ''). */
function broadcast_resolve($pdo, $audience, $phone) {
    $out = array('ids' => array(), 'people' => 0, 'name' => '', 'error' => '');
    if (!in_array($audience, broadcast_audiences(), true)) { $out['error'] = 'Choose who gets the message.'; return $out; }
    if ($audience === 'user') {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if (strlen($digits) < 9) { $out['error'] = 'Type the phone number of the person (at least 9 digits).'; return $out; }
        $s = $pdo->prepare('SELECT id, name FROM users WHERE active = 1 AND RIGHT(REPLACE(REPLACE(phone, \'+\', \'\'), \' \', \'\'), 9) = ? LIMIT 2');
        $s->execute(array(substr($digits, -9)));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) { $out['error'] = 'No active account has that phone number.'; return $out; }
        if (count($rows) > 1) { $out['error'] = 'More than one account has that number. Use the full number with +251.'; return $out; }
        $out['people'] = 1; $out['name'] = (string) $rows[0]['name'];
        $h = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?'); $h->execute(array($rows[0]['id']));
        if (intval($h->fetchColumn()) > 0) $out['ids'] = array(intval($rows[0]['id']));
        return $out;
    }
    $where = 'u.active = 1'; $args = array();
    if ($audience !== 'all') { $where .= ' AND u.role = ?'; $args[] = $audience; }
    $c = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $where); $c->execute($args);
    $out['people'] = intval($c->fetchColumn());
    $s = $pdo->prepare('SELECT DISTINCT u.id FROM users u JOIN push_subscriptions p ON p.user_id = u.id WHERE ' . $where . ' ORDER BY u.id LIMIT ' . (broadcast_max_recipients() + 1));
    $s->execute($args);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $out['ids'][] = intval($id);
    return $out;
}

/* Starts a message: checks the text and the people, refuses an accidental double send, writes the row. Returns array('id' =>, 'ids' =>, 'error' =>). */
function broadcast_start($pdo, $adminId, $audience, $phone, $title, $body) {
    $r = array('id' => 0, 'ids' => array(), 'error' => '');
    $title = broadcast_text($title, 60); $body = broadcast_text($body, 240);
    if ($title === '' || $body === '') { $r['error'] = 'Write a title and a message.'; return $r; }
    $res = broadcast_resolve($pdo, $audience, $phone);
    if ($res['error'] !== '') { $r['error'] = $res['error']; return $r; }
    if (count($res['ids']) === 0) { $r['error'] = $audience === 'user' ? 'That person has not allowed notifications on any phone yet, so nothing can be sent.' : 'Nobody in this group can receive a notification yet.'; return $r; }
    if (count($res['ids']) > broadcast_max_recipients()) { $r['error'] = 'That is more than ' . broadcast_max_recipients() . ' people. Send it to one group at a time (customers, drivers, couriers, service givers).'; return $r; }
    $target = 0;
    if ($audience === 'user') $target = intval($res['ids'][0]);
    $d = $pdo->prepare("SELECT id FROM push_broadcasts WHERE title = ? AND body = ? AND audience = ? AND target_user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
    $d->execute(array($title, $body, $audience, $target));
    if ($d->fetchColumn()) { $r['error'] = 'This exact message was already sent in the last 10 minutes. Change the text or wait.'; return $r; }
    $pdo->prepare("INSERT INTO push_broadcasts (title, body, audience, target_user_id, recipients, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'sending', ?, NOW())")
        ->execute(array($title, $body, $audience, $target, count($res['ids']), intval($adminId)));
    $r['id'] = intval($pdo->lastInsertId()); $r['ids'] = $res['ids'];
    return $r;
}

/* Sends to every person and writes down how it went. A person counts as reached when at least one of their phones took it. */
function broadcast_deliver($pdo, $id, $ids, $title, $body) {
    if (function_exists('set_time_limit')) @set_time_limit(300);
    $title = broadcast_text($title, 60); $body = broadcast_text($body, 240);
    $payload = array('title' => $title, 'body' => $body, 'url' => '/delivery/login.html', 'data' => array('type' => 'broadcast', 'broadcast_id' => intval($id)));
    $reached = 0; $failed = 0;
    foreach ($ids as $uid) {
        $good = false;
        try { foreach (etel_push_to_user($pdo, intval($uid), $payload) as $r) { if (!empty($r['ok'])) { $good = true; break; } } }
        catch (Exception $e) { error_log('[ETEL][broadcast] ' . $e->getMessage()); }
        catch (Throwable $e) { error_log('[ETEL][broadcast] ' . $e->getMessage()); }
        if ($good) $reached++; else $failed++;
    }
    $pdo->prepare("UPDATE push_broadcasts SET reached = ?, failed = ?, status = 'sent', sent_at = NOW() WHERE id = ?")->execute(array($reached, $failed, intval($id)));
}

/* Sends after the answer has gone back to the admin page. Without a way to do that (command line, tests), it sends right away. */
function broadcast_deliver_later($pdo, $id, $ids, $title, $body) {
    if (PHP_SAPI === 'cli' || (!function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request'))) { broadcast_deliver($pdo, $id, $ids, $title, $body); return; }
    ignore_user_abort(true);
    register_shutdown_function(function () use ($pdo, $id, $ids, $title, $body) {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
        elseif (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        try { broadcast_deliver($pdo, $id, $ids, $title, $body); } catch (Throwable $e) { error_log('[ETEL][broadcast] deferred send failed: ' . $e->getMessage()); }
    });
}

function broadcast_history($pdo) {
    $s = $pdo->query("SELECT b.id, b.title, b.body, b.audience, b.recipients, b.reached, b.failed, b.status, b.created_at, b.sent_at, TIMESTAMPDIFF(SECOND, b.created_at, NOW()) AS age_s, u.name AS target_name
                      FROM push_broadcasts b LEFT JOIN users u ON u.id = b.target_user_id AND b.audience = 'user' ORDER BY b.id DESC LIMIT 50");
    $out = array();
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // A message still "sending" after 5 minutes did not finish (the server stopped it): say so instead of showing "sending" for ever.
        if ((string) $r['status'] === 'sending' && intval($r['age_s']) > 300) $r['status'] = 'unfinished';
        $out[] = array('id' => intval($r['id']), 'title' => (string) $r['title'], 'body' => (string) $r['body'], 'audience' => (string) $r['audience'], 'target_name' => (string) $r['target_name'],
                       'recipients' => intval($r['recipients']), 'reached' => intval($r['reached']), 'failed' => intval($r['failed']), 'status' => (string) $r['status'],
                       'created_at' => (string) $r['created_at'], 'sent_at' => (string) $r['sent_at'], 'age_s' => max(0, intval($r['age_s'])));
    }
    return $out;
}

}
