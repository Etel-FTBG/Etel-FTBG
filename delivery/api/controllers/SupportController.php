<?php
// ============================================================
// ETEL Support desk - customer-facing actions (any signed-in Etel user; used by the Help page and every app's Help button).
//   sup_meta            {}                                     -> apps, categories, unread count, desk on/off
//   sup_ticket_create   { app, category, subject, body, related?, lang? }
//   sup_my_tickets      {}                                     -> the person's own requests, newest first
//   sup_ticket_get      { id }                                 -> one request with its messages (internal notes are never shown)
//   sup_ticket_reply    { id, body }
//   sup_ticket_close    { id }                                 -> "my problem is solved"
//   sup_ticket_rate     { id, rating 1..5, note? }
// Limited (profile-incomplete) accounts are allowed on purpose: a person who is stuck must still be able to ask for help.
// ============================================================
require_once __DIR__ . '/../lib/sup_core.php';
require_once __DIR__ . '/../lib/sup_notify.php';

if (!function_exists('sup_public_ticket')) {
function sup_public_ticket($t) {
    return array('id' => intval($t['id']), 'ref' => $t['ref'], 'app' => $t['app'], 'category' => $t['category'], 'subject' => $t['subject'], 'status' => $t['status'],
                 'unread' => !empty($t['user_unread']), 'rating' => $t['rating'] === null ? null : intval($t['rating']), 'created_at' => $t['created_at'], 'updated_at' => $t['updated_at']);
}
}

switch ($action) {

case 'sup_meta': {
    $pdo = db(); $u = require_auth($pdo); $S = sup_settings($pdo);
    $q = $pdo->prepare('SELECT COUNT(*) FROM sup_tickets WHERE user_id = ? AND user_unread = 1'); $q->execute(array($u['id']));
    ok(array('apps' => sup_apps(), 'categories' => sup_categories(), 'enabled' => !empty($S['desk_enabled']), 'csat' => !empty($S['csat_enabled']), 'unread' => intval($q->fetchColumn())));
    break;
}

case 'sup_ticket_create': {
    $pdo = db(); $u = require_auth($pdo);
    try {
        $t = sup_create($pdo, $u, isset($body['app']) ? (string) $body['app'] : 'other', isset($body['category']) ? (string) $body['category'] : 'other',
                        isset($body['subject']) ? $body['subject'] : '', isset($body['body']) ? $body['body'] : '', isset($body['related']) ? $body['related'] : '', isset($body['lang']) ? (string) $body['lang'] : 'am');
    } catch (Exception $e) { fail($e->getMessage(), 400); }
    ok(array('ticket' => sup_public_ticket($t)));
    break;
}

case 'sup_my_tickets': {
    $pdo = db(); $u = require_auth($pdo);
    $q = $pdo->prepare('SELECT * FROM sup_tickets WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 100'); $q->execute(array($u['id']));
    $rows = array(); $unread = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) { $rows[] = sup_public_ticket($t); if (!empty($t['user_unread'])) $unread++; }
    ok(array('tickets' => $rows, 'unread' => $unread));
    break;
}

case 'sup_ticket_get': {
    $pdo = db(); $u = require_auth($pdo);
    $t = sup_ticket($pdo, isset($body['id']) ? intval($body['id']) : 0);
    if (!$t || intval($t['user_id']) !== intval($u['id'])) fail('Request not found', 404);
    $q = $pdo->prepare("SELECT sender, body, created_at FROM sup_messages WHERE ticket_id = ? AND sender IN ('user','staff','system') ORDER BY id ASC"); $q->execute(array($t['id']));
    $msgs = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) $msgs[] = array('from' => $m['sender'] === 'staff' ? 'support' : ($m['sender'] === 'system' ? 'system' : 'me'), 'body' => $m['body'], 'at' => $m['created_at']);
    $pdo->prepare('UPDATE sup_tickets SET user_unread = 0 WHERE id = ?')->execute(array($t['id']));
    $S = sup_settings($pdo);
    $can = ($t['status'] === 'solved' || $t['status'] === 'closed') && $t['rating'] === null && !empty($S['csat_enabled']);
    ok(array('ticket' => sup_public_ticket($t), 'messages' => $msgs, 'can_rate' => $can, 'can_reply' => $t['status'] !== 'closed'));
    break;
}

case 'sup_ticket_reply': {
    $pdo = db(); $u = require_auth($pdo);
    try { $t = sup_user_reply($pdo, $u, isset($body['id']) ? intval($body['id']) : 0, isset($body['body']) ? $body['body'] : ''); }
    catch (Exception $e) { fail($e->getMessage(), 400); }
    ok(array('ticket' => sup_public_ticket($t)));
    break;
}

case 'sup_ticket_close': {
    $pdo = db(); $u = require_auth($pdo);
    $t = sup_ticket($pdo, isset($body['id']) ? intval($body['id']) : 0);
    if (!$t || intval($t['user_id']) !== intval($u['id'])) fail('Request not found', 404);
    if ($t['status'] === 'open' || $t['status'] === 'pending') {
        $pdo->prepare("UPDATE sup_tickets SET status = 'solved', solved_at = NOW(), updated_at = NOW(), staff_unread = 1 WHERE id = ?")->execute(array($t['id']));
        sup_add_message($pdo, $t['id'], 'system', null, 'The customer marked this as solved.');
    }
    ok(array('ticket' => sup_public_ticket(sup_ticket($pdo, $t['id']))));
    break;
}

case 'sup_ticket_rate': {
    $pdo = db(); $u = require_auth($pdo); $S = sup_settings($pdo);
    $t = sup_ticket($pdo, isset($body['id']) ? intval($body['id']) : 0);
    if (!$t || intval($t['user_id']) !== intval($u['id'])) fail('Request not found', 404);
    $r = isset($body['rating']) ? intval($body['rating']) : 0;
    if ($r < 1 || $r > 5) fail('Please choose 1 to 5 stars');
    if (empty($S['csat_enabled']) || ($t['status'] !== 'solved' && $t['status'] !== 'closed') || $t['rating'] !== null) fail('This request cannot be rated', 409);
    $pdo->prepare('UPDATE sup_tickets SET rating = ?, rating_note = ?, updated_at = NOW() WHERE id = ?')->execute(array($r, sup_clean(isset($body['note']) ? $body['note'] : '', 255), $t['id']));
    ok(array('ticket' => sup_public_ticket(sup_ticket($pdo, $t['id']))));
    break;
}

}
